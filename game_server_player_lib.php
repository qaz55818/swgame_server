<?php
/**
 * =============================================================
 *  踏雪笑傲 · 角色服務設定工具庫（game_server_player_lib.php）
 *  -------------------------------------------------------------
 *  管理遠端 player_sev_config_data.lua（gamed/config/script/）：
 *    - 設定遠端檔案路徑（game_server_player_config）
 *    - 透過 SSH/SFTP 讀取與寫回
 *    - 內容快取與初始快照（供「還原初始」）
 *    - 通用 Lua 設定解析器（沿用 game_server_reward_lib 的詞法／語法），
 *      以行內位元組位移就地替換數值，保留註解、函式與縮排
 *    - 中文說明 + 英文原文（gameserver_player_doc）
 *    - 寫入操作稽核（沿用 admin/remote_lib.php）
 *
 *  目標伺服器沿用 game_server_config.server_id（與「遊戲版本設定」共用）。
 *  資料表：game_server_player_config（見 game_server_script_schema.sql）
 *  依賴：game_server_reward_lib.php（通用解析器／編碼）、admin/remote_lib.php
 * =============================================================
 */

if (defined('GAME_SERVER_PLAYER_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_PLAYER_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_reward_lib.php';

if (!defined('GAME_SERVER_PLAYER_TABLE')) {
    define('GAME_SERVER_PLAYER_TABLE', 'game_server_player_config');
}
if (!defined('GAME_SERVER_PLAYER_DEFAULT_PATH')) {
    define('GAME_SERVER_PLAYER_DEFAULT_PATH', '/root/xa274/gamed/config/script/player_sev_config_data.lua');
}

/* ============================================================
 *  一、資料表與設定存取（與其他設定檔同構）
 * ============================================================ */
function gameserver_player_ensure($link): void
{
    if (!$link) {
        return;
    }
    $t = GAME_SERVER_PLAYER_TABLE;
    try {
        $link->query("CREATE TABLE IF NOT EXISTS `$t` (
            `id` tinyint(1) NOT NULL DEFAULT 1,
            `path` varchar(255) NOT NULL DEFAULT '" . GAME_SERVER_PLAYER_DEFAULT_PATH . "',
            `raw_cache` mediumtext NULL,
            `initial_raw` mediumtext NULL,
            `updated_by` varchar(50) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $link->query("INSERT IGNORE INTO `$t` (`id`,`path`) VALUES (1,'" . GAME_SERVER_PLAYER_DEFAULT_PATH . "')");
    } catch (Throwable $e) {
        // 權限不足時略過
    }
}

function gameserver_player_config_get($link): array
{
    $default = [
        'id' => 1, 'path' => GAME_SERVER_PLAYER_DEFAULT_PATH,
        'raw_cache' => '', 'initial_raw' => '', 'updated_by' => '', 'updated_at' => '',
    ];
    if (!$link) {
        return $default;
    }
    try {
        $res = @$link->query("SELECT * FROM `" . GAME_SERVER_PLAYER_TABLE . "` WHERE `id` = 1 LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            $row = array_merge($default, $row);
            $row['raw_cache']   = gameserver_decode_raw($row['raw_cache'] ?? '');
            $row['initial_raw'] = gameserver_decode_raw($row['initial_raw'] ?? '');
            return $row;
        }
    } catch (Throwable $e) {
        // 忽略
    }
    return $default;
}

function gameserver_player_set_path($link, $path, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $path = trim((string)$path);
    if ($path === '') {
        $path = GAME_SERVER_PLAYER_DEFAULT_PATH;
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    try {
        $stmt = $link->prepare("INSERT INTO `" . GAME_SERVER_PLAYER_TABLE . "` (`id`,`path`,`updated_by`)
            VALUES (1,?,?) ON DUPLICATE KEY UPDATE `path`=VALUES(`path`), `updated_by`=VALUES(`updated_by`)");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $path, $by);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

function gameserver_player_cache($link, $content, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $cfg = gameserver_player_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        $initial = (string)$content;
    }
    $enc  = gameserver_encode_raw($content);
    $ienc = gameserver_encode_raw($initial);
    try {
        $stmt = $link->prepare("UPDATE `" . GAME_SERVER_PLAYER_TABLE . "`
            SET `raw_cache`=?, `initial_raw`=?, `updated_by`=? WHERE `id`=1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sss', $enc, $ienc, $by);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

/* ============================================================
 *  二、編碼轉換（委派通用實作）
 * ============================================================ */
function gameserver_player_is_utf8($s): bool { return gameserver_produce_is_utf8($s); }
function gameserver_player_to_utf8($s): string { return gameserver_produce_to_utf8($s); }
function gameserver_player_from_utf8($s): string { return gameserver_produce_from_utf8($s); }

/* ============================================================
 *  三、遠端讀寫（SSH / SFTP）
 * ============================================================ */
function gameserver_player_read($link): array
{
    $cfg = gameserver_player_config_get($link);
    $server = gameserver_file_target_server($link);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器（請先於「遊戲版本設定」選擇）。'];
    }
    $path = (string)$cfg['path'];
    try {
        $sftp = remote_sftp($server, 20);
        $content = $sftp->get($path);
        if ($content === false) {
            return ['ok' => false, 'error' => '無法讀取遠端檔案：' . $path];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'content' => $content, 'server' => $server, 'path' => $path];
}

function gameserver_player_write($link, $content, $by = '', $action = 'save_player'): array
{
    $cfg = gameserver_player_config_get($link);
    $server = gameserver_file_target_server($link);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器。'];
    }
    $path = (string)$cfg['path'];
    try {
        $sftp = remote_sftp($server, 30);
        if (!$sftp->put($path, (string)$content)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $path];
        }
        $cached = gameserver_player_cache($link, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, $action, $path, '');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

function gameserver_player_refresh($link, $by = ''): array
{
    $read = gameserver_player_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    if (!gameserver_player_cache($link, $read['content'], $by)) {
        return ['ok' => false, 'error' => '讀取成功，但本機快取寫入失敗（請檢查資料庫）。'];
    }
    if (!empty($read['server']) && function_exists('remote_audit')) {
        remote_audit($link, $read['server'], 'refresh_player', $read['path'], '');
    }
    return ['ok' => true, 'error' => ''];
}

function gameserver_player_restore($link, $by = ''): array
{
    $cfg = gameserver_player_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        return ['ok' => false, 'error' => '尚無初始快照，請先執行「讀取最新」。'];
    }
    return gameserver_player_write($link, $initial, $by, 'restore_player');
}

/* ============================================================
 *  四、解析 / 套用（沿用通用 Lua 解析器）
 * ============================================================ */
/** 受管理的頂層設定名稱 */
function gameserver_player_known_bases(): array
{
    return [
        'LUCK_VALUE_RESET_TIME',
        'LuckValue',
        'Revive_Base_Money',
        'FreePersonalProValue',
        'CostPersonalProValue',
        'GlobalPrizeForAll',
        'GlobalPrizeForSingle',
        'PersonalLotteryCost',
    ];
}

/**
 * 解析 player_sev_config_data.lua。
 * @return array { entries, nodes, eol }
 */
function gameserver_player_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $parser = new GameserverLuaParser((string)$content);
    $entries = $parser->parseAll(gameserver_player_known_bases());
    $nodes = [];
    $counter = 0;
    $walk = function ($node) use (&$walk, &$nodes, &$counter) {
        if ($node === null) { return; }
        if ($node->kind === 'scalar') {
            $node->id = $counter;
            $nodes[$counter] = $node;
            $counter++;
            return;
        }
        if ($node->kind === 'table') {
            foreach ($node->fields as $f) {
                $walk($f->value);
            }
        }
    };
    foreach ($entries as $e) {
        $walk($e->node);
    }
    return ['entries' => $entries, 'nodes' => $nodes, 'eol' => $eol];
}

/** 依節點 id 就地套用新值，回傳新內容 */
function gameserver_player_apply_content($content, array $edits): string
{
    $parsed = gameserver_player_parse($content);
    $nodes = $parsed['nodes'];
    $repls = [];
    foreach ($edits as $id => $new) {
        $id = (int)$id;
        if (!isset($nodes[$id])) { continue; }
        $node = $nodes[$id];
        $new = trim((string)$new);
        if ($node->vtype === 'number') {
            if (!preg_match('/^-?(\d+(\.\d+)?|\.\d+)$/', $new)) { continue; }
            $repls[] = [$node->start, $node->end, $new];
        } elseif ($node->vtype === 'string') {
            $esc = str_replace(['\\', '"'], ['\\\\', '\\"'], $new);
            $repls[] = [$node->start, $node->end, '"' . $esc . '"'];
        } elseif ($node->vtype === 'bool') {
            if ($new === 'true' || $new === 'false') {
                $repls[] = [$node->start, $node->end, $new];
            }
        }
    }
    usort($repls, function ($a, $b) { return $b[0] <=> $a[0]; });
    foreach ($repls as $r) {
        $content = substr($content, 0, $r[0]) . $r[2] . substr($content, $r[1]);
    }
    return $content;
}

/** 套用結構化更新並寫回遠端 */
function gameserver_player_apply($link, array $edits, $by = ''): array
{
    $read = gameserver_player_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = gameserver_player_apply_content($read['content'], $edits);
    return gameserver_player_write($link, $content, $by, 'save_player');
}

/* ============================================================
 *  五、參數說明（中文 + 英文原文 + 詳細中文註釋）
 * ============================================================ */
function gameserver_player_field_doc(string $key): array
{
    $map = [
        'prob'  => ['概率', 'prob', '該區間 / 項目的觸發機率（0~1，同一清單內的機率總和建議為 1）。'],
        'min'   => ['最小值', 'min', '福緣值區間下限（含）。'],
        'max'   => ['最大值', 'max', '福緣值區間上限（含）。'],
        'count' => ['數量 / 個數', 'count', '吉字個數或發獎數量；於收費表中代表收費階段的下限。'],
        'tid'   => ['獎勵模板 ID', 'tid', '對應獎勵模板編號；0 表示不發獎。'],
        'cost'  => ['消耗元寶', 'cost', '該階段每次抽取消耗的元寶數量。'],
        'item'  => ['日誌物品 ID', 'item', '僅用於記錄日誌的物品編號，不影響實際發獎。'],
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    return [$key, $key, ''];
}

/** 位置型清單的中文標籤（依表名與索引） */
function gameserver_player_pos_doc(string $base, int $index): array
{
    return ['數值 ' . $index, 'value_' . $index];
}

/** 區段定義（順序即畫面順序） */
function gameserver_player_sections(): array
{
    return [
        [
            'key' => 'luck', 'label' => '福緣系統', 'icon' => 'fa-solid fa-clover',
            'desc' => '福緣值重置時間與各福緣區間的出現機率。福緣值影響角色當日的運勢（如掉落、強化成功率等）。',
            'bases' => ['LUCK_VALUE_RESET_TIME', 'LuckValue'],
            'notes' => [
                'LUCK_VALUE_RESET_TIME 為福緣值每日重置時間，單位秒；填 0 表示於當地時間 0 點重置（例：4*3600+30*60 表示每日 04:30 重置）。',
                'LuckValue 每一列代表一個福緣區間：prob 為出現機率，min / max 為該區間的福緣值上下限（含）。',
                '所有 prob 的總和應為 1，系統會依機率抽取一個區間並在 [min, max] 內取得福緣值。',
            ],
        ],
        [
            'key' => 'revive', 'label' => '復活消耗', 'icon' => 'fa-solid fa-heart-pulse',
            'desc' => '角色死亡後原地復活所需消耗的交易幣基數，依等級（1~200）區分。',
            'bases' => ['Revive_Base_Money'],
            'notes' => [
                'Revive_Base_Money[等級] 為該等級復活的基礎交易幣消耗。',
                '實際消耗 = 基礎值 + 復活次數 × 100；若為「被入侵」狀態則再乘以 1.5 倍。',
                '復活次數達 20 次（含）以上時，額外需要重置道具（reset_need = 1）。',
                '等級未設定時，介面回傳失敗（0,0,-1），請確保 1~200 皆有對應值。',
            ],
        ],
        [
            'key' => 'yao_free', 'label' => '免費個人搖搖樂', 'icon' => 'fa-solid fa-dice',
            'desc' => '免費個人搖搖樂（搖獎）中，吉字個數對應的機率與獎勵模板。',
            'bases' => ['FreePersonalProValue'],
            'notes' => [
                '每一列代表一種結果：prob 為機率，count 為搖出的吉字個數，tid 為發放的獎勵模板。',
                '免費次數有上限（預設 10 次），超過上限後改用收費表 PersonalLotteryCost。',
            ],
        ],
        [
            'key' => 'yao_cost', 'label' => '收費個人搖搖樂', 'icon' => 'fa-solid fa-coins',
            'desc' => '收費個人搖搖樂（搖獎）中，吉字個數對應的機率與獎勵模板。',
            'bases' => ['CostPersonalProValue'],
            'notes' => [
                '結構與免費搖搖樂相同，但用於超過免費次數後需付費的抽取。',
                '付費金額由「個人搖搖樂收費」PersonalLotteryCost 決定。',
            ],
        ],
        [
            'key' => 'global_all', 'label' => '全服抽籤（全服發獎）', 'icon' => 'fa-solid fa-globe',
            'desc' => '全服抽籤活動中，達到特定數量時對全服發放的獎勵。',
            'bases' => ['GlobalPrizeForAll'],
            'notes' => [
                '每一列 {count, tid}：當全服抽籤累積達 count 時，對全服玩家發放獎勵模板 tid。',
            ],
        ],
        [
            'key' => 'global_single', 'label' => '全服抽籤（個人發獎）', 'icon' => 'fa-solid fa-user',
            'desc' => '全服抽籤活動中，達到特定數量時對個人發放的獎勵。',
            'bases' => ['GlobalPrizeForSingle'],
            'notes' => [
                '每一列 {count, tid}：當個人抽籤累積達 count 時，對該玩家發放獎勵模板 tid；tid 為 0 表示不發獎。',
            ],
        ],
        [
            'key' => 'lottery_cost', 'label' => '個人搖搖樂收費', 'icon' => 'fa-solid fa-ticket',
            'desc' => '個人搖搖樂超過免費次數後的收費階梯。',
            'bases' => ['PersonalLotteryCost'],
            'notes' => [
                'count 為一個收費階段的下限（第 1 個必須為 10，因免費上限為 10 個）。',
                'cost 為該階段每次抽取消耗的元寶；最後一列代表之後所有次數皆採該費用。',
                'item 僅作為記錄日誌用的物品編號，不影響實際發獎。',
            ],
        ],
    ];
}

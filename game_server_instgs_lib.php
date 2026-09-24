<?php
/**
 * =============================================================
 *  踏雪笑傲 · 場景∕世界設定工具庫（game_server_instgs_lib.php）
 *  -------------------------------------------------------------
 *  專用於遠端 instance_gs.lua（gamed/config/script/）：
 *    - 設定遠端檔案路徑（資料表 game_server_instgs_config）
 *    - 透過 SSH/SFTP 讀取與寫回，內容快取與初始快照（供「還原初始」）
 *    - 行式結構化解析∕套用：
 *        InstInfo_GS[場景ID] = { 欄位 = "值", ... }   場景（副本）設定
 *        WorldInfo[世界ID]  = { type = "...", scenes = "..." }  世界設定
 *    - 節點層級編輯：修改欄位值、新增∕刪除欄位、新增∕刪除節點；
 *      寫入採「僅改動受影響的節點區塊」，其餘內容與排版維持不變。
 *    - 中文說明 + 英文原文（gameserver_instgs_doc）
 *    - 寫入操作稽核（沿用 admin/remote_lib.php）
 *
 *  目標伺服器沿用 game_server_config.server_id（與「遊戲版本設定」共用）。
 *  資料表：game_server_instgs_config（見 game_server_script_schema.sql）
 *  依賴：game_server_script_lib.php（編碼／目標伺服器）、admin/remote_lib.php
 * =============================================================
 */

if (defined('GAME_SERVER_INSTGS_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_INSTGS_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_script_lib.php';

if (!defined('GAME_SERVER_INSTGS_TABLE')) {
    define('GAME_SERVER_INSTGS_TABLE', 'game_server_instgs_config');
}
if (!defined('GAME_SERVER_INSTGS_DEFAULT_PATH')) {
    define('GAME_SERVER_INSTGS_DEFAULT_PATH', '/root/xa274/gamed/config/script/instance_gs.lua');
}

/* ============================================================
 *  一、資料表與設定存取（與生產合成／獎勵設定同構）
 * ============================================================ */
function gameserver_instgs_ensure($link): void
{
    if (!$link) {
        return;
    }
    $t = GAME_SERVER_INSTGS_TABLE;
    try {
        $link->query("CREATE TABLE IF NOT EXISTS `$t` (
            `id` tinyint(1) NOT NULL DEFAULT 1,
            `path` varchar(255) NOT NULL DEFAULT '" . GAME_SERVER_INSTGS_DEFAULT_PATH . "',
            `raw_cache` mediumtext NULL,
            `initial_raw` mediumtext NULL,
            `updated_by` varchar(50) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $link->query("INSERT IGNORE INTO `$t` (`id`,`path`) VALUES (1,'" . GAME_SERVER_INSTGS_DEFAULT_PATH . "')");
    } catch (Throwable $e) {
        // 權限不足時略過
    }
}

function gameserver_instgs_config_get($link): array
{
    $default = [
        'id'          => 1,
        'path'        => GAME_SERVER_INSTGS_DEFAULT_PATH,
        'raw_cache'   => '',
        'initial_raw' => '',
        'updated_by'  => '',
        'updated_at'  => '',
    ];
    if (!$link) {
        return $default;
    }
    try {
        $res = @$link->query("SELECT * FROM `" . GAME_SERVER_INSTGS_TABLE . "` WHERE `id` = 1 LIMIT 1");
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

function gameserver_instgs_set_path($link, $path, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $path = trim((string)$path);
    if ($path === '') {
        $path = GAME_SERVER_INSTGS_DEFAULT_PATH;
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    try {
        $stmt = $link->prepare("INSERT INTO `" . GAME_SERVER_INSTGS_TABLE . "` (`id`,`path`,`updated_by`)
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

function gameserver_instgs_cache($link, $content, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $cfg = gameserver_instgs_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        $initial = (string)$content;
    }
    $enc  = gameserver_encode_raw($content);
    $ienc = gameserver_encode_raw($initial);
    try {
        $stmt = $link->prepare("UPDATE `" . GAME_SERVER_INSTGS_TABLE . "`
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
 *  二、編碼轉換（委派生產設定工具庫的通用實作）
 * ============================================================ */
function gameserver_instgs_is_utf8($s): bool { return gameserver_produce_is_utf8($s); }
function gameserver_instgs_to_utf8($s): string { return gameserver_produce_to_utf8($s); }
function gameserver_instgs_from_utf8($s): string { return gameserver_produce_from_utf8($s); }

/* ============================================================
 *  三、遠端讀寫（SSH / SFTP）
 * ============================================================ */
function gameserver_instgs_read($link): array
{
    $cfg = gameserver_instgs_config_get($link);
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

function gameserver_instgs_write($link, $content, $by = '', $action = 'save_instgs'): array
{
    $cfg = gameserver_instgs_config_get($link);
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
        $cached = gameserver_instgs_cache($link, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, $action, $path, '');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

/* ============================================================
 *  四、高階操作
 * ============================================================ */
function gameserver_instgs_refresh($link, $by = ''): array
{
    $read = gameserver_instgs_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    if (!gameserver_instgs_cache($link, $read['content'], $by)) {
        return ['ok' => false, 'error' => '讀取成功，但本機快取寫入失敗（請檢查資料庫）。'];
    }
    if (!empty($read['server']) && function_exists('remote_audit')) {
        remote_audit($link, $read['server'], 'refresh_instgs', $read['path'], '');
    }
    return ['ok' => true, 'error' => ''];
}

function gameserver_instgs_restore($link, $by = ''): array
{
    $cfg = gameserver_instgs_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        return ['ok' => false, 'error' => '尚無初始快照，請先執行「讀取最新」。'];
    }
    return gameserver_instgs_write($link, $initial, $by, 'restore_instgs');
}

/* ============================================================
 *  五、行式解析
 * ============================================================ */
/**
 * 解析 instance_gs.lua。
 * @return array {
 *   inst:  [ id => ['id','open','close','fields'=>[['key','value','line'], ...]] ],
 *   world: [ id => ... ],
 *   lines: array, eol: string
 * }
 */
function gameserver_instgs_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);

    $inst = [];
    $world = [];
    $cur = null;

    foreach ($lines as $i => $line) {
        $t = trim($line);

        // 空節點（同一行）：InstInfo_GS[1] = {}
        if (preg_match('/^InstInfo_GS\[(\d+)\]\s*=\s*\{\s*\}\s*$/', $t, $m)) {
            $inst[$m[1]] = ['id' => $m[1], 'open' => $i, 'close' => $i, 'fields' => []];
            unset($cur);
            continue;
        }
        if (preg_match('/^WorldInfo\[(\d+)\]\s*=\s*\{\s*\}\s*$/', $t, $m)) {
            $world[$m[1]] = ['id' => $m[1], 'open' => $i, 'close' => $i, 'fields' => []];
            unset($cur);
            continue;
        }

        // 開啟節點：InstInfo_GS[N] = {
        if (preg_match('/^InstInfo_GS\[(\d+)\]\s*=\s*\{\s*$/', $t, $m)) {
            $inst[$m[1]] = ['id' => $m[1], 'open' => $i, 'close' => null, 'fields' => []];
            $cur = &$inst[$m[1]];
            continue;
        }
        if (preg_match('/^WorldInfo\[(\d+)\]\s*=\s*\{\s*$/', $t, $m)) {
            $world[$m[1]] = ['id' => $m[1], 'open' => $i, 'close' => null, 'fields' => []];
            $cur = &$world[$m[1]];
            continue;
        }

        if (isset($cur) && $t === '}') {
            $cur['close'] = $i;
            unset($cur);
            continue;
        }

        if (isset($cur) && $t !== '' && substr($t, 0, 2) !== '--') {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*,?\s*$/', $t, $m)) {
                $key = $m[1];
                $raw = $m[2];
                if (strlen($raw) >= 2 && $raw[0] === '"' && substr($raw, -1) === '"') {
                    $raw = substr($raw, 1, -1);
                }
                $cur['fields'][] = ['key' => $key, 'value' => $raw, 'line' => $i];
            }
        }
    }

    return ['inst' => $inst, 'world' => $world, 'lines' => $lines, 'eol' => $eol];
}

/**
 * 建立節點區塊文字（回傳行陣列）。
 */
function gameserver_instgs_build_block(string $section, string $id, array $fields, string $indent = ''): array
{
    $var = ($section === 'world') ? 'WorldInfo' : 'InstInfo_GS';
    $out = [$indent . $var . '[' . $id . '] = {'];
    foreach ($fields as $f) {
        $key = (string)($f['key'] ?? '');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }
        $val = str_replace(['\\', '"'], ['\\\\', '\\"'], (string)($f['value'] ?? ''));
        $out[] = $indent . "\t" . $key . ' = "' . $val . '",';
    }
    $out[] = $indent . '}';
    return $out;
}

/**
 * 取某行前導空白（縮排）。
 */
function gameserver_instgs_indent_of(string $line): string
{
    if (preg_match('/^(\s*)/', $line, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * 套用節點操作，回傳新內容。
 * $ops = [
 *   'save'   => [ ['section'=>'inst'|'world','id'=>'N','fields'=>[['key','value'],...]] ],
 *   'delete' => [ ['section'=>..,'id'=>'N'] ],
 *   'add'    => [ ['section'=>..,'id'=>'N','fields'=>[...]] ],
 * ]
 */
function gameserver_instgs_apply_ops($content, array $ops): string
{
    $p = gameserver_instgs_parse($content);
    $lines = $p['lines'];

    $maxClose = ['inst' => -1, 'world' => -1];
    foreach (['inst', 'world'] as $sec) {
        foreach ($p[$sec] as $node) {
            if ($node['close'] !== null) {
                $maxClose[$sec] = max($maxClose[$sec], $node['close']);
            }
        }
    }

    $changes = []; // [start, end, newLines]（start>end 表示插入）

    foreach (($ops['save'] ?? []) as $op) {
        $sec = ($op['section'] ?? '') === 'world' ? 'world' : 'inst';
        $id  = (string)($op['id'] ?? '');
        if ($id === '' || !isset($p[$sec][$id])) { continue; }
        $node = $p[$sec][$id];
        if ($node['close'] === null) { continue; }
        $indent = gameserver_instgs_indent_of($lines[$node['open']]);
        $block = gameserver_instgs_build_block($sec, $id, (array)($op['fields'] ?? []), $indent);
        $changes[] = [$node['open'], $node['close'], $block];
    }

    foreach (($ops['delete'] ?? []) as $op) {
        $sec = ($op['section'] ?? '') === 'world' ? 'world' : 'inst';
        $id  = (string)($op['id'] ?? '');
        if ($id === '' || !isset($p[$sec][$id])) { continue; }
        $node = $p[$sec][$id];
        if ($node['close'] === null) { continue; }
        $changes[] = [$node['open'], $node['close'], []];
    }

    foreach (($ops['add'] ?? []) as $op) {
        $sec = ($op['section'] ?? '') === 'world' ? 'world' : 'inst';
        $id  = (string)($op['id'] ?? '');
        if ($id === '' || !preg_match('/^\d+$/', $id) || isset($p[$sec][$id])) { continue; }
        $block = gameserver_instgs_build_block($sec, $id, (array)($op['fields'] ?? []), '');
        $at = $maxClose[$sec];          // 插在該區段最後一個節點之後
        $changes[] = [$at + 1, $at, $block];
    }

    if (!$changes) {
        return $content;
    }

    usort($changes, function ($a, $b) { return $b[0] <=> $a[0]; });
    foreach ($changes as $c) {
        list($start, $end, $new) = $c;
        $len = $end - $start + 1;
        if ($len < 0) { $len = 0; }
        array_splice($lines, $start, $len, $new);
    }

    return implode($p['eol'], $lines);
}

/**
 * 套用節點操作並寫回遠端。
 */
function gameserver_instgs_apply($link, array $ops, $by = ''): array
{
    $read = gameserver_instgs_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = $read['content'];
    if (!gameserver_instgs_is_utf8($content)) {
        foreach (['save', 'add'] as $k) {
            if (empty($ops[$k]) || !is_array($ops[$k])) {
                continue;
            }
            foreach ($ops[$k] as $oi => $op) {
                if (empty($op['fields']) || !is_array($op['fields'])) {
                    continue;
                }
                foreach ($op['fields'] as $fi => $f) {
                    if (isset($f['value'])) {
                        $ops[$k][$oi]['fields'][$fi]['value'] = gameserver_instgs_from_utf8($f['value']);
                    }
                }
            }
        }
    }
    $newContent = gameserver_instgs_apply_ops($content, $ops);
    return gameserver_instgs_write($link, $newContent, $by, 'save_instgs');
}

/* ============================================================
 *  六、模型（供後台渲染）
 * ============================================================ */
/**
 * @return array {
 *   inst: [ ['id'=>, 'fields'=>[['key','value'],...] ], ... ],
 *   world: [ ... ],
 *   summary: { inst_total, inst_used, world_total, world_used, fields }
 * }
 */
function gameserver_instgs_model($content): array
{
    $p = gameserver_instgs_parse($content);
    $out = ['inst' => [], 'world' => []];
    $sum = ['inst_total' => 0, 'inst_used' => 0, 'world_total' => 0, 'world_used' => 0, 'fields' => 0];

    foreach (['inst', 'world'] as $sec) {
        $nodes = $p[$sec];
        // 依 ID 數值排序（呈現較直觀）
        uksort($nodes, function ($a, $b) { return (int)$a <=> (int)$b; });
        foreach ($nodes as $node) {
            $fields = [];
            foreach ($node['fields'] as $f) {
                $fields[] = ['key' => $f['key'], 'value' => $f['value']];
            }
            $out[$sec][] = ['id' => (string)$node['id'], 'fields' => $fields];
            $sum[$sec . '_total']++;
            if ($fields) { $sum[$sec . '_used']++; }
            $sum['fields'] += count($fields);
        }
    }
    $out['summary'] = $sum;
    return $out;
}

/** 節點欄位摘要（供列表顯示） */
function gameserver_instgs_summary(array $fields): string
{
    if (!$fields) {
        return '';
    }
    $parts = [];
    foreach (array_slice($fields, 0, 4) as $f) {
        $parts[] = $f['key'] . '=' . $f['value'];
    }
    $s = implode('  ·  ', $parts);
    if (count($fields) > 4) { $s .= ' …'; }
    return $s;
}

/* ============================================================
 *  七、參數說明（中文 + 英文原文）
 * ============================================================ */
/** 欄位對照：鍵名 => [中文, 英文, 說明]（含 InstInfo_GS 與 WorldInfo） */
function gameserver_instgs_field_doc(string $key): array
{
    $map = [
        // InstInfo_GS 場景欄位
        'max_player_sight_range' => ['玩家最大視距', 'max_player_sight_range', '場景內玩家可視的最大範圍（單位：格／座標單位）；數值越大越早載入遠方物件。'],
        'max_npc_sight_range'    => ['NPC 最大視距', 'max_npc_sight_range',    '場景內 NPC 可視的最大範圍；影響 AI 索敵與同步距離。'],
        'player_capacity'        => ['玩家容量',     'player_capacity',        '同場景可容納的最大玩家數量，超過時由分線（mirror）分流。'],
        'max_mirror_count'       => ['最大分線數',   'max_mirror_count',       '場景最多可開幾個鏡像／分線（mirror instance）。'],
        'master_mirror_line'     => ['主分線編號',   'master_mirror_line',     '主線（master line）所屬的分線編號，新玩家預設進入此線。'],
        'limit'                  => ['場景限制',     'limit',                  '以分號分隔的限制旗標，詳見「場景限制旗標」對照。'],
        'inner'                  => ['內景標記',     'inner',                  '是否為內景（室內）場景：1＝是。影響天候與部分系統。'],
        'bigmap'                 => ['大世界地圖',   'bigmap',                 '所屬大世界地圖編號（可多值以分號分隔）。'],
        'single_player_mode'     => ['單人模式',     'single_player_mode',     '設為 1 表示該場景為單人模式。'],
        'slice_step'             => ['分片步長',     'slice_step',             '場景分片（slice）步長，影響同步與視野載入粒度。'],
        // WorldInfo 世界欄位
        'type'                   => ['世界類型',     'type',                   '世界（WorldInfo）類型，詳見「世界類型」對照；值以分號結尾。'],
        'scenes'                 => ['場景清單',     'scenes',                 '該世界包含的場景 ID 清單（以分號分隔）。'],
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    return [$key, $key, ''];
}

/** 場景限制旗標（limit 內以分號分隔的值） */
function gameserver_instgs_limit_doc(): array
{
    return [
        'no_transmit_sign' => ['禁止傳送標記', 'no_transmit_sign', '禁止使用傳送標記（記錄點傳送）。'],
        'no_couple_jump'   => ['禁止夫妻傳送', 'no_couple_jump',   '禁止情侶／夫妻之間的傳送。'],
        'pk_no_punish'     => ['PK 不受罰',    'pk_no_punish',     '在此場景 PK 不計算懲罰值。'],
        'no_match'         => ['禁止比武',     'no_match',         '禁止在此場景發起切磋／比武。'],
    ];
}

/** 世界類型對照（依檔案頂部註解） */
function gameserver_instgs_world_type_doc(): array
{
    return [
        '0' => ['普通世界', '普通地圖（一般世界）。'],
        '1' => ['攻城世界', '用於攻城戰（領地戰）的世界。'],
        '2' => ['副本世界', '副本（instance）類世界，對應一批場景。'],
        '3' => ['戰場世界', '戰場／競技類世界。'],
        '4' => ['特殊世界', '特殊或跨服類世界（依引擎定義）。'],
    ];
}

/**
 * 文件說明（供「參數說明」分頁）。
 * @return array 區段 => [title, subtitle, desc, rows:[[中文, 英文, 說明]]]
 */
function gameserver_instgs_doc(): array
{
    $instRows = [];
    foreach (['max_player_sight_range', 'max_npc_sight_range', 'player_capacity', 'max_mirror_count',
              'master_mirror_line', 'limit', 'inner', 'bigmap', 'single_player_mode', 'slice_step'] as $k) {
        list($z, $e, $d) = gameserver_instgs_field_doc($k);
        $instRows[] = [$z, $e, $d];
    }
    $worldRows = [];
    foreach (['type', 'scenes'] as $k) {
        list($z, $e, $d) = gameserver_instgs_field_doc($k);
        $worldRows[] = [$z, $e, $d];
    }
    $limitRows = [];
    foreach (gameserver_instgs_limit_doc() as $k => $info) {
        $limitRows[] = [$info[0], $info[1], $info[2]];
    }
    $typeRows = [];
    foreach (gameserver_instgs_world_type_doc() as $k => $info) {
        $typeRows[] = ['類型 ' . $k, 'type = "' . $k . ';"', $info[0] . '：' . $info[1]];
    }

    return [
        'overview' => [
            'title'    => '檔案結構總覽',
            'subtitle' => 'instance_gs.lua',
            'desc'     => '本檔定義「場景（副本）」與「世界」兩類設定。場景以 InstInfo_GS[場景ID] 為索引，'
                . '描述視距、容量、分線、限制等；世界以 WorldInfo[世界ID] 為索引，綁定類型與所屬場景清單。'
                . '檔案末端另有 find_gs_inst / find_gs_world 等唯讀查詢函式（本頁不修改）。',
            'rows'     => [
                ['場景設定',   'InstInfo_GS[場景ID] = { ... }', '場景（副本）層級的設定表；多數場景為空表（使用引擎預設值）。'],
                ['世界設定',   'WorldInfo[世界ID] = { ... }',    '世界層級的設定表；以 type 與 scenes 描述世界。'],
                ['查詢函式',   'find_gs_inst(inst_id, key)',     '依場景 ID 與欄位名取值，找不到回傳空字串（唯讀，不由本頁修改）。'],
                ['世界查詢',   'find_gs_world(world_id, key)',   '依世界 ID 與欄位名取值（唯讀）。'],
                ['載入指令',   'xdofile("config/script/instance_common.lua")', '檔末載入通用副本設定檔。'],
            ],
        ],
        'inst' => [
            'title'    => '場景欄位（InstInfo_GS）',
            'subtitle' => 'max_player_sight_range / player_capacity / limit …',
            'desc'     => '每個場景節點可包含下列欄位；未列出的欄位代表使用引擎預設值。',
            'rows'     => $instRows,
        ],
        'world' => [
            'title'    => '世界欄位（WorldInfo）',
            'subtitle' => 'type / scenes',
            'desc'     => '世界節點由類型與場景清單組成。',
            'rows'     => $worldRows,
        ],
        'limit' => [
            'title'    => '場景限制旗標（limit）',
            'subtitle' => 'no_transmit_sign ; no_couple_jump ; pk_no_punish ; no_match',
            'desc'     => 'limit 欄位是以分號分隔的字串，可同時包含多個旗標。可設定值如下：',
            'rows'     => $limitRows,
        ],
        'wtype' => [
            'title'    => '世界類型（WorldInfo.type）',
            'subtitle' => 'type = "0;" … "4;"',
            'desc'     => 'type 值以分號結尾。以下為依檔案頂端註解整理之常見對照：',
            'rows'     => $typeRows,
        ],
    ];
}

<?php
/**
 * =============================================================
 *  踏雪笑傲 · 活動排期設定工具庫（game_server_campaignlist_lib.php）
 *  -------------------------------------------------------------
 *  專用於遠端 gamed/config/script/campaignlist.lua：
 *    - 設定遠端檔案路徑（game_server_campaignlist_config）
 *    - 透過 SSH/SFTP 讀取與寫回
 *    - 內容快取與初始快照（供「還原初始」）
 *    - 沿用通用 Lua 解析器（game_server_reward_lib 的詞法／語法），
 *      並預先遮蔽 --[[ ... ]] 區塊註解（同長度置換為空白，位移不變），
 *      以位元組位移就地替換「數值」、「索引鍵」與「識別字參照」
 *    - 結構化模型：CAMPAIGN_LIST[N] 的
 *        tids / speakids / time_type / time_sect /
 *        toplist_conditions / open_server_condition / open_count
 *      另含全域排期參數 CAMPAIGN_PARAM.GEN_DAY_LEN
 *    - 中文說明 + 英文原文（gameserver_campaignlist_*_doc）
 *    - 寫入操作稽核（沿用 admin/remote_lib.php）
 *
 *  目標伺服器沿用 game_server_config.server_id（與「遊戲版本設定」共用）。
 *  資料表：game_server_campaignlist_config（見 game_server_script_schema.sql）
 *  依賴：game_server_reward_lib.php（通用解析器／編碼）、admin/remote_lib.php
 * =============================================================
 */

if (defined('GAME_SERVER_CAMPAIGNLIST_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_CAMPAIGNLIST_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_reward_lib.php';

if (!defined('GAME_SERVER_CAMPAIGNLIST_TABLE')) {
    define('GAME_SERVER_CAMPAIGNLIST_TABLE', 'game_server_campaignlist_config');
}
if (!defined('GAME_SERVER_CAMPAIGNLIST_DEFAULT_PATH')) {
    define('GAME_SERVER_CAMPAIGNLIST_DEFAULT_PATH', '/root/xa274/gamed/config/script/campaignlist.lua');
}

/* ============================================================
 *  一、資料表與設定存取（與其他設定檔同構）
 * ============================================================ */
/** 建立資料表並補上預設列（可重複執行、非破壞性） */
function gameserver_campaignlist_ensure($link): void
{
    if (!$link) {
        return;
    }
    $t = GAME_SERVER_CAMPAIGNLIST_TABLE;
    try {
        $link->query("CREATE TABLE IF NOT EXISTS `$t` (
            `id` tinyint(1) NOT NULL DEFAULT 1,
            `path` varchar(255) NOT NULL DEFAULT '" . GAME_SERVER_CAMPAIGNLIST_DEFAULT_PATH . "',
            `raw_cache` mediumtext NULL,
            `initial_raw` mediumtext NULL,
            `updated_by` varchar(50) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $link->query("INSERT IGNORE INTO `$t` (`id`,`path`) VALUES (1,'" . GAME_SERVER_CAMPAIGNLIST_DEFAULT_PATH . "')");
    } catch (Throwable $e) {
        // 權限不足時略過
    }
}

/** 讀取設定列（資料表不存在時回傳預設值） */
function gameserver_campaignlist_config_get($link): array
{
    $default = [
        'id' => 1, 'path' => GAME_SERVER_CAMPAIGNLIST_DEFAULT_PATH,
        'raw_cache' => '', 'initial_raw' => '', 'updated_by' => '', 'updated_at' => '',
    ];
    if (!$link) {
        return $default;
    }
    try {
        $res = @$link->query("SELECT * FROM `" . GAME_SERVER_CAMPAIGNLIST_TABLE . "` WHERE `id` = 1 LIMIT 1");
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

/** 設定遠端檔案路徑 */
function gameserver_campaignlist_set_path($link, $path, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $path = trim((string)$path);
    if ($path === '') {
        $path = GAME_SERVER_CAMPAIGNLIST_DEFAULT_PATH;
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    try {
        $stmt = $link->prepare("INSERT INTO `" . GAME_SERVER_CAMPAIGNLIST_TABLE . "` (`id`,`path`,`updated_by`)
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

/** 由內容更新快取與初始快照；成功回傳 true */
function gameserver_campaignlist_cache($link, $content, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $cfg = gameserver_campaignlist_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        $initial = (string)$content;
    }
    $enc  = gameserver_encode_raw($content);
    $ienc = gameserver_encode_raw($initial);
    try {
        $stmt = $link->prepare("UPDATE `" . GAME_SERVER_CAMPAIGNLIST_TABLE . "`
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
function gameserver_campaignlist_is_utf8($s): bool { return gameserver_produce_is_utf8($s); }
function gameserver_campaignlist_to_utf8($s): string { return gameserver_produce_to_utf8($s); }
function gameserver_campaignlist_from_utf8($s): string { return gameserver_produce_from_utf8($s); }

/* ============================================================
 *  三、遠端讀寫（SSH / SFTP）
 * ============================================================ */
/**
 * 讀取遠端 campaignlist.lua。
 * @return array { ok, content?, error?, server?, path? }
 */
function gameserver_campaignlist_read($link): array
{
    $cfg = gameserver_campaignlist_config_get($link);
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

/** 將內容寫回遠端並更新快取 */
function gameserver_campaignlist_write($link, $content, $by = '', $action = 'save_campaignlist'): array
{
    $cfg = gameserver_campaignlist_config_get($link);
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
        $cached = gameserver_campaignlist_cache($link, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, $action, $path, '');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

/** 讀取遠端並更新快取 */
function gameserver_campaignlist_refresh($link, $by = ''): array
{
    $read = gameserver_campaignlist_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    if (!gameserver_campaignlist_cache($link, $read['content'], $by)) {
        return ['ok' => false, 'error' => '讀取成功，但本機快取寫入失敗（請檢查資料庫）。'];
    }
    if (!empty($read['server']) && function_exists('remote_audit')) {
        remote_audit($link, $read['server'], 'refresh_campaignlist', $read['path'], '');
    }
    return ['ok' => true, 'error' => ''];
}

/** 還原初始內容快照 */
function gameserver_campaignlist_restore($link, $by = ''): array
{
    $cfg = gameserver_campaignlist_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        return ['ok' => false, 'error' => '尚無初始快照，請先執行「讀取最新」。'];
    }
    return gameserver_campaignlist_write($link, $initial, $by, 'restore_campaignlist');
}

/* ============================================================
 *  四、解析 / 套用（沿用通用 Lua 解析器）
 * ============================================================ */
/** 受管理的頂層設定名稱 */
function gameserver_campaignlist_known_bases(): array
{
    return ['CAMPAIGN_LIST', 'CAMPAIGN_PARAM'];
}

/**
 * 將 --[[ ... ]] 區塊註解遮蔽為空白（保留換行，長度與位移不變），
 * 使通用解析器忽略被註解掉的活動區塊。
 */
function gameserver_campaignlist_mask($content): string
{
    $content = (string)$content;
    $n = strlen($content);
    $out = $content;
    $i = 0;
    while ($i < $n - 3) {
        if ($content[$i] === '-' && $content[$i + 1] === '-' && $content[$i + 2] === '[' && $content[$i + 3] === '[') {
            $j = $i + 4;
            while ($j < $n - 1 && !($content[$j] === ']' && $content[$j + 1] === ']')) {
                $j++;
            }
            $end = ($j < $n - 1) ? $j + 2 : $n;
            for ($k = $i; $k < $end; $k++) {
                if ($content[$k] !== "\n" && $content[$k] !== "\r") {
                    $out[$k] = ' ';
                }
            }
            $i = $end;
            continue;
        }
        $i++;
    }
    return $out;
}

/**
 * 解析 campaignlist.lua。
 * 回傳 entries、nodes（純量／識別字節點，附 id）、keys（索引鍵，附 kid）、
 *      eol、content（去 BOM 原內容，位移對齊）。
 * @return array { entries, nodes, keys, eol, content }
 */
function gameserver_campaignlist_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $masked = gameserver_campaignlist_mask($content);
    $parser = new GameserverLuaParser($masked);
    $entries = $parser->parseAll(gameserver_campaignlist_known_bases());

    $nodes = [];
    $keys = [];
    $counter = 0;
    $kcounter = 0;
    $walk = function ($node) use (&$walk, &$nodes, &$keys, &$counter, &$kcounter) {
        if ($node === null) {
            return;
        }
        if ($node->kind === 'scalar' || $node->kind === 'ref') {
            $node->id = $counter;
            $nodes[$counter] = $node;
            $counter++;
            return;
        }
        if ($node->kind === 'table') {
            foreach ($node->fields as $f) {
                if ($f->kind === 'index') {
                    $f->kid = $kcounter;
                    $keys[$kcounter] = $f;
                    $kcounter++;
                }
                $walk($f->value);
            }
        }
    };
    foreach ($entries as $e) {
        $walk($e->node);
    }
    return ['entries' => $entries, 'nodes' => $nodes, 'keys' => $keys, 'eol' => $eol, 'content' => $content];
}

/** 取出索引鍵欄位「[」與「]」之間的位元組範圍（$keyStart 指向 '['） */
function gameserver_campaignlist_key_span(string $content, int $keyStart): array
{
    $n = strlen($content);
    $depth = 0;
    for ($i = $keyStart; $i < $n; $i++) {
        $c = $content[$i];
        if ($c === '[' || $c === '{') {
            $depth++;
        } elseif ($c === ']' || $c === '}') {
            $depth--;
            if ($depth === 0) {
                return [$keyStart + 1, $i];
            }
        }
    }
    return [0, 0];
}

/**
 * 依節點 id / 鍵 id 就地套用新值，回傳新內容。
 * 識別字參照允許英數、底線與點（如 CTT.CTT_PER_HOUR）。
 * @param array $valueEdits id  => 新值（字串）
 * @param array $keyEdits   kid => 新索引鍵（整數或識別字）
 */
function gameserver_campaignlist_apply_content($content, array $valueEdits, array $keyEdits = []): string
{
    $content = gameserver_strip_bom((string)$content);
    $parsed = gameserver_campaignlist_parse($content);
    $nodes = $parsed['nodes'];
    $keys  = $parsed['keys'];
    $repls = [];

    foreach ($valueEdits as $id => $new) {
        $id = (int)$id;
        if (!isset($nodes[$id])) {
            continue;
        }
        $node = $nodes[$id];
        $new = trim((string)$new);
        if ($node->kind === 'ref') {
            if (isset($node->start, $node->end) && preg_match('/^-?\d+$|^[A-Za-z_][A-Za-z0-9_.]*$/', $new)) {
                $repls[] = [$node->start, $node->end, $new];
            }
            continue;
        }
        if ($node->kind !== 'scalar') {
            continue;
        }
        if ($node->vtype === 'number') {
            if (!preg_match('/^-?(\d+(\.\d+)?|\.\d+)$/', $new)) {
                continue;
            }
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

    foreach ($keyEdits as $kid => $newKey) {
        $kid = (int)$kid;
        if (!isset($keys[$kid])) {
            continue;
        }
        $newKey = trim((string)$newKey);
        if ($newKey === '' || !preg_match('/^-?\d+$|^[A-Za-z_]\w*$/', $newKey)) {
            continue;
        }
        list($s, $e) = gameserver_campaignlist_key_span($content, (int)$keys[$kid]->keyStart);
        if ($s === 0 && $e === 0) {
            continue;
        }
        $repls[] = [$s, $e, $newKey];
    }

    usort($repls, function ($a, $b) { return $b[0] <=> $a[0]; });
    foreach ($repls as $r) {
        $content = substr($content, 0, $r[0]) . $r[2] . substr($content, $r[1]);
    }
    return $content;
}

/** 套用結構化更新並寫回遠端 */
function gameserver_campaignlist_apply($link, array $valueEdits, array $keyEdits, $by = ''): array
{
    $read = gameserver_campaignlist_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = gameserver_campaignlist_apply_content($read['content'], $valueEdits, $keyEdits);
    return gameserver_campaignlist_write($link, $content, $by, 'save_campaignlist');
}

/** 由內容擷取各活動的註解名稱（略過 --[[ ]] 區塊與被註解行） */
function gameserver_campaignlist_names($content): array
{
    $content = gameserver_strip_bom((string)$content);
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $names = [];
    $lastComment = '';
    $inBlock = false;
    foreach ($lines as $line) {
        $t = trim($line);
        if ($inBlock) {
            if (strpos($t, ']]') !== false) {
                $inBlock = false;
            }
            continue;
        }
        if (strpos($t, '--[[') === 0) {
            if (strpos($t, ']]') === false) {
                $inBlock = true;
            }
            continue;
        }
        if (preg_match('/^--(.*)$/', $t, $m)) {
            $lastComment = trim($m[1]);
            continue;
        }
        if (preg_match('/^CAMPAIGN_LIST\[(\d+)\]\s*=\s*\{/', $t, $m)) {
            $names[(int)$m[1]] = ($lastComment !== '') ? gameserver_campaignlist_to_utf8($lastComment) : '';
            $lastComment = '';
            continue;
        }
        if ($t !== '') {
            $lastComment = '';
        }
    }
    return $names;
}

/**
 * 將解析結果轉為「活動（campaign）」模型清單。
 * @return array [ [id, name, node, fields, stats], ... ]
 */
function gameserver_campaignlist_items(array $parsed): array
{
    $names = gameserver_campaignlist_names($parsed['content'] ?? '');
    $items = [];
    foreach ($parsed['entries'] as $e) {
        if (($e->base ?? '') !== 'CAMPAIGN_LIST' || $e->node->kind !== 'table') {
            continue;
        }
        $id = 0;
        if (preg_match('/\[(\d+)\]/', (string)$e->path, $m)) {
            $id = (int)$m[1];
        }
        if ($id <= 0) {
            // 略過 `CAMPAIGN_LIST = {}` 之類的初始化宣告
            continue;
        }
        $fields = [];
        foreach ($e->node->fields as $f) {
            if ($f->kind === 'name') {
                $fields[$f->key] = $f->value;
            }
        }
        $count = function ($node) {
            return ($node && $node->kind === 'table') ? count($node->fields) : 0;
        };
        $stats = [
            'tids'    => $count($fields['tids'] ?? null),
            'speak'   => $count($fields['speakids'] ?? null),
            'sect'    => $count($fields['time_sect'] ?? null),
            'toplist' => $count($fields['toplist_conditions'] ?? null),
        ];
        $items[] = [
            'id'     => $id,
            'name'   => ($names[$id] ?? '') !== '' ? $names[$id] : ('活動 #' . $id),
            'node'   => $e->node,
            'fields' => $fields,
            'stats'  => $stats,
        ];
    }
    usort($items, function ($a, $b) { return $a['id'] <=> $b['id']; });
    return $items;
}

/* ============================================================
 *  五、參數說明（中文 + 英文原文 + 詳細中文註釋）
 * ============================================================ */
/** 時間類型列舉（CTT.*） */
function gameserver_campaignlist_time_types(): array
{
    return [
        'CTT.CTT_PER_HOUR'       => ['每小時',   'CTT_PER_HOUR',       '在每小時中的一個或多個時段開啟；BEGIN_TIME 需填 MIN / SEC。'],
        'CTT.CTT_PER_DAY'        => ['每日',     'CTT_PER_DAY',        '在每天中的一個或多個時段開啟；BEGIN_TIME 需填 HOUR / MIN / SEC。'],
        'CTT.CTT_PER_WEEK'       => ['每週',     'CTT_PER_WEEK',       '在每週中的一個或多個時段開啟；BEGIN_TIME 需填 WEEK / HOUR / MIN / SEC。'],
        'CTT.CTT_PER_YEAR'       => ['每年',     'CTT_PER_YEAR',       '在每年中的一個或多個時段開啟；BEGIN_TIME 需填 MONTH / DAY / HOUR / MIN / SEC。'],
        'CTT.CTT_ALL_TIME_OPEN'  => ['全時開放', 'CTT_ALL_TIME_OPEN',  '一直開啟（可透過 IWEB 平台 Hot_Repair 熱操作）。'],
        'CTT.CTT_ALL_TIME_CLOSE' => ['全時關閉', 'CTT_ALL_TIME_CLOSE', '一直關閉（可透過 IWEB 平台 Hot_Repair 熱操作）。'],
    ];
}

/** 欄位說明（活動欄位 / 清單欄位 / 時段欄位） */
function gameserver_campaignlist_key_doc(): array
{
    return [
        // 活動（campaign）層級
        'tids'                  => ['活動模板清單', 'tids',                  '{TID, RATE} 清單；TID 為活動模板編號，RATE 為該模板的權重機率。'],
        'speakids'              => ['預告廣播清單', 'speakids',              '{PRE_MIN} 清單；於活動開始前 PRE_MIN 分鐘發送預告廣播。'],
        'time_type'             => ['時間類型',     'time_type',             '活動排期類型，對應 CTT.*（每小時 / 每日 / 每週 / 每年 / 全時開關）。'],
        'time_sect'             => ['時段清單',     'time_sect',             '{BEGIN_TIME, LAST_TIME} 清單；定義每個時段的起始時間與持續秒數。'],
        'toplist_conditions'    => ['排行榜條件',   'toplist_conditions',    '{TOPLIST_ID, RANK, VALUE, RETCODE} 清單；排行榜達標條件。'],
        'open_server_condition' => ['開服條件',     'open_server_condition', '{AFTER_HOUR, RETCODE}；開服後達指定小時數才開放。'],
        'open_count'            => ['可開啟次數',   'open_count',            '該活動於一個排期週期內可開啟的次數。'],

        // 清單列欄位
        'TID'          => ['模板 ID',     'TID',          '活動模板編號。'],
        'RATE'         => ['權重機率',    'RATE',         '同一活動多個模板時的相對權重（0~1）。'],
        'PRE_MIN'      => ['提前分鐘',    'PRE_MIN',      '活動開始前幾分鐘發送預告廣播；0 表示活動開始時發送。'],

        // 時段欄位
        'BEGIN_TIME'   => ['開始時間',    'BEGIN_TIME',   '時段起始時間；可填欄位依 time_type 而異（YEAR/MONTH/DAY/WEEK/HOUR/MIN/SEC）。'],
        'LAST_TIME'    => ['持續秒數',    'LAST_TIME',    '該時段持續的秒數（例：3600 = 1 小時）。'],
        'YEAR'         => ['年',          'YEAR',         '起始年份（多數情況由系統帶入，可不填）。'],
        'MONTH'        => ['月',          'MONTH',        '起始月份（1~12），僅每年類型使用。'],
        'DAY'          => ['日',          'DAY',          '起始日期（依月份天數），僅每年類型使用。'],
        'WEEK'         => ['星期',        'WEEK',         '起始星期（1~7），僅每週類型使用。'],
        'HOUR'         => ['時',          'HOUR',         '起始小時（0~23）。'],
        'MIN'          => ['分',          'MIN',          '起始分鐘（0~59）。'],
        'SEC'          => ['秒',          'SEC',          '起始秒數（0~59）。'],

        // 排行榜條件欄位
        'TOPLIST_ID'   => ['排行榜 ID',   'TOPLIST_ID',   '對應排行榜編號。'],
        'RANK'         => ['名次',        'RANK',         '取至第幾名（含）。'],
        'VALUE'        => ['數值',        'VALUE',        '排行榜條件數值（如積分門檻）。'],
        'RETCODE'      => ['返回碼',      'RETCODE',      '達標時回傳的代碼。'],

        // 開服條件欄位
        'AFTER_HOUR'   => ['開服後小時',  'AFTER_HOUR',   '開服後經過幾小時才滿足開放條件。'],

        // 全域參數
        'GEN_DAY_LEN'  => ['排期天數',    'GEN_DAY_LEN',  '時間區間預先產生的天數（影響排期表產生的範圍）。'],
        'CAMPAIGN_PARAM' => ['排期參數',  'CAMPAIGN_PARAM', '活動排期的全域參數表。'],
    ];
}

/** 檔案結構總覽（供「參數說明」頁呈現） */
function gameserver_campaignlist_structure_doc(): array
{
    return [
        [
            'title' => '檔案用途',
            'en'    => 'campaignlist.lua',
            'desc'  => '活動排期設定檔。定義每個活動的模板、預告廣播、排期時間類型、時段、排行榜條件與開服條件，並由檔尾的 Generate* 函式產生排期字串。',
        ],
        [
            'title' => '時間類型常數',
            'en'    => 'CAMPAIGN_TIME_TYPE / CTT',
            'desc'  => 'CTT 為 CAMPAIGN_TIME_TYPE 的別名；time_type 以 CTT.CTT_PER_HOUR / CTT_PER_DAY / CTT_PER_WEEK / CTT_PER_YEAR / CTT_ALL_TIME_OPEN / CTT_ALL_TIME_CLOSE 指定排期方式。',
        ],
        [
            'title' => '活動註冊結構',
            'en'    => 'CAMPAIGN_LIST[N] = { ... }',
            'desc'  => '每個活動以數字索引註冊，包含 tids、speakids、time_type、time_sect、toplist_conditions、open_server_condition、open_count。',
        ],
        [
            'title' => '時段結構',
            'en'    => 'time_sect = { { BEGIN_TIME = {...}, LAST_TIME = N }, ... }',
            'desc'  => 'BEGIN_TIME 可填欄位依 time_type 而異：每小時填 MIN/SEC；每日填 HOUR/MIN/SEC；每週填 WEEK/HOUR/MIN/SEC；每年填 MONTH/DAY/HOUR/MIN/SEC。',
        ],
        [
            'title' => '註解與註解區塊',
            'en'    => '-- 與 --[[ ... ]]',
            'desc'  => '單行 -- 註解與 --[[ ... ]] 區塊註解皆會被保留；被區塊註解掉的活動（如範例 CAMPAIGN_LIST[2]）不會被本工具視為有效活動。',
        ],
        [
            'title' => '就地編輯原則',
            'en'    => 'in-place byte replacement',
            'desc'  => '本工具以位元組位移就地替換數值、索引鍵與識別字參照，其餘內容、中文註解、函式與縮排皆維持原樣。',
        ],
    ];
}

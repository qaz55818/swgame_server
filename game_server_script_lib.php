<?php
/**
 * =============================================================
 *  踏雪笑傲 · 生產合成設定工具庫（game_server_script_lib.php）
 *  -------------------------------------------------------------
 *  專用於遠端 produce_svr.lua（gamed/config/script/）：
 *    - 設定遠端檔案路徑（game_server_produce_config）
 *    - 透過 SSH/SFTP 讀取與寫回
 *    - 內容快取與初始快照（供「還原初始」）
 *    - 結構化解析／套用：
 *        ProduceSvr.ItemList          普通物品合成
 *        ProduceSvr.StoneList         寶石合成配方
 *        ProduceSvr.ExchangeEquipList 裝備兌換
 *        ProduceSvr.RefreshEquipList  裝備刷新
 *        StoneCleanConfig             寶石淨化設定
 *    - 中文說明 + 英文原文（gameserver_produce_doc）
 *    - 寫入操作稽核（沿用 admin/remote_lib.php）
 *
 *  目標伺服器沿用 game_server_config.server_id（與「遊戲版本設定」共用）。
 *  資料表：game_server_produce_config（見 game_server_script_schema.sql）
 *  依賴：game_server_files_lib.php（目標伺服器）、admin/remote_lib.php（連線）
 * =============================================================
 */

if (defined('GAME_SERVER_PRODUCE_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_PRODUCE_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_files_lib.php';

if (!defined('GAME_SERVER_PRODUCE_TABLE')) {
    define('GAME_SERVER_PRODUCE_TABLE', 'game_server_produce_config');
}
if (!defined('GAME_SERVER_PRODUCE_DEFAULT_PATH')) {
    define('GAME_SERVER_PRODUCE_DEFAULT_PATH', '/root/xa274/gamed/config/script/produce_svr.lua');
}

/* ============================================================
 *  一、資料表與設定存取
 * ============================================================ */
/** 建立資料表並補上預設列（可重複執行、非破壞性） */
function gameserver_produce_ensure($link): void
{
    if (!$link) {
        return;
    }
    $t = GAME_SERVER_PRODUCE_TABLE;
    try {
        $link->query("CREATE TABLE IF NOT EXISTS `$t` (
            `id` tinyint(1) NOT NULL DEFAULT 1,
            `path` varchar(255) NOT NULL DEFAULT '" . GAME_SERVER_PRODUCE_DEFAULT_PATH . "',
            `raw_cache` mediumtext NULL,
            `initial_raw` mediumtext NULL,
            `updated_by` varchar(50) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $link->query("INSERT IGNORE INTO `$t` (`id`,`path`) VALUES (1,'" . GAME_SERVER_PRODUCE_DEFAULT_PATH . "')");
    } catch (Throwable $e) {
        // 權限不足時略過
    }
}

/** 讀取設定列（資料表不存在時回傳預設值） */
function gameserver_produce_config_get($link): array
{
    $default = [
        'id'          => 1,
        'path'        => GAME_SERVER_PRODUCE_DEFAULT_PATH,
        'raw_cache'   => '',
        'initial_raw' => '',
        'updated_by'  => '',
        'updated_at'  => '',
    ];
    if (!$link) {
        return $default;
    }
    try {
        $res = @$link->query("SELECT * FROM `" . GAME_SERVER_PRODUCE_TABLE . "` WHERE `id` = 1 LIMIT 1");
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
function gameserver_produce_set_path($link, $path, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $path = trim((string)$path);
    if ($path === '') {
        $path = GAME_SERVER_PRODUCE_DEFAULT_PATH;
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    try {
        $stmt = $link->prepare("INSERT INTO `" . GAME_SERVER_PRODUCE_TABLE . "` (`id`,`path`,`updated_by`)
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
function gameserver_produce_cache($link, $content, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $cfg = gameserver_produce_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        $initial = (string)$content;
    }
    $enc  = gameserver_encode_raw($content);
    $ienc = gameserver_encode_raw($initial);
    try {
        $stmt = $link->prepare("UPDATE `" . GAME_SERVER_PRODUCE_TABLE . "`
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
 *  二、編碼轉換（腳本檔多為 ANSI/GBK）
 * ============================================================ */
/** 判斷字串是否為合法 UTF-8 */
function gameserver_produce_is_utf8($s): bool
{
    $s = (string)$s;
    if ($s === '') {
        return true;
    }
    if (function_exists('mb_check_encoding')) {
        return mb_check_encoding($s, 'UTF-8');
    }
    return preg_match('//u', $s) === 1;
}

/** GBK/ANSI 位元組 → UTF-8（供顯示） */
function gameserver_produce_to_utf8($s): string
{
    $s = (string)$s;
    if ($s === '' || !preg_match('/[\x80-\xFF]/', $s)) {
        return $s;
    }
    if (gameserver_produce_is_utf8($s)) {
        return $s;
    }
    if (function_exists('mb_convert_encoding')) {
        $u = @mb_convert_encoding($s, 'UTF-8', 'GBK');
        if (is_string($u) && $u !== '') {
            return $u;
        }
    }
    if (function_exists('iconv')) {
        $u = @iconv('GBK', 'UTF-8//IGNORE', $s);
        if ($u !== false) {
            return $u;
        }
    }
    return $s;
}

/** UTF-8 → GBK（供寫回 ANSI/GBK 檔案） */
function gameserver_produce_from_utf8($s): string
{
    $s = (string)$s;
    if ($s === '' || !preg_match('/[\x{0080}-\x{FFFF}]/u', $s)) {
        return $s;
    }
    if (function_exists('mb_convert_encoding')) {
        $g = @mb_convert_encoding($s, 'GBK', 'UTF-8');
        if (is_string($g) && $g !== '') {
            return $g;
        }
    }
    if (function_exists('iconv')) {
        $g = @iconv('UTF-8', 'GBK//IGNORE', $s);
        if ($g !== false) {
            return $g;
        }
    }
    return $s;
}

/* ============================================================
 *  三、遠端讀寫（SSH / SFTP）
 * ============================================================ */
/**
 * 讀取遠端 produce_svr.lua。
 * @return array { ok, content?, error?, server?, path? }
 */
function gameserver_produce_read($link): array
{
    $cfg = gameserver_produce_config_get($link);
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
function gameserver_produce_write($link, $content, $by = '', $action = 'save_produce'): array
{
    $cfg = gameserver_produce_config_get($link);
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
        $cached = gameserver_produce_cache($link, $content, $by);
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
/** 讀取遠端並更新快取 */
function gameserver_produce_refresh($link, $by = ''): array
{
    $read = gameserver_produce_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    if (!gameserver_produce_cache($link, $read['content'], $by)) {
        return ['ok' => false, 'error' => '讀取成功，但本機快取寫入失敗（請檢查資料庫）。'];
    }
    if (!empty($read['server']) && function_exists('remote_audit')) {
        remote_audit($link, $read['server'], 'refresh_produce', $read['path'], '');
    }
    return ['ok' => true, 'error' => ''];
}

/** 還原初始內容快照 */
function gameserver_produce_restore($link, $by = ''): array
{
    $cfg = gameserver_produce_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        return ['ok' => false, 'error' => '尚無初始快照，請先執行「讀取最新」。'];
    }
    return gameserver_produce_write($link, $initial, $by, 'restore_produce');
}

/* ============================================================
 *  五、produce_svr.lua 解析 / 套用
 * ============================================================ */
/** 取出單行中第一組大括號內的數值（依原始順序） */
function gameserver_produce_row_values($line): array
{
    if (!preg_match('/\{(.*)\}/s', (string)$line, $m)) {
        return [];
    }
    preg_match_all('/-?\d+/', $m[1], $mm);
    return $mm[0];
}

/** 取出單行中第一個字串常值的內容（原始位元組，不含引號） */
function gameserver_produce_extract_desc($line): string
{
    if (preg_match('/"((?:[^"\\\\]|\\\\.)*)"/', (string)$line, $m)) {
        return $m[1];
    }
    return '';
}

/** 以索引替換單行第一組大括號內的數值，保留縮排、逗號與其他內容 */
function gameserver_produce_apply_row($line, array $newByIndex): string
{
    return preg_replace_callback('/\{(.*)\}/s', function ($m) use ($newByIndex) {
        $i = -1;
        $inner = preg_replace_callback('/-?\d+/', function ($n) use (&$i, $newByIndex) {
            $i++;
            return array_key_exists($i, $newByIndex) ? (string)(int)$newByIndex[$i] : $n[0];
        }, $m[1]);
        return '{' . $inner . '}';
    }, (string)$line, 1);
}

/** 取代單行中的說明字串（跳脫引號） */
function gameserver_produce_set_desc($line, $desc): string
{
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$desc);
    return preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '"' . $escaped . '"', (string)$line, 1);
}

/**
 * 解析 produce_svr.lua。
 * @return array {
 *   items: [ {line, values, desc} ],
 *   stones: [ {line, values} ],
 *   exchange: [ {line, values} ],
 *   refresh: [ {line, values} ],
 *   clean: [ outer => [ inner => {line, values} ] ],
 *   lines: array, eol: string
 * }
 */
function gameserver_produce_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);

    $out = [
        'items' => [], 'stones' => [], 'exchange' => [], 'refresh' => [],
        'clean' => [], 'lines' => $lines, 'eol' => $eol,
    ];

    $map = [
        'ItemList'          => 'items',
        'StoneList'         => 'stones',
        'ExchangeEquipList' => 'exchange',
        'RefreshEquipList'  => 'refresh',
    ];

    $block = '';
    $cleanOuter = 0;

    foreach ($lines as $i => $line) {
        $t = trim($line);
        if ($t === '') {
            continue;
        }

        if (preg_match('/^ProduceSvr\.(ItemList|StoneList|ExchangeEquipList|RefreshEquipList)\s*=\s*\{\s*$/', $t, $m)) {
            $block = $map[$m[1]];
            continue;
        }
        if (preg_match('/^StoneCleanConfig\s*=\s*$/', $t)) {
            $block = 'clean';
            $cleanOuter = 0;
            continue;
        }

        if ($block === 'clean') {
            if (preg_match('/^\[(\d+)\]\s*=\s*$/', $t, $m)) {
                $cleanOuter = (int)$m[1];
                continue;
            }
            if (preg_match('/^\[(\d+)\]\s*=\s*\{.*\}\s*,?\s*$/', $t, $m)) {
                $out['clean'][$cleanOuter][(int)$m[1]] = ['line' => $i, 'values' => gameserver_produce_row_values($line)];
            }
            continue;
        }

        if ($block !== '') {
            if ($t === '}') {
                $block = '';
                continue;
            }
            if (substr($t, 0, 2) === '--') {
                continue;
            }
            if ($t[0] === '{') {
                $row = ['line' => $i, 'values' => gameserver_produce_row_values($line)];
                if ($block === 'items') {
                    $row['desc'] = gameserver_produce_extract_desc($line);
                }
                $out[$block][] = $row;
            }
            continue;
        }
    }

    return $out;
}

/**
 * 套用結構化更新並回傳新內容。
 * $updates = [
 *   'items'    => [ idx => ['v' => [0..4 => value], 'desc' => string|null] ],
 *   'stones'   => [ idx => ['v' => [index => value]] ],
 *   'exchange' => [ idx => ['v' => [...]] ],
 *   'refresh'  => [ idx => ['v' => [...]] ],
 *   'clean'    => [ outer => [ inner => ['v' => [...]] ] ],
 * ]
 */
function gameserver_produce_apply_updates($content, array $updates): string
{
    $parsed = gameserver_produce_parse($content);
    $lines  = $parsed['lines'];

    foreach (['items', 'stones', 'exchange', 'refresh'] as $key) {
        if (empty($updates[$key]) || !is_array($updates[$key])) {
            continue;
        }
        foreach ($updates[$key] as $idx => $u) {
            $idx = (int)$idx;
            if (!isset($parsed[$key][$idx]) || !is_array($u)) {
                continue;
            }
            $lineIdx = $parsed[$key][$idx]['line'];
            if (!empty($u['v']) && is_array($u['v'])) {
                $lines[$lineIdx] = gameserver_produce_apply_row($lines[$lineIdx], $u['v']);
            }
            if ($key === 'items' && array_key_exists('desc', $u) && $u['desc'] !== null) {
                $lines[$lineIdx] = gameserver_produce_set_desc($lines[$lineIdx], $u['desc']);
            }
        }
    }

    if (!empty($updates['clean']) && is_array($updates['clean'])) {
        foreach ($updates['clean'] as $outer => $inners) {
            $outer = (int)$outer;
            if (!isset($parsed['clean'][$outer]) || !is_array($inners)) {
                continue;
            }
            foreach ($inners as $inner => $u) {
                $inner = (int)$inner;
                if (!isset($parsed['clean'][$outer][$inner]) || !is_array($u)) {
                    continue;
                }
                $lineIdx = $parsed['clean'][$outer][$inner]['line'];
                if (!empty($u['v']) && is_array($u['v'])) {
                    $lines[$lineIdx] = gameserver_produce_apply_row($lines[$lineIdx], $u['v']);
                }
            }
        }
    }

    return implode($parsed['eol'], $lines);
}

/** 套用結構化更新並寫回遠端 */
function gameserver_produce_apply($link, array $updates, $by = ''): array
{
    $read = gameserver_produce_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = gameserver_produce_apply_updates($read['content'], $updates);
    return gameserver_produce_write($link, $content, $by, 'save_produce');
}

/* ============================================================
 *  六、參數說明（中文 + 英文原文）
 * ============================================================ */
/**
 * produce_svr.lua 各表與欄位說明。
 * @return array 表名 => [ label, en, desc, fields: [ [中文, 英文, 型別, 說明] ] ]
 */
function gameserver_produce_doc(): array
{
    return [
        'ItemList' => [
            'label' => '普通物品合成',
            'en'    => 'ProduceSvr.ItemList',
            'desc'  => '定義「以材料合成成品」的配方。每列為一個配方，依原始檔順序排列。',
            'fields' => [
                ['材料物品 ID', 'material_id', 'int',  '作為材料投入的物品編號（第 1 個數值）。'],
                ['產出物品 ID', 'product_id',  'int',  '合成後產出的物品編號（第 2 個數值）。'],
                ['需求數量',    'need_count',  'int',  '每合成一次所需投入的材料數量（第 3 個數值）。'],
                ['產出數量',    'out_count',   'int',  '每合成一次實際產出的成品數量（第 4 個數值）。'],
                ['綁定狀態',    'bind_flag',   'int',  '產出物的綁定狀態（第 5 個數值；依遊戲定義 0/1/2）。'],
                ['說明文字',    'comment',     'text', '配方的人類可讀說明（第 6 個字串）。'],
            ],
        ],
        'StoneList' => [
            'label' => '寶石合成配方',
            'en'    => 'ProduceSvr.StoneList',
            'desc'  => '寶石／道具合成公式：{配方 ID, 材料 1 ID, 材料 1 數量, 材料 2 ID, 材料 2 數量, …, 產出 ID}。',
            'fields' => [
                ['配方物品 ID', 'recipe_id',   'int', '合成所需的配方／圖紙物品編號（第 1 個數值，如 56490／56491）。'],
                ['材料 ID',     'material_id', 'int', '中間成對出現：奇數位置為材料物品編號。'],
                ['材料數量',    'material_num','int', '中間成對出現：偶數位置為對應材料的數量。'],
                ['產出物品 ID', 'product_id',  'int', '該列最後一個數值，合成產出的物品編號。'],
            ],
        ],
        'ExchangeEquipList' => [
            'label' => '裝備兌換',
            'en'    => 'ProduceSvr.ExchangeEquipList',
            'desc'  => '以材料兌換裝備：{來源裝備 ID, 材料 ID, 材料數量, 產出裝備 ID}。',
            'fields' => [
                ['來源裝備 ID', 'from_id',      'int', '要兌換的來源裝備編號（第 1 個數值）。'],
                ['材料 ID',     'material_id',  'int', '兌換所需材料物品編號（第 2 個數值）。'],
                ['材料數量',    'material_num', 'int', '兌換所需材料數量（第 3 個數值）。'],
                ['產出裝備 ID', 'product_id',   'int', '兌換後取得的裝備編號（第 4 個數值）。'],
            ],
        ],
        'RefreshEquipList' => [
            'label' => '裝備刷新',
            'en'    => 'ProduceSvr.RefreshEquipList',
            'desc'  => '以材料刷新裝備屬性：{裝備 ID, 材料 ID, 消耗數量, 產出裝備 ID}。',
            'fields' => [
                ['裝備 ID',     'equip_id',     'int', '要刷新的裝備編號（第 1 個數值）。'],
                ['材料 ID',     'material_id',  'int', '刷新所需材料物品編號（第 2 個數值）。'],
                ['消耗數量',    'material_num', 'int', '每次刷新消耗的材料數量（第 3 個數值）。'],
                ['產出裝備 ID', 'product_id',   'int', '刷新後取得的裝備編號（第 4 個數值）。'],
            ],
        ],
        'StoneCleanConfig' => [
            'label' => '寶石淨化設定',
            'en'    => 'StoneCleanConfig[寶石等級][淨化等級]',
            'desc'  => '依「寶石等級」與「淨化等級」定義淨化所需的結晶、金錢、產出與冷卻時間。',
            'fields' => [
                ['寶石等級',     'stone_level', 'int', '外層索引 [N]，代表寶石等級。'],
                ['淨化等級',     'clean_level', 'int', '內層索引 [M]，代表淨化等級。'],
                ['消耗結晶',     'cost_crystal','int', '淨化時消耗的結晶數量（第 1 個數值）。'],
                ['花費金錢',     'cost_money',  'int', '淨化時花費的金錢（第 2 個數值）。'],
                ['產出結晶',     'out_crystal', 'int', '淨化後產出的結晶數量（第 3 個數值）。'],
                ['冷卻時間(秒)', 'cooldown',    'int', '淨化後冷卻時間，單位秒（第 4 個數值）。'],
            ],
        ],
    ];
}

<?php
/**
 * =============================================================
 *  踏雪笑傲 · 遊戲伺服器版本設定工具庫（game_server_lib.php）
 *  -------------------------------------------------------------
 *  功能：
 *    - 目標遠端伺服器與 gamesys.conf 路徑設定
 *    - 解析 gamesys.conf（[Global] 版本 / max_users / halflogin_users）
 *    - 透過 SSH/SFTP 讀取與寫回遠端設定檔
 *    - 版本快取（供前台即時顯示）與初始版本快照（供還原）
 *    - 同步版本號至公告系統（news_settings.site_version）
 *
 *  資料表：game_server_config（見 game_server_schema.sql）
 *  依賴：admin/remote_lib.php（僅於實際連線時載入）
 * =============================================================
 */

if (defined('GAME_SERVER_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_LIB_LOADED', 1);

if (!defined('GAME_SERVER_TABLE')) {
    define('GAME_SERVER_TABLE', 'game_server_config');
}
if (!defined('GAME_SERVER_DEFAULT_PATH')) {
    define('GAME_SERVER_DEFAULT_PATH', '/root/xa274/glinkd/gamesys.conf');
}

/** HTML 轉義 */
function game_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * gamesys.conf 可能含非 UTF-8（如 GBK）註解，直接存入 utf8mb4 欄位會失敗，
 * 因此原始內容一律以 base64 編碼儲存，確保位元組原樣保存。
 */
function gameserver_encode_raw($content): string
{
    $content = (string)$content;
    return $content === '' ? '' : base64_encode($content);
}

/** 還原 base64 儲存的原始內容（非 base64 時原樣回傳，相容舊資料） */
function gameserver_decode_raw($stored): string
{
    $stored = (string)$stored;
    if ($stored === '') {
        return '';
    }
    $decoded = base64_decode($stored, true);
    return ($decoded === false) ? $stored : $decoded;
}

/* ============================================================
 *  一、資料表與設定存取
 * ============================================================ */
/** 建立資料表（可重複執行、非破壞性） */
function gameserver_ensure($link): void
{
    if (!$link) {
        return;
    }
    $t = GAME_SERVER_TABLE;
    try {
        $link->query("CREATE TABLE IF NOT EXISTS `$t` (
            `id` tinyint(1) NOT NULL DEFAULT 1,
            `server_id` int(11) NOT NULL DEFAULT 0,
            `conf_path` varchar(255) NOT NULL DEFAULT '" . GAME_SERVER_DEFAULT_PATH . "',
            `version` varchar(64) NOT NULL DEFAULT '',
            `initial_version` varchar(64) NOT NULL DEFAULT '',
            `initial_raw` mediumtext NULL,
            `raw_cache` mediumtext NULL,
            `updated_by` varchar(50) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $link->query("INSERT IGNORE INTO `$t` (`id`) VALUES (1)");
    } catch (Throwable $e) {
        // 權限不足時略過
    }
}

/** 讀取設定列（資料表不存在時回傳預設值，不拋錯） */
function gameserver_config_get($link): array
{
    $default = [
        'id'              => 1,
        'server_id'       => 0,
        'conf_path'       => GAME_SERVER_DEFAULT_PATH,
        'version'         => '',
        'initial_version' => '',
        'initial_raw'     => '',
        'raw_cache'       => '',
        'updated_by'      => '',
        'updated_at'      => '',
    ];
    if (!$link) {
        return $default;
    }
    try {
        $res = @$link->query("SELECT * FROM `" . GAME_SERVER_TABLE . "` WHERE `id` = 1 LIMIT 1");
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

/** 設定目標伺服器與設定檔路徑 */
function gameserver_set_target($link, $serverId, $confPath, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $serverId = (int)$serverId;
    $confPath = trim((string)$confPath);
    if ($confPath === '') {
        $confPath = GAME_SERVER_DEFAULT_PATH;
    }
    if ($confPath[0] !== '/') {
        $confPath = '/' . $confPath;
    }
    try {
        $stmt = $link->prepare("UPDATE `" . GAME_SERVER_TABLE . "` SET `server_id`=?, `conf_path`=?, `updated_by`=? WHERE `id`=1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iss', $serverId, $confPath, $by);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

/** 由設定檔內容更新版本快取與初始快照；成功回傳 true */
function gameserver_cache_content($link, $content, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $global  = gameserver_parse_global($content);
    $version = (string)$global['version'];
    $cfg = gameserver_config_get($link);
    $initialRaw = (string)($cfg['initial_raw'] ?? '');
    $initialVer = (string)($cfg['initial_version'] ?? '');
    if ($initialRaw === '') {
        $initialRaw = (string)$content;
        $initialVer = $version;
    }
    $rawCacheEnc = gameserver_encode_raw($content);
    $initialEnc  = gameserver_encode_raw($initialRaw);
    try {
        $stmt = $link->prepare("UPDATE `" . GAME_SERVER_TABLE . "`
            SET `version`=?, `raw_cache`=?, `initial_raw`=?, `initial_version`=?, `updated_by`=? WHERE `id`=1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sssss', $version, $rawCacheEnc, $initialEnc, $initialVer, $by);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

/* ============================================================
 *  二、gamesys.conf 解析 / 修改
 * ============================================================ */
/** 移除 UTF-8 BOM（避免首行 [Global] 無法辨識） */
function gameserver_strip_bom($content): string
{
    $content = (string)$content;
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
    }
    return $content;
}

/** 解析為區段結構：[{name, keys:[{key,value}]}] */
function gameserver_sections($content): array
{
    $out = [];
    $idx = -1;
    $content = gameserver_strip_bom($content);
    foreach (preg_split('/\r\n|\r|\n/', (string)$content) as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === ';' || $t[0] === '#') {
            continue;
        }
        if (preg_match('/^\[(.+)\]\s*$/', $t, $m)) {
            $out[] = ['name' => trim($m[1]), 'keys' => []];
            $idx = count($out) - 1;
            continue;
        }
        if ($idx >= 0 && preg_match('/^([A-Za-z0-9_.-]+)\s*=\s*(.*)$/', $t, $m)) {
            $out[$idx]['keys'][] = ['key' => $m[1], 'value' => trim($m[2])];
        }
    }
    return $out;
}

/** 取得指定區段的所有鍵值（key => value） */
function gameserver_parse_section($content, $sectionName): array
{
    $out = [];
    $in = false;
    $content = gameserver_strip_bom($content);
    foreach (preg_split('/\r\n|\r|\n/', (string)$content) as $line) {
        $t = trim($line);
        if (preg_match('/^\[(.+)\]\s*$/', $t, $m)) {
            $in = (strcasecmp(trim($m[1]), (string)$sectionName) === 0);
            continue;
        }
        if (!$in || $t === '' || $t[0] === ';' || $t[0] === '#') {
            continue;
        }
        if (preg_match('/^([A-Za-z0-9_.-]+)\s*=\s*(.*)$/', $t, $m)) {
            $out[$m[1]] = trim($m[2]);
        }
    }
    return $out;
}

/** 取得 [Global] 區段的關鍵參數 */
function gameserver_parse_global($content): array
{
    $s = gameserver_parse_section($content, 'Global');
    return [
        'version'        => $s['version'] ?? '',
        'max_users'      => $s['max_users'] ?? '',
        'halflogin_users' => $s['halflogin_users'] ?? '',
    ];
}

/**
 * 修改指定區段中指定鍵的值，保留其餘內容、註解與縮排。
 * 缺少的鍵會補在該區段結尾。
 */
function gameserver_update_section($content, $sectionName, array $updates): string
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $in = false;
    $start = -1;
    $end = -1;
    $applied = [];

    foreach ($lines as $i => $line) {
        $t = trim($line);
        if (preg_match('/^\[(.+)\]\s*$/', $t, $m)) {
            if ($in && $end === -1) {
                $end = $i;
            }
            $in = (strcasecmp(trim($m[1]), (string)$sectionName) === 0);
            if ($in) {
                $start = $i;
            }
            continue;
        }
        if (!$in || $t === '' || $t[0] === ';' || $t[0] === '#') {
            continue;
        }
        if (preg_match('/^(\s*)([A-Za-z0-9_.-]+)(\s*=\s*)(.*)$/', $line, $m)) {
            $k = $m[2];
            if (array_key_exists($k, $updates)) {
                $tail = $m[4];
                $ws = '';
                if (preg_match('/^(.*?)(\s*)$/s', $tail, $tm)) {
                    $ws = $tm[2];
                }
                $lines[$i] = $m[1] . $m[2] . $m[3] . (string)$updates[$k] . $ws;
                $applied[$k] = true;
            }
        }
    }
    if ($in && $end === -1) {
        $end = count($lines);
    }
    $missing = array_diff_key($updates, $applied);
    if (!empty($missing) && $start >= 0) {
        $insertAt = ($end > 0) ? $end : count($lines);
        $newLines = [];
        foreach ($missing as $k => $v) {
            $newLines[] = $k . '=' . $v;
        }
        array_splice($lines, $insertAt, 0, $newLines);
    }
    return implode($eol, $lines);
}

/** 修改 [Global] 區段（gameserver_update_section 的便利包裝） */
function gameserver_update_global($content, array $updates): string
{
    return gameserver_update_section($content, 'Global', $updates);
}

/** 版本號顯示格式（自動補 v 前綴） */
function gameserver_display_version($version): string
{
    $version = trim((string)$version);
    if ($version === '') {
        return '';
    }
    return (preg_match('/^v/i', $version) ? '' : 'v') . $version;
}

/* ============================================================
 *  三、遠端讀寫（SSH / SFTP）
 * ============================================================ */
/** 取得目標遠端伺服器資料列（找不到時回傳 null） */
function gameserver_target_server($link, array $cfg)
{
    if (empty($cfg['server_id'])) {
        return null;
    }
    require_once __DIR__ . '/admin/remote_lib.php';
    return remote_server_get($link, (int)$cfg['server_id']);
}

/**
 * 讀取遠端 gamesys.conf。
 * @return array { ok, content?, error?, server?, path? }
 */
function gameserver_read_remote($link): array
{
    $cfg = gameserver_config_get($link);
    $server = gameserver_target_server($link, $cfg);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器（請先於下方選擇）。'];
    }
    $path = (string)$cfg['conf_path'];
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

/**
 * 寫回遠端 gamesys.conf 並更新快取。
 * @return array { ok, error? }
 */
function gameserver_write_remote($link, $content, $by = '', $action = 'save_version'): array
{
    $cfg = gameserver_config_get($link);
    $server = gameserver_target_server($link, $cfg);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器。'];
    }
    $path = (string)$cfg['conf_path'];
    try {
        $sftp = remote_sftp($server, 30);
        if (!$sftp->put($path, (string)$content)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $path];
        }
        $cached = gameserver_cache_content($link, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, $action, $path, 'version=' . gameserver_parse_global($content)['version']);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'cached' => $cached];
}

/* ============================================================
 *  四、高階操作（供後台呼叫）
 * ============================================================ */
/**
 * 套用 [Global] 參數更新：讀取遠端 → 修改 → 寫回 → 更新快取。
 * @param array $updates key=>value（version / max_users / halflogin_users）
 * @return array { ok, error?, version? }
 */
function gameserver_apply_save($link, array $updates, $by = ''): array
{
    return gameserver_apply_save_sections($link, ['Global' => $updates], $by);
}

/**
 * 套用多區段參數更新：讀取遠端 → 逐區段修改 → 寫回 → 更新快取。
 * @param array $updatesBySection 例如 ['Global'=>['version'=>'275'], 'GLinkServer'=>['port'=>'9224']]
 * @return array { ok, error?, version?, warning? }
 */
function gameserver_apply_save_sections($link, array $updatesBySection, $by = ''): array
{
    $read = gameserver_read_remote($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = $read['content'];
    foreach ($updatesBySection as $section => $updates) {
        if (!is_array($updates) || empty($updates)) {
            continue;
        }
        $content = gameserver_update_section($content, (string)$section, $updates);
    }
    $write = gameserver_write_remote($link, $content, $by, 'save_version');
    if (!$write['ok']) {
        return ['ok' => false, 'error' => $write['error']];
    }
    $version = gameserver_parse_global($content)['version'];
    gameserver_sync_news_version($version);
    $warn = empty($write['cached']) ? '（注意：本機版本快取更新失敗）' : '';
    return ['ok' => true, 'error' => '', 'version' => $version, 'warning' => $warn];
}

/** 還原初始版本快照 */
function gameserver_restore_initial($link, $by = ''): array
{
    $cfg = gameserver_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        return ['ok' => false, 'error' => '尚無初始版本快照，請先執行「讀取最新」。'];
    }
    $write = gameserver_write_remote($link, $initial, $by, 'restore_initial');
    if (!$write['ok']) {
        return ['ok' => false, 'error' => $write['error']];
    }
    gameserver_sync_news_version(gameserver_parse_global($initial)['version']);
    return ['ok' => true, 'error' => ''];
}

/** 重新由遠端讀取並更新快取 */
function gameserver_refresh($link, $by = ''): array
{
    $read = gameserver_read_remote($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    if (!gameserver_cache_content($link, $read['content'], $by)) {
        return ['ok' => false, 'error' => '讀取成功，但本機版本快取寫入失敗（請檢查資料庫）。'];
    }
    if (!empty($read['server']) && function_exists('remote_audit')) {
        remote_audit($link, $read['server'], 'refresh_version', $read['path'], '');
    }
    gameserver_sync_news_version(gameserver_parse_global($read['content'])['version']);
    return ['ok' => true, 'error' => ''];
}

/* ============================================================
 *  五、前台顯示 / 公告同步
 * ============================================================ */
/**
 * 取得目前快取的遊戲版本號（供前台顯示，不觸發 SSH）。
 * 傳入 null 時自行建立連線；任何錯誤皆回傳空字串。
 */
function game_version_current($link = null): string
{
    if (!$link) {
        global $DBHost, $DBUser, $DBPassword, $DBName;
        try {
            $link = @mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
        } catch (Throwable $e) {
            $link = null;
        }
    }
    if (!$link) {
        return '';
    }
    try {
        $res = @mysqli_query($link, "SELECT `version` FROM `" . GAME_SERVER_TABLE . "` WHERE `id` = 1 LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return trim((string)($row['version'] ?? ''));
        }
    } catch (Throwable $e) {
        // 忽略
    }
    return '';
}

/** 同步版本號至公告系統（news_settings.site_version），失敗時靜默忽略 */
function gameserver_sync_news_version($version): void
{
    $version = trim((string)$version);
    if ($version === '') {
        return;
    }
    $cfgFile = __DIR__ . '/news_config.php';
    if (!is_file($cfgFile)) {
        return;
    }
    require_once $cfgFile;
    if (!function_exists('news_setting_put') || !defined('NEWS_DB_NAME')) {
        return;
    }
    global $DBHost, $DBUser, $DBPassword;
    try {
        $db = @mysqli_connect($DBHost, $DBUser, $DBPassword, NEWS_DB_NAME);
        if (!$db) {
            return;
        }
        mysqli_set_charset($db, 'utf8mb4');
        news_setting_put($db, 'site_version', gameserver_display_version($version));
        mysqli_close($db);
    } catch (Throwable $e) {
        // 忽略
    }
}

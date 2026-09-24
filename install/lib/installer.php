<?php
/**
 * =============================================================
 *  踏雪笑傲 · 安裝程式核心工具（install/lib/installer.php）
 *  -------------------------------------------------------------
 *  本檔僅供安裝精靈（install/index.php）使用，與站台執行期程式
 *  完全解耦：即使資料庫尚未設定也能載入。
 * =============================================================
 */

if (defined('APP_INSTALLER_LIB')) {
    return;
}
define('APP_INSTALLER_LIB', 1);

define('INSTALL_DIR', dirname(__DIR__));            // .../sw/install
define('APP_ROOT', dirname(INSTALL_DIR));           // .../sw
define('INSTALL_CONFIG_LOCAL', APP_ROOT . '/config.local.php');
define('INSTALL_LOCK', APP_ROOT . '/install.lock');
define('INSTALL_SCHEMA_DIR', INSTALL_DIR . '/schema');

/** HTML 逸出 */
function install_h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 是否已完成安裝（存在鎖定檔） */
function install_is_locked()
{
    return is_file(INSTALL_LOCK);
}

/** 建立／確保安裝 Session */
function install_session_start()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @session_start();
}

/** 環境需求檢查；回傳每項 { label, ok, detail, required } */
function install_env_checks()
{
    $checks = [];

    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $checks[] = [
        'label'    => 'PHP 版本',
        'ok'       => $phpOk,
        'detail'   => '目前 ' . PHP_VERSION . '（需 7.4 以上，建議 8.0+）',
        'required' => true,
    ];

    $exts = [
        'mysqli'   => true,
        'mbstring' => true,
        'json'     => true,
        'openssl'  => true,
        'curl'     => false,
    ];
    foreach ($exts as $ext => $required) {
        $ok = extension_loaded($ext);
        $checks[] = [
            'label'    => 'PHP 擴充：' . $ext,
            'ok'       => $ok,
            'detail'   => $ok ? '已載入' : '未載入' . ($required ? '' : '（綠界金流需要）'),
            'required' => $required,
        ];
    }

    $rootWritable = is_writable(APP_ROOT);
    $checks[] = [
        'label'    => '站台目錄可寫入',
        'ok'       => $rootWritable,
        'detail'   => $rootWritable ? APP_ROOT . ' 可寫入（供產生 config.local.php）' : APP_ROOT . ' 無法寫入',
        'required' => true,
    ];

    $saReadable = is_readable(APP_ROOT . '/sa.sql');
    $checks[] = [
        'label'    => '遊戲資料庫檔 sa.sql',
        'ok'       => $saReadable,
        'detail'   => $saReadable ? '可讀取' : '找不到或無法讀取 sa.sql',
        'required' => true,
    ];

    $schemaCount = 0;
    foreach (glob(APP_ROOT . '/*_schema.sql') as $f) {
        $schemaCount++;
    }
    $checks[] = [
        'label'    => '前台資料表結構檔',
        'ok'       => $schemaCount > 0,
        'detail'   => '偵測到 ' . $schemaCount . ' 個 *_schema.sql',
        'required' => true,
    ];

    $lockWritable = is_writable(APP_ROOT);
    $checks[] = [
        'label'    => '可建立安裝鎖定檔',
        'ok'       => $lockWritable,
        'detail'   => $lockWritable ? 'install.lock 可寫入' : '無法寫入 install.lock',
        'required' => true,
    ];

    return $checks;
}

/** 環境檢查是否全部通過（僅計必要項） */
function install_env_pass(array $checks)
{
    foreach ($checks as $c) {
        if (!empty($c['required']) && empty($c['ok'])) {
            return false;
        }
    }
    return true;
}

/** 自動偵測站台網址（作為安裝表單預設值） */
function install_detect_site_url()
{
    $https  = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    $appDir    = str_replace('\\', '/', APP_ROOT);
    $docRoot   = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
    $basePath  = '';
    if ($docRoot !== '' && strpos($appDir, $docRoot) === 0) {
        $basePath = substr($appDir, strlen($docRoot));
    } else {
        $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? '')));
        if ($scriptDir !== '' && strpos($scriptDir, $appDir) === 0) {
            $sub = trim(substr($scriptDir, strlen($appDir)), '/');
            $depth = $sub === '' ? 0 : substr_count($sub, '/') + 1;
            $basePath = str_repeat('/..', $depth);
        }
    }
    $basePath = rtrim($basePath, '/');

    return $scheme . '://' . $host . $basePath;
}

/** 資料庫連線；回傳 { ok, link, error, errno } */
function install_db_connect($host, $port, $user, $pass, $db = null)
{
    $out = ['ok' => false, 'link' => null, 'error' => '', 'errno' => 0];
    if (!function_exists('mysqli_connect')) {
        $out['error'] = 'PHP 未載入 mysqli 擴充。';
        return $out;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $link = @mysqli_connect($host, $user, $pass, ($db === null || $db === '') ? null : $db, (int)$port);
    if (!$link) {
        $out['errno'] = mysqli_connect_errno();
        $out['error'] = mysqli_connect_error() ?: '連線失敗。';
        return $out;
    }
    @mysqli_set_charset($link, 'utf8mb4');
    $out['ok']  = true;
    $out['link'] = $link;
    return $out;
}

/** 資料庫是否已存在 */
function install_db_exists(mysqli $link, $db)
{
    $dbSafe = mysqli_real_escape_string($link, (string)$db);
    $res = @mysqli_query($link, "SHOW DATABASES LIKE '" . $dbSafe . "'");
    return $res && mysqli_num_rows($res) > 0;
}

/** 建立資料庫（utf8mb4）；回傳 { ok, error } */
function install_db_create(mysqli $link, $db)
{
    $dbId = '`' . str_replace('`', '', (string)$db) . '`';
    if (@mysqli_query($link, "CREATE DATABASE IF NOT EXISTS {$dbId} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        return ['ok' => true, 'error' => ''];
    }
    return ['ok' => false, 'error' => mysqli_error($link)];
}

/**
 * 將 SQL 字串切分為單一語句，支援 DELIMITER 指令、字串引號與註解。
 * 回傳語句陣列。
 */
function install_sql_split($sql)
{
    $statements = [];
    $delimiter  = ';';
    $buffer     = '';
    $len        = strlen($sql);
    $i          = 0;
    $inSingle   = false;
    $inDouble   = false;
    $inBacktick = false;

    while ($i < $len) {
        $ch = $sql[$i];

        if (!$inSingle && !$inDouble && !$inBacktick) {
            // 行註解 -- 或 #
            if (($ch === '-' && substr($sql, $i, 2) === '--' && ($i + 2 >= $len || ctype_space($sql[$i + 2])))
                || $ch === '#'
            ) {
                $nl = strpos($sql, "\n", $i);
                $i = ($nl === false) ? $len : $nl + 1;
                continue;
            }
            // 區塊註解
            if ($ch === '/' && substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = ($end === false) ? $len : $end + 2;
                continue;
            }
            // DELIMITER 指令（僅在語句邊界）
            if (trim($buffer) === '' && strncasecmp(substr($sql, $i, 9), 'DELIMITER', 9) === 0
                && ($i + 9 >= $len || ctype_space($sql[$i + 9]))
            ) {
                $nl  = strpos($sql, "\n", $i);
                $line = ($nl === false) ? substr($sql, $i) : substr($sql, $i, $nl - $i);
                $newDelim = trim(substr($line, 9));
                $delimiter = $newDelim === '' ? ';' : $newDelim;
                $buffer = '';
                $i = ($nl === false) ? $len : $nl + 1;
                continue;
            }
        }

        // 引號切換
        if ($ch === "'" && !$inDouble && !$inBacktick) {
            $inSingle = !$inSingle;
            $buffer .= $ch;
            $i++;
            continue;
        }
        if ($ch === '"' && !$inSingle && !$inBacktick) {
            $inDouble = !$inDouble;
            $buffer .= $ch;
            $i++;
            continue;
        }
        if ($ch === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
            $buffer .= $ch;
            $i++;
            continue;
        }
        // 反斜線逸出
        if ($ch === '\\' && ($inSingle || $inDouble)) {
            $buffer .= substr($sql, $i, 2);
            $i += 2;
            continue;
        }
        // 分隔符
        if (!$inSingle && !$inDouble && !$inBacktick && substr($sql, $i, strlen($delimiter)) === $delimiter) {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
            $i += strlen($delimiter);
            continue;
        }

        $buffer .= $ch;
        $i++;
    }

    $stmt = trim($buffer);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }
    return $statements;
}

/** 可忽略的 SQL 錯誤編號（重複建立等） */
function install_sql_ignorable_errno($errno)
{
    // 1050 資料表已存在, 1062 重複鍵值, 1061 重複索引, 1091 索引/欄位不存在,
    // 1051 未知資料表, 1826 重複外鍵, 1824 外鍵不存在, 1305 程序不存在
    return in_array((int)$errno, [1050, 1062, 1061, 1091, 1051, 1826, 1824, 1305], true);
}

/**
 * 執行單一 SQL 檔；回傳 { ok, file, total, executed, errors, warnings }
 */
function install_run_sql_file(mysqli $link, $file, $stopOnError = false, $defaultDb = null)
{
    $out = ['ok' => true, 'file' => $file, 'total' => 0, 'executed' => 0, 'errors' => [], 'warnings' => []];
    if (!is_readable($file)) {
        $out['ok'] = false;
        $out['errors'][] = '無法讀取檔案：' . $file;
        return $out;
    }

    @mysqli_set_charset($link, 'utf8mb4');
    $sql = file_get_contents($file);
    // 去除 UTF-8 BOM，避免首句語法錯誤
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") {
        $sql = substr($sql, 3);
    }
    // 部分結構檔（news/events/download）會自行 CREATE DATABASE + USE，
    // 因此每次執行前先切回主資料庫，確保主庫結構匯入到正確位置。
    if ($defaultDb !== null && $defaultDb !== '') {
        @mysqli_select_db($link, $defaultDb);
    }
    $statements = install_sql_split($sql);
    $out['total'] = count($statements);

    foreach ($statements as $stmt) {
        if (@mysqli_query($link, $stmt)) {
            $out['executed']++;
            continue;
        }
        $errno = mysqli_errno($link);
        $error = mysqli_error($link);
        if (install_sql_ignorable_errno($errno)) {
            $out['warnings'][] = '[' . $errno . '] ' . $error;
            continue;
        }
        $out['ok'] = false;
        $out['errors'][] = '[' . $errno . '] ' . $error . ' :: ' . mb_substr(preg_replace('/\s+/', ' ', $stmt), 0, 160, 'UTF-8');
        if ($stopOnError) {
            break;
        }
    }
    return $out;
}

/**
 * 資料庫結構檔清單（依匯入順序）。
 * 先匯入遊戲基底 sa.sql，再匯入各前台模組結構，最後補齊缺失資料表。
 */
function install_schema_manifest()
{
    $files = [];
    $files[] = APP_ROOT . '/sa.sql';

    // 主資料庫（sa）的前台模組結構
    foreach (glob(APP_ROOT . '/*_schema.sql') as $f) {
        $files[] = $f;
    }

    // 官網共用設定（swo_site_settings，位於 sa 主資料庫）
    $siteSchema = APP_ROOT . '/swo/site_schema.sql';
    if (is_file($siteSchema)) {
        $files[] = $siteSchema;
    }

    // 補齊 sa.sql 未定義、程式執行期會用到的資料表
    $files[] = INSTALL_SCHEMA_DIR . '/web_extras.sql';
    return $files;
}

/**
 * 依序匯入全部結構檔；回傳 { ok, results }
 *
 * @param string|null $mainDb 主資料庫名稱；每個檔案執行前會先切回此資料庫，
 *                            避免被 news/events/download 的 USE 指令帶偏。
 */
function install_run_all_schemas(mysqli $link, $mainDb = null)
{
    $results = [];
    $allOk = true;
    foreach (install_schema_manifest() as $file) {
        $r = install_run_sql_file($link, $file, false, $mainDb);
        $results[] = $r;
        if (empty($r['ok'])) {
            $allOk = false;
        }
    }
    return ['ok' => $allOk, 'results' => $results];
}

/** 產生並寫入 config.local.php；回傳 { ok, error, path } */
function install_write_config(array $data)
{
    $path = INSTALL_CONFIG_LOCAL;
    if (!is_writable(APP_ROOT)) {
        return ['ok' => false, 'error' => '站台目錄不可寫入，無法產生 ' . $path, 'path' => $path];
    }

    $host     = (string)($data['host'] ?? 'localhost');
    $port     = (int)($data['port'] ?? 3306);
    $user     = (string)($data['user'] ?? '');
    $pass     = (string)($data['pass'] ?? '');
    $name     = (string)($data['name'] ?? 'sa');
    $siteUrl  = rtrim((string)($data['site_url'] ?? ''), '/');
    $siteName = (string)($data['site_name'] ?? '踏雪笑傲');
    $timezone = (string)($data['timezone'] ?? 'Asia/Taipei');

    // 遠端伺服器憑證加密金鑰（安裝時自動產生，僅存於本機設定）
    $remoteKey = (string)($data['remote_cred_key'] ?? '');
    if ($remoteKey === '') {
        $remoteKey = bin2hex(random_bytes(32));
    }

    $content = "<?php\n"
        . "/**\n"
        . " * =============================================================\n"
        . " *  踏雪笑傲 · 本機設定（由安裝程式自動產生，請勿任意修改）\n"
        . " *  產生時間：" . date('Y-m-d H:i:s') . "\n"
        . " * =============================================================\n"
        . " */\n\n"
        . '$port       = ' . var_export($port, true) . ";\n"
        . '$DBHost     = ' . var_export($host, true) . ";\n"
        . '$DBUser     = ' . var_export($user, true) . ";\n"
        . '$DBPassword = ' . var_export($pass, true) . ";\n"
        . '$DBName     = ' . var_export($name, true) . ";\n\n"
        . "if (!defined('SITE_URL')) {\n"
        . "    define('SITE_URL', " . var_export($siteUrl, true) . ");\n"
        . "}\n"
        . "if (!defined('APP_SITE_NAME')) {\n"
        . "    define('APP_SITE_NAME', " . var_export($siteName, true) . ");\n"
        . "}\n"
        . "if (!defined('APP_TIMEZONE')) {\n"
        . "    define('APP_TIMEZONE', " . var_export($timezone, true) . ");\n"
        . "}\n"
        . "if (!defined('REMOTE_CRED_KEY')) {\n"
        . "    define('REMOTE_CRED_KEY', " . var_export($remoteKey, true) . ");\n"
        . "}\n"
        . "if (!defined('APP_INSTALLED')) {\n"
        . "    define('APP_INSTALLED', true);\n"
        . "}\n";

    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $content) === false) {
        return ['ok' => false, 'error' => '寫入暫存設定檔失敗。', 'path' => $path];
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => '更名設定檔失敗。', 'path' => $path];
    }
    @chmod($path, 0640);
    return ['ok' => true, 'error' => '', 'path' => $path];
}

/**
 * 確保已安裝的 config.local.php 含有 REMOTE_CRED_KEY。
 * 僅在「尚無任何遠端伺服器憑證」時才可補寫，避免更換金鑰導致既有密文無法解讀。
 * 回傳 true 表示已存在或已成功補寫。
 */
function install_ensure_remote_key($link = null)
{
    if (defined('REMOTE_CRED_KEY') && REMOTE_CRED_KEY !== '') {
        return true;
    }
    $path = INSTALL_CONFIG_LOCAL;
    if (!is_file($path) || !is_writable($path)) {
        return false;
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        return false;
    }
    if (strpos($content, 'REMOTE_CRED_KEY') !== false) {
        return true;
    }
    if ($link && install_table_exists($link, 'remote_servers')) {
        $res = @mysqli_query($link, 'SELECT COUNT(*) AS c FROM `remote_servers`');
        if ($res && (int)(mysqli_fetch_assoc($res)['c'] ?? 0) > 0) {
            return false;
        }
    }
    $key = bin2hex(random_bytes(32));
    $append = "\nif (!defined('REMOTE_CRED_KEY')) {\n    define('REMOTE_CRED_KEY', " . var_export($key, true) . ");\n}\n";
    return @file_put_contents($path, $content . $append) !== false;
}

/** 建立安裝鎖定檔；回傳 { ok, error, path } */
function install_create_lock(array $data = [])
{
    $path = INSTALL_LOCK;
    $content = "installed_at=" . date('c') . "\n"
        . "site_url=" . (string)($data['site_url'] ?? '') . "\n"
        . "db_name=" . (string)($data['name'] ?? '') . "\n";
    if (@file_put_contents($path, $content) === false) {
        return ['ok' => false, 'error' => '無法建立安裝鎖定檔。', 'path' => $path];
    }
    return ['ok' => true, 'error' => '', 'path' => $path];
}

/** 檢查資料表是否存在 */
function install_table_exists(mysqli $link, $table)
{
    $safe = mysqli_real_escape_string($link, (string)$table);
    $res = @mysqli_query($link, "SHOW TABLES LIKE '" . $safe . "'");
    return $res && mysqli_num_rows($res) > 0;
}

/** 是否已有任何 GM 帳號 */
function install_gm_count(mysqli $link)
{
    if (!install_table_exists($link, 'gm_accounts')) {
        return 0;
    }
    $res = @mysqli_query($link, "SELECT COUNT(*) AS c FROM `gm_accounts`");
    return $res ? (int)(mysqli_fetch_assoc($res)['c'] ?? 0) : 0;
}

/** 清除範例 GM 帳號（可選，避免範例憑證外流） */
function install_clear_sample_gm(mysqli $link)
{
    if (!install_table_exists($link, 'gm_accounts')) {
        return ['ok' => true, 'error' => '', 'deleted' => 0];
    }
    if (!@mysqli_query($link, "DELETE FROM `gm_accounts`")) {
        return ['ok' => false, 'error' => mysqli_error($link), 'deleted' => 0];
    }
    $deleted = mysqli_affected_rows($link);
    return ['ok' => true, 'error' => '', 'deleted' => max(0, $deleted)];
}

/** 建立 GM 管理員；回傳 { ok, error } */
function install_create_gm(mysqli $link, $username, $password)
{
    $username = strtolower(trim((string)$username));
    if (!preg_match('/^[a-z0-9_-]{3,50}$/', $username)) {
        return ['ok' => false, 'error' => '管理員帳號僅允許 3-50 個小寫英數字、底線或減號。'];
    }
    if (strlen((string)$password) < 8) {
        return ['ok' => false, 'error' => '管理員密碼至少需 8 個字元。'];
    }
    if (!install_table_exists($link, 'gm_accounts')) {
        return ['ok' => false, 'error' => '找不到 gm_accounts 資料表，請先完成資料庫匯入。'];
    }

    $hash = password_hash((string)$password, PASSWORD_BCRYPT);
    $stmt = @mysqli_prepare($link, "INSERT INTO `gm_accounts` (`username`, `password_hash`) VALUES (?, ?)");
    if (!$stmt) {
        return ['ok' => false, 'error' => mysqli_error($link)];
    }
    mysqli_stmt_bind_param($stmt, 'ss', $username, $hash);
    $ok = mysqli_stmt_execute($stmt);
    $error = $ok ? '' : mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    return ['ok' => (bool)$ok, 'error' => $error];
}

/** 解析 SQL 執行結果為摘要字串 */
function install_sql_summary(array $r)
{
    $base = basename($r['file']) . '：執行 ' . (int)$r['executed'] . '/' . (int)$r['total'] . ' 句';
    if (!empty($r['warnings'])) {
        $base .= '，略過 ' . count($r['warnings']) . ' 句（已存在）';
    }
    if (!empty($r['errors'])) {
        $base .= '，錯誤 ' . count($r['errors']) . ' 句';
    }
    return $base;
}

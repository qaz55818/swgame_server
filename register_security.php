<?php
/**
 * =============================================================
 *  踏雪笑傲 · 註冊安全、限流、封鎖與稽核工具庫
 *  -------------------------------------------------------------
 *  後端：index.php（註冊）、check_username.php（帳號檢查）
 *  後台：admin/registration_api.php、admin/registration_admin.php
 *
 *  使用方式：
 *    require_once __DIR__ . '/register_security.php';
 *    $settings = register_settings_load($Link);
 *    $access   = register_check_access($Link, $settings, $ip, $username, true);
 *
 *  設計原則：
 *    - 所有查詢使用參數化（prepared statement）。
 *    - 限流使用資料庫原子計數（INSERT ... ON DUPLICATE KEY UPDATE），可承受併發。
 *    - 只記錄實際到達應用程式的嘗試，不宣稱涵蓋 CDN／伺服器提前攔截的請求。
 *    - 紀錄欄位長度受限；提供保留天數與分批清理。
 *    - 絕不記錄明文密碼、密碼雜湊、驗證碼、Session ID 或完整請求內容。
 * =============================================================
 */

if (defined('REGISTER_SECURITY_LIB')) {
    return;
}
define('REGISTER_SECURITY_LIB', 1);

/* ------------------------------------------------------------------
 * 預設設定值（可由後台「註冊設定」覆寫，儲存於 register_settings）
 * ------------------------------------------------------------------ */
function register_setting_defaults()
{
    return [
        'register_enabled'        => '1',
        'closed_notice'           => '註冊功能目前暫停開放，造成不便敬請見諒。',
        'rate_window_seconds'     => '300',
        'rate_max_per_ip'         => '5',
        'rate_max_per_account'    => '3',
        'rate_max_per_ip_hour'    => '20',
        'rate_cooldown_seconds'   => '300',
        'block_duration_seconds'  => '3600',
        'retention_days'          => '90',
        'trusted_proxies'         => '',
        // 註冊規則：一組 Email 可註冊的帳號數
        'email_max_accounts'      => '1',
        // 帳號／密碼長度限制
        'account_min_length'      => '4',
        'account_max_length'      => '10',
        'password_min_length'     => '4',
        'password_max_length'     => '10',
        // 禁止用於帳號或密碼的詞彙（逗號／換行分隔，不分大小寫）
        'forbidden_words'         => '',
        // 禁止用於密碼的特殊符號（字元集合，例如 !@#$）
        'forbidden_symbols'       => '',
        // 禁止的 Email 格式／網域（逗號／換行分隔，支援 * 萬用字元）
        'forbidden_email_patterns'=> '',
    ];
}

/** 後台可調整的設定鍵（白名單） */
function register_setting_keys()
{
    return array_keys(register_setting_defaults());
}

/** 讀取註冊設定（含預設值合併，並以靜態快取避免重複查詢） */
function register_settings_load($Link)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $settings = register_setting_defaults();
    if (!$Link) {
        $cache = $settings;
        return $cache;
    }
    try {
        register_ensure_core_tables($Link);
        $res = $Link->query("SELECT `setting_key`, `setting_value` FROM `register_settings`");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (array_key_exists($row['setting_key'], $settings)) {
                    $settings[$row['setting_key']] = (string)$row['setting_value'];
                }
            }
        }
    } catch (Throwable $e) {
        // 資料表尚未建立時沿用預設值
    }
    $cache = $settings;
    return $cache;
}

/** 取得布林設定 */
function register_setting_bool($settings, $key, $default = false)
{
    if (!isset($settings[$key])) {
        return $default;
    }
    return in_array((string)$settings[$key], ['1', 'true', 'on', 'yes'], true);
}

/** 取得整數設定 */
function register_setting_int($settings, $key, $default = 0)
{
    if (!isset($settings[$key]) || $settings[$key] === '') {
        return $default;
    }
    return (int)$settings[$key];
}

/* ------------------------------------------------------------------
 * 註冊規則輔助：詞彙／符號／Email 格式清單
 * ------------------------------------------------------------------ */

/** 將逗號／換行分隔的清單正規化（去重、去空白、限制筆數與長度） */
function register_normalize_word_list($value, $maxItems = 500, $maxItemLen = 64)
{
    $value = str_replace(["\r\n", "\r"], "\n", (string)$value);
    $parts = preg_split('/[,\n]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) {
        return '';
    }
    $out = [];
    foreach ($parts as $item) {
        $item = trim($item);
        if ($item === '') {
            continue;
        }
        $item = mb_substr($item, 0, $maxItemLen);
        if (!in_array($item, $out, true)) {
            $out[] = $item;
        }
        if (count($out) >= $maxItems) {
            break;
        }
    }
    return implode("\n", $out);
}

/** 讀取清單型設定為陣列（逗號／換行分隔） */
function register_setting_list($settings, $key)
{
    $raw = (string)($settings[$key] ?? '');
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/[,\n]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) {
        return [];
    }
    $out = [];
    foreach ($parts as $item) {
        $item = trim($item);
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return $out;
}

/** 將特殊符號正規化為去重後的字元集合（支援全形字元） */
function register_normalize_symbol_set($value, $maxChars = 128)
{
    $value = preg_replace('/[\s,]+/u', '', (string)$value);
    if ($value === null || $value === '') {
        return '';
    }
    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars)) {
        return '';
    }
    $out = [];
    foreach ($chars as $ch) {
        if (!isset($out[$ch])) {
            $out[$ch] = true;
            if (count($out) >= $maxChars) {
                break;
            }
        }
    }
    return implode('', array_keys($out));
}

/** 讀取特殊符號設定為字元陣列 */
function register_setting_symbols($settings, $key)
{
    $raw = (string)($settings[$key] ?? '');
    if ($raw === '') {
        return [];
    }
    $chars = preg_split('//u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($chars) ? $chars : [];
}

/** 文字是否包含任一禁用詞彙（不分大小寫、子字串比對） */
function register_text_has_forbidden_word($text, array $words)
{
    $text = (string)$text;
    if ($text === '' || !$words) {
        return false;
    }
    $haystack = mb_strtolower($text);
    foreach ($words as $word) {
        $word = mb_strtolower(trim((string)$word));
        if ($word !== '' && mb_strpos($haystack, $word) !== false) {
            return true;
        }
    }
    return false;
}

/** 文字是否包含任一禁用特殊符號 */
function register_text_has_forbidden_symbol($text, array $symbols)
{
    $text = (string)$text;
    if ($text === '' || !$symbols) {
        return false;
    }
    foreach ($symbols as $symbol) {
        if ($symbol !== '' && mb_strpos($text, $symbol) !== false) {
            return true;
        }
    }
    return false;
}

/** 將支援 * 萬用字元的樣式轉為安全的正規表達式 */
function register_wildcard_regex($pattern)
{
    $pattern = (string)$pattern;
    $quoted = preg_quote($pattern, '/');
    $quoted = str_replace('\*', '.*', $quoted);
    return '/^' . $quoted . '$/u';
}

/** Email 是否符合任一禁止格式／網域樣式（支援 * 萬用字元） */
function register_email_matches_forbidden($email, array $patterns)
{
    $email = mb_strtolower(trim((string)$email));
    if ($email === '' || !$patterns) {
        return false;
    }
    $at = mb_strrpos($email, '@');
    $domain = ($at === false) ? '' : mb_substr($email, $at + 1);
    foreach ($patterns as $pattern) {
        $pattern = mb_strtolower(trim((string)$pattern));
        if ($pattern === '') {
            continue;
        }
        $regex = register_wildcard_regex($pattern);
        if (preg_match($regex, $email)) {
            return true;
        }
        if ($domain !== '' && preg_match($regex, $domain)) {
            return true;
        }
    }
    return false;
}

/** 計算某 Email 目前已註冊的帳號數（不分大小寫） */
function register_email_account_count($Link, $email)
{
    if (!$Link || $email === '') {
        return 0;
    }
    try {
        $stmt = $Link->prepare("SELECT COUNT(*) AS c FROM `users` WHERE LOWER(`email`) = ?");
        $emailLower = mb_strtolower($email);
        $stmt->bind_param("s", $emailLower);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $count;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * 儲存註冊設定（白名單鍵 + 值域驗證），並寫入管理操作紀錄。
 *
 * @return array{ok:bool,errors:array<string,string>,saved:array<string,string>}
 */
function register_settings_save($Link, array $input, $gmUsername = '', $ip = '')
{
    $result = ['ok' => false, 'errors' => [], 'saved' => []];
    if (!$Link) {
        $result['errors']['_'] = '資料庫連線失敗。';
        return $result;
    }
    register_ensure_core_tables($Link);

    $current = register_settings_load($Link);
    $clean = [];
    $errors = [];

    foreach (register_setting_keys() as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = is_string($input[$key]) ? $input[$key] : (string)$input[$key];
        $value = str_replace("\0", '', $value);
        $value = trim($value);

        switch ($key) {
            case 'register_enabled':
                $clean[$key] = (in_array($value, ['1', 'on', 'true'], true)) ? '1' : '0';
                break;
            case 'closed_notice':
                $value = mb_substr($value, 0, 300);
                $clean[$key] = $value;
                break;
            case 'rate_window_seconds':
                $clean[$key] = (string)register_clamp_int($value, 30, 86400, 300);
                break;
            case 'rate_max_per_ip':
                $clean[$key] = (string)register_clamp_int($value, 1, 1000, 5);
                break;
            case 'rate_max_per_account':
                $clean[$key] = (string)register_clamp_int($value, 1, 1000, 3);
                break;
            case 'rate_max_per_ip_hour':
                $clean[$key] = (string)register_clamp_int($value, 1, 100000, 20);
                break;
            case 'rate_cooldown_seconds':
                $clean[$key] = (string)register_clamp_int($value, 30, 86400, 300);
                break;
            case 'block_duration_seconds':
                $clean[$key] = (string)register_clamp_int($value, 60, 2592000, 3600);
                break;
            case 'retention_days':
                $clean[$key] = (string)register_clamp_int($value, 1, 3650, 90);
                break;
            case 'trusted_proxies':
                $normalized = register_normalize_trusted_proxies($value);
                if ($normalized === null) {
                    $errors[$key] = '可信代理清單格式錯誤，請輸入以逗號分隔的 IP 或 CIDR。';
                } else {
                    $clean[$key] = $normalized;
                }
                break;
            case 'email_max_accounts':
                $clean[$key] = (string)register_clamp_int($value, 1, 1000, 1);
                break;
            case 'account_min_length':
                $clean[$key] = (string)register_clamp_int($value, 1, 32, 4);
                break;
            case 'account_max_length':
                $clean[$key] = (string)register_clamp_int($value, 1, 32, 10);
                break;
            case 'password_min_length':
                $clean[$key] = (string)register_clamp_int($value, 1, 128, 4);
                break;
            case 'password_max_length':
                $clean[$key] = (string)register_clamp_int($value, 1, 128, 10);
                break;
            case 'forbidden_words':
                $clean[$key] = register_normalize_word_list($value);
                break;
            case 'forbidden_symbols':
                $clean[$key] = register_normalize_symbol_set($value);
                break;
            case 'forbidden_email_patterns':
                $clean[$key] = mb_strtolower(register_normalize_word_list($value, 500, 128));
                break;
        }
    }

    // 跨欄位檢查：長度上限不可小於下限
    if (!isset($errors['account_max_length'])
        && isset($clean['account_min_length'], $clean['account_max_length'])
        && (int)$clean['account_min_length'] > (int)$clean['account_max_length']) {
        $errors['account_max_length'] = '帳號長度上限不可小於下限。';
    }
    if (!isset($errors['password_max_length'])
        && isset($clean['password_min_length'], $clean['password_max_length'])
        && (int)$clean['password_min_length'] > (int)$clean['password_max_length']) {
        $errors['password_max_length'] = '密碼長度上限不可小於下限。';
    }

    if ($errors) {
        $result['errors'] = $errors;
        return $result;
    }

    try {
        $stmt = $Link->prepare(
            "INSERT INTO `register_settings` (`setting_key`, `setting_value`, `updated_by`)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_by` = VALUES(`updated_by`), `updated_at` = NOW()"
        );
        foreach ($clean as $key => $value) {
            $stmt->bind_param("sss", $key, $value, $gmUsername);
            $stmt->execute();
        }
        $stmt->close();
    } catch (Throwable $e) {
        $result['errors']['_'] = '設定儲存失敗，請稍後再試。';
        return $result;
    }

    // 更新快取需重新載入
    $result['saved'] = $clean;

    // 管理操作紀錄：只記錄異動的鍵與新值（不含機密；本頁沒有機密設定）
    $changed = [];
    foreach ($clean as $key => $value) {
        $old = $current[$key] ?? '';
        if ((string)$old !== (string)$value) {
            if ($key === 'trusted_proxies') {
                $value = register_mask_proxy_list($value);
            }
            $changed[] = $key . '=' . mb_substr((string)$value, 0, 60);
        }
    }
    if ($changed) {
        register_admin_log($Link, $gmUsername, 'settings_update', 'register_settings', implode(', ', $changed), $ip);
    }
    $result['ok'] = true;
    return $result;
}

/** 移除設定快取（同一請求內儲存後可重讀） */
function register_settings_reset_cache()
{
    // 靜態快取位於 register_settings_load，無法直接清除；
    // 需要重讀時請於儲存後呼叫 register_settings_load_fresh。
    return true;
}

/** 強制重新讀取設定（不使用快取） */
function register_settings_load_fresh($Link)
{
    if (!$Link) {
        return register_setting_defaults();
    }
    $settings = register_setting_defaults();
    try {
        $res = $Link->query("SELECT `setting_key`, `setting_value` FROM `register_settings`");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (array_key_exists($row['setting_key'], $settings)) {
                    $settings[$row['setting_key']] = (string)$row['setting_value'];
                }
            }
        }
    } catch (Throwable $e) {
    }
    return $settings;
}

/* ------------------------------------------------------------------
 * 來源 IP 判斷（僅在可信代理情況下採用代理標頭）
 * ------------------------------------------------------------------ */

/** 將 IP/CIDR 清單正規化；格式錯誤時回傳 null */
function register_normalize_trusted_proxies($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $parts = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $item) {
        $item = trim($item);
        if ($item === '') {
            continue;
        }
        if (strpos($item, '/') !== false) {
            [$ip, $bits] = explode('/', $item, 2);
            if (!filter_var($ip, FILTER_VALIDATE_IP) || !ctype_digit($bits)) {
                return null;
            }
            $max = (strpos($ip, ':') !== false) ? 128 : 32;
            $bits = (int)$bits;
            if ($bits < 0 || $bits > $max) {
                return null;
            }
            $out[] = $ip . '/' . $bits;
        } else {
            if (!filter_var($item, FILTER_VALIDATE_IP)) {
                return null;
            }
            $out[] = $item;
        }
    }
    return implode(',', array_unique($out));
}

/** 以遮罩方式顯示代理清單（供稽核用） */
function register_mask_proxy_list($value)
{
    $value = (string)$value;
    if ($value === '') {
        return '(空)';
    }
    return mb_substr($value, 0, 120);
}

/** 判斷 IP 是否落在任一 CIDR/IP 清單內（支援 IPv4 與 IPv6） */
function register_ip_in_cidrs($ip, $list)
{
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (is_string($list)) {
        $list = preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($list)) {
        return false;
    }
    $ipBin = @inet_pton($ip);
    if ($ipBin === false) {
        return false;
    }
    foreach ($list as $entry) {
        $entry = trim((string)$entry);
        if ($entry === '') {
            continue;
        }
        if (strpos($entry, '/') !== false) {
            [$net, $bits] = explode('/', $entry, 2);
            $netBin = @inet_pton($net);
            if ($netBin === false || strlen($netBin) !== strlen($ipBin)) {
                continue;
            }
            $bits = (int)$bits;
            if ($bits < 0) {
                continue;
            }
            $maxBits = strlen($ipBin) * 8;
            if ($bits > $maxBits) {
                $bits = $maxBits;
            }
            $fullBytes = intdiv($bits, 8);
            $remainder = $bits % 8;
            if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
                continue;
            }
            if ($remainder > 0) {
                $mask = 0xFF << (8 - $remainder) & 0xFF;
                if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($netBin[$fullBytes]) & $mask)) {
                    continue;
                }
            }
            return true;
        }
        if (@inet_pton($entry) === $ipBin) {
            return true;
        }
    }
    return false;
}

/** 取得實際連線來源 IP（不信任未設定為可信代理的標頭） */
function register_client_ip($Link = null, $settings = null)
{
    $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '';
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        $remote = '';
    }

    if ($settings === null) {
        $settings = $Link ? register_settings_load($Link) : register_setting_defaults();
    }
    $trusted = trim((string)($settings['trusted_proxies'] ?? ''));

    if ($remote === '' || $trusted === '' || !register_ip_in_cidrs($remote, $trusted)) {
        return $remote !== '' ? $remote : '0.0.0.0';
    }

    $trustedList = preg_split('/[\s,]+/', $trusted, -1, PREG_SPLIT_NO_EMPTY);

    // X-Forwarded-For：由右往左找第一個「非可信代理」的位址，避免最左側被偽造。
    $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        $chain = array_reverse(array_map('trim', explode(',', $xff)));
        foreach ($chain as $candidate) {
            if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (register_ip_in_cidrs($candidate, $trustedList)) {
                continue;
            }
            return $candidate;
        }
    }

    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $key) {
        if (!empty($_SERVER[$key])) {
            $candidate = trim((string)$_SERVER[$key]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }

    return $remote;
}

/** X-Forwarded-For 原始字串（僅供稽核顯示，永不作為判斷依據） */
function register_forwarded_raw()
{
    return mb_substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), 0, 255);
}

/** 用戶端環境資訊（不含 Session ID、Token 等機密） */
function register_client_meta()
{
    return [
        'ip_forwarded'    => register_forwarded_raw(),
        'user_agent'      => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
        'accept_language' => mb_substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 128),
        'referer'         => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
    ];
}

/** 取得請求大小上限檢查結果（避免伺服器端被超大型請求拖垮） */
function register_request_too_large($maxBytes = 16384)
{
    $len = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    return $len > (int)$maxBytes;
}

/* ------------------------------------------------------------------
 * 資料表建立（遷移；可重複執行，非破壞性）
 * ------------------------------------------------------------------ */

function register_table_ddl()
{
    return [
        'register_settings' => "CREATE TABLE IF NOT EXISTS `register_settings` (
            `setting_key` varchar(64) NOT NULL,
            `setting_value` text NOT NULL,
            `updated_by` varchar(64) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'register_blocks' => "CREATE TABLE IF NOT EXISTS `register_blocks` (
            `id` bigint NOT NULL AUTO_INCREMENT,
            `scope` varchar(16) NOT NULL DEFAULT 'ip',
            `block_key` varchar(64) NOT NULL DEFAULT '',
            `reason` varchar(255) NOT NULL DEFAULT '',
            `source` varchar(16) NOT NULL DEFAULT 'manual',
            `active` tinyint(1) NOT NULL DEFAULT 1,
            `created_by` varchar(64) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` datetime NULL DEFAULT NULL,
            `released_by` varchar(64) NOT NULL DEFAULT '',
            `released_at` datetime NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_block_scope_key` (`scope`, `block_key`, `active`),
            KEY `idx_block_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'register_rate_limits' => "CREATE TABLE IF NOT EXISTS `register_rate_limits` (
            `id` bigint NOT NULL AUTO_INCREMENT,
            `scope` varchar(24) NOT NULL,
            `bucket_key` varchar(64) NOT NULL,
            `window_start` bigint NOT NULL,
            `hits` int NOT NULL DEFAULT 0,
            `last_seen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_rate` (`scope`, `bucket_key`, `window_start`),
            KEY `idx_rate_seen` (`last_seen`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'register_admin_logs' => "CREATE TABLE IF NOT EXISTS `register_admin_logs` (
            `id` bigint NOT NULL AUTO_INCREMENT,
            `gm_username` varchar(64) NOT NULL DEFAULT '',
            `action` varchar(48) NOT NULL DEFAULT '',
            `target` varchar(128) NOT NULL DEFAULT '',
            `detail` varchar(255) NOT NULL DEFAULT '',
            `ip` varchar(45) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_admin_created` (`created_at`),
            KEY `idx_admin_action` (`action`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function register_ensure_core_tables($Link)
{
    static $done = false;
    if ($done || !$Link) {
        return;
    }
    $done = true;
    foreach (register_table_ddl() as $ddl) {
        try {
            $Link->query($ddl);
        } catch (Throwable $e) {
            // 忽略權限不足等問題，後續查詢會再回報
        }
    }
    register_ensure_log_columns($Link);
}

/** 為既有 register_log 補上 status/reason 欄位（非破壞性，可重複執行） */
function register_ensure_log_columns($Link)
{
    static $done = false;
    if ($done || !$Link) {
        return;
    }
    $done = true;
    $alters = [
        "ALTER TABLE `register_log` ADD COLUMN `status` varchar(16) NOT NULL DEFAULT 'success' AFTER `session_id`",
        "ALTER TABLE `register_log` ADD COLUMN `reason_code` varchar(48) NOT NULL DEFAULT '' AFTER `status`",
        "ALTER TABLE `register_log` ADD COLUMN `reason_text` varchar(255) NOT NULL DEFAULT '' AFTER `reason_code`",
        "ALTER TABLE `register_log` ADD INDEX `idx_register_status` (`status`)",
    ];
    foreach ($alters as $sql) {
        try {
            $Link->query($sql);
        } catch (Throwable $e) {
            // 1060=欄位已存在、1061=索引已存在，其他錯誤忽略
        }
    }
}

/* ------------------------------------------------------------------
 * 限流與封鎖
 * ------------------------------------------------------------------ */

/** 依視窗秒數對齊視窗起點 */
function register_window_start($windowSeconds)
{
    $windowSeconds = max(1, (int)$windowSeconds);
    return intdiv(time(), $windowSeconds) * $windowSeconds;
}

/**
 * 原子遞增計數：INSERT ... ON DUPLICATE KEY UPDATE。
 * 回傳目前視窗內累計次數；資料表不存在時回傳 0（不阻擋，但會嘗試建立）。
 */
function register_hit($Link, $scope, $bucket, $windowSeconds)
{
    if (!$Link || $bucket === '') {
        return 0;
    }
    $scope = (string)$scope;
    $bucket = mb_substr((string)$bucket, 0, 64);
    $windowSeconds = max(1, (int)$windowSeconds);
    $windowStart = register_window_start($windowSeconds);

    try {
        $stmt = $Link->prepare(
            "INSERT INTO `register_rate_limits` (`scope`, `bucket_key`, `window_start`, `hits`, `last_seen`)
             VALUES (?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE `hits` = `hits` + 1, `last_seen` = NOW()"
        );
        $stmt->bind_param("ssi", $scope, $bucket, $windowStart);
        $stmt->execute();
        $stmt->close();

        $stmt = $Link->prepare(
            "SELECT `hits` FROM `register_rate_limits` WHERE `scope` = ? AND `bucket_key` = ? AND `window_start` = ? LIMIT 1"
        );
        $stmt->bind_param("ssi", $scope, $bucket, $windowStart);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['hits'] ?? 0);
    } catch (Throwable $e) {
        register_ensure_core_tables($Link);
        return 0;
    }
}

/** 查詢目前生效中的封鎖（自動忽略已到期者） */
function register_active_block($Link, $scope, $key)
{
    if (!$Link || $key === '') {
        return null;
    }
    try {
        $stmt = $Link->prepare(
            "SELECT * FROM `register_blocks`
             WHERE `scope` = ? AND `block_key` = ? AND `active` = 1
               AND (`expires_at` IS NULL OR `expires_at` > NOW())
             ORDER BY `id` DESC LIMIT 1"
        );
        $stmt->bind_param("ss", $scope, $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        register_ensure_core_tables($Link);
        return null;
    }
}

/** 計算封鎖剩餘秒數（永久封鎖回傳 null） */
function register_block_retry_after($block)
{
    if (!$block || empty($block['expires_at'])) {
        return null;
    }
    $ts = strtotime((string)$block['expires_at']);
    if ($ts === false) {
        return null;
    }
    return max(0, $ts - time());
}

/**
 * 檢查一次註冊嘗試是否被允許。
 *
 * @return array{allowed:bool,http:int,reason_code:string,scope:string,retry_after:?int,message:string}
 */
function register_check_access($Link, $settings, $ip, $username, $countAccount = true)
{
    $result = [
        'allowed'     => true,
        'http'        => 200,
        'reason_code' => '',
        'scope'       => '',
        'retry_after' => null,
        'message'     => '',
    ];
    if (!$Link) {
        // 無資料庫時不阻擋（註冊流程本身也無法完成）
        return $result;
    }

    $ip = mb_substr((string)$ip, 0, 45);
    $username = mb_substr((string)$username, 0, 64);

    // 1) 既有封鎖（手動或自動）
    if ($ip !== '' && $ip !== '0.0.0.0') {
        $block = register_active_block($Link, 'ip', $ip);
        if ($block) {
            $result['allowed'] = false;
            $result['http'] = 429;
            $result['reason_code'] = 'ip_blocked';
            $result['scope'] = 'ip';
            $result['retry_after'] = register_block_retry_after($block);
            $result['message'] = '目前暫時無法註冊，請稍後再試。';
            return $result;
        }
    }
    if ($username !== '') {
        $block = register_active_block($Link, 'account', $username);
        if ($block) {
            $result['allowed'] = false;
            $result['http'] = 429;
            $result['reason_code'] = 'account_blocked';
            $result['scope'] = 'account';
            $result['retry_after'] = register_block_retry_after($block);
            $result['message'] = '目前暫時無法以該帳號註冊，請稍後再試。';
            return $result;
        }
    }

    $windowSeconds = register_setting_int($settings, 'rate_window_seconds', 300);
    $maxPerIp = register_setting_int($settings, 'rate_max_per_ip', 5);
    $maxPerAccount = register_setting_int($settings, 'rate_max_per_account', 3);
    $maxPerIpHour = register_setting_int($settings, 'rate_max_per_ip_hour', 20);
    $blockDuration = register_setting_int($settings, 'block_duration_seconds', 3600);

    // 2) IP 短期視窗
    if ($ip !== '' && $ip !== '0.0.0.0') {
        $ipHits = register_hit($Link, 'ip', $ip, $windowSeconds);
        if ($ipHits > $maxPerIp) {
            register_block_add($Link, 'ip', $ip, '短時間註冊次數過多', 'auto', $blockDuration, 'system');
            $result['allowed'] = false;
            $result['http'] = 429;
            $result['reason_code'] = 'rate_limited';
            $result['scope'] = 'ip';
            $result['retry_after'] = $blockDuration;
            $result['message'] = '註冊嘗試過於頻繁，請稍後再試。';
            return $result;
        }
        // 3) IP 長時間視窗（每小時）
        $ipHourHits = register_hit($Link, 'ip_hour', $ip, 3600);
        if ($ipHourHits > $maxPerIpHour) {
            register_block_add($Link, 'ip', $ip, '每小時註冊次數過多', 'auto', $blockDuration, 'system');
            $result['allowed'] = false;
            $result['http'] = 429;
            $result['reason_code'] = 'rate_limited';
            $result['scope'] = 'ip';
            $result['retry_after'] = $blockDuration;
            $result['message'] = '註冊嘗試過於頻繁，請稍後再試。';
            return $result;
        }
    }

    // 4) 帳號維度（僅計數，不自動封鎖，避免攻擊者藉此讓他人無法註冊特定帳號）
    if ($countAccount && $username !== '') {
        $accountHits = register_hit($Link, 'account', $username, $windowSeconds);
        if ($accountHits > $maxPerAccount) {
            $result['allowed'] = false;
            $result['http'] = 429;
            $result['reason_code'] = 'account_rate_limited';
            $result['scope'] = 'account';
            $result['retry_after'] = $windowSeconds;
            $result['message'] = '此帳號的嘗試次數過多，請稍後再試。';
            return $result;
        }
    }

    return $result;
}

/** 新增封鎖（ttlSeconds 為 null 代表永久） */
function register_block_add($Link, $scope, $key, $reason, $source = 'manual', $ttlSeconds = 3600, $createdBy = '')
{
    if (!$Link || $key === '') {
        return 0;
    }
    $scope = in_array($scope, ['ip', 'account'], true) ? $scope : 'ip';
    $source = in_array($source, ['auto', 'manual'], true) ? $source : 'manual';
    $key = mb_substr((string)$key, 0, 64);
    $reason = mb_substr((string)$reason, 0, 255);
    $createdBy = mb_substr((string)$createdBy, 0, 64);
    $expiresExpr = 'NULL';
    $ttl = null;
    if ($ttlSeconds !== null) {
        $ttl = max(1, (int)$ttlSeconds);
        $expiresExpr = 'DATE_ADD(NOW(), INTERVAL ? SECOND)';
    }
    try {
        if ($ttl !== null) {
            $stmt = $Link->prepare(
                "INSERT INTO `register_blocks` (`scope`, `block_key`, `reason`, `source`, `active`, `created_by`, `expires_at`)
                 VALUES (?, ?, ?, ?, 1, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))"
            );
            $stmt->bind_param("sssssi", $scope, $key, $reason, $source, $createdBy, $ttl);
        } else {
            $stmt = $Link->prepare(
                "INSERT INTO `register_blocks` (`scope`, `block_key`, `reason`, `source`, `active`, `created_by`, `expires_at`)
                 VALUES (?, ?, ?, ?, 1, ?, NULL)"
            );
            $stmt->bind_param("sssss", $scope, $key, $reason, $source, $createdBy);
        }
        $stmt->execute();
        $id = (int)$Link->insert_id;
        $stmt->close();
        return $id;
    } catch (Throwable $e) {
        register_ensure_core_tables($Link);
        return 0;
    }
}

/** 解除封鎖（軟性停用） */
function register_block_release($Link, $id, $gmUsername = '')
{
    if (!$Link) {
        return false;
    }
    try {
        $stmt = $Link->prepare(
            "UPDATE `register_blocks`
             SET `active` = 0, `released_by` = ?, `released_at` = NOW()
             WHERE `id` = ? AND `active` = 1"
        );
        $stmt->bind_param("si", $gmUsername, $id);
        $stmt->execute();
        $ok = $stmt->affected_rows >= 0;
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

/* ------------------------------------------------------------------
 * 嘗試紀錄
 * ------------------------------------------------------------------ */

/**
 * 寫入一筆註冊嘗試（不含任何密碼資訊）。
 * 回傳新增的紀錄 ID；失敗回傳 0。
 */
function register_record_attempt($Link, array $data)
{
    if (!$Link) {
        return 0;
    }
    $username = mb_substr((string)($data['username'] ?? ''), 0, 32);
    $email = mb_substr((string)($data['email'] ?? ''), 0, 64);
    $userid = (int)($data['userid'] ?? 0);
    $status = mb_substr((string)($data['status'] ?? 'pending'), 0, 16);
    $reasonCode = mb_substr((string)($data['reason_code'] ?? ''), 0, 48);
    $reasonText = mb_substr((string)($data['reason_text'] ?? ''), 0, 255);
    $ip = mb_substr((string)($data['ip'] ?? ''), 0, 45);
    $ipForwarded = mb_substr((string)($data['ip_forwarded'] ?? ''), 0, 255);
    $ua = mb_substr((string)($data['user_agent'] ?? ''), 0, 512);
    $lang = mb_substr((string)($data['accept_language'] ?? ''), 0, 128);
    $referer = mb_substr((string)($data['referer'] ?? ''), 0, 255);
    $fp = mb_substr((string)($data['fingerprint_hash'] ?? ''), 0, 64);

    $insert = function ($withStatus) use ($Link, $username, $email, $userid, $status, $reasonCode, $reasonText, $ip, $ipForwarded, $ua, $lang, $referer, $fp) {
        if ($withStatus) {
            $sql = "INSERT INTO `register_log`
                        (`userid`,`username`,`email`,`ip`,`ip_forwarded`,`user_agent`,`accept_language`,`referer`,`fingerprint_hash`,`status`,`reason_code`,`reason_text`,`created_at`)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())";
            $stmt = $Link->prepare($sql);
            $stmt->bind_param("isssssssssss", $userid, $username, $email, $ip, $ipForwarded, $ua, $lang, $referer, $fp, $status, $reasonCode, $reasonText);
        } else {
            $sql = "INSERT INTO `register_log`
                        (`userid`,`username`,`email`,`ip`,`ip_forwarded`,`user_agent`,`accept_language`,`referer`,`fingerprint_hash`,`created_at`)
                    VALUES (?,?,?,?,?,?,?,?,?,NOW())";
            $stmt = $Link->prepare($sql);
            $stmt->bind_param("issssssss", $userid, $username, $email, $ip, $ipForwarded, $ua, $lang, $referer, $fp);
        }
        $stmt->execute();
        $id = (int)$Link->insert_id;
        $stmt->close();
        return $id;
    };

    try {
        return $insert(true);
    } catch (Throwable $e) {
        // 可能是欄位尚未遷移（1054），補欄位後重試一次
        register_ensure_core_tables($Link);
        try {
            return $insert(true);
        } catch (Throwable $e2) {
            try {
                return $insert(false);
            } catch (Throwable $e3) {
                return 0;
            }
        }
    }
}

/** 更新既有嘗試紀錄的最終結果 */
function register_finalize_attempt($Link, $logId, $status, $reasonCode, $reasonText, $userId = 0)
{
    $logId = (int)$logId;
    if (!$Link || $logId <= 0) {
        return false;
    }
    try {
        $stmt = $Link->prepare(
            "UPDATE `register_log`
             SET `status` = ?, `reason_code` = ?, `reason_text` = ?, `userid` = ?
             WHERE `id` = ?"
        );
        $status = mb_substr((string)$status, 0, 16);
        $reasonCode = mb_substr((string)$reasonCode, 0, 48);
        $reasonText = mb_substr((string)$reasonText, 0, 255);
        $userId = (int)$userId;
        $stmt->bind_param("sssii", $status, $reasonCode, $reasonText, $userId, $logId);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ------------------------------------------------------------------
 * 管理操作紀錄
 * ------------------------------------------------------------------ */

function register_admin_log($Link, $gmUsername, $action, $target = '', $detail = '', $ip = '')
{
    if (!$Link) {
        return false;
    }
    try {
        $gmUsername = mb_substr((string)$gmUsername, 0, 64);
        $action = mb_substr((string)$action, 0, 48);
        $target = mb_substr((string)$target, 0, 128);
        $detail = mb_substr((string)$detail, 0, 255);
        $ip = mb_substr((string)$ip, 0, 45);
        $stmt = $Link->prepare(
            "INSERT INTO `register_admin_logs` (`gm_username`,`action`,`target`,`detail`,`ip`)
             VALUES (?,?,?,?,?)"
        );
        $stmt->bind_param("sssss", $gmUsername, $action, $target, $detail, $ip);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        register_ensure_core_tables($Link);
        return false;
    }
}

/* ------------------------------------------------------------------
 * 清理
 * ------------------------------------------------------------------ */

/** 分批刪除超過保留天數的註冊紀錄與限流計數 */
function register_purge($Link, $days, $batch = 1000)
{
    if (!$Link) {
        return ['logs' => 0, 'rate' => 0];
    }
    $days = max(1, (int)$days);
    $batch = max(1, min(5000, (int)$batch));
    $out = ['logs' => 0, 'rate' => 0];
    try {
        $stmt = $Link->prepare("DELETE FROM `register_log` WHERE `created_at` < NOW() - INTERVAL ? DAY LIMIT ?");
        $stmt->bind_param("ii", $days, $batch);
        $stmt->execute();
        $out['logs'] = $stmt->affected_rows;
        $stmt->close();
    } catch (Throwable $e) {
        register_ensure_core_tables($Link);
    }
    try {
        $stmt = $Link->prepare("DELETE FROM `register_rate_limits` WHERE `last_seen` < NOW() - INTERVAL ? DAY LIMIT ?");
        $stmt->bind_param("ii", $days, $batch);
        $stmt->execute();
        $out['rate'] = $stmt->affected_rows;
        $stmt->close();
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

/* ------------------------------------------------------------------
 * 狀態與原因代碼（供後台顯示；不推測無法取得的資訊）
 * ------------------------------------------------------------------ */

function register_result_meta()
{
    return [
        'success'  => ['label' => '註冊成功', 'cls' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'dot' => 'bg-emerald-500'],
        'failed'   => ['label' => '驗證失敗', 'cls' => 'bg-rose-50 text-rose-700 border-rose-200',          'dot' => 'bg-rose-500'],
        'limited'  => ['label' => '遭限流',   'cls' => 'bg-amber-50 text-amber-700 border-amber-200',       'dot' => 'bg-amber-500'],
        'blocked'  => ['label' => '已封鎖',   'cls' => 'bg-orange-50 text-orange-700 border-orange-200',    'dot' => 'bg-orange-500'],
        'disabled' => ['label' => '註冊關閉', 'cls' => 'bg-slate-100 text-slate-600 border-slate-300',      'dot' => 'bg-slate-400'],
        'pending'  => ['label' => '處理中',   'cls' => 'bg-sky-50 text-sky-700 border-sky-200',             'dot' => 'bg-sky-500'],
    ];
}

function register_reason_labels()
{
    return [
        'ok'                    => '註冊成功',
        'account_exists'        => '帳號已被使用',
        'account_invalid'       => '帳號格式錯誤',
        'account_forbidden'     => '帳號含禁用詞彙或符號',
        'password_mismatch'     => '兩次密碼不一致',
        'password_invalid'      => '密碼格式錯誤',
        'password_weak'         => '密碼與帳號相同',
        'password_forbidden'    => '密碼含禁用詞彙或符號',
        'email_invalid'         => '電子郵件格式錯誤',
        'email_forbidden'       => '電子郵件格式不受允許',
        'email_exists'          => '電子郵件已達註冊上限',
        'csrf_invalid'          => '表單權杖失效（CSRF）',
        'honeypot'              => '觸發蜜罐欄位',
        'registration_disabled' => '註冊功能已關閉',
        'ip_blocked'            => '來源 IP 已封鎖',
        'account_blocked'       => '帳號已封鎖',
        'rate_limited'          => 'IP 超過限流門檻',
        'account_rate_limited'  => '帳號超過限流門檻',
        'request_too_large'     => '請求內容過大',
        'server_error'          => '系統錯誤',
        'db_error'              => '資料庫錯誤',
        'concurrent_duplicate'  => '併發重複註冊',
        'unknown'               => '未知結果',
    ];
}

/* ------------------------------------------------------------------
 * CSRF（後台 API 專用）
 * ------------------------------------------------------------------ */

function register_csrf_token()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['register_csrf_token'])) {
        $_SESSION['register_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['register_csrf_token'];
}

function register_csrf_validate($token)
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['register_csrf_token'])) {
        return false;
    }
    return is_string($token) && hash_equals((string)$_SESSION['register_csrf_token'], $token);
}

/* ------------------------------------------------------------------
 * 小工具
 * ------------------------------------------------------------------ */

function register_clamp_int($value, $min, $max, $default)
{
    if (!is_numeric($value)) {
        return $default;
    }
    $value = (int)$value;
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

function register_json_response($httpCode, array $payload)
{
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

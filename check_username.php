<?php
/**
 * 帳號 / 電子郵件可用性檢查 API（供註冊頁即時提示使用）
 * - 僅接受 POST，使用參數化查詢。
 * - 送出 `email` 時檢查電子郵件是否已被註冊；送出 `username` 時檢查遊戲帳號。
 * - 伺服器端限流（獨立於註冊次數配額），避免被用於大量帳號列舉。
 * - 註冊功能關閉時一律回應「未存在」且不查詢資料庫。
 * - 任何錯誤皆不回傳資料庫訊息或堆疊。
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

include_once __DIR__ . '/config.php';
require_once __DIR__ . '/register_security.php';

function check_username_reply($payload, $http = 200)
{
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    check_username_reply(['ok' => false, 'message' => '不支援的請求方法。'], 405);
}

$Link = null;
try {
    $Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName, $port ?? 3306);
} catch (Throwable $e) {
    $Link = null;
}

$settings = $Link ? register_settings_load($Link) : register_setting_defaults();

if (!register_setting_bool($settings, 'register_enabled', true)) {
    check_username_reply(['ok' => true, 'exists' => false, 'registration_enabled' => false]);
}

if (!$Link) {
    check_username_reply(['ok' => false, 'message' => '系統暫時無法使用。'], 503);
}

mysqli_set_charset($Link, 'utf8');

// 獨立限流：同一 IP 每分鐘最多 60 次帳號檢查（避免列舉）
$ip = register_client_ip($Link, $settings);
$hits = register_hit($Link, 'check_ip', $ip, 60);
if ($hits > 60) {
    check_username_reply(['ok' => false, 'message' => '查詢過於頻繁，請稍後再試。'], 429);
}

$email = trim((string)($_POST['email'] ?? ''));

// 電子郵件可用性：格式不合法時不需查詢，直接回覆未存在
if ($email !== '') {
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        check_username_reply(['ok' => true, 'exists' => false, 'field' => 'email']);
    }
    // 後台設定的禁止 Email 格式／網域
    if (register_email_matches_forbidden($email, register_setting_list($settings, 'forbidden_email_patterns'))) {
        mysqli_close($Link);
        check_username_reply([
            'ok'        => true,
            'exists'    => false,
            'forbidden' => true,
            'field'     => 'email',
            'message'   => '此電子信箱格式或網域不受允許，請改用其他信箱。',
        ]);
    }
    $emailMaxAccounts = max(1, register_setting_int($settings, 'email_max_accounts', 1));
    $emailCount = 0;
    try {
        $emailLower = strtolower($email);
        $stmt = $Link->prepare("SELECT COUNT(*) AS c FROM `users` WHERE LOWER(`email`) = ?");
        $stmt->bind_param("s", $emailLower);
        $stmt->execute();
        $emailCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    } catch (Throwable $e) {
        mysqli_close($Link);
        check_username_reply(['ok' => false, 'message' => '系統暫時無法使用。'], 503);
    }
    mysqli_close($Link);
    check_username_reply([
        'ok'     => true,
        'exists' => ($emailCount >= $emailMaxAccounts),
        'field'  => 'email',
        'count'  => $emailCount,
        'max'    => $emailMaxAccounts,
    ]);
}

$username = strtolower(trim((string)($_POST['username'] ?? '')));
if ($username === '' || strlen($username) > 32 || !preg_match('/^[a-z0-9_-]+$/', $username)) {
    // 格式不合法時不需查詢，直接回覆未存在
    check_username_reply(['ok' => true, 'exists' => false, 'field' => 'username']);
}

// 後台設定的帳號長度與禁用詞彙／符號
$accountMinLength = register_setting_int($settings, 'account_min_length', 4);
$accountMaxLength = register_setting_int($settings, 'account_max_length', 10);
if ($accountMinLength > $accountMaxLength) { $accountMinLength = $accountMaxLength; }
if (strlen($username) < $accountMinLength || strlen($username) > $accountMaxLength) {
    check_username_reply([
        'ok'        => true,
        'exists'    => false,
        'forbidden' => true,
        'field'     => 'username',
        'message'   => '遊戲帳號長度必須為 ' . $accountMinLength . '-' . $accountMaxLength . ' 個字元。',
    ]);
}
if (register_text_has_forbidden_word($username, register_setting_list($settings, 'forbidden_words'))
    || register_text_has_forbidden_symbol($username, register_setting_symbols($settings, 'forbidden_symbols'))) {
    check_username_reply([
        'ok'        => true,
        'exists'    => false,
        'forbidden' => true,
        'field'     => 'username',
        'message'   => '此遊戲帳號包含系統禁止使用的詞彙或符號，請更換其他帳號。',
    ]);
}

$exists = false;
try {
    $stmt = $Link->prepare("SELECT `name` FROM `users` WHERE `name` = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
} catch (Throwable $e) {
    mysqli_close($Link);
    check_username_reply(['ok' => false, 'message' => '系統暫時無法使用。'], 503);
}
mysqli_close($Link);

check_username_reply(['ok' => true, 'exists' => $exists, 'field' => 'username']);

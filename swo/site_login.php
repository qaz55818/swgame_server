<?php
/**
 * =============================================================
 *  踏雪笑傲 · 彈出式子帳號登入處理（AJAX / JSON）
 *  供 swo/site_auth.php 的登入彈出視窗呼叫
 *  驗證邏輯與 sw/login.php 一致
 * =============================================================
 */
require_once __DIR__ . '/../session_bootstrap.php';
app_session_start(['remember' => true]);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../login_security.php';
require_once __DIR__ . '/../remember_me.php';

$PasswordColumn = 'passwd';

function sau_reply(array $payload)
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function sau_rotate_token()
{
    $_SESSION['sau_form_token'] = bin2hex(random_bytes(32));
    return $_SESSION['sau_form_token'];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sau_reply(['ok' => false, 'message' => '不支援的請求方式。', 'token' => sau_rotate_token()]);
}

$token = (string)($_POST['form_token'] ?? '');
$sessionToken = (string)($_SESSION['sau_form_token'] ?? '');
if ($sessionToken === '' || !hash_equals($sessionToken, $token)) {
    sau_reply(['ok' => false, 'message' => '表單已失效，請重新整理後再試。', 'token' => sau_rotate_token()]);
}

/* 每次嘗試都更換 token，避免重放 */
$newToken = sau_rotate_token();

$Login = trim((string)($_POST['login'] ?? ''));
$Pass  = trim((string)($_POST['passwd'] ?? ''));

if ($Login === '' || $Pass === '') {
    sau_reply(['ok' => false, 'message' => '請輸入帳號與密碼。', 'token' => $newToken]);
}

$Link = @mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
if (!$Link) {
    sau_reply(['ok' => false, 'message' => '系統連線異常，請稍後再試。', 'token' => $newToken]);
}
mysqli_set_charset($Link, 'utf8');

$clientIp = login_client_ip();
$limit = login_check_rate_limit($Link, $Login, $clientIp);
if ($limit['blocked']) {
    login_record($Link, $Login, 'blocked', ($limit['scope'] === 'ip' ? 'IP 嘗試次數過多' : '帳號嘗試次數過多'), 0);
    mysqli_close($Link);
    sau_reply([
        'ok' => false,
        'message' => '嘗試次數過多，基於安全考量已暫時鎖定，請於 ' . LOGIN_WINDOW_MINUTES . ' 分鐘後再試。',
        'token' => $newToken,
    ]);
}

/* 帳密驗證（users.passwd 為 latin1 原始位元組） */
mysqli_set_charset($Link, 'latin1');
$stmt = $Link->prepare("SELECT `ID`, `name`, `$PasswordColumn` FROM `users` WHERE `name` = ?");
$stmt->bind_param('s', $Login);
$stmt->execute();
$result = $stmt->get_result();

$authOk = false;
$row = null;
if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $expectedHash = md5($row['name'] . $Pass, true);
    if (hash_equals($row[$PasswordColumn], $expectedHash)) {
        $authOk = true;
    }
}
$stmt->close();

if ($authOk && $row) {
    mysqli_set_charset($Link, 'utf8');
    login_record($Link, $row['name'], 'success', '登入成功（彈出視窗）', (int)$row['ID']);

    session_regenerate_id(true);
    $_SESSION['user_id'] = $row['ID'];
    $_SESSION['username'] = $row['name'];
    $_SESSION['is_gm'] = 0;
    $_SESSION['login_time'] = time();
    $_SESSION['login_ip'] = $clientIp;

    // 勾選「記住我」時，發放長效權杖以維持登入
    if (!empty($_POST['remember'])) {
        remember_me_issue($Link, (int)$row['ID']);
    } else {
        remember_me_clear($Link);
    }

    mysqli_close($Link);

    sau_reply([
        'ok' => true,
        'message' => '登入成功，正在進入江湖…',
        'redirect' => '../home.php',
        'username' => $row['name'],
    ]);
}

mysqli_set_charset($Link, 'utf8');
login_record($Link, $Login, 'failed', '帳號或密碼不正確', 0);
mysqli_close($Link);
usleep(400000);

sau_reply(['ok' => false, 'message' => '帳號或密碼不正確，請重新輸入。', 'token' => $newToken]);

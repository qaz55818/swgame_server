<?php
require_once __DIR__ . '/session_bootstrap.php';
app_session_start();

// 撤銷「記住我」持久登入權杖
$rmPath = __DIR__ . '/remember_me.php';
if (is_file($rmPath)) {
    require_once $rmPath;
    if (function_exists('remember_me_clear')) {
        remember_me_clear();
    }
}

// 清除所有 session 資料
$_SESSION = array();

// 移除 session cookie 本身
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header("Location: login.php");
exit();

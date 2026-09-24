<?php
/**
 * =============================================================
 *  踏雪笑傲 · Session 安全啟動工具
 *  -------------------------------------------------------------
 *  用途：
 *    require_once __DIR__ . '/session_bootstrap.php';
 *    app_session_start();                        // 取代 session_start()
 *    app_session_start(['remember' => true]);    // 前台：啟用「記住我」自動登入
 *    app_session_enforce_timeout(7200);          // 選用：閒置逾時（秒）
 *
 *  特性：
 *    - 僅使用 Cookie 傳遞 Session ID（use_only_cookies）。
 *    - 嚴格模式避免未初始化的 Session ID 遭固定（use_strict_mode）。
 *    - Cookie 設定 HttpOnly、SameSite=Lax；偵測到 HTTPS 時自動加上 Secure。
 *    - 延長 Session 保存期限（預設 30 天），避免登入後短時間即被 GC 回收而登出。
 *    - 不信任訪客自行提交的 X-Forwarded-Proto，僅依實際連線判斷。
 * =============================================================
 */

if (!defined('APP_SESSION_BOOTSTRAP')) {
    define('APP_SESSION_BOOTSTRAP', 1);

    /** 判斷目前連線是否為 HTTPS（不信任任何前端可偽造的標頭） */
    function app_request_is_https()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return false;
    }

    /** Session 保存期限（預設 30 天；避免閒置後被 GC 回收而需重新登入） */
    if (!defined('APP_SESSION_TTL')) {
        define('APP_SESSION_TTL', 60 * 60 * 24 * 30);
    }

    /**
     * 啟動安全 Session（可重複呼叫，不會重複啟動）。
     *
     * @param array $options 目前支援：
     *   - remember (bool) 是否於無登入者時嘗試以「記住我」權杖自動恢復登入。
     */
    function app_session_start($options = [])
    {
        $remember = !empty($options['remember']);

        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($remember) { app_session_remember_hook(); }
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            @ini_set('session.use_only_cookies', '1');
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_trans_sid', '0');
            @ini_set('session.cookie_httponly', '1');
            if (PHP_VERSION_ID >= 70300) {
                @ini_set('session.cookie_samesite', 'Lax');
            }
            @ini_set('session.gc_maxlifetime', (string)APP_SESSION_TTL);

            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => app_request_is_https(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            } else {
                session_set_cookie_params(
                    0,
                    '/',
                    '',
                    app_request_is_https(),
                    true
                );
            }
        }

        session_start();

        if ($remember) {
            app_session_remember_hook();
        }
    }

    /** 載入「記住我」工具並嘗試自動恢復登入（僅在尚未登入時） */
    function app_session_remember_hook()
    {
        if (!empty($_SESSION['username'])) {
            return;
        }
        $lib = __DIR__ . '/remember_me.php';
        if (is_file($lib)) {
            require_once $lib;
            if (function_exists('app_remember_me_restore')) {
                app_remember_me_restore();
            }
        }
    }

    /** 閒置逾時：超過指定秒數未活動即銷毀 Session（預設 2 小時） */
    function app_session_enforce_timeout($maxIdleSeconds = 7200)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $maxIdleSeconds = (int)$maxIdleSeconds;
        if ($maxIdleSeconds <= 0) {
            return;
        }
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - (int)$_SESSION['last_activity']) > $maxIdleSeconds) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            session_start();
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = $now;
    }

    /* ============================================================
     *  後台全站側邊導覽列（僅 admin 管理頁面啟用）
     *  ------------------------------------------------------------
     *  以白名單（*_admin.php、gmpanel.php）掛上輸出緩衝，於回應結束
     *  時把側欄（admin/assets/admin-sidebar.*）注入所有後台頁面；
     *  API / 串流端點不會被緩衝，維持原有行為。
     * ============================================================ */

    /** 將側邊導覽列注入後台頁面 HTML */
    function app_admin_sidebar_inject($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '</body>') === false) {
            return $html;
        }
        if (strpos($html, 'id="adm-nav-root"') !== false || strpos($html, 'id="adm-nav"') !== false) {
            return $html; // 已注入
        }
        if (empty($_SESSION['gm_username'])) {
            return $html;
        }
        // 以子視窗（iframe, ?embed=1）載入時不注入側欄
        if (!empty($_GET['embed'])) {
            return $html;
        }
        $menuFile = __DIR__ . '/admin/admin_menu.php';
        if (!is_file($menuFile)) {
            return $html;
        }
        require_once $menuFile;
        if (!function_exists('admin_menu_groups')) {
            return $html;
        }
        $json = json_encode(
            admin_menu_groups(),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($json === false) {
            $json = '[]';
        }
        $gmJson = json_encode(
            (string)$_SESSION['gm_username'],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        // 於 <head> 先套用「預設展開」狀態，避免繪製時閃動
        $early = '<script>(function(){try{var p=localStorage.getItem("admNavPinned");'
            . 'if(p===null||p==="1"){document.documentElement.className+=" adm-nav-pinned";}}'
            . 'catch(e){document.documentElement.className+=" adm-nav-pinned";}})();</script>';
        $css = '<link rel="stylesheet" href="assets/admin-sidebar.css?v=20260925b">' . $early;
        $inject = '<div id="adm-nav-root"></div>'
            . '<script>window.ADMIN_SIDEBAR=' . $json . ';window.ADMIN_SIDEBAR_GM=' . $gmJson . ';</script>'
            . '<script src="assets/admin-sidebar.js?v=20260925b" defer></script>';
        if (stripos($html, '</head>') !== false) {
            $html = str_ireplace('</head>', $css . '</head>', $html);
        } else {
            $inject = $css . $inject;
        }
        return str_ireplace('</body>', $inject . '</body>', $html);
    }

    if (PHP_SAPI !== 'cli' && !defined('APP_ADMIN_SIDEBAR')) {
        $appScript = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $appBase   = basename($appScript);
        if (preg_match('/_admin\.php$/i', $appBase) || strtolower($appBase) === 'gmpanel.php') {
            define('APP_ADMIN_SIDEBAR', 1);
            ob_start('app_admin_sidebar_inject');
        }
    }
}

<?php
/**
 * =============================================================
 *  踏雪笑傲 · 記住我（持久登入）工具庫
 *  -------------------------------------------------------------
 *  目的：
 *    Session 會因瀏覽器關閉、GC 回收或閒置而失效，導致會員
 *    每過一段時間就必須重新登入。本檔提供「記住我」權杖機制，
 *    在使用者勾選後發放長效 Cookie，即使 Session 過期仍可安全地
 *    自動恢復登入狀態。
 *
 *  安全設計（Selector / Validator 模式）：
 *    - Cookie 內容為 `selector:validator`（皆為隨機十六進位）。
 *    - 資料庫僅儲存 selector 與 validator 的 sha256，外洩無法直接登入。
 *    - 每次自動登入成功即更換權杖（rotation），降低被盜用風險。
 *    - 權杖具到期時間，預設 30 天；登出或驗證失敗即刪除。
 * =============================================================
 */

if (!defined('REMEMBER_ME_LIB')) {
    define('REMEMBER_ME_LIB', 1);

    /** Cookie 名稱（避免與其他系統衝突） */
    if (!defined('REMEMBER_COOKIE_NAME')) {
        define('REMEMBER_COOKIE_NAME', 'sw_remember');
    }

    /** 權杖有效秒數（預設 30 天） */
    if (!defined('REMEMBER_TOKEN_TTL')) {
        define('REMEMBER_TOKEN_TTL', 60 * 60 * 24 * 30);
    }

    /** 是否使用 HTTPS（供 Cookie Secure 判斷） */
    function remember_me_is_https()
    {
        if (function_exists('app_request_is_https')) {
            return app_request_is_https();
        }
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        return isset($_SERVER['SERVER_PORT']) && (int)$SERVER['SERVER_PORT'] === 443;
    }

    /**
     * 載入 config.php 並把其中變數提升為全域（若尚未載入）。
     *
     * 注意：直接於函式內 include 會使變數留在區域範圍，因此以閉包取得
     * 變數後再寫回 $GLOBALS，確保後續頁面可直接使用 $DBHost 等變數。
     */
    function remember_me_load_config()
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;
        $cfg = __DIR__ . '/config.php';
        if (!is_file($cfg)) {
            return;
        }
        $vars = (static function () use ($cfg) {
            include $cfg;
            return get_defined_vars();
        })();
        foreach ($vars as $k => $v) {
            if (!array_key_exists($k, $GLOBALS)) {
                $GLOBALS[$k] = $v;
            }
        }
    }

    /** 取得（或建立）資料庫連線；失敗回傳 null */
    function remember_me_db()
    {
        if (!isset($GLOBALS['DBHost']) || !isset($GLOBALS['DBName'])) {
            remember_me_load_config();
        }
        if (!isset($GLOBALS['DBHost'])) {
            return null;
        }
        try {
            $Link = @mysqli_connect(
                $GLOBALS['DBHost'],
                $GLOBALS['DBUser'] ?? '',
                $GLOBALS['DBPassword'] ?? '',
                $GLOBALS['DBName'] ?? '',
                $GLOBALS['port'] ?? 3306
            );
        } catch (Throwable $e) {
            return null;
        }
        if (!$Link) {
            return null;
        }
        @mysqli_set_charset($Link, 'utf8mb4');
        return $Link;
    }

    /** 確保資料表存在（可重複執行，非破壞性） */
    function remember_me_ensure($Link)
    {
        static $done = false;
        if (!$Link || $done) {
            return $done;
        }
        try {
            $Link->query("CREATE TABLE IF NOT EXISTS `web_remember_tokens` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `uid` int NOT NULL,
                `selector` char(32) NOT NULL,
                `validator_hash` char(64) NOT NULL,
                `user_agent` varchar(255) NOT NULL DEFAULT '',
                `ip` varchar(45) NOT NULL DEFAULT '',
                `expires_at` bigint unsigned NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_used_at` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_selector` (`selector`),
                KEY `idx_uid` (`uid`),
                KEY `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $done = true;
        } catch (Throwable $e) {
            $done = false;
        }
        return $done;
    }

    /** 發送持久 Cookie */
    function remember_me_set_cookie($value, $expires)
    {
        // 同步更新當前請求的 $_COOKIE，確保同一請求內後續邏輯一致
        if ($value === '') {
            unset($_COOKIE[REMEMBER_COOKIE_NAME]);
        } else {
            $_COOKIE[REMEMBER_COOKIE_NAME] = $value;
        }
        $secure = remember_me_is_https();
        if (PHP_VERSION_ID >= 70300) {
            setcookie(REMEMBER_COOKIE_NAME, $value, [
                'expires'  => $expires,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie(REMEMBER_COOKIE_NAME, $value, $expires, '/', '', $secure, true);
        }
    }

    /** 清除持久 Cookie */
    function remember_me_forget_cookie()
    {
        remember_me_set_cookie('', time() - 42000);
        unset($_COOKIE[REMEMBER_COOKIE_NAME]);
    }

    /**
     * 發放一組新的「記住我」權杖並寫入 Cookie。
     *
     * @return bool 是否成功
     */
    function remember_me_issue($Link, $uid)
    {
        if (!$Link || (int)$uid <= 0) {
            return false;
        }
        if (!remember_me_ensure($Link)) {
            return false;
        }
        try {
            $uid = (int)$uid;
            // 清理過期權杖（支援多裝置：不同裝置各自保留有效權杖）
            $Link->query("DELETE FROM `web_remember_tokens` WHERE `expires_at` < " . time());

            $selector  = bin2hex(random_bytes(16));
            $validator = bin2hex(random_bytes(32));
            $vHash     = hash('sha256', $validator);
            $expires   = time() + REMEMBER_TOKEN_TTL;
            $ua        = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $ip        = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

            $stmt = $Link->prepare(
                "INSERT INTO `web_remember_tokens` (`uid`,`selector`,`validator_hash`,`user_agent`,`ip`,`expires_at`)
                 VALUES (?,?,?,?,?,?)"
            );
            $stmt->bind_param('issssi', $uid, $selector, $vHash, $ua, $ip, $expires);
            $ok = $stmt->execute();
            $stmt->close();

            if ($ok) {
                remember_me_set_cookie($selector . ':' . $validator, time() + REMEMBER_TOKEN_TTL);
            }
            return (bool)$ok;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** 撤銷目前的「記住我」權杖（登出時呼叫） */
    function remember_me_clear($Link = null)
    {
        $cookie = (string)($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
        if ($cookie !== '' && strpos($cookie, ':') !== false) {
            $selector = substr($cookie, 0, strpos($cookie, ':'));
            if ($selector !== '') {
                if (!$Link) {
                    $Link = remember_me_db();
                }
                if ($Link) {
                    try {
                        $stmt = $Link->prepare("DELETE FROM `web_remember_tokens` WHERE `selector` = ?");
                        $stmt->bind_param('s', $selector);
                        $stmt->execute();
                        $stmt->close();
                    } catch (Throwable $e) {
                        // 忽略
                    }
                }
            }
        }
        remember_me_forget_cookie();
    }

    /**
     * 嘗試以「記住我」權杖恢復登入。
     * 需在 session 已啟動後呼叫；若目前已有登入者則直接略過。
     *
     * @return bool 是否成功恢復登入
     */
    function app_remember_me_restore()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        if (!empty($_SESSION['username'])) {
            return false;
        }
        $cookie = (string)($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
        if ($cookie === '' || substr_count($cookie, ':') !== 1) {
            return false;
        }
        [$selector, $validator] = explode(':', $cookie, 2);
        if (!preg_match('/^[a-f0-9]{32}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
            remember_me_forget_cookie();
            return false;
        }

        $Link = remember_me_db();
        if (!$Link) {
            return false;
        }

        try {
            $now = time();
            $stmt = $Link->prepare(
                "SELECT `uid`, `validator_hash` FROM `web_remember_tokens`
                  WHERE `selector` = ? AND `expires_at` > ? LIMIT 1"
            );
            $stmt->bind_param('si', $selector, $now);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !hash_equals((string)$row['validator_hash'], hash('sha256', $validator))) {
                remember_me_clear($Link);
                mysqli_close($Link);
                return false;
            }

            $uid = (int)$row['uid'];
            $userStmt = $Link->prepare("SELECT `ID`, `name` FROM `users` WHERE `ID` = ? LIMIT 1");
            $userStmt->bind_param('i', $uid);
            $userStmt->execute();
            $user = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();

            if (!$user) {
                remember_me_clear($Link);
                mysqli_close($Link);
                return false;
            }

            // 恢復登入狀態（與 login.php 一致）
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION['user_id']    = $user['ID'];
            $_SESSION['username']   = $user['name'];
            $_SESSION['is_gm']      = 0;
            $_SESSION['login_time'] = time();
            $_SESSION['login_ip']   = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $_SESSION['restored']   = 1;

            // 更換權杖（rotation）：僅刪除本次使用的權杖，不影響其他裝置
            $del = $Link->prepare("DELETE FROM `web_remember_tokens` WHERE `selector` = ?");
            $del->bind_param('s', $selector);
            $del->execute();
            $del->close();
            remember_me_issue($Link, $uid);
            mysqli_close($Link);
            return true;
        } catch (Throwable $e) {
            if ($Link) {
                @mysqli_close($Link);
            }
            return false;
        }
    }
}

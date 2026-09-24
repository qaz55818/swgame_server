<?php
/**
 * =============================================================
 *  踏雪笑傲 · 網站端密碼驗證（與遊戲端相容）
 *  -------------------------------------------------------------
 *  背景：
 *    遊戲伺服器（C++）直接讀取 `users.passwd`，其格式固定為
 *    `md5(username . password, true)` 的「原始二進位」MD5。
 *    因此無法直接把 `users.passwd` 改成 password_hash() 的輸出，
 *    否則遊戲端將無法登入。
 *
 *  相容強化方案（本檔實作）：
 *    另建網站專用資料表 `web_credentials(uid, password_hash, legacy_fingerprint)`：
 *      - password_hash         ：bcrypt（password_hash / password_verify）。
 *      - legacy_fingerprint    ：設定當下 users.passwd 內容的 sha256，
 *                                用來偵測密碼是否被遊戲端或工具離線變更。
 *
 *    驗證流程：
 *      1. 若 web_credentials 存在，且其 fingerprint 與目前 users.passwd 相符，
 *         代表密碼未被離線變更 → 以 bcrypt 為權威進行驗證（不再回退 MD5）。
 *      2. 若不存在或 fingerprint 不符（例如透過遊戲端或 reset_all_pass
 *         變更密碼）→ 以既有 MD5 驗證；成功後自動補寫 bcrypt 與新 fingerprint。
 *
 *    如此一來遊戲端完全不受影響，網站端則在可行範圍內逐步改用強雜湊，
 *    且不會因密碼離線變更而誤用過期的 bcrypt。
 * =============================================================
 */

if (defined('WEB_AUTH_LIB')) {
    return;
}
define('WEB_AUTH_LIB', 1);

/** 建立／補齊 web_credentials（可重複執行，非破壞性） */
function web_credentials_ensure($Link)
{
    static $done = false;
    if (!$Link || $done) {
        return;
    }
    $done = true;
    try {
        $Link->query("CREATE TABLE IF NOT EXISTS `web_credentials` (
            `uid` int NOT NULL,
            `password_hash` varchar(255) NOT NULL DEFAULT '',
            `legacy_fingerprint` char(64) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // 忽略：權限不足等
    }
    // 相容較早建立、尚無 legacy_fingerprint 欄位的資料表
    try {
        $res = $Link->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_credentials' AND COLUMN_NAME = 'legacy_fingerprint'");
        $has = $res && ((int)($res->fetch_assoc()['c'] ?? 0) > 0);
        if (!$has) {
            $Link->query("ALTER TABLE `web_credentials` ADD COLUMN `legacy_fingerprint` char(64) NOT NULL DEFAULT '' AFTER `password_hash`");
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** fingerprint：對 users.passwd 的原始位元組取 sha256，用於偵測離線變更 */
function web_auth_legacy_fingerprint($legacyStored)
{
    return hash('sha256', (string)$legacyStored);
}

/** 讀取指定 uid 的網站端憑證（無資料或未遷移時回傳 null） */
function web_credentials_get($Link, $uid)
{
    if (!$Link) {
        return null;
    }
    web_credentials_ensure($Link);
    try {
        $stmt = $Link->prepare("SELECT `password_hash`, `legacy_fingerprint` FROM `web_credentials` WHERE `uid` = ? LIMIT 1");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * 寫入／更新網站端 bcrypt 憑證。
 *
 * @param mixed $legacyStored 目前 users.passwd 的原始內容
 */
function web_password_sync($Link, $uid, $plainPassword, $legacyStored)
{
    if (!$Link) {
        return false;
    }
    web_credentials_ensure($Link);
    try {
        $hash = password_hash((string)$plainPassword, PASSWORD_DEFAULT);
        $fp = web_auth_legacy_fingerprint($legacyStored);
        $uid = (int)$uid;
        $stmt = $Link->prepare(
            "INSERT INTO `web_credentials` (`uid`, `password_hash`, `legacy_fingerprint`)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `password_hash` = VALUES(`password_hash`), `legacy_fingerprint` = VALUES(`legacy_fingerprint`), `updated_at` = NOW()"
        );
        $stmt->bind_param("iss", $uid, $hash, $fp);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 移除網站端憑證（帳號刪除等情況使用） */
function web_password_clear($Link, $uid)
{
    if (!$Link) {
        return false;
    }
    try {
        $stmt = $Link->prepare("DELETE FROM `web_credentials` WHERE `uid` = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 網站端密碼驗證（相容遊戲端 MD5）。
 *
 * @param string $username     帳號
 * @param string $plainPassword 使用者輸入的密碼
 * @param string $legacyStored  目前 users.passwd 的原始內容（latin1 連線讀取）
 * @param mixed  $uid           users.ID
 *
 * @return array{ok:bool,method:string,upgraded:bool}
 *   method: 'bcrypt' 或以 MD5 驗證成功（method='md5'，並已嘗試補寫 bcrypt）
 */
function web_authenticate($Link, $uid, $username, $plainPassword, $legacyStored)
{
    $out = ['ok' => false, 'method' => '', 'upgraded' => false];
    if (!$Link) {
        return $out;
    }

    $uid = (int)$uid;
    $legacyFp = web_auth_legacy_fingerprint($legacyStored);
    $cred = web_credentials_get($Link, $uid);

    // 情況一：網站憑證存在且與目前遊戲端密碼一致 → bcrypt 為權威
    if ($cred && hash_equals((string)$cred['legacy_fingerprint'], $legacyFp)) {
        if (password_verify((string)$plainPassword, (string)$cred['password_hash'])) {
            $out['ok'] = true;
            $out['method'] = 'bcrypt';
            return $out;
        }
        // 憑證是新鮮的，bcrypt 失敗即為密碼錯誤，不再回退 MD5。
        return $out;
    }

    // 情況二：無憑證或已被離線變更 → 以遊戲端 MD5 驗證
    $expected = md5((string)$username . (string)$plainPassword, true);
    if (hash_equals((string)$legacyStored, $expected)) {
        $out['ok'] = true;
        $out['method'] = 'md5';
        if (web_password_sync($Link, $uid, $plainPassword, $legacyStored)) {
            $out['upgraded'] = true;
        }
        return $out;
    }

    return $out;
}

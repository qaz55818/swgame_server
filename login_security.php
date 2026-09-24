<?php
/**
 * =============================================================
 *  踏雪笑傲 · 登入安全與紀錄工具庫
 *  資料表：login_logs
 *  使用：login.php（寫入與防護）、admin/gm_login_logs_api.php（查詢）
 *
 *  防護機制（滑動視窗）：
 *    - 同一 IP 於 15 分鐘內失敗達 LOGIN_MAX_IP_ATTEMPTS 次 → 暫時鎖定
 *    - 同一帳號於 15 分鐘內失敗達 LOGIN_MAX_USER_ATTEMPTS 次 → 暫時鎖定
 * =============================================================
 */

if (!defined('LOGIN_WINDOW_MINUTES')) {
    define('LOGIN_WINDOW_MINUTES', 15);
}
if (!defined('LOGIN_MAX_IP_ATTEMPTS')) {
    define('LOGIN_MAX_IP_ATTEMPTS', 15);
}
if (!defined('LOGIN_MAX_USER_ATTEMPTS')) {
    define('LOGIN_MAX_USER_ATTEMPTS', 6);
}

require_once __DIR__ . '/register_security.php';

/**
 * 取得來源 IP。
 *
 * 安全原則：預設只信任實際連線來源（REMOTE_ADDR）。
 * 僅當 REMOTE_ADDR 屬於後台設定的「可信代理」清單時，才採用
 * X-Forwarded-For / CF-Connecting-IP 等標頭；避免訪客自行偽造 IP 繞過限制。
 */
function login_client_ip($Link = null, $settings = null)
{
    if (function_exists('register_client_ip')) {
        return substr(register_client_ip($Link, $settings), 0, 45);
    }
    $remote = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    return substr($remote, 0, 45);
}

/** 取得 X-Forwarded-For 原始字串 */
function login_forwarded_for()
{
    return mb_substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), 0, 255);
}

/** 取得用戶端環境資訊 */
function login_client_meta()
{
    return [
        'ip'              => login_client_ip(),
        'ip_forwarded'    => login_forwarded_for(),
        'user_agent'      => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
        'accept_language' => mb_substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 128),
        'referer'         => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
        // 基於安全與隱私，登入紀錄不再保存 Session ID（欄位保留以相容舊資料）。
        'session_id'      => '',
    ];
}

/**
 * 寫入一筆登入紀錄（狀態：success / failed / blocked）。
 */
function login_record($Link, $username, $status, $reason = '', $userId = 0)
{
    if (!$Link) {
        return false;
    }
    $m = login_client_meta();
    $username = mb_substr((string)$username, 0, 64);
    $reason = mb_substr((string)$reason, 0, 255);
    try {
        $stmt = $Link->prepare(
            "INSERT INTO `login_logs`
                (`username`,`user_id`,`status`,`reason`,`ip`,`ip_forwarded`,`user_agent`,`accept_language`,`referer`,`session_id`)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param(
            "sissssssss",
            $username, $userId, $status, $reason,
            $m['ip'], $m['ip_forwarded'], $m['user_agent'], $m['accept_language'], $m['referer'], $m['session_id']
        );
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 統計指定欄位（ip / username）於時間視窗內、指定狀態的次數。
 */
function login_count_recent($Link, $field, $value, $status = 'failed', $minutes = LOGIN_WINDOW_MINUTES)
{
    if (!$Link || $value === '' || !in_array($field, ['ip', 'username'], true)) {
        return 0;
    }
    $minutes = max(1, (int)$minutes);
    try {
        $sql = "SELECT COUNT(*) AS c FROM `login_logs`
                 WHERE `$field` = ? AND `status` = ? AND `created_at` >= NOW() - INTERVAL ? MINUTE";
        $stmt = $Link->prepare($sql);
        $stmt->bind_param("ssi", $value, $status, $minutes);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * 判斷是否已達鎖定門檻。
 *
 * @return array{blocked:bool,scope:string,ip_fails:int,user_fails:int}
 */
function login_check_rate_limit($Link, $username, $ip)
{
    $ipFails = login_count_recent($Link, 'ip', $ip);
    $userFails = login_count_recent($Link, 'username', $username);
    $blocked = false;
    $scope = '';
    if ($ipFails >= LOGIN_MAX_IP_ATTEMPTS) {
        $blocked = true;
        $scope = 'ip';
    } elseif ($userFails >= LOGIN_MAX_USER_ATTEMPTS) {
        $blocked = true;
        $scope = 'username';
    }
    return [
        'blocked'    => $blocked,
        'scope'      => $scope,
        'ip_fails'   => $ipFails,
        'user_fails' => $userFails,
    ];
}

/** 狀態顯示對照（後台用） */
function login_status_meta()
{
    return [
        'success' => ['label' => '登入成功', 'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'dot' => 'bg-emerald-500'],
        'failed'  => ['label' => '登入失敗', 'badge' => 'bg-rose-50 text-rose-700 border-rose-200',          'dot' => 'bg-rose-500'],
        'blocked' => ['label' => '已鎖定',   'badge' => 'bg-amber-50 text-amber-700 border-amber-200',      'dot' => 'bg-amber-500'],
    ];
}

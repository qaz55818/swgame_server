<?php
/**
 * =============================================================
 *  踏雪笑傲 · 會員訊息通知中心 JSON API
 *  -------------------------------------------------------------
 *  僅限已登入會員（$_SESSION['username']）存取，且僅能操作自己的訊息。
 *    GET  action=unread   ：回傳未讀數（供鈴鐺輪詢）
 *    GET  action=list     ：回傳訊息列表 + 未讀數
 *    POST action=read     ：標記單則已讀（需 CSRF）
 *    POST action=read_all ：全部標記已讀（需 CSRF）
 * =============================================================
 */
require_once __DIR__ . '/session_bootstrap.php';
app_session_start(['remember' => true]);
@include_once __DIR__ . '/config.php';
require_once __DIR__ . '/messages.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function msg_reply($http, array $payload)
{
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_SESSION['username']) || $_SESSION['username'] === '') {
    msg_reply(401, ['ok' => false, 'message' => '請先登入會員帳號。']);
}

$username = (string)$_SESSION['username'];
$userId = $_SESSION['user_id'] ?? '';

$Link = null;
try {
    $Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName, $port ?? 3306);
} catch (Throwable $e) {
    $Link = null;
}
if (!$Link) {
    msg_reply(500, ['ok' => false, 'message' => '資料庫連線失敗。']);
}
mysqli_set_charset($Link, 'utf8mb4');

$uid = member_message_resolve_uid($Link, $username, $userId);
if ($uid <= 0) {
    msg_reply(404, ['ok' => false, 'message' => '找不到會員資料。']);
}
member_message_ensure_table($Link);

$action = (string)($_REQUEST['action'] ?? 'list');
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

if ($action === 'unread') {
    msg_reply(200, [
        'ok'     => true,
        'unread' => member_message_unread_count($Link, $uid),
        'latest' => member_message_latest_id($Link, $uid),
    ]);
}

if ($action === 'list') {
    $onlyUnread = ((int)($_GET['unread'] ?? 0) === 1);
    $rows = member_message_list($Link, $uid, 60, 0, $onlyUnread);
    $out = [];
    foreach ($rows as $r) {
        $meta = member_message_type_meta((string)$r['type']);
        $out[] = [
            'id'           => (int)$r['id'],
            'title'        => (string)$r['title'],
            'title_color'  => (string)($r['title_color'] ?? ''),
            'title_bold'   => (int)($r['title_bold'] ?? 0),
            'content'      => member_message_sanitize_html($r['content']),
            'content_mode' => (string)($r['content_mode'] ?? 'html'),
            'type'         => (string)$r['type'],
            'type_label'   => $meta['label'],
            'type_icon'    => $meta['icon'],
            'type_badge'   => $meta['badge'],
            'sender_type'  => (string)$r['sender_type'],
            'sender_name'  => (string)$r['sender_name'],
            'is_read'      => (int)$r['is_read'],
            'created_at'   => (string)$r['created_at'],
            'ago'          => member_message_ago((string)$r['created_at']),
        ];
    }
    msg_reply(200, [
        'ok'     => true,
        'unread' => member_message_unread_count($Link, $uid),
        'items'  => $out,
    ]);
}

if (in_array($action, ['read', 'read_all', 'delete', 'delete_all'], true)) {
    if (!$isPost) {
        msg_reply(405, ['ok' => false, 'message' => '此操作僅接受 POST。']);
    }
    $token = (string)($_POST['csrf'] ?? '');
    if (empty($_SESSION['msg_csrf']) || !hash_equals((string)$_SESSION['msg_csrf'], $token)) {
        msg_reply(403, ['ok' => false, 'message' => '安全權杖失效，請重新整理頁面。']);
    }
    if ($action === 'read') {
        member_message_mark_read($Link, $uid, (int)($_POST['id'] ?? 0));
    } elseif ($action === 'read_all') {
        member_message_mark_all_read($Link, $uid);
    } elseif ($action === 'delete') {
        member_message_delete($Link, $uid, (int)($_POST['id'] ?? 0));
    } else {
        member_message_delete_all($Link, $uid);
    }
    msg_reply(200, ['ok' => true, 'unread' => member_message_unread_count($Link, $uid)]);
}

msg_reply(400, ['ok' => false, 'message' => '未知的操作。']);

<?php
/**
 * =============================================================
 *  踏雪笑傲 · 申訴回報系統（會員端）
 *  對應資料表：support_categories / support_tickets /
 *             support_messages / support_attachments / support_status_log
 *  管理端：admin/support_admin.php
 * =============================================================
 */
require_once __DIR__ . '/session_bootstrap.php';
app_session_start(['remember' => true]);
@include_once "config.php";
require_once __DIR__ . '/permissions.php';

// ------------------------------------------------------------
// 登入檢查
// ------------------------------------------------------------
if (!isset($_SESSION['username']) || $_SESSION['username'] === '') {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$sessionUserId = $_SESSION['user_id'] ?? '';

$Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
if (!$Link) {
    die("Database connection failed: " . mysqli_connect_error());
}
// 申訴資料以 UTF-8 儲存（與 users 的 latin1 二進位密碼欄位不同）
mysqli_set_charset($Link, "utf8");

// ------------------------------------------------------------
// 解析登入者 UID
// ------------------------------------------------------------
$uid = 0;
$accountInfo = null;
if ($sessionUserId !== '') {
    $stmt = $Link->prepare("SELECT `ID`, `name`, `email`, `mobilenumber` FROM `users` WHERE `ID` = ? LIMIT 1");
    $stmt->bind_param("i", $sessionUserId);
} else {
    $stmt = $Link->prepare("SELECT `ID`, `name`, `email`, `mobilenumber` FROM `users` WHERE `name` = ? LIMIT 1");
    $stmt->bind_param("s", $username);
}
$stmt->execute();
$accountInfo = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (is_array($accountInfo) && !empty($accountInfo['ID'])) {
    $uid = (int)$accountInfo['ID'];
} elseif ($sessionUserId !== '') {
    $uid = (int)$sessionUserId;
}
if ($uid <= 0) {
    header("Location: login.php");
    exit();
}

// 會員功能權限：申訴回報權限
member_permission_require($Link, $uid, 'can_support', ['back' => 'home.php', 'countdown' => 5]);

$displayEmail  = (is_array($accountInfo) && !empty($accountInfo['email'])) ? $accountInfo['email'] : '';
$displayMobile = (is_array($accountInfo) && !empty($accountInfo['mobilenumber'])) ? $accountInfo['mobilenumber'] : '';

// ------------------------------------------------------------
// CSRF Token
// ------------------------------------------------------------
if (empty($_SESSION['support_token'])) {
    $_SESSION['support_token'] = bin2hex(random_bytes(32));
}
$formToken = $_SESSION['support_token'];

// ------------------------------------------------------------
// 常數 / 輔助函式
// ------------------------------------------------------------
$SupportStatuses = [
    'open'         => ['label' => '待處理',   'badge' => 'bg-amber-50 text-amber-700 border-amber-200',   'dot' => 'bg-amber-500',   'strip' => 'bg-amber-500',   'icon' => 'fa-hourglass-half', 'glow' => 'shadow-amber-500/40'],
    'processing'   => ['label' => '處理中',   'badge' => 'bg-sky-50 text-sky-700 border-sky-200',         'dot' => 'bg-sky-500',     'strip' => 'bg-sky-500',     'icon' => 'fa-gears',          'glow' => 'shadow-sky-500/40'],
    'pending_user' => ['label' => '待您回覆', 'badge' => 'bg-violet-50 text-violet-700 border-violet-200', 'dot' => 'bg-violet-500', 'strip' => 'bg-violet-500', 'icon' => 'fa-comment-dots',   'glow' => 'shadow-violet-500/40'],
    'resolved'     => ['label' => '已結案',   'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'dot' => 'bg-emerald-500', 'strip' => 'bg-emerald-500', 'icon' => 'fa-circle-check', 'glow' => 'shadow-emerald-500/40'],
    'closed'       => ['label' => '已關閉',   'badge' => 'bg-slate-100 text-slate-600 border-slate-300',   'dot' => 'bg-slate-400',   'strip' => 'bg-slate-400',   'icon' => 'fa-lock',           'glow' => 'shadow-slate-400/40'],
];
$SupportPriorities = [
    'low'    => ['label' => '低', 'badge' => 'bg-slate-100 text-slate-500 border-slate-200'],
    'normal' => ['label' => '一般', 'badge' => 'bg-slate-100 text-slate-600 border-slate-200'],
    'high'   => ['label' => '高', 'badge' => 'bg-orange-50 text-orange-700 border-orange-200'],
    'urgent' => ['label' => '緊急', 'badge' => 'bg-rose-50 text-rose-700 border-rose-200'],
];

function support_status_meta($s, $map) {
    return $map[$s] ?? ['label' => $s, 'badge' => 'bg-slate-100 text-slate-600 border-slate-200', 'dot' => 'bg-slate-400', 'strip' => 'bg-slate-400', 'icon' => 'fa-circle', 'glow' => 'shadow-slate-400/40'];
}
function support_priority_meta($p, $map) {
    return $map[$p] ?? ['label' => $p, 'badge' => 'bg-slate-100 text-slate-600 border-slate-200'];
}
function support_gen_ticket_no($Link) {
    do {
        $no = 'SW' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $stmt = $Link->prepare("SELECT `id` FROM `support_tickets` WHERE `ticket_no` = ? LIMIT 1");
        $stmt->bind_param("s", $no);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } while ($exists);
    return $no;
}
function support_log($Link, $ticketId, $action, $opType, $opId, $opName, $oldStatus, $newStatus, $oldPriority, $newPriority, $assignedTo, $note) {
    $stmt = $Link->prepare(
        "INSERT INTO `support_status_log`
            (`ticket_id`,`action`,`operator_type`,`operator_id`,`operator_name`,`old_status`,`new_status`,`old_priority`,`new_priority`,`assigned_to`,`note`)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param("ississsssss", $ticketId, $action, $opType, $opId, $opName, $oldStatus, $newStatus, $oldPriority, $newPriority, $assignedTo, $note);
    $stmt->execute();
    $stmt->close();
}
/**
 * 處理附件上傳；成功回傳 null，失敗回傳錯誤字串
 */
function support_handle_upload($fileKey, $ticketId, $messageId, $uploaderType, $uploaderId, $Link) {
    if (!isset($_FILES[$fileKey]) || !is_array($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$fileKey];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return '附件上傳失敗（錯誤碼 ' . (int)$f['error'] . '）。';
    }
    if ($f['size'] <= 0 || $f['size'] > 5 * 1024 * 1024) {
        return '附件大小必須介於 1 byte 至 5 MB 之間。';
    }
    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'application/zip' => 'zip', 'application/x-zip-compressed' => 'zip',
    ];
    $mime = '';
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$fi->file($f['tmp_name']);
    }
    if ($mime === '') {
        $mime = (string)($f['type'] ?? '');
    }
    if (!isset($allowed[$mime])) {
        return '不支援的附件格式（僅接受 JPG / PNG / GIF / WEBP / PDF / ZIP）。';
    }
    $ext = $allowed[$mime];
    $sub = 'uploads/support/' . date('Ym');
    $dir = __DIR__ . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return '無法建立附件儲存目錄。';
    }
    $stored = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
        return '附件寫入失敗，請稍後再試。';
    }
    $rel = $sub . '/' . $stored;
    $orig = mb_substr((string)$f['name'], 0, 180);
    $size = (int)$f['size'];
    $stmt = $Link->prepare(
        "INSERT INTO `support_attachments`
            (`ticket_id`,`message_id`,`uploader_type`,`uploader_id`,`file_name`,`stored_name`,`file_path`,`file_size`,`mime_type`)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param("iisisssis", $ticketId, $messageId, $uploaderType, $uploaderId, $orig, $stored, $rel, $size, $mime);
    $stmt->execute();
    $stmt->close();
    return null;
}

// ------------------------------------------------------------
// 處理表單送出（PRG）
// ------------------------------------------------------------
$notice = $_SESSION['support_notice'] ?? null;
unset($_SESSION['support_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['form_token']) || !hash_equals($_SESSION['support_token'], (string)$_POST['form_token'])) {
        $_SESSION['support_notice'] = ['type' => 'danger', 'msg' => '表單已失效，請重新操作。'];
        header("Location: support.php");
        exit();
    }
    $action = $_POST['action'] ?? '';

    // -------- 建立新回報 --------
    if ($action === 'create') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $subject    = trim((string)($_POST['subject'] ?? ''));
        $content    = trim((string)($_POST['content'] ?? ''));
        $serverZone = trim((string)($_POST['server_zone'] ?? ''));
        $roleName   = trim((string)($_POST['role_name'] ?? ''));
        $contactEmail  = trim((string)($_POST['contact_email'] ?? ''));
        $contactMobile = trim((string)($_POST['contact_mobile'] ?? ''));

        // 分類驗證
        $cat = null;
        $stmt = $Link->prepare("SELECT `id`,`code`,`name`,`default_priority` FROM `support_categories` WHERE `id` = ? AND `enabled` = 1 LIMIT 1");
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();
        $cat = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$cat) {
            $_SESSION['support_notice'] = ['type' => 'danger', 'msg' => '請選擇有效的回報分類。'];
            header("Location: support.php?view=new");
            exit();
        }
        if ($subject === '' || mb_strlen($subject) > 150) {
            $_SESSION['support_notice'] = ['type' => 'danger', 'msg' => '主旨為必填，且不可超過 150 字。'];
            header("Location: support.php?view=new");
            exit();
        }
        if ($content === '' || mb_strlen($content) < 10) {
            $_SESSION['support_notice'] = ['type' => 'danger', 'msg' => '申訴內容至少需 10 個字，請詳細描述問題。'];
            header("Location: support.php?view=new");
            exit();
        }
        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['support_notice'] = ['type' => 'danger', 'msg' => '聯絡信箱格式不正確。'];
            header("Location: support.php?view=new");
            exit();
        }
        $priority = $cat['default_priority'];
        $ticketNo = support_gen_ticket_no($Link);

        $stmt = $Link->prepare(
            "INSERT INTO `support_tickets`
                (`ticket_no`,`uid`,`username`,`category_id`,`category_code`,`subject`,`content`,
                 `server_zone`,`role_name`,`contact_email`,`contact_mobile`,`priority`,`status`,`last_reply_by`,`last_reply_at`)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'open', 'user', NOW())"
        );
        $stmt->bind_param(
            "sisissssssss",
            $ticketNo, $uid, $username, $categoryId, $cat['code'], $subject, $content,
            $serverZone, $roleName, $contactEmail, $contactMobile, $priority
        );
        $ok = $stmt->execute();
        $ticketId = $ok ? (int)$Link->insert_id : 0;
        $stmt->close();

        if (!$ticketId) {
            $_SESSION['support_notice'] = ['type' => 'danger', 'msg' => '回報建立失敗，請稍後再試。'];
            header("Location: support.php?view=new");
            exit();
        }

        // 將內容寫入對話訊息，方便後續追蹤
        $stmt = $Link->prepare(
            "INSERT INTO `support_messages` (`ticket_id`,`sender_type`,`sender_id`,`sender_name`,`content`,`is_internal`)
             VALUES (?, 'user', ?, ?, ?, 0)"
        );
        $stmt->bind_param("iiss", $ticketId, $uid, $username, $content);
        $stmt->execute();
        $messageId = (int)$Link->insert_id;
        $stmt->close();

        $upErr = support_handle_upload('attachment', $ticketId, $messageId, 'user', $uid, $Link);
        support_log($Link, $ticketId, 'create', 'user', $uid, $username, '', 'open', '', $priority, '', '會員建立回報');

        $_SESSION['support_notice'] = [
            'type' => $upErr ? 'warning' : 'success',
            'msg'  => '回報已送出，單號：' . $ticketNo . '。客服將盡快處理。' . ($upErr ? '（附件：' . $upErr . '）' : ''),
        ];
        header("Location: support.php?t=" . $ticketId);
        exit();
    }

    // -------- 會員回覆 --------
    if ($action === 'reply') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $content  = trim((string)($_POST['content'] ?? ''));

        $stmt = $Link->prepare("SELECT `id`,`ticket_no`,`status` FROM `support_tickets` WHERE `id` = ? AND `uid` = ? LIMIT 1");
        $stmt->bind_param("ii", $ticketId, $uid);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$ticket) {
            $_SESSION['support_notice'] = ['type' => 'danger', 'msg' => '找不到該回報單。'];
            header("Location: support.php");
            exit();
        }
        if ($content === '') {
            $_SESSION['support_notice'] = ['type' => 'danger', 'msg' => '請輸入回覆內容。'];
            header("Location: support.php?t=" . $ticketId);
            exit();
        }

        $stmt = $Link->prepare(
            "INSERT INTO `support_messages` (`ticket_id`,`sender_type`,`sender_id`,`sender_name`,`content`,`is_internal`)
             VALUES (?, 'user', ?, ?, ?, 0)"
        );
        $stmt->bind_param("iiss", $ticketId, $uid, $username, $content);
        $stmt->execute();
        $messageId = (int)$Link->insert_id;
        $stmt->close();

        $upErr = support_handle_upload('attachment', $ticketId, $messageId, 'user', $uid, $Link);

        $oldStatus = $ticket['status'];
        $newStatus = in_array($oldStatus, ['resolved', 'closed', 'pending_user'], true) ? 'open' : $oldStatus;
        $stmt = $Link->prepare(
            "UPDATE `support_tickets`
                SET `reply_count` = `reply_count` + 1, `last_reply_by` = 'user', `last_reply_at` = NOW(),
                    `status` = ?, `resolved_at` = CASE WHEN ? = 'resolved' THEN `resolved_at` ELSE NULL END,
                    `closed_at` = CASE WHEN ? = 'closed' THEN `closed_at` ELSE NULL END
              WHERE `id` = ? AND `uid` = ?"
        );
        $stmt->bind_param("sssii", $newStatus, $newStatus, $newStatus, $ticketId, $uid);
        $stmt->execute();
        $stmt->close();

        support_log($Link, $ticketId, 'reply', 'user', $uid, $username, $oldStatus, $newStatus, '', '', '', '會員回覆');
        $_SESSION['support_notice'] = ['type' => $upErr ? 'warning' : 'success', 'msg' => '回覆已送出。' . ($upErr ? '（附件：' . $upErr . '）' : '')];
        header("Location: support.php?t=" . $ticketId);
        exit();
    }

    // -------- 會員關閉回報 --------
    if ($action === 'close') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $stmt = $Link->prepare("SELECT `id`,`status` FROM `support_tickets` WHERE `id` = ? AND `uid` = ? LIMIT 1");
        $stmt->bind_param("ii", $ticketId, $uid);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($ticket && !in_array($ticket['status'], ['closed'], true)) {
            $stmt = $Link->prepare("UPDATE `support_tickets` SET `status` = 'closed', `closed_at` = NOW() WHERE `id` = ? AND `uid` = ?");
            $stmt->bind_param("ii", $ticketId, $uid);
            $stmt->execute();
            $stmt->close();
            support_log($Link, $ticketId, 'close', 'user', $uid, $username, $ticket['status'], 'closed', '', '', '', '會員自行關閉');
            $_SESSION['support_notice'] = ['type' => 'success', 'msg' => '回報單已關閉。'];
        } else {
            $_SESSION['support_notice'] = ['type' => 'warning', 'msg' => '此回報單無法關閉。'];
        }
        header("Location: support.php?t=" . $ticketId);
        exit();
    }

    $_SESSION['support_notice'] = ['type' => 'danger', 'msg' => '未知的操作。'];
    header("Location: support.php");
    exit();
}

// ------------------------------------------------------------
// 讀取分類
// ------------------------------------------------------------
$categories = [];
$res = mysqli_query($Link, "SELECT `id`,`code`,`name`,`icon`,`description`,`default_priority` FROM `support_categories` WHERE `enabled` = 1 ORDER BY `sort_order` ASC, `id` ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
}

// ------------------------------------------------------------
// 檢視模式：list / new / detail
// ------------------------------------------------------------
$view = $_GET['view'] ?? 'list';
$ticketId = (int)($_GET['t'] ?? 0);
if ($ticketId > 0) {
    $view = 'detail';
}
if (!in_array($view, ['list', 'new', 'detail'], true)) {
    $view = 'list';
}
$preselectCategory = (int)($_GET['category'] ?? 0);

// -------- 統計 --------
$stats = ['total' => 0, 'open' => 0, 'processing' => 0, 'pending_user' => 0, 'resolved' => 0, 'closed' => 0];
$stmt = $Link->prepare(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN `status` IN ('open','processing','pending_user') THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN `status` = 'resolved' THEN 1 ELSE 0 END) AS resolved,
            SUM(CASE WHEN `status` = 'closed' THEN 1 ELSE 0 END) AS closed
     FROM `support_tickets` WHERE `uid` = ?"
);
$stmt->bind_param("i", $uid);
$stmt->execute();
$s = $stmt->get_result()->fetch_assoc();
$stmt->close();
$stats['total']    = (int)($s['total'] ?? 0);
$stats['active']   = (int)($s['active'] ?? 0);
$stats['resolved'] = (int)($s['resolved'] ?? 0);
$stats['closed']   = (int)($s['closed'] ?? 0);

$tickets = [];
$filter = $_GET['status'] ?? 'all';
$allowedFilters = ['all', 'open', 'processing', 'pending_user', 'resolved', 'closed'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}
$keyword = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;
$offset = ($page - 1) * $perPage;
$totalRows = 0;
$totalPages = 0;

$where = "`uid` = ?";
$types = "i";
$params = [$uid];
if ($filter !== 'all') {
    $where .= " AND `status` = ?";
    $types .= "s";
    $params[] = $filter;
}
if ($keyword !== '') {
    $where .= " AND (`ticket_no` LIKE ? OR `subject` LIKE ?)";
    $like = '%' . $keyword . '%';
    $types .= "ss";
    $params[] = $like;
    $params[] = $like;
}

$stmt = $Link->prepare("SELECT COUNT(*) AS c FROM `support_tickets` WHERE $where");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$totalRows = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();
$totalPages = $totalRows > 0 ? (int)ceil($totalRows / $perPage) : 0;
if ($totalPages > 0 && $page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

if ($view === 'list') {
    $sql = "SELECT t.`id`,t.`ticket_no`,t.`subject`,t.`category_id`,t.`category_code`,t.`priority`,t.`status`,
                   t.`reply_count`,t.`last_reply_by`,t.`last_reply_at`,t.`created_at`,t.`updated_at`, c.`name` AS category_name, c.`icon` AS category_icon
            FROM `support_tickets` t
            LEFT JOIN `support_categories` c ON c.`id` = t.`category_id`
            WHERE $where
            ORDER BY t.`id` DESC
            LIMIT ? OFFSET ?";
    $stmt = $Link->prepare($sql);
    $lp = $params;
    $lt = $types . "ii";
    $lp[] = $perPage;
    $lp[] = $offset;
    $stmt->bind_param($lt, ...$lp);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $tickets[] = $row;
    }
    $stmt->close();
}

// -------- 詳細資料 --------
$ticket = null;
$timeline = [];
$attachmentsByMessage = [];
if ($view === 'detail' && $ticketId > 0) {
    $stmt = $Link->prepare(
        "SELECT t.*, c.`name` AS category_name, c.`icon` AS category_icon
         FROM `support_tickets` t
         LEFT JOIN `support_categories` c ON c.`id` = t.`category_id`
         WHERE t.`id` = ? AND t.`uid` = ? LIMIT 1"
    );
    $stmt->bind_param("ii", $ticketId, $uid);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ticket) {
        $_SESSION['support_notice'] = ['type' => 'danger', 'msg' => '找不到該回報單，或您無權檢視。'];
        header("Location: support.php");
        exit();
    }

    // 對話訊息（不含內部備註）
    $stmt = $Link->prepare(
        "SELECT `id`,`sender_type`,`sender_name`,`content`,`created_at` FROM `support_messages`
         WHERE `ticket_id` = ? AND `is_internal` = 0 ORDER BY `id` ASC"
    );
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $mr = $stmt->get_result();
    while ($row = $mr->fetch_assoc()) {
        $timeline[] = [
            'kind' => 'message',
            'sender_type' => $row['sender_type'],
            'sender_name' => $row['sender_name'],
            'content' => $row['content'],
            'created_at' => $row['created_at'],
            'id' => (int)$row['id'],
        ];
        $attachmentsByMessage[(int)$row['id']] = [];
    }
    $stmt->close();

    // 狀態 / 處理歷程（僅顯示狀態變化，不含內部備註）
    $stmt = $Link->prepare(
        "SELECT `action`,`operator_type`,`operator_name`,`old_status`,`new_status`,`created_at`
         FROM `support_status_log`
         WHERE `ticket_id` = ? AND `action` IN ('status','close','priority','assign')
         ORDER BY `id` ASC"
    );
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $lr = $stmt->get_result();
    while ($row = $lr->fetch_assoc()) {
        $timeline[] = ['kind' => 'log'] + $row;
    }
    $stmt->close();

    usort($timeline, function ($a, $b) {
        $ta = strtotime($a['created_at']);
        $tb = strtotime($b['created_at']);
        if ($ta === $tb) {
            return 0;
        }
        return $ta <=> $tb;
    });

    // 附件
    $stmt = $Link->prepare("SELECT `message_id`,`file_name`,`file_path`,`file_size`,`mime_type` FROM `support_attachments` WHERE `ticket_id` = ? ORDER BY `id` ASC");
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $ar = $stmt->get_result();
    while ($row = $ar->fetch_assoc()) {
        $mid = (int)$row['message_id'];
        if (!isset($attachmentsByMessage[$mid])) {
            $attachmentsByMessage[$mid] = [];
        }
        $attachmentsByMessage[$mid][] = $row;
    }
    $stmt->close();
}

$mainAttachments = $attachmentsByMessage[0] ?? [];
mysqli_close($Link);

$statusMeta = $SupportStatuses;
$priorityMeta = $SupportPriorities;
$noticeType = is_array($notice) ? ($notice['type'] ?? 'info') : 'info';
$noticeMsg  = is_array($notice) ? ($notice['msg'] ?? '') : '';
function support_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>踏雪笑傲 · 申訴回報中心</title>
<link rel="stylesheet" href="assets/tailwind.css">
<!-- 本頁專用：補齊 Tailwind 工具類（依 support.php 內容產生，避免舊版精簡檔缺少樣式） -->
<link rel="stylesheet" href="assets/support-tailwind.css">
<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Noto+Serif+TC:wght@400;500;600;700;900&family=Noto+Sans+TC:wght@300;400;500;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root { --vermilion:#be123c; --ice-blue:#0284c7; --ink-deep:#080c14; }
    body {
        background-color: var(--ink-deep);
        color:#1e293b;
        font-family:'Noto Sans TC', sans-serif;
        min-height:100vh;
        overflow-x:hidden;
        background-image:
            radial-gradient(ellipse at 50% 0%, rgba(30,41,59,.7) 0%, rgba(8,12,20,.98) 75%),
            radial-gradient(circle at 80% 20%, rgba(2,132,199,.08) 0%, transparent 40%),
            radial-gradient(circle at 15% 75%, rgba(190,18,60,.05) 0%, transparent 45%);
    }
    #snow-canvas { position:fixed; top:0; left:0; width:100vw; height:100vh; pointer-events:none; z-index:1; transform:translateZ(0); backface-visibility:hidden; }
    .snow-card {
        background:rgba(255,255,255,.94);
        backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px);
        border:1px solid rgba(226,232,240,.9);
        box-shadow:0 20px 45px -12px rgba(0,0,0,.35), 0 0 0 1px rgba(226,232,240,.6);
        position:relative;
    }
    .snow-card-interactive { transition:all .32s cubic-bezier(.34,1.56,.64,1); }
    .snow-card-interactive:hover { transform:translateY(-4px); box-shadow:0 28px 55px -12px rgba(0,0,0,.42), 0 0 0 1px rgba(255,255,255,.95); }
    .ornament-tl { position:absolute; top:-1px; left:-1px; width:14px; height:14px; border-top:2.5px solid var(--vermilion); border-left:2.5px solid var(--vermilion); pointer-events:none; border-top-left-radius:4px; }
    .ornament-br { position:absolute; bottom:-1px; right:-1px; width:14px; height:14px; border-bottom:2.5px solid var(--ice-blue); border-right:2.5px solid var(--ice-blue); pointer-events:none; border-bottom-right-radius:4px; }
    .seal-tag { background-color:#fff1f2; color:#be123c; border:1px solid #fecdd3; font-weight:600; }
    .support-input { width:100%; border:1px solid #cbd5e1; border-radius:.75rem; padding:.65rem .85rem; font-size:.85rem; color:#1e293b; background:#fff; transition:all .2s; }
    .support-input:focus { outline:none; border-color:#0284c7; box-shadow:0 0 0 3px rgba(2,132,199,.15); }
    .support-label { display:block; font-size:.75rem; font-weight:600; color:#475569; margin-bottom:.35rem; }
    .filter-chip { transition:all .2s ease; }
    .filter-chip.active { background:linear-gradient(135deg,#0f172a,#334155); color:#fff; border-color:transparent; }
    /* ---------- 進場動畫 ---------- */
    @keyframes fadeInUp { from { opacity:0; transform:translateY(16px);} to { opacity:1; transform:none;} }
    .anim-up { animation:fadeInUp .55s cubic-bezier(.22,1,.36,1) both; }
    .anim-d1 { animation-delay:.06s; } .anim-d2 { animation-delay:.12s; } .anim-d3 { animation-delay:.18s; } .anim-d4 { animation-delay:.24s; }
    /* ---------- 醒目主按鈕（流光） ---------- */
    .btn-glow {
        position:relative; overflow:hidden; color:#fff; font-weight:700;
        background:linear-gradient(135deg,#fb7185 0%,#e11d48 42%,#9f1239 100%);
        box-shadow:0 14px 34px -8px rgba(190,18,60,.65), inset 0 1px 0 rgba(255,255,255,.3);
        transition:transform .3s cubic-bezier(.34,1.56,.64,1), box-shadow .3s;
    }
    .btn-glow:hover { transform:translateY(-2px) scale(1.02); box-shadow:0 20px 46px -10px rgba(190,18,60,.8), inset 0 1px 0 rgba(255,255,255,.4); }
    .btn-glow:active { transform:translateY(0) scale(.99); }
    .btn-glow::after {
        content:''; position:absolute; top:0; left:-130%; width:55%; height:100%;
        background:linear-gradient(100deg,transparent,rgba(255,255,255,.5),transparent);
        transform:skewX(-20deg); animation:shine 3.4s ease-in-out infinite;
    }
    @keyframes shine { 0% { left:-130%; } 55%,100% { left:150%; } }
    /* ---------- 浮動新增回報按鈕 ---------- */
    .quick-fab-wrap { position:fixed; right:1.5rem; bottom:1.5rem; z-index:60; }
    .quick-fab {
        position:relative; width:64px; height:64px; border-radius:9999px; border:none; cursor:pointer; color:#fff;
        display:flex; align-items:center; justify-content:center; font-size:1.45rem;
        background:linear-gradient(135deg,#fb7185 0%,#e11d48 45%,#9f1239 100%);
        box-shadow:0 16px 38px -8px rgba(190,18,60,.75), inset 0 1px 0 rgba(255,255,255,.35);
        transition:transform .38s cubic-bezier(.34,1.56,.64,1), box-shadow .3s;
    }
    .quick-fab:hover { transform:scale(1.08); box-shadow:0 22px 50px -10px rgba(190,18,60,.9), inset 0 1px 0 rgba(255,255,255,.45); }
    .quick-fab .fab-icon { transition:transform .4s cubic-bezier(.34,1.56,.64,1); }
    .quick-fab.open .fab-icon { transform:rotate(135deg); }
    .quick-fab-ring { position:absolute; inset:0; border-radius:9999px; border:2px solid rgba(251,113,133,.65); animation:ringPulse 2.4s ease-out infinite; pointer-events:none; }
    @keyframes ringPulse { 0% { transform:scale(1); opacity:.85; } 100% { transform:scale(1.85); opacity:0; } }
    .quick-fab-label {
        position:absolute; right:78px; top:50%; transform:translateY(-50%) translateX(10px);
        background:rgba(12,16,26,.95); color:#fff; border:1px solid rgba(244,63,94,.45);
        padding:.45rem .85rem; border-radius:.7rem; font-size:.75rem; font-weight:600; white-space:nowrap;
        opacity:0; pointer-events:none; transition:all .28s ease; box-shadow:0 10px 26px -8px rgba(0,0,0,.6);
    }
    .quick-fab-wrap:hover .quick-fab-label { opacity:1; transform:translateY(-50%) translateX(0); }
    /* ---------- 快速新增選單 ---------- */
    .quick-backdrop { position:fixed; inset:0; z-index:55; background:rgba(4,7,13,.55); backdrop-filter:blur(3px); -webkit-backdrop-filter:blur(3px); opacity:0; pointer-events:none; transition:opacity .3s ease; }
    .quick-backdrop.open { opacity:1; pointer-events:auto; }
    .quick-menu {
        position:absolute; right:0; bottom:80px; width:360px; max-width:calc(100vw - 2rem);
        background:rgba(255,255,255,.98); border:1px solid rgba(226,232,240,.95); border-radius:1.35rem;
        box-shadow:0 34px 80px -22px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.55);
        padding:1.1rem; opacity:0; transform:translateY(18px) scale(.95); transform-origin:bottom right;
        pointer-events:none; transition:all .34s cubic-bezier(.34,1.56,.64,1);
    }
    .quick-menu.open { opacity:1; transform:translateY(0) scale(1); pointer-events:auto; }
    .quick-cat {
        display:flex; flex-direction:column; align-items:center; justify-content:center; gap:.4rem;
        padding:.7rem .35rem; border-radius:.9rem; border:1px solid #e2e8f0; background:#fff;
        text-decoration:none; transition:all .2s ease;
    }
    .quick-cat:hover { border-color:#0284c7; background:#f0f9ff; transform:translateY(-3px); box-shadow:0 10px 22px -12px rgba(2,132,199,.6); }
    .quick-cat .qc-icon { width:40px; height:40px; border-radius:.75rem; display:flex; align-items:center; justify-content:center; font-size:1.05rem; background:linear-gradient(135deg,#e0f2fe,#bae6fd); color:#0369a1; transition:transform .25s; }
    .quick-cat:hover .qc-icon { transform:scale(1.08) rotate(-4deg); }
    .quick-cat .qc-name { font-size:.7rem; font-weight:600; color:#475569; text-align:center; line-height:1.15; }
    /* ---------- 回報卡片 ---------- */
    .ticket-card { overflow:hidden; }
    .ticket-card .ticket-strip { position:absolute; left:0; top:0; bottom:0; width:5px; border-radius:0; }
    .ticket-bell { animation:bellPulse 1.8s ease-in-out infinite; }
    @keyframes bellPulse { 0%,100% { opacity:1; transform:scale(1);} 50% { opacity:.55; transform:scale(1.18);} }
    .stat-tile { transition:transform .3s cubic-bezier(.34,1.56,.64,1), box-shadow .3s; }
    .stat-tile:hover { transform:translateY(-4px); box-shadow:0 16px 34px -16px rgba(0,0,0,.4); }
    /* ---------- 表單區塊 ---------- */
    .form-section { border:1px solid #e2e8f0; border-radius:1rem; padding:1.1rem 1.15rem; background:linear-gradient(180deg,#ffffff, #f8fafc); }
    .form-section-title { display:flex; align-items:center; gap:.5rem; font-size:.8rem; font-weight:700; color:#334155; margin-bottom:.85rem; }
    .form-section-title .num { width:20px; height:20px; border-radius:9999px; background:linear-gradient(135deg,#e11d48,#9f1239); color:#fff; font-size:.68rem; display:flex; align-items:center; justify-content:center; }
    .cat-card { position:relative; overflow:hidden; }
    .cat-card .cat-ico { transition:transform .3s; }
    .cat-card:hover .cat-ico { transform:scale(1.12) rotate(-5deg); }
    @media (max-width:640px) {
        .quick-fab-wrap { right:1rem; bottom:1rem; }
        .quick-fab { width:58px; height:58px; font-size:1.3rem; }
        .quick-menu { bottom:72px; }
    }
    ::-webkit-scrollbar { width:6px; height:6px; }
    ::-webkit-scrollbar-track { background:#0f172a; }
    ::-webkit-scrollbar-thumb { background:#334155; border-radius:3px; }
</style>
</head>
<body class="relative flex flex-col justify-between selection:bg-rose-900 selection:text-white min-h-screen">
<canvas id="snow-canvas"></canvas>
<div class="fixed top-0 left-1/2 -translate-x-1/2 w-[800px] h-[280px] bg-sky-500/10 rounded-full blur-[140px] pointer-events-none -z-0"></div>

<header class="relative z-20 border-b border-slate-800/80 bg-[#0c101a]/90 backdrop-blur-md sticky top-0 shadow-lg">
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
<div class="flex items-center justify-between h-20">
<a class="group flex items-center space-x-3.5 no-underline" href="home.php">
<div class="w-11 h-11 rounded-xl bg-white p-1 shadow-md border border-slate-200 flex items-center justify-center overflow-hidden transition-transform group-hover:scale-105">
<img alt="踏雪笑傲 Logo" class="w-full h-full object-contain" src="assets/logo.svg">
</div>
<div>
<div class="flex items-center space-x-2">
<span class="text-2xl font-bold tracking-wider text-slate-100 font-serif">踏雪笑傲</span>
<span class="seal-tag text-[11px] px-1.5 py-0.5 rounded tracking-normal">申訴回報</span>
</div>
<p class="text-[11px] text-slate-400 font-mono tracking-widest uppercase">Snow Wanderer Online</p>
</div>
</a>
<nav class="hidden md:flex items-center space-x-2">
<a class="px-3.5 py-2 text-sm text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg transition-colors flex items-center space-x-2" href="home.php">
<i class="fa-solid fa-house-chimney text-xs text-slate-400"></i><span>帳號總覽</span>
</a>
<a class="px-3.5 py-2 text-sm text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg transition-colors flex items-center space-x-2" href="usercontrol.php">
<i class="fa-regular fa-address-card text-xs text-slate-400"></i><span>帳號安全</span>
</a>
<a class="px-3.5 py-2 text-sm text-sky-400 font-semibold bg-sky-950/40 border border-sky-800/60 rounded-lg flex items-center space-x-2 transition-all shadow-sm" href="support.php">
<i class="fa-solid fa-headset text-xs"></i><span>申訴回報</span>
</a>
<div class="pl-4 ml-2 border-l border-slate-800 flex items-center space-x-3">
<div class="flex items-center space-x-2.5 bg-slate-900/90 border border-slate-700/70 px-3.5 py-1.5 rounded-full">
<div class="w-6 h-6 rounded-full bg-gradient-to-tr from-rose-700 to-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-sm">俠</div>
<div class="text-left">
<p class="text-xs font-semibold text-slate-200 leading-tight max-w-[120px] truncate"><?= support_h($username) ?></p>
<span class="text-[10px] text-emerald-400 flex items-center gap-1 font-mono"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>已登入</span>
</div>
</div>
<button class="px-3 py-1.5 rounded-lg border border-rose-800/50 bg-rose-950/30 text-rose-300 hover:bg-rose-900/50 hover:text-white text-xs font-medium transition-colors flex items-center gap-1.5 shadow-sm" onclick="openLogoutModal()" title="登出系統">
<i class="fa-solid fa-arrow-right-from-bracket text-xs"></i><span>登出</span>
</button>
</div>
</nav>
<div class="flex md:hidden items-center">
<button class="p-2 text-slate-300 hover:text-white rounded-lg focus:outline-none bg-slate-800/60" id="mobile-toggle"><i class="fa-solid fa-bars text-lg"></i></button>
</div>
</div>
</div>
<div class="hidden md:hidden border-t border-slate-800 bg-[#0c101a] px-5 py-4 space-y-2" id="mobile-menu">
<div class="pb-3 border-b border-slate-800 flex items-center justify-between">
<span class="text-xs text-slate-400">當前登入帳號：</span>
<span class="text-xs text-sky-400 font-bold"><?= support_h($username) ?></span>
</div>
<a class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60" href="home.php"><i class="fa-solid fa-house-chimney mr-2 text-slate-400"></i> 帳號總覽</a>
<a class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60" href="usercontrol.php"><i class="fa-regular fa-address-card mr-2 text-slate-400"></i> 帳號安全</a>
<a class="block px-3 py-2 rounded-lg text-sm text-sky-400 bg-sky-950/40 font-medium" href="support.php"><i class="fa-solid fa-headset mr-2"></i> 申訴回報</a>
<button class="w-full text-left px-3 py-2 rounded-lg text-sm text-rose-400 hover:bg-rose-950/40" onclick="openLogoutModal()"><i class="fa-solid fa-arrow-right-from-bracket mr-2"></i> 安全登出</button>
</div>
</header>

<main class="relative z-10 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10 flex-1 w-full space-y-8">

<?php if ($noticeMsg !== ''): ?>
<div class="snow-card rounded-xl px-5 py-4 flex items-start gap-3 border-l-4
    <?= $noticeType === 'success' ? 'border-l-emerald-500' : ($noticeType === 'warning' ? 'border-l-amber-500' : 'border-l-rose-500') ?>">
<?php if ($noticeType === 'success'): ?><i class="fa-solid fa-circle-check text-emerald-600 mt-0.5"></i>
<?php elseif ($noticeType === 'warning'): ?><i class="fa-solid fa-triangle-exclamation text-amber-600 mt-0.5"></i>
<?php else: ?><i class="fa-solid fa-circle-exclamation text-rose-600 mt-0.5"></i><?php endif; ?>
<p class="text-sm text-slate-700"><?= support_h($noticeMsg) ?></p>
</div>
<?php endif; ?>

<?php if ($view === 'detail' && $ticket):
    $sm = support_status_meta($ticket['status'], $statusMeta);
    $pm = support_priority_meta($ticket['priority'], $priorityMeta);
    $canReply = !in_array($ticket['status'], ['closed'], true);
?>
<!-- ============ 回報單詳細 ============ -->
<div class="flex items-center justify-between flex-wrap gap-3">
<a href="support.php" class="text-sm text-slate-300 hover:text-white flex items-center gap-2 no-underline">
<i class="fa-solid fa-arrow-left"></i> 返回回報列表
</a>
<?php if ($canReply): ?>
<form method="POST" onsubmit="return confirm('確定要關閉此回報單嗎？關閉後將無法再回覆。');">
<input type="hidden" name="form_token" value="<?= support_h($formToken) ?>">
<input type="hidden" name="action" value="close">
<input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
<button type="submit" class="px-3.5 py-2 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-600 text-xs font-semibold flex items-center gap-2 transition-colors">
<i class="fa-solid fa-lock"></i> 關閉回報單
</button>
</form>
<?php endif; ?>
</div>

<div class="snow-card rounded-2xl p-6 sm:p-8">
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="flex items-start justify-between gap-4 flex-wrap pb-5 border-b border-slate-200">
<div class="space-y-2">
<div class="flex items-center gap-2 flex-wrap">
<span class="seal-tag text-xs px-2.5 py-0.5 rounded font-mono"><?= support_h($ticket['ticket_no']) ?></span>
<span class="text-xs px-2.5 py-0.5 rounded border font-medium <?= support_h($sm['badge']) ?>"><?= support_h($sm['label']) ?></span>
<span class="text-xs px-2.5 py-0.5 rounded border font-medium <?= support_h($pm['badge']) ?>">優先度：<?= support_h($pm['label']) ?></span>
</div>
<h1 class="text-xl sm:text-2xl font-bold text-slate-800 font-serif"><?= support_h($ticket['subject']) ?></h1>
<div class="flex items-center gap-4 flex-wrap text-xs text-slate-500">
<span><i class="fa-solid fa-tag mr-1"></i><?= support_h($ticket['category_name'] ?: $ticket['category_code']) ?></span>
<span><i class="fa-regular fa-clock mr-1"></i>建立於 <?= support_h($ticket['created_at']) ?></span>
<?php if (!empty($ticket['handler'])): ?><span><i class="fa-solid fa-user-shield mr-1"></i>受理 GM：<?= support_h($ticket['handler']) ?></span><?php endif; ?>
</div>
</div>
</div>

<!-- 原單資訊 -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 py-5">
<div class="p-3 rounded-xl bg-slate-50 border border-slate-200"><span class="text-[11px] text-slate-500 block mb-1">伺服器 / 區域</span><span class="text-sm font-semibold text-slate-700"><?= support_h($ticket['server_zone'] ?: '未提供') ?></span></div>
<div class="p-3 rounded-xl bg-slate-50 border border-slate-200"><span class="text-[11px] text-slate-500 block mb-1">角色名稱</span><span class="text-sm font-semibold text-slate-700"><?= support_h($ticket['role_name'] ?: '未提供') ?></span></div>
<div class="p-3 rounded-xl bg-slate-50 border border-slate-200"><span class="text-[11px] text-slate-500 block mb-1">聯絡信箱</span><span class="text-sm font-semibold text-slate-700 break-all"><?= support_h($ticket['contact_email'] ?: '未提供') ?></span></div>
<div class="p-3 rounded-xl bg-slate-50 border border-slate-200"><span class="text-[11px] text-slate-500 block mb-1">聯絡手機</span><span class="text-sm font-semibold text-slate-700"><?= support_h($ticket['contact_mobile'] ?: '未提供') ?></span></div>
</div>

<?php if (!empty($ticket['verdict'])): ?>
<div class="mb-5 p-4 rounded-xl bg-emerald-50 border border-emerald-200">
<span class="text-xs font-bold text-emerald-700 block mb-1"><i class="fa-solid fa-gavel mr-1"></i>處理結論</span>
<p class="text-sm text-emerald-900 leading-relaxed"><?= nl2br(support_h($ticket['verdict'])) ?></p>
</div>
<?php endif; ?>

<!-- 對話時間軸 -->
<div class="space-y-4 pt-2">
<h3 class="text-sm font-bold text-slate-700 flex items-center gap-2"><i class="fa-regular fa-comments text-slate-400"></i>處理歷程與對話</h3>
<?php if (empty($timeline)): ?>
<p class="text-sm text-slate-400">尚無對話紀錄。</p>
<?php endif; ?>
<?php foreach ($timeline as $ev): ?>
<?php if ($ev['kind'] === 'log'): ?>
<div class="flex justify-center">
<span class="text-[11px] text-slate-400 bg-slate-100 border border-slate-200 rounded-full px-3 py-1">
<?php
    $logText = '';
    if ($ev['action'] === 'status') {
        $logText = '狀態變更為「' . support_status_meta($ev['new_status'], $statusMeta)['label'] . '」';
    } elseif ($ev['action'] === 'close') {
        $logText = '回報單已關閉';
    } elseif ($ev['action'] === 'priority') {
        $logText = '優先度已調整';
    } elseif ($ev['action'] === 'assign') {
        $logText = '已指派受理人員';
    }
?>
<?= support_h($logText) ?> · <?= support_h(date('Y-m-d H:i', strtotime($ev['created_at']))) ?>
</span>
</div>
<?php else: ?>
<?php $isUser = ($ev['sender_type'] === 'user'); ?>
<div class="flex <?= $isUser ? 'justify-end' : 'justify-start' ?>">
<div class="max-w-[85%] sm:max-w-[75%] rounded-2xl px-4 py-3 <?= $isUser ? 'bg-sky-50 border border-sky-200' : 'bg-rose-50 border border-rose-200' ?>">
<div class="flex items-center gap-2 mb-1.5">
<i class="fa-solid <?= $isUser ? 'fa-user text-sky-600' : 'fa-user-shield text-rose-600' ?> text-xs"></i>
<span class="text-xs font-bold <?= $isUser ? 'text-sky-700' : 'text-rose-700' ?>"><?= $isUser ? '我' : support_h($ev['sender_name'] ?: '客服人員') ?></span>
<span class="text-[10px] text-slate-400"><?= support_h(date('Y-m-d H:i', strtotime($ev['created_at']))) ?></span>
</div>
<p class="text-sm text-slate-700 leading-relaxed whitespace-pre-wrap"><?= support_h($ev['content']) ?></p>
<?php if (!empty($attachmentsByMessage[$ev['id']])): ?>
<div class="mt-2 flex flex-wrap gap-2">
<?php foreach ($attachmentsByMessage[$ev['id']] as $att): ?>
<a href="<?= support_h($att['file_path']) ?>" target="_blank" class="text-[11px] bg-white border border-slate-200 rounded-lg px-2 py-1 text-slate-600 hover:border-sky-400 hover:text-sky-700 no-underline flex items-center gap-1.5">
<i class="fa-solid fa-paperclip"></i><?= support_h($att['file_name']) ?>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
<?php endforeach; ?>
</div>

<?php if ($canReply): ?>
<form method="POST" enctype="multipart/form-data" class="mt-6 pt-5 border-t border-slate-200 space-y-3">
<input type="hidden" name="form_token" value="<?= support_h($formToken) ?>">
<input type="hidden" name="action" value="reply">
<input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
<label class="support-label">追加回覆</label>
<textarea name="content" rows="4" class="support-input" placeholder="請補充說明或回覆客服問題…" required></textarea>
<div class="flex items-center justify-between flex-wrap gap-3">
<label class="text-xs text-slate-500 flex items-center gap-2 cursor-pointer">
<i class="fa-solid fa-paperclip"></i>
<span>附加檔案（可選，5MB 內）</span>
<input type="file" name="attachment" class="text-xs">
</label>
<button type="submit" class="btn-vermilion px-5 py-2.5 rounded-xl text-sm font-semibold text-white flex items-center gap-2">
<i class="fa-solid fa-paper-plane"></i> 送出回覆
</button>
</div>
</form>
<?php else: ?>
<div class="mt-6 pt-5 border-t border-slate-200 text-center">
<p class="text-sm text-slate-500"><i class="fa-solid fa-lock mr-1"></i>此回報單已關閉。如需再次協助，請<a href="support.php?view=new" class="text-sky-700 font-semibold hover:underline">建立新的回報單</a>。</p>
</div>
<?php endif; ?>
</div>

<?php elseif ($view === 'new'): ?>
<!-- ============ 新增回報 ============ -->
<?php $catColors = ['from-sky-500 to-cyan-400','from-rose-500 to-pink-400','from-amber-500 to-orange-400','from-emerald-500 to-teal-400','from-violet-500 to-purple-400','from-indigo-500 to-blue-400','from-lime-500 to-green-400','from-fuchsia-500 to-pink-400']; ?>
<div class="flex items-center justify-between flex-wrap gap-3 anim-up">
<div class="flex items-center gap-3">
<a href="support.php" class="w-10 h-10 rounded-xl bg-white/10 border border-slate-700 text-slate-300 hover:text-white hover:bg-white/20 flex items-center justify-center no-underline transition-colors"><i class="fa-solid fa-arrow-left"></i></a>
<div>
<h1 class="text-xl sm:text-2xl font-bold text-slate-100 font-serif">填寫申訴回報</h1>
<p class="text-xs text-slate-400 mt-1">請盡可能提供完整資訊，以利客服加速處理。</p>
</div>
</div>
<span class="hidden sm:flex items-center gap-2 text-xs text-slate-300 bg-slate-800/70 border border-slate-700 rounded-full px-3 py-1.5"><i class="fa-solid fa-shield-halved text-emerald-400"></i>加密安全傳輸</span>
</div>

<form method="POST" enctype="multipart/form-data" class="snow-card rounded-2xl p-5 sm:p-8 space-y-6 anim-up anim-d1">
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<input type="hidden" name="form_token" value="<?= support_h($formToken) ?>">
<input type="hidden" name="action" value="create">

<div class="form-section">
<div class="form-section-title"><span class="num">1</span>選擇回報分類 <span class="text-rose-600">*</span></div>
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
<?php foreach ($categories as $i => $cat):
    $checked = ($preselectCategory > 0) ? ((int)$cat['id'] === $preselectCategory) : ($i === 0);
    $grad = $catColors[$i % count($catColors)];
?>
<label class="relative cursor-pointer">
<input type="radio" name="category_id" value="<?= (int)$cat['id'] ?>" class="peer sr-only" <?= $checked ? 'checked' : '' ?>>
<div class="cat-card rounded-xl border-2 border-slate-200 bg-white p-3 text-center transition-all duration-200 peer-checked:border-rose-500 peer-checked:bg-rose-50 peer-checked:shadow-lg peer-checked:shadow-rose-500/20 hover:border-rose-300 hover:-translate-y-0.5">
<span class="cat-ico w-11 h-11 mx-auto rounded-xl flex items-center justify-center text-lg text-white bg-gradient-to-br <?= support_h($grad) ?> shadow-sm">
<i class="fa-solid <?= support_h($cat['icon'] ?: 'fa-circle-question') ?>"></i>
</span>
<p class="text-xs font-semibold text-slate-700 mt-2"><?= support_h($cat['name']) ?></p>
<p class="text-[10px] text-slate-400 mt-0.5 leading-tight line-clamp-2"><?= support_h($cat['description']) ?></p>
</div>
<span class="pointer-events-none absolute top-1.5 right-1.5 w-5 h-5 rounded-full bg-rose-600 text-white flex items-center justify-center text-[9px] opacity-0 scale-50 transition-all duration-200 peer-checked:opacity-100 peer-checked:scale-100 shadow"><i class="fa-solid fa-check"></i></span>
</label>
<?php endforeach; ?>
</div>
</div>

<div class="form-section">
<div class="form-section-title"><span class="num">2</span>回報資訊</div>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
<div>
<label class="support-label">回報主旨 <span class="text-rose-600">*</span></label>
<input type="text" name="subject" maxlength="150" class="support-input" placeholder="請簡短描述問題" required>
</div>
<div>
<label class="support-label">伺服器 / 區域</label>
<input type="text" name="server_zone" maxlength="60" class="support-input" placeholder="例：S1 · 傲雪">
</div>
<div class="sm:col-span-2">
<label class="support-label">角色名稱</label>
<input type="text" name="role_name" maxlength="50" class="support-input" placeholder="例：踏雪無痕">
</div>
</div>
</div>

<div class="form-section">
<div class="form-section-title"><span class="num">3</span>聯絡方式 <span class="text-[10px] font-normal text-slate-400">（選填，方便客服回覆通知）</span></div>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
<div>
<label class="support-label"><i class="fa-regular fa-envelope mr-1 text-slate-400"></i>聯絡信箱</label>
<input type="email" name="contact_email" maxlength="64" class="support-input" value="<?= support_h($displayEmail) ?>" placeholder="you@example.com">
</div>
<div>
<label class="support-label"><i class="fa-solid fa-mobile-screen mr-1 text-slate-400"></i>聯絡手機</label>
<input type="text" name="contact_mobile" maxlength="32" class="support-input" value="<?= support_h($displayMobile) ?>" placeholder="09xxxxxxxx">
</div>
</div>
</div>

<div class="form-section">
<div class="form-section-title"><span class="num">4</span>問題描述 <span class="text-rose-600">*</span> <span class="text-[10px] font-normal text-slate-400">（至少 10 字）</span></div>
<textarea name="content" rows="6" class="support-input" placeholder="請描述發生的時間、地點、操作步驟與期望結果…" required></textarea>
<label class="mt-3 flex items-center gap-3 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-3 cursor-pointer hover:border-sky-400 hover:bg-sky-50/60 transition-colors">
<span class="w-9 h-9 rounded-lg bg-sky-100 text-sky-600 flex items-center justify-center"><i class="fa-solid fa-paperclip"></i></span>
<span class="text-xs text-slate-500">附加證明檔案（可選）<br><span class="text-[10px] text-slate-400">JPG / PNG / GIF / WEBP / PDF / ZIP，5MB 內</span></span>
<input type="file" name="attachment" class="ml-auto text-xs text-slate-600 max-w-[45%]">
</label>
</div>

<div class="flex items-center justify-between gap-3 pt-1 flex-wrap">
<p class="text-[11px] text-slate-400"><i class="fa-solid fa-circle-info mr-1"></i>送出後可於回報列表追蹤處理進度。</p>
<div class="flex items-center gap-3">
<a href="support.php" class="px-5 py-3 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-600 text-sm font-semibold no-underline">取消</a>
<button type="submit" class="btn-glow px-7 py-3 rounded-xl text-sm flex items-center gap-2"><i class="fa-solid fa-paper-plane"></i> 送出回報</button>
</div>
</div>
</form>

<?php else: ?>
<!-- ============ 回報列表 ============ -->
<div class="snow-card rounded-2xl p-6 sm:p-8 relative overflow-hidden anim-up">
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="absolute -top-20 -right-14 w-60 h-60 rounded-full bg-rose-400/20 blur-3xl pointer-events-none"></div>
<div class="absolute -bottom-24 -left-12 w-64 h-64 rounded-full bg-sky-400/20 blur-3xl pointer-events-none"></div>
<div class="relative flex flex-col lg:flex-row lg:items-center justify-between gap-6">
<div class="flex items-start gap-4">
<div class="relative shrink-0">
<div class="absolute inset-0 rounded-2xl bg-rose-500/30 blur-lg"></div>
<div class="relative w-16 h-16 rounded-2xl bg-gradient-to-tr from-rose-700 via-rose-600 to-pink-500 text-white flex items-center justify-center text-3xl shadow-lg"><i class="fa-solid fa-headset"></i></div>
</div>
<div>
<div class="flex items-center gap-2 mb-1.5 flex-wrap">
<span class="seal-tag text-[11px] px-2.5 py-0.5 rounded-full"><i class="fa-solid fa-shield-heart mr-1"></i>24H 客服支援</span>
<span class="text-[11px] text-slate-400">平均回覆時間 &lt; 24 小時</span>
</div>
<h1 class="text-2xl sm:text-3xl font-bold text-slate-800 font-serif tracking-wide">申訴回報中心</h1>
<p class="text-xs text-slate-500 mt-1.5 max-w-xl leading-relaxed">帳號異常、儲值點數、遊戲異常、違規檢舉與各項客服諮詢，皆可於此提出，並即時追蹤處理進度。</p>
</div>
</div>
<button type="button" onclick="openQuickMenu()" class="btn-glow px-6 py-3.5 rounded-2xl text-sm flex items-center gap-2.5 self-start">
<i class="fa-solid fa-circle-plus text-base"></i>
<span class="flex flex-col items-start leading-tight"><span>新增回報</span><span class="text-[10px] font-normal opacity-90">快速選擇分類</span></span>
<i class="fa-solid fa-chevron-up text-[10px] opacity-90"></i>
</button>
</div>
<div class="relative grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4 pt-7">
<div class="stat-tile p-4 rounded-xl bg-gradient-to-br from-slate-50 to-white border border-slate-200">
<div class="flex items-center justify-between mb-2">
<span class="w-8 h-8 rounded-lg bg-slate-800 text-white flex items-center justify-center text-xs"><i class="fa-solid fa-layer-group"></i></span>
<span class="text-[10px] font-mono text-slate-400">TOTAL</span>
</div>
<span class="text-[11px] text-slate-500 block">全部回報</span>
<span class="text-2xl font-bold text-slate-800 font-mono"><?= (int)$stats['total'] ?></span>
</div>
<div class="stat-tile p-4 rounded-xl bg-gradient-to-br from-amber-50 to-white border border-amber-200">
<div class="flex items-center justify-between mb-2">
<span class="w-8 h-8 rounded-lg bg-gradient-to-br from-amber-400 to-amber-600 text-white flex items-center justify-center text-xs"><i class="fa-solid fa-hourglass-half"></i></span>
<span class="text-[10px] font-mono text-amber-500">ACTIVE</span>
</div>
<span class="text-[11px] text-amber-700 block">處理中</span>
<span class="text-2xl font-bold text-amber-700 font-mono"><?= (int)$stats['active'] ?></span>
</div>
<div class="stat-tile p-4 rounded-xl bg-gradient-to-br from-emerald-50 to-white border border-emerald-200">
<div class="flex items-center justify-between mb-2">
<span class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-400 to-emerald-600 text-white flex items-center justify-center text-xs"><i class="fa-solid fa-circle-check"></i></span>
<span class="text-[10px] font-mono text-emerald-500">DONE</span>
</div>
<span class="text-[11px] text-emerald-700 block">已結案</span>
<span class="text-2xl font-bold text-emerald-700 font-mono"><?= (int)$stats['resolved'] ?></span>
</div>
<div class="stat-tile p-4 rounded-xl bg-gradient-to-br from-slate-100 to-white border border-slate-200">
<div class="flex items-center justify-between mb-2">
<span class="w-8 h-8 rounded-lg bg-gradient-to-br from-slate-400 to-slate-600 text-white flex items-center justify-center text-xs"><i class="fa-solid fa-lock"></i></span>
<span class="text-[10px] font-mono text-slate-400">CLOSED</span>
</div>
<span class="text-[11px] text-slate-600 block">已關閉</span>
<span class="text-2xl font-bold text-slate-600 font-mono"><?= (int)$stats['closed'] ?></span>
</div>
</div>
</div>

<div class="snow-card rounded-2xl p-3 sm:p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 anim-up anim-d1">
<div class="flex items-center gap-2 flex-wrap">
<?php
$chips = [
    'all'          => ['label' => '全部',       'icon' => 'fa-layer-group'],
    'open'         => ['label' => '待處理',     'icon' => 'fa-hourglass-half'],
    'processing'   => ['label' => '處理中',     'icon' => 'fa-gears'],
    'pending_user' => ['label' => '待您回覆',   'icon' => 'fa-comment-dots'],
    'resolved'     => ['label' => '已結案',     'icon' => 'fa-circle-check'],
    'closed'       => ['label' => '已關閉',     'icon' => 'fa-lock'],
];
foreach ($chips as $key => $c):
    $active = ($filter === $key);
    $url = 'support.php?status=' . $key . ($keyword !== '' ? '&q=' . urlencode($keyword) : '');
?>
<a href="<?= support_h($url) ?>" class="filter-chip text-xs px-3 py-1.5 rounded-full border inline-flex items-center gap-1.5 <?= $active ? 'active border-slate-700' : 'border-slate-300 text-slate-600 bg-white/80 hover:border-slate-400' ?>"><i class="fa-solid <?= support_h($c['icon']) ?> text-[10px]"></i><?= support_h($c['label']) ?></a>
<?php endforeach; ?>
</div>
<form method="GET" class="flex items-center gap-2">
<input type="hidden" name="status" value="<?= support_h($filter) ?>">
<div class="relative">
<i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
<input type="text" name="q" value="<?= support_h($keyword) ?>" placeholder="搜尋單號 / 主旨" class="support-input !w-56 !py-2 !pl-8 !text-xs">
</div>
<button type="submit" class="px-3.5 py-2 rounded-lg bg-slate-800 text-white text-xs font-semibold hover:bg-slate-700 transition-colors"><i class="fa-solid fa-magnifying-glass"></i></button>
</form>
</div>

<div class="space-y-3.5">
<?php if (empty($tickets)): ?>
<div class="snow-card rounded-2xl p-12 text-center anim-up anim-d2">
<div class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center text-slate-400 text-3xl mb-4 shadow-inner"><i class="fa-regular fa-folder-open"></i></div>
<p class="text-slate-600 text-sm font-semibold">目前沒有符合條件的回報紀錄</p>
<p class="text-slate-400 text-xs mt-1">遇到問題嗎？立即建立回報，客服將盡快協助您。</p>
<button type="button" onclick="openQuickMenu()" class="btn-glow inline-flex items-center gap-2 mt-5 px-5 py-2.5 rounded-xl text-sm"><i class="fa-solid fa-plus"></i> 立即新增回報</button>
</div>
<?php else: ?>
<?php foreach ($tickets as $t):
    $sm = support_status_meta($t['status'], $statusMeta);
    $pm = support_priority_meta($t['priority'], $priorityMeta);
?>
<a href="support.php?t=<?= (int)$t['id'] ?>" class="ticket-card snow-card snow-card-interactive rounded-2xl block no-underline group anim-up anim-d2 relative pl-6 pr-5 py-5">
<span class="ticket-strip <?= support_h($sm['strip']) ?>"></span>
<div class="flex items-start justify-between gap-4">
<div class="flex items-start gap-4 min-w-0">
<div class="w-12 h-12 rounded-xl bg-gradient-to-br from-slate-50 to-slate-100 border border-slate-200 flex items-center justify-center text-sky-600 text-lg shrink-0">
<i class="fa-solid <?= support_h($t['category_icon'] ?: 'fa-circle-question') ?>"></i>
</div>
<div class="min-w-0">
<div class="flex items-center gap-2 flex-wrap mb-1">
<span class="text-[11px] font-mono text-slate-400">NO. <?= support_h($t['ticket_no']) ?></span>
<span class="text-[11px] px-2 py-0.5 rounded-full border font-medium inline-flex items-center gap-1 <?= support_h($sm['badge']) ?>"><span class="w-1.5 h-1.5 rounded-full <?= support_h($sm['dot']) ?>"></span><?= support_h($sm['label']) ?></span>
<span class="text-[11px] px-2 py-0.5 rounded-full border font-medium <?= support_h($pm['badge']) ?>"><?= support_h($pm['label']) ?></span>
</div>
<h3 class="text-base font-bold text-slate-800 group-hover:text-rose-700 transition-colors truncate"><?= support_h($t['subject']) ?></h3>
<div class="flex items-center gap-3 mt-2 text-[11px] text-slate-500 flex-wrap">
<span><i class="fa-solid fa-tag mr-1 text-slate-400"></i><?= support_h($t['category_name'] ?: $t['category_code']) ?></span>
<span><i class="fa-regular fa-comment mr-1 text-slate-400"></i><?= (int)$t['reply_count'] ?> 則回覆</span>
<span><i class="fa-regular fa-clock mr-1 text-slate-400"></i><?= support_h($t['last_reply_at'] ?: $t['created_at']) ?></span>
<?php if ($t['last_reply_by'] === 'gm'): ?><span class="text-rose-600 font-semibold inline-flex items-center"><i class="fa-solid fa-bell mr-1 ticket-bell"></i>客服已回覆</span><?php endif; ?>
</div>
</div>
</div>
<span class="w-9 h-9 rounded-full bg-slate-100 group-hover:bg-rose-600 text-slate-400 group-hover:text-white flex items-center justify-center transition-all shrink-0 mt-1 shadow-sm">
<i class="fa-solid fa-chevron-right text-xs group-hover:translate-x-0.5 transition-transform"></i>
</span>
</div>
</a>
<?php endforeach; ?>
<?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="flex items-center justify-center gap-2 pt-2">
<?php for ($p = 1; $p <= $totalPages; $p++):
    $q = ['status' => $filter, 'page' => $p];
    if ($keyword !== '') { $q['q'] = $keyword; }
    $active = ($p === $page);
?>
<a href="support.php?<?= support_h(http_build_query($q)) ?>" class="w-9 h-9 rounded-lg flex items-center justify-center text-xs font-semibold border no-underline transition-colors <?= $active ? 'bg-slate-800 text-white border-slate-800' : 'bg-white/80 text-slate-600 border-slate-300 hover:border-slate-400' ?>"><?= $p ?></a>
<?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>
</main>

<footer class="relative z-10 border-t border-slate-800 bg-[#090d16] py-8 text-center text-xs text-slate-400 mt-auto">
<div class="max-w-7xl mx-auto px-4 space-y-2.5">
<div class="flex items-center justify-center space-x-2 text-slate-300 font-serif font-semibold text-sm"><span>踏雪笑傲 · 繁體中文官方正版</span></div>
<p class="text-slate-400">
<a class="hover:text-slate-200 mx-2" href="home.php">帳號總覽</a> ·
<a class="hover:text-slate-200 mx-2" href="usercontrol.php">安全中心</a> ·
<a class="hover:text-slate-200 mx-2" href="support.php">申訴回報</a> ·
<a class="hover:text-slate-200 mx-2" href="download.php">遊戲下載</a>
</p>
<p class="text-[11px] text-slate-400 font-mono">© 2025 踏雪笑傲營運團隊 All Rights Reserved.</p>
</div>
</footer>

<?php if ($view !== 'new'): ?>
<!-- ============ 浮動新增回報按鈕與快速選單 ============ -->
<div id="quick-backdrop" class="quick-backdrop" onclick="closeQuickMenu()"></div>
<div class="quick-fab-wrap">
<div id="quick-menu" class="quick-menu" role="dialog" aria-label="快速新增回報">
<div class="flex items-center justify-between mb-3.5">
<div class="flex items-center gap-2.5">
<span class="w-9 h-9 rounded-xl bg-gradient-to-tr from-rose-700 to-pink-500 text-white flex items-center justify-center text-sm shadow"><i class="fa-solid fa-bolt"></i></span>
<div>
<p class="text-sm font-bold text-slate-800 font-serif leading-tight">快速新增回報</p>
<p class="text-[10px] text-slate-400">選擇分類，立即前往填寫</p>
</div>
</div>
<button type="button" onclick="closeQuickMenu()" class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 flex items-center justify-center transition-colors" aria-label="關閉"><i class="fa-solid fa-xmark"></i></button>
</div>
<div class="grid grid-cols-4 gap-2.5">
<?php foreach ($categories as $cat): ?>
<a href="support.php?view=new&category=<?= (int)$cat['id'] ?>" class="quick-cat">
<span class="qc-icon"><i class="fa-solid <?= support_h($cat['icon'] ?: 'fa-circle-question') ?>"></i></span>
<span class="qc-name"><?= support_h($cat['name']) ?></span>
</a>
<?php endforeach; ?>
</div>
<a href="support.php?view=new" class="mt-3.5 w-full flex items-center justify-center gap-2 rounded-xl py-3 text-sm font-semibold text-white bg-gradient-to-r from-rose-600 to-rose-800 hover:from-rose-500 hover:to-rose-700 transition-all shadow-md shadow-rose-600/30 no-underline">
<i class="fa-solid fa-pen-to-square"></i> 填寫完整回報表單 <i class="fa-solid fa-arrow-right text-xs"></i>
</a>
</div>
<button type="button" id="quick-fab-btn" class="quick-fab" onclick="toggleQuickMenu()" aria-label="新增回報">
<span class="quick-fab-ring"></span>
<i class="fa-solid fa-plus fab-icon"></i>
</button>
<span class="quick-fab-label">新增回報</span>
</div>
<?php endif; ?>

<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-300 px-4" id="logout-modal">
<div class="snow-card max-w-sm w-full rounded-2xl p-6 text-center border-slate-300 shadow-2xl transform scale-95 transition-transform duration-300" id="logout-card">
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="w-14 h-14 mx-auto rounded-full bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-700 text-2xl mb-4"><i class="fa-solid fa-arrow-right-from-bracket"></i></div>
<h3 class="text-lg font-bold text-slate-800 font-serif mb-2">確認登出帳號？</h3>
<p class="text-xs text-slate-500 leading-relaxed mb-6">您即將結束本次登入工作階段。</p>
<div class="flex items-center justify-center space-x-3">
<button class="w-1/2 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold transition-all shadow-sm" onclick="closeLogoutModal()">取消並返回</button>
<a class="w-1/2 py-2.5 rounded-xl bg-gradient-to-r from-rose-700 to-rose-800 hover:from-rose-800 hover:to-rose-900 text-white text-xs font-semibold transition-all shadow-md text-center no-underline" href="logout.php">確認登出</a>
</div>
</div>
</div>

<script>
(function () {
    const mobileToggle = document.getElementById('mobile-toggle');
    const mobileMenu = document.getElementById('mobile-menu');
    if (mobileToggle && mobileMenu) {
        mobileToggle.addEventListener('click', () => mobileMenu.classList.toggle('hidden'));
    }
    const logoutModal = document.getElementById('logout-modal');
    const logoutCard = document.getElementById('logout-card');
    window.openLogoutModal = function () {
        if (!logoutModal) return;
        logoutModal.classList.remove('opacity-0', 'pointer-events-none');
        logoutModal.classList.add('opacity-100');
        if (logoutCard) { logoutCard.classList.remove('scale-95'); logoutCard.classList.add('scale-100'); }
    };
    window.closeLogoutModal = function () {
        if (!logoutModal) return;
        logoutModal.classList.add('opacity-0', 'pointer-events-none');
        logoutModal.classList.remove('opacity-100');
        if (logoutCard) { logoutCard.classList.remove('scale-100'); logoutCard.classList.add('scale-95'); }
    };
    if (logoutModal) {
        logoutModal.addEventListener('click', (e) => { if (e.target === logoutModal) closeLogoutModal(); });
    }

    // 快速新增回報選單
    const quickBtn = document.getElementById('quick-fab-btn');
    const quickMenu = document.getElementById('quick-menu');
    const quickBackdrop = document.getElementById('quick-backdrop');
    window.openQuickMenu = function () {
        if (!quickMenu) return;
        quickMenu.classList.add('open');
        if (quickBtn) quickBtn.classList.add('open');
        if (quickBackdrop) quickBackdrop.classList.add('open');
        document.body.style.overflow = 'hidden';
    };
    window.closeQuickMenu = function () {
        if (!quickMenu) return;
        quickMenu.classList.remove('open');
        if (quickBtn) quickBtn.classList.remove('open');
        if (quickBackdrop) quickBackdrop.classList.remove('open');
        document.body.style.overflow = '';
    };
    window.toggleQuickMenu = function () {
        if (!quickMenu) return;
        if (quickMenu.classList.contains('open')) { closeQuickMenu(); } else { openQuickMenu(); }
    };
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeQuickMenu();
    });

    (function initSnow() {
        const canvas = document.getElementById('snow-canvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        let width = (canvas.width = window.innerWidth);
        let height = (canvas.height = window.innerHeight);
        window.addEventListener('resize', () => {
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
        });
        const particles = [];
        const count = Math.min(Math.floor(window.innerWidth / 28), 50);
        for (let i = 0; i < count; i++) {
            particles.push({
                x: Math.random() * width, y: Math.random() * height,
                radius: Math.random() * 2.2 + 0.8, speedY: Math.random() * 0.7 + 0.3,
                speedX: (Math.random() - 0.5) * 0.4, opacity: Math.random() * 0.55 + 0.25,
                pulseSpeed: Math.random() * 0.015 + 0.005, pulsePhase: Math.random() * Math.PI * 2
            });
        }
        function render() {
            ctx.clearRect(0, 0, width, height);
            for (let i = 0; i < particles.length; i++) {
                const p = particles[i];
                p.y += p.speedY; p.x += p.speedX; p.pulsePhase += p.pulseSpeed;
                if (p.y > height) { p.y = -10; p.x = Math.random() * width; }
                if (p.x > width) p.x = 0;
                if (p.x < 0) p.x = width;
                const op = Math.max(0.15, Math.min(0.8, p.opacity + Math.sin(p.pulsePhase) * 0.15));
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(240,249,255,' + op + ')';
                ctx.shadowBlur = 4;
                ctx.shadowColor = 'rgba(255,255,255,0.4)';
                ctx.fill();
            }
            requestAnimationFrame(render);
        }
        render();
    })();
})();
</script>
</body>
</html>

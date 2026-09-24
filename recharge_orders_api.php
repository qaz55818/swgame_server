<?php
/**
 * ==============================================================
 *  儲值歷史訂單查詢 API（JSON）
 *  僅回傳「目前登入會員」自己的訂單
 *
 *  參數：
 *    q      關鍵字（訂單編號 / 綠界交易編號）
 *    status 狀態篩選：all / pending / paid / failed / expired / cancelled
 *    page   頁碼（預設 1）
 *    limit  每頁筆數（預設 10，上限 50）
 * ==============================================================
 */
require_once __DIR__ . '/session_bootstrap.php';
app_session_start(['remember' => true]);
@include_once "config.php";
require_once __DIR__ . '/ecpay_config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['username']) || $_SESSION['username'] === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '請先登入'], JSON_UNESCAPED_UNICODE);
    exit();
}

$username = $_SESSION['username'];
$sessionUserId = $_SESSION['user_id'] ?? '';

$q      = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all'));
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = (int)($_GET['limit'] ?? 10);
if ($limit < 1)  { $limit = 10; }
if ($limit > 50) { $limit = 50; }
$offset = ($page - 1) * $limit;

$allowedStatus = ['all', 'pending', 'paid', 'failed', 'expired', 'cancelled'];
if (!in_array($status, $allowedStatus, true)) {
    $status = 'all';
}

$Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
if (!$Link) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '資料庫連線失敗'], JSON_UNESCAPED_UNICODE);
    exit();
}
mysqli_set_charset($Link, "utf8");

// 解析使用者 ID
$uid = 0;
if ($sessionUserId !== '') {
    $stmt = $Link->prepare("SELECT `ID` FROM `users` WHERE `ID` = ? LIMIT 1");
    $stmt->bind_param("i", $sessionUserId);
} else {
    $stmt = $Link->prepare("SELECT `ID` FROM `users` WHERE `name` = ? LIMIT 1");
    $stmt->bind_param("s", $username);
}
$stmt->execute();
$urow = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (is_array($urow) && !empty($urow['ID'])) {
    $uid = (int)$urow['ID'];
} elseif ($sessionUserId !== '') {
    $uid = (int)$sessionUserId;
}

if ($uid <= 0) {
    mysqli_close($Link);
    echo json_encode(['ok' => true, 'orders' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'server_now' => time(),
        'stats' => ['count' => 0, 'paid_count' => 0, 'paid_amount' => 0, 'paid_points' => 0]], JSON_UNESCAPED_UNICODE);
    exit();
}

// 查詢前先將逾時未付款訂單標記為已逾期（惰性過期）
recharge_expire_overdue_orders($Link, $uid);

// 動態組條件
$where  = "`uid` = ?";
$types  = "i";
$params = [$uid];

if ($q !== '') {
    $where .= " AND (`order_no` LIKE ? OR `trade_no` LIKE ?)";
    $like = '%' . $q . '%';
    $types .= "ss";
    $params[] = $like;
    $params[] = $like;
}
if ($status !== 'all') {
    $where .= " AND `status` = ?";
    $types .= "s";
    $params[] = $status;
}

// 總筆數
$countSql = "SELECT COUNT(*) AS c FROM `recharge_orders` WHERE $where";
$stmt = $Link->prepare($countSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();
$pages = $total > 0 ? (int)ceil($total / $limit) : 0;
if ($page > $pages && $pages > 0) {
    $page = $pages;
    $offset = ($page - 1) * $limit;
}

// 查詢資料
$listSql = "SELECT `order_no`, `method_key`, `method_name`, `choose_payment`, `amount`, `bonus`,
                   `total_points`, `status`, `rtn_code`, `rtn_msg`, `trade_no`, `payment_type`,
                   `payment_info`, `paid_at`, `created_at`
            FROM `recharge_orders`
            WHERE $where
            ORDER BY `id` DESC
            LIMIT ? OFFSET ?";
$stmt = $Link->prepare($listSql);
$listTypes = $types . "ii";
$listParams = $params;
$listParams[] = $limit;
$listParams[] = $offset;
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$res = $stmt->get_result();

$orders = [];
while ($row = $res->fetch_assoc()) {
    $info = null;
    if (!empty($row['payment_info'])) {
        $decoded = json_decode($row['payment_info'], true);
        if (is_array($decoded)) { $info = $decoded; }
    }
    $orders[] = [
        'order_no'     => $row['order_no'],
        'method_key'   => $row['method_key'],
        'method_name'  => $row['method_name'],
        'choose_payment' => $row['choose_payment'],
        'amount'       => (int)$row['amount'],
        'bonus'        => (int)$row['bonus'],
        'total_points' => (int)$row['total_points'],
        'status'       => $row['status'],
        'rtn_code'     => $row['rtn_code'],
        'rtn_msg'      => $row['rtn_msg'],
        'trade_no'     => $row['trade_no'],
        'payment_type' => $row['payment_type'],
        'payment_info' => $info,
        'paid_at'      => $row['paid_at'],
        'created_at'   => $row['created_at'],
        'expire_seconds' => recharge_expire_seconds($row['method_key']),
        'expires_ts'     => recharge_order_expires_ts($row['created_at'], $row['method_key']),
    ];
}
$stmt->close();

// 統計（不受分頁影響）
$stmt = $Link->prepare(
    "SELECT COUNT(*) AS cnt,
            SUM(CASE WHEN `status` = 'paid' THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN `status` = 'paid' THEN `amount` ELSE 0 END) AS paid_amount,
            SUM(CASE WHEN `status` = 'paid' THEN `total_points` ELSE 0 END) AS paid_points
     FROM `recharge_orders` WHERE `uid` = ?"
);
$stmt->bind_param("i", $uid);
$stmt->execute();
$s = $stmt->get_result()->fetch_assoc();
$stmt->close();
mysqli_close($Link);

echo json_encode([
    'ok'     => true,
    'orders' => $orders,
    'total'  => $total,
    'page'   => $page,
    'pages'  => $pages,
    'server_now' => time(),
    'stats'  => [
        'count'       => (int)($s['cnt'] ?? 0),
        'paid_count'  => (int)($s['paid_count'] ?? 0),
        'paid_amount' => (int)($s['paid_amount'] ?? 0),
        'paid_points' => (int)($s['paid_points'] ?? 0),
    ],
], JSON_UNESCAPED_UNICODE);

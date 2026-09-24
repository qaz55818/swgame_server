<?php
/**
 * ==============================================================
 *  儲值訂單操作端點（僅限目前登入會員）
 *  --------------------------------------------------------------
 *  action = cancel ：取消尚未付款的訂單（JSON 回應）
 *  action = repay  ：重新前往付款；建立新訂單並導向綠界付款頁
 * ==============================================================
 */
require_once __DIR__ . '/session_bootstrap.php';
app_session_start(['remember' => true]);
@include_once "config.php";
require_once __DIR__ . '/ecpay_config.php';

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$isRepay = ($action === 'repay');

// 未登入
if (!isset($_SESSION['username']) || $_SESSION['username'] === '') {
    if ($isRepay) {
        header('Location: login.php');
        exit();
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => '請先登入'], JSON_UNESCAPED_UNICODE);
    exit();
}

$username = $_SESSION['username'];
$sessionUserId = $_SESSION['user_id'] ?? '';

// 統一的錯誤回應（repay 以導回儲值頁顯示訊息）
function recharge_action_fail($message, $isRepay)
{
    if ($isRepay) {
        $_SESSION['recharge_result'] = ['ok' => false, 'message' => $message];
        header('Location: recharge.php');
        exit();
    }
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit();
}

// CSRF 驗證
$token = (string)($_POST['form_token'] ?? $_GET['form_token'] ?? '');
if (empty($_SESSION['recharge_token']) || !hash_equals((string)$_SESSION['recharge_token'], $token)) {
    recharge_action_fail('操作已失效，請重新整理頁面後再試。', $isRepay);
}

$orderNo = trim((string)($_POST['order_no'] ?? $_GET['order_no'] ?? ''));
if ($orderNo === '') {
    recharge_action_fail('缺少訂單編號。', $isRepay);
}

if (!in_array($action, ['cancel', 'repay'], true)) {
    recharge_action_fail('不支援的操作。', $isRepay);
}

$Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
if (!$Link) {
    recharge_action_fail('資料庫連線失敗，請稍後再試。', $isRepay);
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
    recharge_action_fail('無法辨識會員身分。', $isRepay);
}

// 先處理逾時未付款訂單
recharge_expire_overdue_orders($Link, $uid);

// 取得訂單（僅限本人）
$stmt = $Link->prepare(
    "SELECT `id`, `order_no`, `uid`, `method_key`, `method_name`, `choose_payment`,
            `amount`, `bonus`, `total_points`, `status`
       FROM `recharge_orders`
      WHERE `order_no` = ? AND `uid` = ?
      LIMIT 1"
);
$stmt->bind_param("si", $orderNo, $uid);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    mysqli_close($Link);
    recharge_action_fail('找不到對應的訂單。', $isRepay);
}

if ($order['status'] !== 'pending') {
    $label = [
        'paid'      => '已付款',
        'cancelled' => '已取消',
        'expired'   => '已逾期',
        'failed'    => '付款失敗',
    ][$order['status']] ?? $order['status'];
    mysqli_close($Link);
    recharge_action_fail('此訂單目前狀態為「' . $label . '」，無法進行操作。', $isRepay);
}

/* ---------------- 取消支付 ---------------- */
if ($action === 'cancel') {
    $stmt = $Link->prepare(
        "UPDATE `recharge_orders`
            SET `status` = 'cancelled', `rtn_msg` = '會員取消支付'
          WHERE `id` = ? AND `status` = 'pending'"
    );
    $stmt->bind_param("i", $order['id']);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    mysqli_close($Link);

    header('Content-Type: application/json; charset=utf-8');
    if ($affected > 0) {
        echo json_encode(['ok' => true, 'message' => '訂單已取消。'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'message' => '訂單狀態已變更，請重新整理後再試。'], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

/* ---------------- 重新前往支付 ---------------- */
$methodKey = (string)$order['method_key'];
$amount    = (int)$order['amount'];
$choosePayment = (string)$order['choose_payment'];

// 綠界不支援（虛擬貨幣）：沿用原訂單，直接導回儲值頁顯示付款資訊
if ($choosePayment === '') {
    $payInfo = [
        'crypto' => ['USDT (TRC20)：TXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', '請於 30 分鐘內完成鏈上轉帳並保留交易雜湊。'],
    ];
    $_SESSION['recharge_result'] = [
        'ok'        => true,
        'order_no'  => $order['order_no'],
        'method'    => $order['method_name'],
        'amount'    => $amount,
        'bonus'     => (int)$order['bonus'],
        'total'     => (int)$order['total_points'],
        'pay_info'  => $payInfo[$methodKey] ?? ['請依客服指示完成付款。'],
    ];
    mysqli_close($Link);
    header('Location: recharge.php');
    exit();
}

// 綠界付款：建立新訂單（MerchantTradeNo 不得重複），舊單標記為已取消
$newOrderNo = 'RC' . date('YmdHis') . strtoupper(bin2hex(random_bytes(2)));

$stmt = $Link->prepare(
    "INSERT INTO `recharge_orders`
        (`order_no`, `uid`, `username`, `method_key`, `method_name`, `choose_payment`,
         `amount`, `bonus`, `total_points`, `status`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
);
$stmt->bind_param(
    "sissssiii",
    $newOrderNo, $uid, $username, $methodKey, $order['method_name'], $choosePayment,
    $amount, $order['bonus'], $order['total_points']
);
$stmt->execute();
$stmt->close();

$stmt = $Link->prepare(
    "UPDATE `recharge_orders`
        SET `status` = 'cancelled', `rtn_msg` = '重新建立付款訂單'
      WHERE `id` = ? AND `status` = 'pending'"
);
$stmt->bind_param("i", $order['id']);
$stmt->execute();
$stmt->close();
mysqli_close($Link);

$input = ecpay_build_recharge_input($newOrderNo, $methodKey, $amount);
echo ecpay_build_form($input);
exit();

<?php
/**
 * ==============================================================
 *  ECPay 綠界金流 Callback 接收端
 *  同時作為：
 *    - ReturnURL      ：付款結果通知（RtnCode=1 付款成功）
 *    - PaymentInfoURL ：ATM / CVS 取號結果通知（RtnCode=2 / 10100073）
 *
 *  ⚠️ AIO 為 Form POST，RtnCode 型別為「字串」，必須嚴格比對 '1'
 *  ⚠️ 必須回應純文字 1|OK 且 HTTP 200，否則綠界將每 5-15 分鐘重送（每日最多 4 次）
 *  來源：ECPay API Skill guides/01-payment-aio.md（SNAPSHOT 2026-03）
 * ==============================================================
 */

@include_once "config.php";
require_once __DIR__ . '/ecpay_config.php';

header('Content-Type: text/plain; charset=utf-8');

// 只接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(200);
    echo '1|OK';
    exit();
}

$post = $_POST;

// 1) 驗證 CheckMacValue（timing-safe）
if (!ecpay_verify_cmv($post)) {
    error_log('[ECPay][recharge_notify] CheckMacValue 驗證失敗 order=' . ($post['MerchantTradeNo'] ?? ''));
    // 仍回應 1|OK + 200，避免綠界持續重送
    echo '1|OK';
    exit();
}

$orderNo  = isset($post['MerchantTradeNo']) ? trim((string)$post['MerchantTradeNo']) : '';
$rtnCode  = isset($post['RtnCode']) ? (string)$post['RtnCode'] : '';
$rtnMsg   = isset($post['RtnMsg']) ? mb_substr((string)$post['RtnMsg'], 0, 200) : '';
$tradeNo  = isset($post['TradeNo']) ? (string)$post['TradeNo'] : '';
$payType  = isset($post['PaymentType']) ? (string)$post['PaymentType'] : '';
$tradeAmt = isset($post['TradeAmt']) ? (int)$post['TradeAmt'] : 0;

if ($orderNo === '') {
    echo '1|OK';
    exit();
}

$Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
if (!$Link) {
    error_log('[ECPay][recharge_notify] DB 連線失敗');
    // 回非 1|OK 讓綠界稍後重送
    http_response_code(500);
    echo 'ERROR';
    exit();
}
mysqli_set_charset($Link, "utf8");

// 取得訂單
$stmt = $Link->prepare("SELECT `id`, `uid`, `status`, `total_points` FROM `recharge_orders` WHERE `order_no` = ? LIMIT 1");
$stmt->bind_param("s", $orderNo);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    error_log('[ECPay][recharge_notify] 找不到訂單 ' . $orderNo);
    mysqli_close($Link);
    echo '1|OK';
    exit();
}

$raw = json_encode($post, JSON_UNESCAPED_UNICODE);

// 取號通知（ATM=2 / CVS、BARCODE=10100073）→ 保存繳費資訊，尚未付款
if ($rtnCode === '2' || $rtnCode === '10100073') {
    $info = [];
    foreach (['BankCode', 'vAccount', 'PaymentNo', 'ExpireDate', 'Barcode1', 'Barcode2', 'Barcode3'] as $k) {
        if (isset($post[$k]) && $post[$k] !== '') {
            $info[$k] = $post[$k];
        }
    }
    $infoJson = json_encode($info, JSON_UNESCAPED_UNICODE);

    $stmt = $Link->prepare(
        "UPDATE `recharge_orders`
            SET `rtn_code` = ?, `rtn_msg` = ?, `trade_no` = ?, `payment_type` = ?,
                `payment_info` = ?, `callback_raw` = ?
          WHERE `id` = ?"
    );
    $stmt->bind_param("ssssssi", $rtnCode, $rtnMsg, $tradeNo, $payType, $infoJson, $raw, $order['id']);
    $stmt->execute();
    $stmt->close();

    mysqli_close($Link);
    echo '1|OK';
    exit();
}

// 付款成功
if ($rtnCode === '1') {
    // 冪等防護：僅當訂單非 paid 時才入帳，避免綠界重送造成重複加點
    $stmt = $Link->prepare(
        "UPDATE `recharge_orders`
            SET `status` = 'paid', `rtn_code` = ?, `rtn_msg` = ?, `trade_no` = ?,
                `payment_type` = ?, `callback_raw` = ?, `paid_at` = NOW()
          WHERE `id` = ? AND `status` <> 'paid'"
    );
    $stmt->bind_param("sssssi", $rtnCode, $rtnMsg, $tradeNo, $payType, $raw, $order['id']);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        $uid = (int)$order['uid'];
        $points = (int)$order['total_points'];
        if ($uid > 0 && $points > 0) {
            if (!recharge_credit_points($Link, $uid, $points)) {
                error_log('[ECPay][recharge_notify] 入帳失敗 order=' . $orderNo . ' uid=' . $uid);
            }
        }
    }

    mysqli_close($Link);
    echo '1|OK';
    exit();
}

// 其他狀態（付款失敗等）：記錄，不入帳
$stmt = $Link->prepare(
    "UPDATE `recharge_orders`
        SET `status` = 'failed', `rtn_code` = ?, `rtn_msg` = ?, `trade_no` = ?,
            `payment_type` = ?, `callback_raw` = ?
      WHERE `id` = ? AND `status` <> 'paid'"
);
$stmt->bind_param("sssssi", $rtnCode, $rtnMsg, $tradeNo, $payType, $raw, $order['id']);
$stmt->execute();
$stmt->close();

mysqli_close($Link);
echo '1|OK';
exit();

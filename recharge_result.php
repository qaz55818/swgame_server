<?php
/**
 * ==============================================================
 *  ECPay OrderResultURL：消費者付款完成後的前端導回頁
 *  （僅信用卡等即時付款支援；ATM/CVS 走非同步 ReturnURL）
 *  ⚠️ 此頁為前台顯示，不需回應 1|OK
 * ==============================================================
 */
require_once __DIR__ . '/session_bootstrap.php';
app_session_start(['remember' => true]);
@include_once "config.php";
require_once __DIR__ . '/ecpay_config.php';

$orderNo   = trim((string)($_POST['MerchantTradeNo'] ?? ''));
$rtnCode   = (string)($_POST['RtnCode'] ?? '');
$rtnMsg    = (string)($_POST['RtnMsg'] ?? '');
$tradeNo   = (string)($_POST['TradeNo'] ?? '');
$tradeAmt  = (int)($_POST['TradeAmt'] ?? 0);
$cmvOk     = ecpay_verify_cmv($_POST);

$success = ($cmvOk && $rtnCode === '1');

// 讀取訂單資料供顯示
$order = null;
$Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
if ($Link && $orderNo !== '') {
    mysqli_set_charset($Link, "utf8");
    $stmt = $Link->prepare("SELECT `order_no`, `method_name`, `amount`, `bonus`, `total_points`, `status` FROM `recharge_orders` WHERE `order_no` = ? LIMIT 1");
    $stmt->bind_param("s", $orderNo);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    mysqli_close($Link);
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>踏雪笑傲 · 付款結果</title>
<link rel="stylesheet" href="assets/tailwind.css">
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+TC:wght@400;600;700;900&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    body { background-color:#080c14; font-family:'Noto Serif TC', sans-serif;
        background-image: radial-gradient(ellipse at 50% 0%, rgba(190,18,60,0.10) 0%, transparent 60%),
            radial-gradient(ellipse at 80% 90%, rgba(2,132,199,0.10) 0%, transparent 55%); }
    .snow-card { background: rgba(255,255,255,0.96); backdrop-filter: blur(16px);
        box-shadow: 0 20px 45px -12px rgba(0,0,0,0.35); }
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
<div class="snow-card max-w-md w-full rounded-2xl p-7 text-center">
    <?php if ($success): ?>
        <div class="w-16 h-16 mx-auto rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center text-emerald-600 text-2xl mb-4">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-800 font-serif mb-2">付款成功</h3>
        <p class="text-sm text-slate-500 leading-relaxed mb-5">系統已收到您的付款，元寶將立即入帳至您的帳號。</p>
    <?php else: ?>
        <div class="w-16 h-16 mx-auto rounded-full bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-700 text-2xl mb-4">
            <i class="fa-solid fa-circle-exclamation"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-800 font-serif mb-2">付款未完成</h3>
        <p class="text-sm text-slate-500 leading-relaxed mb-5">
            <?= htmlspecialchars($rtnMsg !== '' ? $rtnMsg : '交易未成功，或您已取消付款。') ?>
        </p>
    <?php endif; ?>

    <?php if (is_array($order)): ?>
    <div class="text-left text-xs space-y-2 mb-5 p-4 rounded-xl bg-slate-50 border border-slate-200">
        <div class="flex justify-between"><span class="text-slate-400">訂單編號</span><span class="font-mono text-slate-800"><?= htmlspecialchars($order['order_no']) ?></span></div>
        <div class="flex justify-between"><span class="text-slate-400">儲值方式</span><span class="text-slate-800"><?= htmlspecialchars($order['method_name']) ?></span></div>
        <div class="flex justify-between"><span class="text-slate-400">儲值金額</span><span class="font-mono text-slate-800">NT$ <?= number_format((int)$order['amount']) ?></span></div>
        <div class="flex justify-between"><span class="text-slate-400">獲得元寶</span><span class="font-mono text-amber-700"><?= number_format((int)$order['total_points']) ?> 點</span></div>
        <?php if ($tradeNo !== ''): ?>
        <div class="flex justify-between"><span class="text-slate-400">綠界交易編號</span><span class="font-mono text-slate-800"><?= htmlspecialchars($tradeNo) ?></span></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <a href="recharge.php" class="inline-flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-gradient-to-r from-rose-700 to-rose-800 hover:from-rose-800 hover:to-rose-900 text-white text-sm font-semibold transition-all shadow-md no-underline">
        <i class="fa-solid fa-arrow-left-long"></i> 返回儲值中心
    </a>
</div>
</body>
</html>

<?php
require_once __DIR__ . '/session_bootstrap.php';
app_session_start(['remember' => true]);
@include_once "config.php";
require_once __DIR__ . '/ecpay_config.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/permissions.php';

// 未登入則顯示提示並導向登入頁
$loggedIn = isset($_SESSION['username']) && $_SESSION['username'] !== '';
if (!$loggedIn) {
    ?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>踏雪笑傲 · 請先登入</title>
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
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm px-4">
    <div class="snow-card max-w-sm w-full rounded-2xl p-7 text-center">
        <div class="w-16 h-16 mx-auto rounded-full bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-700 text-2xl mb-4">
            <i class="fa-solid fa-lock"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-800 font-serif mb-2">請先登入會員</h3>
        <p class="text-sm text-slate-500 leading-relaxed mb-6">
            儲值功能僅限登入會員使用。<br>系統將於 <span id="countdown">3</span> 秒後自動前往登入頁面。
        </p>
        <a href="login.php" class="inline-flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-gradient-to-r from-rose-700 to-rose-800 hover:from-rose-800 hover:to-rose-900 text-white text-sm font-semibold transition-all shadow-md no-underline">
            <i class="fa-solid fa-right-to-bracket"></i> 立即前往登入
        </a>
    </div>
</div>
<script>
    let sec = 3;
    const el = document.getElementById('countdown');
    const timer = setInterval(function () {
        sec--;
        if (el) el.textContent = sec;
        if (sec <= 0) {
            clearInterval(timer);
            window.location.href = 'login.php';
        }
    }, 1000);
</script>
</body>
</html>
    <?php
    exit();
}

$username = $_SESSION['username'];
$userId = $_SESSION['user_id'] ?? '';

// 預設儲值方式（固定四種管道，狀態與金額方案皆以後台設定為準）
$baseRechargeMethods = [
    'credit_card'   => ['name' => '線上刷卡', 'desc' => '支援 Visa / MasterCard / JCB<br>綠界加密安全即時入帳', 'icon' => 'fa-credit-card', 'enabled' => true],
    'bank_transfer' => ['name' => '銀行轉帳', 'desc' => '自動核銷專屬虛擬帳號<br>ATM 或網路銀行即時入帳', 'icon' => 'fa-building-columns', 'enabled' => true],
    'cvs'           => ['name' => '超商繳費', 'desc' => '全台四大超商機台列印<br>繳費代碼即時核銷入帳', 'icon' => 'fa-store', 'enabled' => true],
    'crypto'        => ['name' => '虛擬貨幣', 'desc' => 'USDT (TRC20 / ERC20)<br>智能合約快速確認入帳', 'icon' => 'fa-coins', 'enabled' => true],
];
$rechargeMethods = $baseRechargeMethods;
$rcTiers = [];
$rcConfig = ['min_amount' => 50, 'max_amount' => 100000];

// 加成計算（依後台設定的金額方案與回饋比例）
function calcBonusByTiers($amount, $tiers) {
    $bestPct = 0;
    foreach ($tiers as $t) {
        if ($amount >= (int)$t['amount']) {
            $bestPct = (int)$t['bonus_percent'];
        }
    }
    return (int)floor($amount * $bestPct / 100);
}

// 讀取帳號資料
$accountInfo = null;
$points = 0;
$yuanbao = 0;
$currencySettings = currency_default_settings();
$Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
if ($Link) {
    mysqli_set_charset($Link, "utf8");
    if ($userId !== '') {
        $stmt = $Link->prepare("SELECT `ID`, `name`, `email`, `mobilenumber`, `creatime` FROM `users` WHERE `ID` = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
    } else {
        $stmt = $Link->prepare("SELECT `ID`, `name`, `email`, `mobilenumber`, `creatime` FROM `users` WHERE `name` = ? LIMIT 1");
        $stmt->bind_param("s", $username);
    }
    $stmt->execute();
    $accountInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $uid = 0;
    if (is_array($accountInfo) && !empty($accountInfo['ID'])) {
        $uid = (int)$accountInfo['ID'];
    } elseif ($userId !== '') {
        $uid = (int)$userId;
    }
    if ($uid > 0) {
        // 會員功能權限：儲值權限
        member_permission_require($Link, $uid, 'can_recharge', ['back' => 'home.php', 'countdown' => 5]);

        // 讀取錢包：點數（儲值取得）與元寶（兌換取得）
        $wallet = currency_get_wallet($Link, $uid);
        $points = (int)$wallet['points'];
        $yuanbao = (int)$wallet['yuanbao'];
        $currencySettings = currency_load_settings($Link);

        // 將逾時未付款訂單標記為已逾期
        recharge_expire_overdue_orders($Link, $uid);
    }

    // 讀取後台儲值設定（支付狀態、金額方案、自訂金額範圍）
    $cfg = loadRechargeSettings($Link);
    if (!empty($cfg['methods'])) {
        foreach ($rechargeMethods as $key => $m) {
            if (isset($cfg['methods'][$key])) {
                $rechargeMethods[$key]['enabled'] = !empty($cfg['methods'][$key]['enabled']);
                if (isset($cfg['methods'][$key]['name']) && !empty($cfg['methods'][$key]['name'])) {
                    $rechargeMethods[$key]['name'] = $cfg['methods'][$key]['name'];
                }
            }
        }
    }
    $rcTiers = $cfg['tiers'];
    $rcConfig = array_merge($rcConfig, $cfg['config']);

    mysqli_close($Link);
}

// 有效金額方案與自訂金額範圍
$presetTiers = [];
foreach ($rcTiers as $t) {
    if (!empty($t['enabled'])) {
        $presetTiers[] = $t;
    }
}
$rechargeMin = (int)($rcConfig['min_amount'] ?: 50);
$rechargeMax = (int)($rcConfig['max_amount'] ?: 100000);

// 儲值方式卡片裝飾資訊
$methodDecor = [
    'credit_card'   => ['badge' => '即時生效', 'iconbg' => 'from-sky-50 via-sky-100 to-sky-200', 'icon' => 'text-sky-600', 'badgeclr' => 'text-sky-700 border-sky-200'],
    'bank_transfer' => ['badge' => '專屬帳號', 'iconbg' => 'from-emerald-50 via-emerald-100 to-emerald-200', 'icon' => 'text-emerald-600', 'badgeclr' => 'text-emerald-700 border-emerald-200'],
    'cvs'           => ['badge' => '24H 便利', 'iconbg' => 'from-amber-50 via-amber-100 to-amber-200', 'icon' => 'text-amber-600', 'badgeclr' => 'text-amber-700 border-amber-200'],
    'crypto'        => ['badge' => '全球通用', 'iconbg' => 'from-rose-50 via-rose-100 to-rose-200', 'icon' => 'text-rose-600', 'badgeclr' => 'text-rose-700 border-rose-200'],
];

// 顯示值（無法取得時採常規描述）
$displayEmail = (is_array($accountInfo) && !empty($accountInfo['email'])) ? $accountInfo['email'] : '尚未綁定電子信箱';
$displayMobile = (is_array($accountInfo) && !empty($accountInfo['mobilenumber'])) ? $accountInfo['mobilenumber'] : '尚未綁定手機';
$displayRegDate = (is_array($accountInfo) && !empty($accountInfo['creatime']) && $accountInfo['creatime'] !== '0000-00-00 00:00:00')
    ? date('Y-m-d', strtotime($accountInfo['creatime'])) : '註冊時間未提供';
$displayPoints = number_format($points);
$displayYuanbao = number_format($yuanbao);
$pointsName = $currencySettings['points_name'] ?? '點數';
$yuanbaoName = $currencySettings['yuanbao_name'] ?? '元寶';
$exchangeRate = (float)($currencySettings['exchange_rate'] ?? 1);

// CSRF token
if (empty($_SESSION['recharge_token'])) {
    $_SESSION['recharge_token'] = bin2hex(random_bytes(32));
}
$rechargeToken = $_SESSION['recharge_token'];

// 讀取一次性結果（PRG）
$result = $_SESSION['recharge_result'] ?? null;
if ($result !== null) {
    unset($_SESSION['recharge_result']);
}

// 處理儲值送出
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['recharge_token']) {
        $_SESSION['recharge_result'] = ['ok' => false, 'message' => '表單已失效，請重新操作。'];
        header("Location: recharge.php");
        exit();
    }
    unset($_SESSION['recharge_token']);

    $method = $_POST['method'] ?? '';
    $amountPreset = isset($_POST['amount_preset']) ? (int)$_POST['amount_preset'] : 0;
    $amountCustom = isset($_POST['amount_custom']) ? (int)$_POST['amount_custom'] : 0;
    $amount = $amountCustom > 0 ? $amountCustom : $amountPreset;

    if (!array_key_exists($method, $rechargeMethods)) {
        $_SESSION['recharge_result'] = ['ok' => false, 'message' => '請選擇有效的儲值方式。'];
    } elseif (empty($rechargeMethods[$method]['enabled'])) {
        $_SESSION['recharge_result'] = ['ok' => false, 'message' => '該儲值方式目前暫停使用，請更換其他方式。'];
    } elseif ($amount < $rechargeMin || $amount > $rechargeMax) {
        $_SESSION['recharge_result'] = ['ok' => false, 'message' => '儲值金額需介於 ' . number_format($rechargeMin) . ' 至 ' . number_format($rechargeMax) . ' 之間。'];
    } else {
        $bonus = calcBonusByTiers($amount, $presetTiers);
        $total = $amount + $bonus;
        $orderNo = 'RC' . date('YmdHis') . strtoupper(bin2hex(random_bytes(2)));
        $methodName = $rechargeMethods[$method]['name'];

        // 使用者 ID（以 users.ID 為準）
        $postUid = 0;
        if (is_array($accountInfo) && !empty($accountInfo['ID'])) {
            $postUid = (int)$accountInfo['ID'];
        } elseif ($userId !== '') {
            $postUid = (int)$userId;
        }

        $ecpayMap = ecpay_method_map();
        $choosePayment = $ecpayMap[$method] ?? '';

        // 1) 每筆訂單先寫入資料庫（pending）
        $orderId = 0;
        $orderLink = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
        if ($orderLink) {
            mysqli_set_charset($orderLink, "utf8");
            $stmt = $orderLink->prepare(
                "INSERT INTO `recharge_orders`
                    (`order_no`, `uid`, `username`, `method_key`, `method_name`, `choose_payment`,
                     `amount`, `bonus`, `total_points`, `status`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->bind_param(
                "sissssiii",
                $orderNo, $postUid, $username, $method, $methodName, $choosePayment,
                $amount, $bonus, $total
            );
            $stmt->execute();
            $orderId = (int)$stmt->insert_id;
            $stmt->close();
            mysqli_close($orderLink);
        }

        // 2) 綠界支援的付款方式：導向綠界 AIO 付款頁
        if ($choosePayment !== '') {
            $input = ecpay_build_recharge_input($orderNo, $method, $amount);
            echo ecpay_build_form($input);
            exit();
        }

        // 3) 綠界不支援的付款方式（虛擬貨幣）：保留人工/示意付款資訊
        $payInfo = [
            'crypto' => ['USDT (TRC20)：TXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', '請於 30 分鐘內完成鏈上轉帳並保留交易雜湊。'],
        ];
        $_SESSION['recharge_result'] = [
            'ok' => true,
            'order_no' => $orderNo,
            'method' => $methodName,
            'amount' => $amount,
            'bonus' => $bonus,
            'total' => $total,
            'pay_info' => $payInfo[$method] ?? ['請依客服指示完成付款。'],
        ];
    }

    header("Location: recharge.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>踏雪笑傲 · 儲值點數 (尊爵水墨旗艦版)</title>
<link rel="stylesheet" href="assets/tailwind.css">
<!-- 本頁專用：補齊 Tailwind 工具類（貨幣資產顯示） -->
<link rel="stylesheet" href="assets/front-currency-tailwind.css">
<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&amp;family=Noto+Serif+TC:wght@400;500;600;700;900&amp;family=Noto+Sans+TC:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<!-- 樣式由 assets/tailwind.css 提供（編譯後靜態檔，不再使用 Tailwind CDN） -->
<style>
    :root {
        --vermilion: #be123c;
        --ice-blue: #0284c7;
        --ink-deep: #070a12;
    }
    body {
        background-color: var(--ink-deep);
        color: #1e293b;
        font-family: 'Noto Sans TC', sans-serif;
        min-height: 100vh;
        overflow-x: hidden;
        background-image:
            radial-gradient(ellipse at 50% -10%, rgba(30, 41, 59, 0.75) 0%, rgba(7, 10, 18, 0.98) 75%),
            radial-gradient(circle at 85% 15%, rgba(2, 132, 199, 0.12) 0%, transparent 45%),
            radial-gradient(circle at 10% 70%, rgba(190, 18, 60, 0.08) 0%, transparent 50%);
    }
    #snow-canvas {
        position: fixed;
        inset: 0;
        width: 100vw;
        height: 100vh;
        pointer-events: none;
        z-index: 1;
        transform: translateZ(0);
    }
    .snow-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(226, 232, 240, 0.95);
        box-shadow: 0 20px 45px -12px rgba(0, 0, 0, 0.42), 0 0 0 1px rgba(255, 255, 255, 0.8) inset;
        position: relative;
        transform: translateZ(0);
    }
    .ornament-corner-tl {
        position: absolute;
        top: -1px;
        left: -1px;
        width: 16px;
        height: 16px;
        border-top: 2.5px solid var(--vermilion);
        border-left: 2.5px solid var(--vermilion);
        border-top-left-radius: 6px;
        pointer-events: none;
    }
    .ornament-corner-br {
        position: absolute;
        bottom: -1px;
        right: -1px;
        width: 16px;
        height: 16px;
        border-bottom: 2.5px solid var(--ice-blue);
        border-right: 2.5px solid var(--ice-blue);
        border-bottom-right-radius: 6px;
        pointer-events: none;
    }
    .seal-tag {
        background-color: #fff1f2;
        color: #be123c;
        border: 1px solid #fecdd3;
        font-weight: 600;
    }
    .method-card {
        transition: all 0.24s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
        background: #ffffff;
        position: relative;
        overflow: hidden;
    }
    .method-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 14px 28px -6px rgba(0,0,0,0.09), 0 4px 12px -2px rgba(0,0,0,0.04);
        border-color: #cbd5e1;
    }
    .method-radio:checked + .method-card {
        border-color: #be123c;
        background: linear-gradient(180deg, #ffffff 0%, #fffcfc 100%);
        box-shadow: 0 0 0 2px #be123c, 0 16px 32px -8px rgba(190, 18, 60, 0.18);
    }
    .method-radio:checked + .method-card .method-check-badge {
        opacity: 1;
        transform: scale(1);
    }
    .amount-chip {
        transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
        position: relative;
    }
    .amount-chip:hover {
        transform: translateY(-2px);
        border-color: #cbd5e1;
        box-shadow: 0 8px 18px -4px rgba(0,0,0,0.06);
    }
    .amount-radio:checked + .amount-chip {
        background: linear-gradient(135deg, #be123c 0%, #9f1239 100%);
        color: #ffffff;
        border-color: #be123c;
        box-shadow: 0 8px 20px -4px rgba(190, 18, 60, 0.35);
        transform: translateY(-2px);
    }
    .amount-radio:checked + .amount-chip .chip-label {
        color: rgba(255, 255, 255, 0.85);
    }
    .amount-radio:checked + .amount-chip .chip-val {
        color: #ffffff;
    }
    .amount-radio:checked + .amount-chip .chip-bonus-badge {
        background-color: #fef08a;
        color: #854d0e;
        border-color: #fef08a;
    }
    .gold-glow-box {
        background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        border: 1px solid #fde68a;
    }
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: #0b0f19; }
    ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 3px; }
</style>
</head>
<body class="relative flex flex-col justify-between selection:bg-rose-900 selection:text-white min-h-screen">
<canvas id="snow-canvas"></canvas>

<div class="fixed top-0 left-1/2 -translate-x-1/2 w-[850px] h-[300px] bg-amber-500/10 rounded-full blur-[140px] pointer-events-none -z-0"></div>

<!-- 頂部導覽列 -->
<header class="relative z-20 border-b border-slate-800/80 bg-[#090d16]/90 backdrop-blur-md sticky top-0 shadow-xl">
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
<div class="flex items-center justify-between h-20">
    <a class="group flex items-center space-x-3.5 no-underline" href="home.php">
        <div class="w-12 h-12 rounded-xl bg-white p-1 shadow-md border border-slate-200 flex items-center justify-center overflow-hidden transition-transform group-hover:scale-105">
            <img alt="踏雪笑傲 Logo" class="w-full h-full object-contain" src="assets/logo.svg">
        </div>
        <div>
            <div class="flex items-center space-x-2">
                <span class="text-2xl font-bold tracking-wider text-slate-100 font-serif">踏雪笑傲</span>
                <span class="seal-tag text-[11px] px-2 py-0.5 rounded tracking-normal">儲值中心</span>
            </div>
            <p class="text-[11px] text-slate-400 font-mono tracking-widest uppercase mt-0.5">SNOW WANDERER ONLINE</p>
        </div>
    </a>

    <nav class="hidden md:flex items-center space-x-2">
        <a class="px-3.5 py-2 text-xs text-slate-300 hover:text-white hover:bg-slate-800/60 rounded-lg transition-colors flex items-center space-x-2" href="home.php">
            <i class="fa-solid fa-house-chimney text-slate-400"></i><span class="">帳號總覽</span>
        </a>
        <a class="px-3.5 py-2 text-xs text-slate-300 hover:text-white hover:bg-slate-800/60 rounded-lg transition-colors flex items-center space-x-2" href="usercontrol.php">
            <i class="fa-regular fa-address-card text-slate-400"></i><span class="">帳號安全</span>
        </a>
        <a class="px-3.5 py-2 text-xs text-amber-300 font-semibold bg-amber-950/40 border border-amber-700/50 rounded-lg flex items-center space-x-2 shadow-sm" href="recharge.php">
            <i class="fa-solid fa-coins text-amber-400"></i><span class="">儲值點數</span>
        </a>
        <a class="px-3.5 py-2 text-xs text-slate-300 hover:text-white hover:bg-slate-800/60 rounded-lg transition-colors flex items-center space-x-2" href="exchange.php">
            <i class="fa-solid fa-right-left text-amber-400"></i><span class="">兌換中心</span>
        </a>
        <button type="button" onclick="openHistoryModal()" class="px-3.5 py-2 text-xs text-slate-300 hover:text-white hover:bg-slate-800/60 rounded-lg transition-colors flex items-center space-x-2 cursor-pointer">
            <i class="fa-solid fa-clock-rotate-left text-sky-400"></i><span class="">歷史訂單</span>
        </button>

        <div class="pl-4 ml-2 border-l border-slate-800 flex items-center space-x-3">
            <div class="flex items-center space-x-2.5 bg-slate-900/90 border border-slate-700/70 px-3.5 py-1.5 rounded-full shadow-inner">
                <div class="w-6 h-6 rounded-full bg-gradient-to-tr from-rose-700 to-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-sm">俠</div>
                <div class="text-left">
                    <p class="text-xs font-semibold text-slate-200 leading-tight max-w-[120px] truncate"><?= htmlspecialchars($username) ?></p>
                    <span class="text-[10px] text-emerald-400 flex items-center gap-1 font-mono leading-none mt-0.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>已登入
                    </span>
                </div>
            </div>
            <button class="px-3 py-1.5 rounded-lg border border-rose-800/50 bg-rose-950/30 text-rose-300 hover:bg-rose-900/50 hover:text-white text-xs font-medium transition-colors flex items-center gap-1.5" onclick="openLogoutModal()" title="登出系統">
                <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i><span class="">登出</span>
            </button>
        </div>
    </nav>

    <div class="flex md:hidden items-center">
        <button class="p-2 text-slate-300 hover:text-white rounded-lg focus:outline-none bg-slate-800/60 border border-slate-700" id="mobile-toggle">
            <i class="fa-solid fa-bars text-lg"></i>
        </button>
    </div>
</div>
</div>

<div class="hidden md:hidden border-t border-slate-800 bg-[#090d16] px-5 py-4 space-y-2 shadow-2xl" id="mobile-menu">
    <div class="pb-3 border-b border-slate-800 flex items-center justify-between">
        <span class="text-xs text-slate-400">當前登入帳號：</span>
        <span class="text-xs text-sky-400 font-bold font-mono"><?= htmlspecialchars($username) ?></span>
    </div>
    <a class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60" href="home.php"><i class="fa-solid fa-house-chimney mr-2 text-slate-400"></i> 帳號總覽</a>
    <a class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60" href="usercontrol.php"><i class="fa-regular fa-address-card mr-2 text-slate-400"></i> 帳號安全</a>
    <a class="block px-3 py-2 rounded-lg text-sm text-amber-300 bg-amber-950/40 font-medium" href="recharge.php"><i class="fa-solid fa-coins mr-2 text-amber-400"></i> 儲值點數</a>
    <a class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60" href="exchange.php"><i class="fa-solid fa-right-left mr-2 text-amber-400"></i> 兌換中心</a>
    <button class="w-full text-left px-3 py-2 rounded-lg text-sm text-sky-300 hover:bg-slate-800/60" onclick="openHistoryModal()"><i class="fa-solid fa-clock-rotate-left mr-2 text-sky-400"></i> 歷史訂單</button>
    <button class="w-full text-left px-3 py-2 rounded-lg text-sm text-rose-400 hover:bg-rose-950/40" onclick="openLogoutModal()"><i class="fa-solid fa-arrow-right-from-bracket mr-2"></i> 安全登出</button>
</div>
</header>

<main class="relative z-10 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10 flex-1 w-full space-y-8">

    <!-- 儲值結果提示 -->
    <?php if (is_array($result)): ?>
        <?php if (!empty($result['ok'])): ?>
        <div class="snow-card rounded-2xl p-6 sm:p-8">
            <div class="ornament-corner-tl"></div><div class="ornament-corner-br"></div>
            <div class="flex items-start gap-4">
                <div class="w-14 h-14 shrink-0 rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center text-emerald-600 text-2xl">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="flex-1">
                    <h2 class="text-lg font-bold text-slate-800 font-serif mb-1">儲值訂單已建立</h2>
                    <p class="text-xs text-slate-500 mb-4">請依下方資訊完成付款，付款完成後系統將自動為帳號加值點數。</p>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs mb-4">
                        <div class="p-3 rounded-lg bg-slate-50 border border-slate-200">
                            <span class="block text-slate-400 mb-0.5">訂單編號</span>
                            <span class="inline-flex items-center gap-1.5">
                                <strong class="font-mono text-slate-800" style="word-break:break-all;"><?= htmlspecialchars($result['order_no']) ?></strong>
                                <button type="button" class="js-copy w-6 h-6 shrink-0 rounded-md border border-slate-200 bg-white hover:bg-slate-100 text-slate-500 hover:text-slate-700 transition-colors flex items-center justify-center cursor-pointer" data-copy="<?= htmlspecialchars($result['order_no']) ?>" title="複製訂單編號">
                                    <i class="fa-regular fa-copy text-[10px]"></i>
                                </button>
                            </span>
                        </div>
                        <div class="p-3 rounded-lg bg-slate-50 border border-slate-200">
                            <span class="block text-slate-400 mb-0.5">儲值方式</span>
                            <strong class="text-slate-800"><?= htmlspecialchars($result['method']) ?></strong>
                        </div>
                        <div class="p-3 rounded-lg bg-slate-50 border border-slate-200">
                            <span class="block text-slate-400 mb-0.5">儲值金額</span>
                            <strong class="font-mono text-slate-800">NT$ <?= number_format($result['amount']) ?></strong>
                        </div>
                        <div class="p-3 rounded-lg bg-amber-50 border border-amber-200">
                            <span class="block text-amber-700 mb-0.5">獲得點數</span>
                            <strong class="font-mono text-amber-700"><?= number_format($result['total']) ?> 點</strong>
                        </div>
                    </div>
                    <div class="p-4 rounded-lg bg-slate-100/80 border-l-4 border-rose-600 text-xs text-slate-700 space-y-1">
                        <div class="font-bold text-slate-800 mb-1">付款資訊</div>
                        <?php foreach ($result['pay_info'] as $line): ?>
                            <div>• <?= htmlspecialchars($line) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="p-4 rounded-lg border border-rose-300 bg-rose-50 text-rose-700 text-sm flex items-center gap-2.5">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span><?= htmlspecialchars($result['message']) ?></span>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- 帳號資產與身分面板 -->
    <div class="snow-card rounded-2xl p-6 sm:p-7">
        <div class="ornament-corner-tl"></div><div class="ornament-corner-br"></div>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-6">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-slate-50 via-slate-100 to-slate-200 border-2 border-white shadow-md flex items-center justify-center relative overflow-hidden">
                    <i class="fa-solid fa-user-shield text-slate-500 text-2xl relative z-10"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <h1 class="text-xl font-bold text-slate-900 font-serif tracking-wide"><?= htmlspecialchars($username) ?></h1>
                        <?php if ($userId !== ''): ?>
                        <span class="seal-tag text-xs px-2 py-0.5 rounded font-mono">UID: <?= htmlspecialchars($userId) ?></span>
                        <?php endif; ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] font-medium">
                            <i class="fa-solid fa-circle-check text-[10px]"></i> 帳號認證良好
                        </span>
                    </div>
                    <div class="flex flex-wrap items-center gap-y-1 gap-x-4 text-xs text-slate-500 mt-1.5">
                        <span class="flex items-center gap-1.5">
                            <i class="fa-regular fa-envelope text-slate-400"></i>
                            <span class=""><?= htmlspecialchars($displayEmail) ?></span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <i class="fa-solid fa-mobile-screen text-slate-400"></i>
                            <span class=""><?= htmlspecialchars($displayMobile) ?></span>
                        </span>
                        <span class="flex items-center gap-1.5">
                            <i class="fa-regular fa-calendar-check text-slate-400"></i>
                            <span class="">註冊於 <?= htmlspecialchars($displayRegDate) ?></span>
                        </span>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap sm:flex-nowrap items-center justify-end gap-3 sm:gap-4 shrink-0">
                <div class="gold-glow-box rounded-2xl px-5 py-3.5 shadow-sm border border-amber-300/60">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-sky-500/10 border border-sky-300 flex items-center justify-center text-sky-700 text-lg shrink-0">
                            <i class="fa-solid fa-coins"></i>
                        </div>
                        <div class="text-right">
                            <span class="text-[11px] font-medium text-slate-500 tracking-wider block">可用<?= htmlspecialchars($pointsName) ?></span>
                            <div class="flex items-baseline justify-end gap-1">
                                <span class="text-2xl sm:text-3xl font-extrabold text-sky-700 font-mono tracking-tight leading-none"><?= htmlspecialchars($displayPoints) ?></span>
                                <span class="text-xs font-bold text-sky-700">點</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 justify-end mt-1.5 pt-1.5 border-t border-amber-200/70 text-[11px] text-amber-700">
                        <i class="fa-solid fa-gem"></i><span><?= htmlspecialchars($yuanbaoName) ?> <?= htmlspecialchars($displayYuanbao) ?></span>
                    </div>
                </div>
                <a href="exchange.php" class="flex items-center justify-center gap-2 rounded-2xl border border-amber-300 bg-gradient-to-r from-amber-50 to-white hover:from-amber-100 hover:border-amber-400 text-amber-800 px-5 py-3.5 text-xs font-bold transition-all shadow-sm no-underline">
                    <i class="fa-solid fa-right-left text-sm"></i>
                    <span class="">兌換<?= htmlspecialchars($yuanbaoName) ?></span>
                </a>
                <button type="button" onclick="openHistoryModal()" class="flex items-center justify-center gap-2 rounded-2xl border border-sky-200 bg-sky-50 hover:bg-sky-100 hover:border-sky-300 text-sky-700 px-5 py-3.5 text-xs font-bold transition-all shadow-sm cursor-pointer">
                    <i class="fa-solid fa-clock-rotate-left text-sm"></i>
                    <span class="">歷史訂單</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 儲值表單核心架構 -->
    <form method="POST" action="recharge.php" id="recharge-form" class="space-y-8" novalidate>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($rechargeToken) ?>">

        <!-- 步驟一：選擇儲值管道 -->
        <div class="snow-card rounded-2xl p-6 sm:p-7">
            <div class="ornament-corner-tl"></div><div class="ornament-corner-br"></div>
            <div class="flex items-center justify-between pb-4 mb-6 border-b border-slate-200">
                <h2 class="text-base font-bold text-slate-900 font-serif flex items-center gap-2.5">
                    <span class="w-7 h-7 rounded-full bg-rose-700 text-white text-xs flex items-center justify-center font-mono font-bold shadow-sm">1</span>
                    選擇儲值管道
                </h2>
                <div class="flex items-center gap-1.5 text-[11px] text-slate-500 font-medium">
                    <i class="fa-solid fa-shield-halved text-emerald-600 text-xs"></i>
                    <span class="">256-bit 金流加密安全防護</span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($rechargeMethods as $key => $m): ?>
                    <?php $mEnabled = !empty($m['enabled']); ?>
                    <?php $decor = $methodDecor[$key] ?? ['badge' => '', 'iconbg' => 'from-slate-50 via-slate-100 to-slate-200', 'icon' => 'text-slate-600', 'badgeclr' => 'text-slate-600 border-slate-200']; ?>
                    <div class="relative">
                        <input type="radio" name="method" id="method-<?= $key ?>" value="<?= $key ?>" class="method-radio hidden" required <?php if (!$mEnabled) echo 'disabled'; ?>>
                        <label for="method-<?= $key ?>" class="method-card block h-full rounded-2xl border-2 border-slate-200 p-5 text-left flex flex-col justify-between <?php if (!$mEnabled) echo 'opacity-60 pointer-events-none'; ?>">
                            <div class="method-check-badge absolute top-3 right-3 w-5 h-5 rounded-full bg-rose-700 text-white flex items-center justify-center text-[10px] opacity-0 scale-75 transition-all">
                                <i class="fa-solid fa-check"></i>
                            </div>
                            <div class="flex flex-col items-center text-center pt-2 pb-1">
                                <div class="relative mb-4 group">
                                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br <?= $decor['iconbg'] ?> border-2 border-white shadow-lg flex items-center justify-center <?= $decor['icon'] ?> text-3xl transition-transform duration-300 group-hover:scale-105">
                                        <i class="fa-solid <?= $m['icon'] ?>"></i>
                                    </div>
                                    <?php if ($decor['badge'] !== ''): ?>
                                    <span class="absolute -bottom-2 -right-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-white <?= $decor['badgeclr'] ?> border shadow-sm"><?= htmlspecialchars($decor['badge']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-base font-bold text-slate-900 mb-1.5 font-serif"><?= htmlspecialchars($m['name']) ?></div>
                                <div class="text-[11px] text-slate-500 leading-relaxed px-1"><?= $m['desc'] ?></div>
                                <?php if (!$mEnabled): ?>
                                    <div class="mt-3 inline-flex items-center gap-1 px-2 py-0.5 rounded bg-slate-100 text-slate-500 text-[10px] font-medium border border-slate-200">
                                        <i class="fa-solid fa-lock text-[9px]"></i>
                                        <span>暫時無法使用該支付方式</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 步驟二：選擇儲值金額 -->
        <div class="snow-card rounded-2xl p-6 sm:p-7">
            <div class="ornament-corner-tl"></div><div class="ornament-corner-br"></div>
            <div class="flex items-center justify-between pb-4 mb-6 border-b border-slate-200">
                <h2 class="text-base font-bold text-slate-900 font-serif flex items-center gap-2.5">
                    <span class="w-7 h-7 rounded-full bg-rose-700 text-white text-xs flex items-center justify-center font-mono font-bold shadow-sm">2</span>
                    選擇儲值額度方案
                </h2>
                <span class="text-[11px] text-amber-700 font-medium flex items-center gap-1">
                    <i class="fa-solid fa-gift text-amber-600"></i> 儲值愈多，回饋愈多
                </span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                <?php foreach ($presetTiers as $t): ?>
                <?php $amt = (int)$t['amount']; $b = calcBonusByTiers($amt, $presetTiers); ?>
                <div>
                    <input type="radio" name="amount_preset" id="amt-<?= $amt ?>" value="<?= $amt ?>" class="amount-radio hidden">
                    <label for="amt-<?= $amt ?>" class="amount-chip flex flex-col items-center justify-center rounded-2xl border-2 border-slate-200 bg-white p-4 h-full">
                        <span class="chip-label text-xs text-slate-400 font-medium mb-0.5">實付台幣</span>
                        <span class="chip-val text-base font-extrabold font-mono text-slate-900 leading-tight">NT$ <?= number_format($amt) ?></span>
                        <?php if ($b > 0): ?>
                        <div class="chip-bonus-badge mt-2 text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-200 transition-colors">+<?= number_format($b) ?> 加贈</div>
                        <?php else: ?>
                        <div class="chip-label mt-2 text-[10px] text-slate-400 font-medium">基本方案</div>
                        <?php endif; ?>
                    </label>
                </div>
                <?php endforeach; ?>
                <?php if (empty($presetTiers)): ?>
                    <span class="text-[11px] text-slate-400">目前尚無預設金額方案，請使用自訂金額儲值。</span>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-stretch">
                <div class="lg:col-span-7 bg-slate-50 border border-slate-200 rounded-2xl p-5 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-bold text-slate-800" for="amount_custom">自訂儲值金額</label>
                            <span class="text-[11px] text-slate-400 font-mono">範圍：NT$ <?= number_format($rechargeMin) ?> ～ <?= number_format($rechargeMax) ?></span>
                        </div>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400 font-bold text-sm">NT$</span>
                            <input type="number" name="amount_custom" id="amount_custom" min="<?= (int)$rechargeMin ?>" max="<?= (int)$rechargeMax ?>" step="1" placeholder="輸入其他金額" class="w-full pl-14 pr-4 py-3 rounded-xl border border-slate-300 bg-white text-slate-800 text-sm font-semibold placeholder:font-normal placeholder:text-slate-400 focus:outline-none focus:border-ice-600 focus:ring-2 focus:ring-sky-200 transition-all">
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-2.5 flex items-center gap-1.5">
                        <i class="fa-solid fa-circle-question text-slate-400 text-xs"></i>
                        <span class="">輸入自訂金額後，右側將自動依對應級距為您核算加碼回饋點數。</span>
                    </p>
                </div>

                <div class="lg:col-span-5 rounded-2xl bg-gradient-to-br from-slate-900 to-ink-850 text-white p-5 border border-slate-700/80 shadow-inner flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between text-xs text-slate-300 mb-3 border-b border-slate-800 pb-2.5">
                            <span class="flex items-center gap-1.5"><i class="fa-solid fa-receipt text-slate-400"></i> 本次訂單核算</span>
                            <span class="text-[10px] font-mono text-slate-400">REALTIME CALC</span>
                        </div>
                        <div class="space-y-2 text-xs">
                            <div class="flex items-center justify-between text-slate-300">
                                <span class="">基礎點數 (1:1)</span>
                                <span id="preview-base" class="font-mono font-semibold text-slate-200">0 點</span>
                            </div>
                            <div class="flex items-center justify-between text-slate-300">
                                <span class="flex items-center gap-1 text-emerald-400"><i class="fa-solid fa-sparkles text-[10px]"></i> 方案加碼回饋</span>
                                <span id="preview-bonus" class="font-mono text-emerald-400 font-bold">+0 點</span>
                            </div>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-800 mt-3 flex items-end justify-between">
                        <div>
                            <span class="text-[11px] text-slate-400 block mb-0.5">總計入帳點數</span>
                            <span class="text-xs text-amber-400/90 font-medium">即時發送至帳號</span>
                        </div>
                        <div class="text-right">
                            <span id="preview-total" class="font-mono text-amber-400 font-extrabold text-2xl tracking-tight leading-none">0</span>
                            <span class="text-xs font-bold text-amber-300 ml-0.5">點</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 步驟三：確認與送出儲值訂單 -->
        <div class="snow-card rounded-2xl p-6 sm:p-7">
            <div class="ornament-corner-tl"></div><div class="ornament-corner-br"></div>
            <div class="flex flex-col sm:flex-row items-center justify-between gap-5">
                <div class="space-y-2 text-center sm:text-left flex-1">
                    <p class="text-xs text-slate-600 flex items-center justify-center sm:justify-start gap-1.5 font-medium">
                        <i class="fa-solid fa-shield-check text-emerald-600 text-sm"></i>
                        <span class="">官方防護 · 加密金流認證 · 24 小時即時核銷加值</span>
                    </p>
                    <div class="flex items-start justify-center sm:justify-start gap-2 pt-0.5">
                        <input type="checkbox" id="agreement-checkbox" checked="" class="mt-0.5 w-4 h-4 rounded text-amber-600 border-slate-300 focus:ring-amber-500 cursor-pointer transition-all" onchange="toggleSubmitButton(this.checked)">
                        <label for="agreement-checkbox" class="text-[11px] text-slate-500 leading-relaxed cursor-pointer select-none text-left">
                            我已詳細閱讀並同意遵守《踏雪笑傲》遊戲服務與儲值消費規章及使用者條款。
                        </label>
                    </div>
                    <p id="agreement-warning" class="hidden text-[11px] text-rose-600 font-medium">
                        <i class="fa-solid fa-circle-question text-[10px]"></i> 請先勾選並同意服務與儲值消費規章後再送出訂單
                    </p>
                </div>
                <div class="w-full sm:w-auto shrink-0">
                    <button id="submit-order-btn" type="button" onclick="handleSubmitOrder()" class="w-full sm:w-auto px-9 py-3.5 rounded-xl bg-gradient-to-r from-amber-600 via-amber-700 to-amber-800 hover:from-amber-700 hover:to-amber-900 text-white font-bold text-sm shadow-lg hover:shadow-amber-900/30 transition-all flex items-center justify-center gap-2.5 tracking-wide transform hover:-translate-y-0.5 cursor-pointer">
                        <i class="fa-solid fa-coins text-amber-200"></i>
                        <span class="">確認送出儲值訂單</span>
                        <i class="fa-solid fa-arrow-right text-xs text-amber-200"></i>
                    </button>
                </div>
            </div>
        </div>
    </form>
</main>

<!-- 頁尾宣告 -->
<footer class="relative z-10 border-t border-slate-800 bg-[#070a12] py-8 text-center text-xs text-slate-400 mt-auto">
    <div class="max-w-7xl mx-auto px-4 space-y-3">
        <div class="flex items-center justify-center space-x-2 text-slate-200 font-serif font-bold text-sm">
            <span class="">踏雪笑傲 · 繁體中文官方正版</span>
        </div>
        <p class="text-slate-400 text-xs">
            <a class="hover:text-slate-200 mx-2 transition-colors" href="home.php">帳號總覽</a> ·
            <a class="hover:text-slate-200 mx-2 transition-colors" href="usercontrol.php">安全中心</a> ·
            <a class="hover:text-slate-200 mx-2 transition-colors text-amber-400 font-semibold" href="recharge.php">儲值點數</a> ·
            <a class="hover:text-slate-200 mx-2 transition-colors" href="download.php">遊戲下載</a>
        </p>
        <p class="text-[11px] text-slate-500 font-mono">© 2025 踏雪笑傲營運團隊 All Rights Reserved. 系統操作均納入稽核日誌。</p>
    </div>
</footer>

<!-- 登出確認對話框 -->
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-300 px-4" id="logout-modal">
    <div class="snow-card max-w-sm w-full rounded-2xl p-6 text-center transform scale-95 transition-transform duration-300 border border-slate-200 shadow-2xl" id="logout-card">
        <div class="ornament-corner-tl"></div><div class="ornament-corner-br"></div>
        <div class="w-14 h-14 mx-auto rounded-2xl bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-700 text-2xl mb-4 shadow-inner">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-800 font-serif mb-2">確認登出帳號？</h3>
        <p class="text-xs text-slate-500 leading-relaxed mb-6">您即將結束本次安全登入階段。若需再次使用儲值與帳號管理功能，需重新登入。</p>
        <div class="flex items-center justify-center space-x-3">
            <button class="w-1/2 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-bold transition-all" onclick="closeLogoutModal()">取消並返回</button>
            <a class="w-1/2 py-2.5 rounded-xl bg-gradient-to-r from-rose-700 to-rose-800 hover:from-rose-800 hover:to-rose-900 text-white text-xs font-bold transition-all text-center no-underline shadow-md" href="logout.php">確認登出</a>
        </div>
    </div>
</div>

<!-- 歷史訂單查詢子視窗 -->
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-300 px-3 sm:px-4" id="history-modal">
    <div class="snow-card w-full max-w-2xl rounded-2xl border border-slate-200 shadow-2xl transform scale-95 transition-transform duration-300 flex flex-col max-h-[88vh]" id="history-card">
        <div class="ornament-corner-tl"></div><div class="ornament-corner-br"></div>

        <!-- 標題列 -->
        <div class="flex items-start justify-between gap-4 p-5 sm:p-6 border-b border-slate-200">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-2xl bg-sky-50 border border-sky-200 flex items-center justify-center text-sky-600 text-xl shrink-0">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-800 font-serif">歷史儲值訂單</h3>
                    <p class="text-[11px] text-slate-500 mt-0.5">查詢您的儲值紀錄、訂單狀態與金額</p>
                </div>
            </div>
            <button type="button" onclick="closeHistoryModal()" class="w-9 h-9 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors flex items-center justify-center cursor-pointer" title="關閉">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- 查詢列 -->
        <div class="p-4 sm:px-6 bg-slate-50/70 border-b border-slate-200">
            <div class="flex flex-col sm:flex-row gap-2.5">
                <div class="relative flex-1">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><i class="fa-solid fa-magnifying-glass text-xs"></i></span>
                    <input type="text" id="history-keyword" placeholder="輸入訂單編號或付款方式…" class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-slate-300 bg-white text-slate-800 text-xs focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-100 transition-all">
                </div>
                <select id="history-status" class="px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-slate-700 text-xs font-medium focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-100 transition-all">
                    <option value="all">全部狀態</option>
                    <option value="pending">待付款</option>
                    <option value="paid">已付款</option>
                    <option value="failed">付款失敗</option>
                    <option value="expired">已逾期</option>
                    <option value="cancelled">已取消</option>
                </select>
                <button type="button" onclick="searchHistory()" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-sky-600 to-sky-700 hover:from-sky-700 hover:to-sky-800 text-white text-xs font-bold shadow-md transition-all flex items-center justify-center gap-2 cursor-pointer">
                    <i class="fa-solid fa-magnifying-glass"></i> 查詢
                </button>
            </div>
            <div class="flex items-center gap-3 mt-3 text-[11px] text-slate-500">
                <span id="history-summary">—</span>
                <button type="button" onclick="resetHistoryFilter()" class="text-sky-700 hover:text-sky-800 font-medium underline-offset-2 hover:underline hidden cursor-pointer" id="history-reset">清除篩選</button>
            </div>
        </div>

        <!-- 訂單清單 -->
        <div class="p-4 sm:px-6 overflow-y-auto flex-1" id="history-list">
            <div class="py-16 text-center text-slate-400 text-sm">
                <i class="fa-solid fa-spinner fa-spin text-2xl mb-3 block"></i>
                載入中…
            </div>
        </div>

        <!-- 分頁 -->
        <div class="hidden px-4 sm:px-6 py-3 border-t border-slate-200 bg-slate-50/70 items-center justify-between gap-3" id="history-pager">
            <span class="text-[11px] text-slate-500" id="history-pager-info">—</span>
            <div class="flex items-center gap-2">
                <button type="button" id="history-prev" onclick="changeHistoryPage(-1)" class="px-3 py-1.5 rounded-lg border border-slate-300 bg-white text-slate-600 hover:bg-slate-100 text-xs font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer">
                    <i class="fa-solid fa-chevron-left text-[10px]"></i> 上一頁
                </button>
                <button type="button" id="history-next" onclick="changeHistoryPage(1)" class="px-3 py-1.5 rounded-lg border border-slate-300 bg-white text-slate-600 hover:bg-slate-100 text-xs font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer">
                    下一頁 <i class="fa-solid fa-chevron-right text-[10px]"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 取消支付確認對話框 -->
<div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/75 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-300 px-4" id="cancel-modal">
    <div class="snow-card max-w-sm w-full rounded-2xl p-6 text-center transform scale-95 transition-transform duration-300 border border-slate-200 shadow-2xl" id="cancel-card">
        <div class="ornament-corner-tl"></div><div class="ornament-corner-br"></div>
        <div class="w-14 h-14 mx-auto rounded-2xl bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-700 text-2xl mb-4 shadow-inner">
            <i class="fa-solid fa-ban"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-800 font-serif mb-2">確認取消支付？</h3>
        <p class="text-xs text-slate-500 leading-relaxed mb-3">取消後此訂單將無法繼續付款，如需儲值請重新建立訂單。</p>
        <p class="text-[11px] text-slate-400 font-mono mb-6">訂單編號：<span id="cancel-confirm-order" class="text-slate-600 font-bold">—</span></p>
        <div class="flex items-center justify-center space-x-3">
            <button type="button" class="w-1/2 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-bold transition-all cursor-pointer" onclick="closeCancelModal()">返回</button>
            <button type="button" id="cancel-confirm-btn" class="w-1/2 py-2.5 rounded-xl bg-gradient-to-r from-rose-700 to-rose-800 hover:from-rose-800 hover:to-rose-900 text-white text-xs font-bold transition-all shadow-md cursor-pointer" onclick="confirmCancelOrder()">確認取消支付</button>
        </div>
    </div>
</div>

<script>
    // 行動端選單切換
    const mobileToggle = document.getElementById('mobile-toggle');
    const mobileMenu = document.getElementById('mobile-menu');
    if (mobileToggle && mobileMenu) {
        mobileToggle.addEventListener('click', () => mobileMenu.classList.toggle('hidden'));
    }

    // 登出彈窗控制
    const logoutModal = document.getElementById('logout-modal');
    const logoutCard = document.getElementById('logout-card');
    function openLogoutModal() {
        if (!logoutModal) return;
        logoutModal.classList.remove('opacity-0', 'pointer-events-none');
        logoutModal.classList.add('opacity-100');
        if (logoutCard) { logoutCard.classList.remove('scale-95'); logoutCard.classList.add('scale-100'); }
    }
    function closeLogoutModal() {
        if (!logoutModal) return;
        logoutModal.classList.add('opacity-0', 'pointer-events-none');
        logoutModal.classList.remove('opacity-100');
        if (logoutCard) { logoutCard.classList.remove('scale-100'); logoutCard.classList.add('scale-95'); }
    }
    if (logoutModal) {
        logoutModal.addEventListener('click', (e) => { if (e.target === logoutModal) closeLogoutModal(); });
    }

    // 後台設定的金額方案與回饋比例
    const RECHARGE_TIERS = <?php echo json_encode(array_map(function ($t) { return ['amount' => (int)$t['amount'], 'bonus_percent' => (int)$t['bonus_percent']]; }, $presetTiers)); ?>;
    const RECHARGE_MIN = <?php echo (int)$rechargeMin; ?>;
    const RECHARGE_MAX = <?php echo (int)$rechargeMax; ?>;
    const RECHARGE_TOKEN = <?php echo json_encode($rechargeToken); ?>;

    const customInput = document.getElementById('amount_custom');
    const presetRadios = document.querySelectorAll('input[name="amount_preset"]');
    const previewBase = document.getElementById('preview-base');
    const previewBonus = document.getElementById('preview-bonus');
    const previewTotal = document.getElementById('preview-total');

    function calcBonus(a) {
        let pct = 0;
        for (const t of RECHARGE_TIERS) {
            if (a >= t.amount) pct = t.bonus_percent;
        }
        return Math.floor(a * pct / 100);
    }

    function currentAmount() {
        const custom = parseInt(customInput.value, 10);
        if (!isNaN(custom) && custom > 0) return custom;
        const checked = document.querySelector('input[name="amount_preset"]:checked');
        if (checked) return parseInt(checked.value, 10);
        return RECHARGE_TIERS.length ? RECHARGE_TIERS[0].amount : 0;
    }

    function updatePreview() {
        const a = currentAmount();
        const bonus = a > 0 ? calcBonus(a) : 0;
        const total = a > 0 ? (a + bonus) : 0;
        if (previewBase) previewBase.textContent = a.toLocaleString() + ' 點';
        if (previewBonus) previewBonus.textContent = '+' + bonus.toLocaleString() + ' 點';
        if (previewTotal) previewTotal.textContent = total.toLocaleString();
    }

    presetRadios.forEach(r => r.addEventListener('change', () => {
        if (customInput) customInput.value = '';
        updatePreview();
    }));

    if (customInput) customInput.addEventListener('input', () => {
        presetRadios.forEach(r => { r.checked = false; });
        updatePreview();
    });
    updatePreview();

    // 同意條款後才可送出
    function toggleSubmitButton(isChecked) {
        const btn = document.getElementById('submit-order-btn');
        const warning = document.getElementById('agreement-warning');
        if (!btn) return;
        if (isChecked) {
            btn.disabled = false;
            btn.classList.remove('opacity-50', 'cursor-not-allowed', 'pointer-events-none');
            btn.classList.add('hover:from-amber-700', 'hover:to-amber-900', 'hover:-translate-y-0.5', 'cursor-pointer');
            if (warning) warning.classList.add('hidden');
        } else {
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed', 'pointer-events-none');
            btn.classList.remove('hover:from-amber-700', 'hover:to-amber-900', 'hover:-translate-y-0.5', 'cursor-pointer');
        }
    }
    function handleSubmitOrder() {
        const checkbox = document.getElementById('agreement-checkbox');
        const warning = document.getElementById('agreement-warning');
        if (!checkbox || !checkbox.checked) {
            if (warning) warning.classList.remove('hidden');
            return false;
        }
        document.getElementById('recharge-form').submit();
    }

    // ===== 歷史訂單查詢 =====
    const HISTORY_STATUS_META = {
        paid:      { text: '已付款',   cls: 'bg-emerald-50 text-emerald-700 border-emerald-200', dot: 'bg-emerald-500', bar: 'border-l-emerald-500' },
        pending:   { text: '待付款',   cls: 'bg-amber-50 text-amber-700 border-amber-200',       dot: 'bg-amber-500',   bar: 'border-l-amber-500' },
        failed:    { text: '付款失敗', cls: 'bg-rose-50 text-rose-700 border-rose-200',          dot: 'bg-rose-500',    bar: 'border-l-rose-500' },
        expired:   { text: '已逾期',   cls: 'bg-slate-100 text-slate-600 border-slate-300',       dot: 'bg-slate-400',   bar: 'border-l-slate-400' },
        cancelled: { text: '已取消',   cls: 'bg-slate-100 text-slate-600 border-slate-300',       dot: 'bg-slate-400',   bar: 'border-l-slate-400' },
    };
    const HISTORY_DEFAULT_META = { text: '未知', cls: 'bg-slate-100 text-slate-600 border-slate-300', dot: 'bg-slate-400', bar: 'border-l-slate-400' };

    const historyModal = document.getElementById('history-modal');
    const historyCard = document.getElementById('history-card');
    const historyList = document.getElementById('history-list');
    const historyKeyword = document.getElementById('history-keyword');
    const historyStatus = document.getElementById('history-status');
    const historySummary = document.getElementById('history-summary');
    const historyReset = document.getElementById('history-reset');
    const historyPager = document.getElementById('history-pager');
    const historyPagerInfo = document.getElementById('history-pager-info');
    const historyPrev = document.getElementById('history-prev');
    const historyNext = document.getElementById('history-next');
    let historyLoaded = false;
    let historyDebounce = null;
    let historyPage = 1;
    let historyPages = 1;
    let historyTimers = [];
    let historyServerOffset = 0;
    let historyRefreshHandle = null;

    function openHistoryModal() {
        if (!historyModal) return;
        historyModal.classList.remove('opacity-0', 'pointer-events-none');
        historyModal.classList.add('opacity-100');
        if (historyCard) { historyCard.classList.remove('scale-95'); historyCard.classList.add('scale-100'); }
        document.body.style.overflow = 'hidden';
        loadHistory(!historyLoaded);
    }
    function closeHistoryModal() {
        if (!historyModal) return;
        historyModal.classList.add('opacity-0', 'pointer-events-none');
        historyModal.classList.remove('opacity-100');
        if (historyCard) { historyCard.classList.remove('scale-100'); historyCard.classList.add('scale-95'); }
        document.body.style.overflow = '';
        clearHistoryTimers();
    }
    if (historyModal) {
        historyModal.addEventListener('click', (e) => { if (e.target === historyModal) closeHistoryModal(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && historyModal.classList.contains('opacity-100')) closeHistoryModal();
        });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function fmtMoney(n) { return 'NT$ ' + Number(n || 0).toLocaleString(); }
    function fmtPoints(n) { return Number(n || 0).toLocaleString() + ' 點'; }

    function resetHistoryFilter() {
        if (historyKeyword) historyKeyword.value = '';
        if (historyStatus) historyStatus.value = 'all';
        historyPage = 1;
        loadHistory(true);
    }

    function searchHistory() {
        historyPage = 1;
        loadHistory(true);
    }

    function changeHistoryPage(delta) {
        const target = historyPage + delta;
        if (target < 1 || target > historyPages) return;
        historyPage = target;
        loadHistory(true);
    }

    // ===== 待付款訂單倒數與操作 =====
    function clearHistoryTimers() {
        historyTimers.forEach(t => clearInterval(t));
        historyTimers = [];
        if (historyRefreshHandle) {
            clearTimeout(historyRefreshHandle);
            historyRefreshHandle = null;
        }
    }

    function serverNowSec() {
        return Math.floor(Date.now() / 1000) + historyServerOffset;
    }

    function fmtRemain(sec) {
        if (sec <= 0) return '00:00:00';
        let s = sec;
        const d = Math.floor(s / 86400); s %= 86400;
        const h = Math.floor(s / 3600); s %= 3600;
        const m = Math.floor(s / 60);
        const ss = s % 60;
        const pad = n => String(n).padStart(2, '0');
        return (d > 0 ? d + '天 ' : '') + pad(h) + ':' + pad(m) + ':' + pad(ss);
    }

    function scheduleHistoryRefresh() {
        if (historyRefreshHandle) return;
        historyRefreshHandle = setTimeout(() => {
            historyRefreshHandle = null;
            loadHistory(false);
        }, 1200);
    }

    function startHistoryTimers() {
        clearHistoryTimers();
        const els = historyList ? historyList.querySelectorAll('.js-expire') : [];
        els.forEach(el => {
            const expires = Number(el.getAttribute('data-expires')) || 0;
            if (!expires) return;
            const tick = () => {
                const remain = expires - serverNowSec();
                if (remain <= 0) {
                    el.textContent = '00:00:00';
                    clearInterval(timer);
                    scheduleHistoryRefresh();
                    return;
                }
                el.textContent = fmtRemain(remain);
            };
            const timer = setInterval(tick, 1000);
            historyTimers.push(timer);
            tick();
        });
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(() => true).catch(() => fallbackCopy(text));
        }
        return Promise.resolve(fallbackCopy(text));
    }

    function fallbackCopy(text) {
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-1000px';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (e) {
            return false;
        }
    }

    function submitRepay(orderNo) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'recharge_order_action.php';
        form.style.display = 'none';
        form.innerHTML = '<input type="hidden" name="action" value="repay">'
            + '<input type="hidden" name="order_no" value="' + escapeHtml(orderNo) + '">'
            + '<input type="hidden" name="form_token" value="' + escapeHtml(RECHARGE_TOKEN) + '">';
        document.body.appendChild(form);
        form.submit();
    }

    let pendingCancelOrder = '';
    function openCancelModal(orderNo) {
        pendingCancelOrder = orderNo;
        const orderEl = document.getElementById('cancel-confirm-order');
        if (orderEl) orderEl.textContent = orderNo;
        const modal = document.getElementById('cancel-modal');
        const card = document.getElementById('cancel-card');
        if (!modal) return;
        modal.classList.remove('opacity-0', 'pointer-events-none');
        modal.classList.add('opacity-100');
        if (card) { card.classList.remove('scale-95'); card.classList.add('scale-100'); }
    }
    function closeCancelModal() {
        const modal = document.getElementById('cancel-modal');
        const card = document.getElementById('cancel-card');
        if (!modal) return;
        modal.classList.add('opacity-0', 'pointer-events-none');
        modal.classList.remove('opacity-100');
        if (card) { card.classList.remove('scale-100'); card.classList.add('scale-95'); }
        pendingCancelOrder = '';
    }
    function confirmCancelOrder() {
        if (!pendingCancelOrder) return;
        const btn = document.getElementById('cancel-confirm-btn');
        if (btn) { btn.disabled = true; btn.classList.add('opacity-60', 'cursor-not-allowed'); }
        const body = new URLSearchParams({
            action: 'cancel',
            order_no: pendingCancelOrder,
            form_token: RECHARGE_TOKEN
        });
        fetch('recharge_order_action.php', {
            method: 'POST',
            body: body,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => r.json())
            .then(data => {
                if (data && data.ok) {
                    closeCancelModal();
                    historyLoaded = false;
                    loadHistory(false);
                } else {
                    alert((data && data.message) || '取消支付失敗，請稍後再試。');
                }
            })
            .catch(() => alert('網路連線異常，請稍後再試。'))
            .finally(() => {
                if (btn) { btn.disabled = false; btn.classList.remove('opacity-60', 'cursor-not-allowed'); }
            });
    }
    const cancelModalEl = document.getElementById('cancel-modal');
    if (cancelModalEl) {
        cancelModalEl.addEventListener('click', (e) => { if (e.target === cancelModalEl) closeCancelModal(); });
    }

    // 複製訂單編號（全域事件委派）
    document.addEventListener('click', (e) => {
        const copyBtn = e.target.closest('.js-copy');
        if (!copyBtn) return;
        const text = copyBtn.getAttribute('data-copy') || '';
        copyToClipboard(text).then(ok => {
            const icon = copyBtn.querySelector('i');
            if (icon) {
                const original = icon.className;
                icon.className = ok
                    ? 'fa-solid fa-check text-[10px] text-emerald-600'
                    : 'fa-solid fa-xmark text-[10px] text-rose-600';
                copyBtn.classList.toggle('border-emerald-300', ok);
                setTimeout(() => {
                    icon.className = original;
                    copyBtn.classList.remove('border-emerald-300');
                }, 1400);
            }
        });
    });

    // 歷史訂單清單事件委派（取消 / 重新付款）
    if (historyList) {
        historyList.addEventListener('click', (e) => {
            const cancelBtn = e.target.closest('.js-cancel');
            if (cancelBtn) { openCancelModal(cancelBtn.getAttribute('data-order') || ''); return; }
            const repayBtn = e.target.closest('.js-repay');
            if (repayBtn) { submitRepay(repayBtn.getAttribute('data-order') || ''); return; }
        });
    }

    function loadHistory(showLoading) {
        if (!historyList) return;
        const q = historyKeyword ? historyKeyword.value.trim() : '';
        const status = historyStatus ? historyStatus.value : 'all';
        if (historyReset) historyReset.classList.toggle('hidden', !(q || status !== 'all'));

        clearHistoryTimers();
        if (showLoading) {
            historyList.innerHTML = '<div class="py-16 text-center text-slate-400 text-sm"><i class="fa-solid fa-spinner fa-spin text-2xl mb-3 block"></i>載入中…</div>';
        }

        const url = 'recharge_orders_api.php?q=' + encodeURIComponent(q)
            + '&status=' + encodeURIComponent(status)
            + '&page=' + encodeURIComponent(historyPage)
            + '&limit=10';
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                if (!data || !data.ok) {
                    historyList.innerHTML = '<div class="py-16 text-center text-rose-500 text-sm"><i class="fa-solid fa-circle-exclamation text-2xl mb-3 block"></i>' + escapeHtml((data && data.message) || '查詢失敗') + '</div>';
                    if (historyPager) { historyPager.classList.add('hidden'); historyPager.classList.remove('flex'); }
                    return;
                }
                historyLoaded = true;
                if (typeof data.server_now === 'number') {
                    historyServerOffset = data.server_now - Math.floor(Date.now() / 1000);
                }
                renderHistory(data);
            })
            .catch(() => {
                historyList.innerHTML = '<div class="py-16 text-center text-rose-500 text-sm"><i class="fa-solid fa-wifi text-2xl mb-3 block"></i>網路連線異常，請稍後再試。</div>';
                if (historyPager) { historyPager.classList.add('hidden'); historyPager.classList.remove('flex'); }
            });
    }

    function payInfoText(pi) {
        if (!pi) return '';
        if (typeof pi === 'string') return pi;
        if (pi.vAccount) return '虛擬帳號 ' + pi.vAccount + (pi.BankCode ? '（銀行 ' + pi.BankCode + '）' : '');
        if (pi.PaymentNo) return '繳費代碼 ' + pi.PaymentNo + (pi.ExpireDate ? '（期限 ' + pi.ExpireDate + '）' : '');
        return '';
    }

    function renderHistory(data) {
        const orders = data.orders || [];
        const stats = data.stats || {};
        historyPage = Number(data.page || 1);
        historyPages = Math.max(1, Number(data.pages || 1));

        if (historySummary) {
            historySummary.textContent = '共 ' + Number(data.total || stats.count || 0) + ' 筆 · 已付款 '
                + Number(stats.paid_count || 0) + ' 筆 · 累計儲值 NT$ ' + Number(stats.paid_amount || 0).toLocaleString();
        }

        if (!orders.length) {
            historyList.innerHTML = '<div class="py-16 text-center text-slate-400 text-sm"><i class="fa-regular fa-folder-open text-3xl mb-3 block"></i>查無符合的儲值訂單</div>';
        } else {
            historyList.innerHTML = orders.map(o => {
                const meta = HISTORY_STATUS_META[o.status] || HISTORY_DEFAULT_META;
                const created = o.created_at ? String(o.created_at).replace('T', ' ').slice(0, 16) : '';
                const paidLine = o.paid_at ? '<span class="text-slate-300">|</span><span class="text-slate-400">付款：' + escapeHtml(String(o.paid_at).slice(0, 16)) + '</span>' : '';
                const infoText = payInfoText(o.payment_info);
                const infoLine = infoText ? '<div class="mt-1 text-[11px] text-slate-500"><i class="fa-solid fa-circle-info text-[9px] mr-1"></i>' + escapeHtml(infoText) + '</div>' : '';
                const orderNo = escapeHtml(o.order_no);
                const isPending = o.status === 'pending';
                const expiresTs = Number(o.expires_ts) || 0;
                const expireLine = (isPending && expiresTs > 0) ? `
                            <div class="mt-2">
                                <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg bg-amber-50 border border-amber-200 text-[11px] font-medium text-amber-700">
                                    <i class="fa-regular fa-hourglass-half text-[10px]"></i>
                                    預計 <span class="js-expire font-mono font-bold" data-expires="${expiresTs}">--:--:--</span> 後訂單失效
                                </span>
                            </div>` : '';
                const actionLine = isPending ? `
                            <div class="flex items-center gap-2 mt-2.5">
                                <button type="button" class="js-repay inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-sky-300 bg-sky-50 hover:bg-sky-100 text-sky-700 text-[11px] font-bold transition-colors cursor-pointer" data-order="${orderNo}" title="重新前往支付">
                                    <i class="fa-solid fa-rotate-right text-[10px]"></i> 重新前往支付
                                </button>
                                <button type="button" class="js-cancel inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 text-rose-700 text-[11px] font-bold transition-colors cursor-pointer" data-order="${orderNo}" title="取消支付">
                                    <i class="fa-solid fa-ban text-[10px]"></i> 取消支付
                                </button>
                            </div>` : '';
                return `
                <div class="mb-2.5 rounded-xl border border-slate-200 border-l-4 ${meta.bar} bg-white p-3.5 hover:shadow-md transition-shadow">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-mono text-xs font-bold text-slate-800 truncate">${orderNo}</span>
                                <button type="button" class="js-copy w-6 h-6 shrink-0 rounded-md border border-slate-200 bg-slate-50 hover:bg-slate-100 text-slate-500 hover:text-slate-700 transition-colors flex items-center justify-center cursor-pointer" data-copy="${orderNo}" title="複製訂單編號">
                                    <i class="fa-regular fa-copy text-[10px]"></i>
                                </button>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[10px] font-bold ${meta.cls}">
                                    <span class="w-1.5 h-1.5 rounded-full ${meta.dot}"></span>${escapeHtml(meta.text)}
                                </span>
                            </div>
                            <div class="flex items-center gap-2 mt-1.5 text-[11px] text-slate-500 flex-wrap">
                                <span><i class="fa-solid fa-wallet text-[10px] mr-1"></i>${escapeHtml(o.method_name)}</span>
                                <span class="text-slate-300">|</span>
                                <span>${escapeHtml(created)}</span>
                                ${paidLine}
                            </div>
                            ${infoLine}
                            ${expireLine}
                            ${actionLine}
                        </div>
                        <div class="text-right shrink-0">
                            <div class="font-mono text-sm font-extrabold text-slate-800">${fmtMoney(o.amount)}</div>
                            <div class="text-[11px] font-mono text-amber-600 mt-0.5">+${fmtPoints(o.total_points)}</div>
                            ${o.bonus > 0 ? '<div class="text-[10px] text-emerald-600">含加贈 ' + fmtPoints(o.bonus) + '</div>' : ''}
                        </div>
                    </div>
                </div>`;
            }).join('');
            startHistoryTimers();
        }

        // 分頁控制
        if (historyPager) {
            if (historyPages > 1) {
                historyPager.classList.remove('hidden');
                historyPager.classList.add('flex');
            } else {
                historyPager.classList.add('hidden');
                historyPager.classList.remove('flex');
            }
        }
        if (historyPagerInfo) historyPagerInfo.textContent = '第 ' + historyPage + ' / ' + historyPages + ' 頁';
        if (historyPrev) historyPrev.disabled = historyPage <= 1;
        if (historyNext) historyNext.disabled = historyPage >= historyPages;
    }

    // 即時搜尋（停頓 350ms 後查詢）
    if (historyKeyword) {
        historyKeyword.addEventListener('input', () => {
            clearTimeout(historyDebounce);
            historyDebounce = setTimeout(() => { historyPage = 1; loadHistory(false); }, 350);
        });
    }
    if (historyStatus) {
        historyStatus.addEventListener('change', () => { historyPage = 1; loadHistory(true); });
    }

    // 輕量化落雪系統
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
        const count = Math.min(Math.floor(window.innerWidth / 32), 45);
        for (let i = 0; i < count; i++) {
            particles.push({
                x: Math.random() * width,
                y: Math.random() * height,
                radius: Math.random() * 2.0 + 0.8,
                speedY: Math.random() * 0.65 + 0.3,
                speedX: (Math.random() - 0.5) * 0.35,
                opacity: Math.random() * 0.55 + 0.25,
                pulseSpeed: Math.random() * 0.015 + 0.005,
                pulsePhase: Math.random() * Math.PI * 2
            });
        }

        function render() {
            ctx.clearRect(0, 0, width, height);
            for (let i = 0; i < particles.length; i++) {
                const p = particles[i];
                p.y += p.speedY;
                p.x += p.speedX;
                p.pulsePhase += p.pulseSpeed;
                if (p.y > height) { p.y = -10; p.x = Math.random() * width; }
                if (p.x > width) p.x = 0;
                if (p.x < 0) p.x = width;
                const o = Math.max(0.15, Math.min(0.8, p.opacity + Math.sin(p.pulsePhase) * 0.15));
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(240, 249, 255, ${o})`;
                ctx.shadowBlur = 3;
                ctx.shadowColor = 'rgba(255, 255, 255, 0.4)';
                ctx.fill();
            }
            requestAnimationFrame(render);
        }
        render();
    })();
</script>

</body>
</html>
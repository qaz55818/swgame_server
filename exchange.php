<?php
/**
 * =============================================================
 *  踏雪笑傲 · 元寶兌換中心（點數 → 元寶）
 *  資料表：user_wallet / currency_ledger / exchange_orders / currency_settings
 *  共用工具：currency.php
 * =============================================================
 */
require_once __DIR__ . '/session_bootstrap.php';
app_session_start(['remember' => true]);
@include_once "config.php";
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/permissions.php';

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
mysqli_set_charset($Link, "utf8");

$u = currency_resolve_user($Link, $username, $sessionUserId);
$uid = (int)$u['uid'];
$displayUser = $u['username'];
if ($uid <= 0) {
    header("Location: login.php");
    exit();
}

// 會員功能權限：兌換權限
member_permission_require($Link, $uid, 'can_exchange', ['back' => 'home.php', 'countdown' => 5]);

if (empty($_SESSION['exchange_token'])) {
    $_SESSION['exchange_token'] = bin2hex(random_bytes(32));
}
$formToken = $_SESSION['exchange_token'];

$settings = currency_load_settings($Link);
$pointsName = $settings['points_name'];
$yuanbaoName = $settings['yuanbao_name'];

$notice = $_SESSION['exchange_notice'] ?? null;
unset($_SESSION['exchange_notice']);

// -------- 送出兌換 --------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['form_token']) || !hash_equals($_SESSION['exchange_token'], (string)$_POST['form_token'])) {
        $_SESSION['exchange_notice'] = ['type' => 'danger', 'msg' => '表單已失效，請重新操作。'];
        header("Location: exchange.php");
        exit();
    }
    $points = (int)($_POST['points'] ?? 0);
    $result = currency_exchange_yuanbao($Link, $uid, $displayUser, $points, 'self');
    $_SESSION['exchange_notice'] = [
        'type' => $result['ok'] ? 'success' : 'danger',
        'msg'  => $result['message'],
    ];
    header("Location: exchange.php");
    exit();
}

$wallet = currency_get_wallet($Link, $uid);

// 交易流水
$ledger = [];
try {
    $stmt = $Link->prepare("SELECT `currency`,`change_amount`,`balance_after`,`type`,`ref_no`,`description`,`created_at` FROM `currency_ledger` WHERE `uid` = ? ORDER BY `id` DESC LIMIT 12");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) { $ledger[] = $row; }
    $stmt->close();
} catch (Throwable $e) {}

// 兌換紀錄
$exchanges = [];
try {
    $stmt = $Link->prepare("SELECT `exchange_no`,`points_used`,`rate`,`yuanbao_gained`,`status`,`created_at` FROM `exchange_orders` WHERE `uid` = ? ORDER BY `id` DESC LIMIT 8");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) { $exchanges[] = $row; }
    $stmt->close();
} catch (Throwable $e) {}

mysqli_close($Link);

$rate = (float)$settings['exchange_rate'];
$minPoints = (int)$settings['exchange_min'];
$feePercent = (int)$settings['exchange_fee_percent'];
$exchangeEnabled = ((int)$settings['exchange_enabled'] === 1);

function ex_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ex_type_label($type) {
    $map = [
        'recharge' => '儲值入帳', 'exchange_in' => '兌換取得', 'exchange_out' => '兌換扣點',
        'admin_grant' => '管理發放', 'admin_deduct' => '管理扣減', 'consume' => '消費',
        'refund' => '退款退回', 'adjust' => '調整', 'register_bonus' => '註冊獎勵',
    ];
    return $map[$type] ?? $type;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>踏雪笑傲 · 元寶兌換中心</title>
<link rel="stylesheet" href="assets/tailwind.css">
<link rel="stylesheet" href="assets/exchange-tailwind.css">
<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Noto+Serif+TC:wght@400;500;600;700;900&family=Noto+Sans+TC:wght@300;400;500;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root { --vermilion:#be123c; --ice-blue:#0284c7; --gold:#d97706; --gold-lt:#fbbf24; --ink-deep:#080c14; }
    body {
        background-color: var(--ink-deep); color:#1e293b; font-family:'Noto Sans TC', sans-serif; min-height:100vh; overflow-x:hidden;
        background-image:
            radial-gradient(ellipse at 50% 0%, rgba(30,41,59,.7) 0%, rgba(8,12,20,.98) 75%),
            radial-gradient(circle at 82% 18%, rgba(217,119,6,.12) 0%, transparent 42%),
            radial-gradient(circle at 15% 78%, rgba(190,18,60,.06) 0%, transparent 46%);
    }
    #snow-canvas { position:fixed; top:0; left:0; width:100vw; height:100vh; pointer-events:none; z-index:1; }
    .snow-card { background:rgba(255,255,255,.95); backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px); border:1px solid rgba(226,232,240,.9); box-shadow:0 20px 45px -12px rgba(0,0,0,.38), 0 0 0 1px rgba(226,232,240,.6); position:relative; }
    .seal-tag { background-color:#fff7ed; color:#c2410c; border:1px solid #fed7aa; font-weight:600; }
    .ornament-tl { position:absolute; top:-1px; left:-1px; width:14px; height:14px; border-top:2.5px solid var(--vermilion); border-left:2.5px solid var(--vermilion); pointer-events:none; border-top-left-radius:4px; }
    .ornament-br { position:absolute; bottom:-1px; right:-1px; width:14px; height:14px; border-bottom:2.5px solid var(--gold); border-right:2.5px solid var(--gold); pointer-events:none; border-bottom-right-radius:4px; }
    /* 錢包卡 */
    .wallet-card { position:relative; overflow:hidden; border-radius:1.25rem; }
    .coin-orb { position:absolute; border-radius:9999px; filter:blur(2px); opacity:.9; }
    .shine::after { content:''; position:absolute; top:0; left:-130%; width:55%; height:100%; background:linear-gradient(100deg,transparent,rgba(255,255,255,.55),transparent); transform:skewX(-20deg); animation:shine 3.6s ease-in-out infinite; }
    @keyframes shine { 0%{left:-130%} 55%,100%{left:150%} }
    .exchange-input { width:100%; border:2px solid #e2e8f0; border-radius:1rem; padding:.9rem 1rem; font-size:1.35rem; font-weight:800; font-family:'Noto Sans TC',monospace; color:#0f172a; background:#fff; text-align:center; transition:all .2s; }
    .exchange-input:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 4px rgba(217,119,6,.15); }
    .qbtn { transition:all .18s ease; }
    .qbtn:hover { transform:translateY(-2px); }
    /* 流水時間軸 */
    .ledger-row { transition:background .15s; }
    .ledger-row:hover { background:#fffbeb; }
    .fade-in-up { animation:fadeInUp .5s cubic-bezier(.22,1,.36,1) both; }
    @keyframes fadeInUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:none} }
    .d1{animation-delay:.06s}.d2{animation-delay:.12s}.d3{animation-delay:.18s}
    .flip-arrow { animation:flipY 2.4s ease-in-out infinite; }
    @keyframes flipY { 0%,100%{transform:rotate(0)} 50%{transform:rotate(180deg)} }
    ::-webkit-scrollbar { width:6px; height:6px; }
    ::-webkit-scrollbar-track { background:#0f172a; }
    ::-webkit-scrollbar-thumb { background:#334155; border-radius:3px; }
</style>
</head>
<body class="relative flex flex-col justify-between selection:bg-amber-900 selection:text-white min-h-screen">
<canvas id="snow-canvas"></canvas>
<div class="fixed top-0 left-1/2 -translate-x-1/2 w-[820px] h-[280px] bg-amber-500/10 rounded-full blur-[140px] pointer-events-none -z-0"></div>

<header class="relative z-20 border-b border-slate-800/80 bg-[#0c101a]/90 backdrop-blur-md sticky top-0 shadow-lg">
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
<div class="flex items-center justify-between h-20">
<a class="group flex items-center space-x-3.5 no-underline" href="home.php">
<div class="w-11 h-11 rounded-xl bg-white p-1 shadow-md border border-slate-200 flex items-center justify-center overflow-hidden transition-transform group-hover:scale-105"><img alt="踏雪笑傲 Logo" class="w-full h-full object-contain" src="assets/logo.svg"></div>
<div>
<div class="flex items-center space-x-2">
<span class="text-2xl font-bold tracking-wider text-slate-100 font-serif">踏雪笑傲</span>
<span class="seal-tag text-[11px] px-1.5 py-0.5 rounded tracking-normal">兌換中心</span>
</div>
<p class="text-[11px] text-slate-400 font-mono tracking-widest uppercase">Snow Wanderer Online</p>
</div>
</a>
<nav class="hidden md:flex items-center space-x-2">
<a class="px-3.5 py-2 text-sm text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg transition-colors flex items-center space-x-2" href="home.php"><i class="fa-solid fa-house-chimney text-xs text-slate-400"></i><span>帳號總覽</span></a>
<a class="px-3.5 py-2 text-sm text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg transition-colors flex items-center space-x-2" href="recharge.php"><i class="fa-solid fa-coins text-xs text-amber-400"></i><span>儲值</span></a>
<a class="px-3.5 py-2 text-sm text-amber-300 font-semibold bg-amber-950/40 border border-amber-800/60 rounded-lg flex items-center space-x-2" href="exchange.php"><i class="fa-solid fa-right-left text-xs"></i><span>兌換中心</span></a>
<a class="px-3.5 py-2 text-sm text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg transition-colors flex items-center space-x-2" href="support.php"><i class="fa-solid fa-headset text-xs text-slate-400"></i><span>申訴回報</span></a>
<div class="pl-4 ml-2 border-l border-slate-800 flex items-center space-x-3">
<div class="flex items-center space-x-2.5 bg-slate-900/90 border border-slate-700/70 px-3.5 py-1.5 rounded-full">
<div class="w-6 h-6 rounded-full bg-gradient-to-tr from-amber-600 to-amber-400 text-white flex items-center justify-center text-xs font-bold shadow-sm">俠</div>
<div class="text-left">
<p class="text-xs font-semibold text-slate-200 leading-tight max-w-[120px] truncate"><?= ex_h($displayUser) ?></p>
<span class="text-[10px] text-emerald-400 flex items-center gap-1 font-mono"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>已登入</span>
</div>
</div>
<button class="px-3 py-1.5 rounded-lg border border-rose-800/50 bg-rose-950/30 text-rose-300 hover:bg-rose-900/50 hover:text-white text-xs font-medium transition-colors flex items-center gap-1.5 shadow-sm" onclick="openLogoutModal()" title="登出系統"><i class="fa-solid fa-arrow-right-from-bracket text-xs"></i><span>登出</span></button>
</div>
</nav>
<div class="flex md:hidden items-center"><button class="p-2 text-slate-300 hover:text-white rounded-lg focus:outline-none bg-slate-800/60" id="mobile-toggle"><i class="fa-solid fa-bars text-lg"></i></button></div>
</div>
</div>
<div class="hidden md:hidden border-t border-slate-800 bg-[#0c101a] px-5 py-4 space-y-2" id="mobile-menu">
<div class="pb-3 border-b border-slate-800 flex items-center justify-between"><span class="text-xs text-slate-400">當前登入帳號：</span><span class="text-xs text-amber-400 font-bold"><?= ex_h($displayUser) ?></span></div>
<a class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60" href="home.php"><i class="fa-solid fa-house-chimney mr-2 text-slate-400"></i> 帳號總覽</a>
<a class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60" href="recharge.php"><i class="fa-solid fa-coins mr-2 text-amber-400"></i> 儲值</a>
<a class="block px-3 py-2 rounded-lg text-sm text-amber-300 bg-amber-950/40 font-medium" href="exchange.php"><i class="fa-solid fa-right-left mr-2"></i> 兌換中心</a>
<a class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60" href="support.php"><i class="fa-solid fa-headset mr-2 text-slate-400"></i> 申訴回報</a>
<button class="w-full text-left px-3 py-2 rounded-lg text-sm text-rose-400 hover:bg-rose-950/40" onclick="openLogoutModal()"><i class="fa-solid fa-arrow-right-from-bracket mr-2"></i> 安全登出</button>
</div>
</header>

<main class="relative z-10 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10 flex-1 w-full space-y-7">

<?php if ($notice && !empty($notice['msg'])): ?>
<div class="snow-card rounded-xl px-5 py-4 flex items-start gap-3 border-l-4 fade-in-up <?= $notice['type'] === 'success' ? 'border-l-emerald-500' : 'border-l-rose-500' ?>">
<i class="fa-solid <?= $notice['type'] === 'success' ? 'fa-circle-check text-emerald-600' : 'fa-circle-exclamation text-rose-600' ?> mt-0.5"></i>
<p class="text-sm text-slate-700"><?= ex_h($notice['msg']) ?></p>
</div>
<?php endif; ?>

<!-- 標題 -->
<div class="fade-in-up">
<div class="flex items-center gap-2 mb-1.5">
<span class="seal-tag text-[11px] px-2.5 py-0.5 rounded-full"><i class="fa-solid fa-gem mr-1"></i>點數兌換</span>
<span class="text-xs text-slate-400">儲值點數，自由兌換為遊戲元寶</span>
</div>
<h1 class="text-2xl sm:text-3xl font-bold text-slate-100 font-serif tracking-wide">元寶兌換中心</h1>
<p class="text-xs text-slate-400 mt-1.5">將帳號中的「<?= ex_h($pointsName) ?>」兌換為「<?= ex_h($yuanbaoName) ?>」，兌換後元寶將即時同步至遊戲內。</p>
</div>

<!-- 錢包總覽 -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5 fade-in-up d1">
<div class="wallet-card snow-card shine p-6 bg-gradient-to-br from-sky-50 via-white to-sky-50">
<div class="ornament-tl"></div>
<div class="flex items-center justify-between mb-3">
<span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-sky-500 to-cyan-400 text-white flex items-center justify-center text-xl shadow-lg shadow-sky-500/30"><i class="fa-solid fa-coins"></i></span>
<span class="text-[10px] font-mono tracking-widest text-sky-500">POINTS</span>
</div>
<p class="text-xs text-slate-500 mb-1"><?= ex_h($pointsName) ?>餘額</p>
<div class="flex items-baseline gap-2">
<span class="text-3xl font-extrabold text-sky-700 font-mono"><?= number_format($wallet['points']) ?></span>
<span class="text-xs text-slate-400">點</span>
</div>
<a href="recharge.php" class="mt-4 inline-flex items-center gap-1.5 text-[11px] font-semibold text-sky-700 hover:text-sky-800 no-underline"><i class="fa-solid fa-plus"></i> 前往儲值</a>
</div>

<div class="wallet-card snow-card shine p-6 bg-gradient-to-br from-amber-50 via-white to-amber-50">
<div class="ornament-tl"></div>
<div class="flex items-center justify-between mb-3">
<span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-yellow-400 text-white flex items-center justify-center text-xl shadow-lg shadow-amber-500/30"><i class="fa-solid fa-gem"></i></span>
<span class="text-[10px] font-mono tracking-widest text-amber-500">YUANBAO</span>
</div>
<p class="text-xs text-slate-500 mb-1"><?= ex_h($yuanbaoName) ?>餘額</p>
<div class="flex items-baseline gap-2">
<span class="text-3xl font-extrabold text-amber-700 font-mono"><?= number_format($wallet['yuanbao']) ?></span>
<span class="text-xs text-slate-400">元寶</span>
</div>
<p class="mt-4 text-[11px] text-slate-400"><i class="fa-solid fa-gamepad mr-1"></i>已同步至遊戲帳號</p>
</div>

<div class="wallet-card snow-card p-6 bg-gradient-to-br from-emerald-50 via-white to-emerald-50">
<div class="ornament-tl"></div>
<div class="flex items-center justify-between mb-3">
<span class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-400 text-white flex items-center justify-center text-xl shadow-lg shadow-emerald-500/30"><i class="fa-solid fa-chart-line"></i></span>
<span class="text-[10px] font-mono tracking-widest text-emerald-500">TOTAL</span>
</div>
<p class="text-xs text-slate-500 mb-3">累計獲得</p>
<div class="space-y-1.5 text-sm">
<div class="flex items-center justify-between"><span class="text-slate-500 text-xs"><?= ex_h($pointsName) ?></span><span class="font-bold text-slate-700 font-mono"><?= number_format($wallet['total_points_in']) ?></span></div>
<div class="flex items-center justify-between"><span class="text-slate-500 text-xs"><?= ex_h($yuanbaoName) ?></span><span class="font-bold text-slate-700 font-mono"><?= number_format($wallet['total_yuanbao_in']) ?></span></div>
</div>
</div>
</div>

<!-- 兌換面板 -->
<div class="snow-card rounded-2xl p-6 sm:p-8 fade-in-up d2">
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="flex items-center justify-between flex-wrap gap-3 pb-5 mb-6 border-b border-slate-200">
<div class="flex items-center gap-3">
<span class="w-11 h-11 rounded-2xl bg-gradient-to-br from-amber-500 to-rose-500 text-white flex items-center justify-center text-lg shadow-md"><i class="fa-solid fa-right-left"></i></span>
<div>
<h2 class="text-lg font-bold text-slate-800 font-serif">點數兌換元寶</h2>
<p class="text-[11px] text-slate-500">目前匯率：1 <?= ex_h($pointsName) ?> ≈ <?= ex_h(rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.')) ?> <?= ex_h($yuanbaoName) ?><?= $feePercent > 0 ? '（手續費 ' . $feePercent . '%）' : '' ?></p>
</div>
</div>
<span class="text-[11px] px-3 py-1 rounded-full border <?= $exchangeEnabled ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?>"><i class="fa-solid <?= $exchangeEnabled ? 'fa-circle-check' : 'fa-circle-xmark' ?> mr-1"></i><?= $exchangeEnabled ? '兌換開放中' : '暫停開放' ?></span>
</div>

<?php if (!$exchangeEnabled): ?>
<div class="rounded-xl bg-rose-50 border border-rose-200 p-5 text-sm text-rose-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i>兌換功能目前暫停開放，請稍後再試。</div>
<?php else: ?>
<form method="POST" class="grid grid-cols-1 lg:grid-cols-5 gap-6 items-center" id="exchange-form">
<input type="hidden" name="form_token" value="<?= ex_h($formToken) ?>">
<input type="hidden" name="points" id="points-hidden" value="">
<div class="lg:col-span-2">
<label class="text-xs font-semibold text-slate-600 block mb-2">使用<?= ex_h($pointsName) ?>（可用 <?= number_format($wallet['points']) ?>）</label>
<input type="number" id="points-input" class="exchange-input" min="<?= (int)$minPoints ?>" step="1" value="<?= (int)$minPoints ?>" inputmode="numeric" autocomplete="off">
<div class="grid grid-cols-4 gap-2 mt-3">
<?php foreach ([100, 500, 1000, 5000] as $quick): ?>
<button type="button" class="qbtn quick-amt text-xs font-semibold py-2 rounded-lg border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100" data-amt="<?= $quick ?>"><?= number_format($quick) ?></button>
<?php endforeach; ?>
</div>
<button type="button" id="max-btn" class="mt-2 w-full text-[11px] font-semibold text-amber-700 hover:text-amber-800 py-1.5 rounded-lg border border-dashed border-amber-300 hover:bg-amber-50">全部兌換（<?= number_format($wallet['points']) ?>）</button>
<p class="mt-2 text-[11px] text-slate-400"><i class="fa-solid fa-circle-info mr-1"></i>最低兌換 <?= number_format($minPoints) ?> 點</p>
</div>

<div class="lg:col-span-1 flex flex-col items-center justify-center py-3">
<div class="w-14 h-14 rounded-full bg-gradient-to-br from-amber-500 to-rose-500 text-white flex items-center justify-center text-xl shadow-lg flip-arrow"><i class="fa-solid fa-arrow-right-arrow-left"></i></div>
</div>

<div class="lg:col-span-2">
<div class="rounded-2xl border-2 border-amber-200 bg-gradient-to-br from-amber-50 to-white p-5">
<p class="text-xs font-semibold text-amber-700 mb-1">預估可獲得<?= ex_h($yuanbaoName) ?></p>
<div class="flex items-baseline gap-2">
<span class="text-4xl font-extrabold text-amber-700 font-mono" id="preview-yuanbao">0</span>
<span class="text-sm text-amber-600">元寶</span>
</div>
<p class="text-[11px] text-slate-400 mt-2" id="preview-detail">—</p>
<button type="submit" id="exchange-btn" class="mt-4 w-full py-3.5 rounded-xl bg-gradient-to-r from-amber-500 via-amber-600 to-amber-700 hover:from-amber-600 hover:to-amber-800 text-white font-bold text-sm shadow-lg transition-all flex items-center justify-center gap-2" <?= $wallet['points'] < $minPoints ? 'disabled' : '' ?>>
<i class="fa-solid fa-gem"></i> 確認兌換
</button>
<?php if ($wallet['points'] < $minPoints): ?>
<p class="mt-2 text-[11px] text-rose-500 text-center"><?= ex_h($pointsName) ?>不足，請先<a href="recharge.php" class="underline">前往儲值</a>。</p>
<?php endif; ?>
</div>
</div>
</form>
<?php endif; ?>
</div>

<!-- 紀錄 -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 fade-in-up d3">
<div class="snow-card rounded-2xl p-6">
<div class="ornament-tl"></div>
<h3 class="text-sm font-bold text-slate-700 flex items-center gap-2 mb-4"><i class="fa-solid fa-clock-rotate-left text-slate-400"></i>最近<?= ex_h($pointsName) ?> / 元寶異動</h3>
<div class="space-y-1">
<?php if (empty($ledger)): ?><p class="text-sm text-slate-400 py-6 text-center">尚無交易紀錄。</p><?php endif; ?>
<?php foreach ($ledger as $l):
    $isYb = ($l['currency'] === 'yuanbao');
    $pos = ((int)$l['change_amount'] >= 0);
?>
<div class="ledger-row flex items-center gap-3 px-3 py-2.5 rounded-lg">
<span class="w-8 h-8 rounded-lg flex items-center justify-center text-xs <?= $isYb ? 'bg-amber-100 text-amber-700' : 'bg-sky-100 text-sky-700' ?>"><i class="fa-solid <?= $isYb ? 'fa-gem' : 'fa-coins' ?>"></i></span>
<div class="flex-1 min-w-0">
<div class="text-xs font-semibold text-slate-700"><?= ex_h($l['description'] ?: ex_type_label($l['type'])) ?></div>
<div class="text-[10px] text-slate-400 font-mono"><?= ex_h($l['created_at']) ?><?= $l['ref_no'] ? ' · ' . ex_h($l['ref_no']) : '' ?></div>
</div>
<div class="text-right">
<div class="text-sm font-bold font-mono <?= $pos ? 'text-emerald-600' : 'text-rose-600' ?>"><?= $pos ? '+' : '' ?><?= number_format((int)$l['change_amount']) ?></div>
<div class="text-[10px] text-slate-400">餘 <?= number_format((int)$l['balance_after']) ?></div>
</div>
</div>
<?php endforeach; ?>
</div>
</div>

<div class="snow-card rounded-2xl p-6">
<div class="ornament-br"></div>
<h3 class="text-sm font-bold text-slate-700 flex items-center gap-2 mb-4"><i class="fa-solid fa-arrow-right-arrow-left text-slate-400"></i>兌換紀錄</h3>
<div class="space-y-1">
<?php if (empty($exchanges)): ?><p class="text-sm text-slate-400 py-6 text-center">尚無兌換紀錄。</p><?php endif; ?>
<?php foreach ($exchanges as $e): ?>
<div class="ledger-row flex items-center gap-3 px-3 py-2.5 rounded-lg">
<span class="w-8 h-8 rounded-lg bg-gradient-to-br from-amber-500 to-rose-500 text-white flex items-center justify-center text-xs"><i class="fa-solid fa-right-left"></i></span>
<div class="flex-1 min-w-0">
<div class="text-xs font-semibold text-slate-700"><?= number_format((int)$e['points_used']) ?> 點 → <span class="text-amber-700"><?= number_format((int)$e['yuanbao_gained']) ?> 元寶</span></div>
<div class="text-[10px] text-slate-400 font-mono"><?= ex_h($e['exchange_no']) ?> · <?= ex_h($e['created_at']) ?></div>
</div>
<span class="text-[10px] px-2 py-0.5 rounded-full <?= $e['status'] === 'completed' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600 border border-slate-200' ?>"><?= $e['status'] === 'completed' ? '完成' : ex_h($e['status']) ?></span>
</div>
<?php endforeach; ?>
</div>
</div>
</div>
</main>

<footer class="relative z-10 border-t border-slate-800 bg-[#090d16] py-8 text-center text-xs text-slate-400 mt-auto">
<div class="max-w-7xl mx-auto px-4 space-y-2.5">
<div class="flex items-center justify-center space-x-2 text-slate-300 font-serif font-semibold text-sm"><span>踏雪笑傲 · 繁體中文官方正版</span></div>
<p class="text-slate-400">
<a class="hover:text-slate-200 mx-2" href="home.php">帳號總覽</a> ·
<a class="hover:text-slate-200 mx-2" href="recharge.php">儲值</a> ·
<a class="hover:text-slate-200 mx-2" href="exchange.php">兌換中心</a> ·
<a class="hover:text-slate-200 mx-2" href="support.php">申訴回報</a>
</p>
<p class="text-[11px] text-slate-400 font-mono">© 2025 踏雪笑傲營運團隊 All Rights Reserved.</p>
</div>
</footer>

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
    if (mobileToggle && mobileMenu) { mobileToggle.addEventListener('click', () => mobileMenu.classList.toggle('hidden')); }

    const logoutModal = document.getElementById('logout-modal');
    const logoutCard = document.getElementById('logout-card');
    window.openLogoutModal = function () {
        if (!logoutModal) return;
        logoutModal.classList.remove('opacity-0', 'pointer-events-none'); logoutModal.classList.add('opacity-100');
        if (logoutCard) { logoutCard.classList.remove('scale-95'); logoutCard.classList.add('scale-100'); }
    };
    window.closeLogoutModal = function () {
        if (!logoutModal) return;
        logoutModal.classList.add('opacity-0', 'pointer-events-none'); logoutModal.classList.remove('opacity-100');
        if (logoutCard) { logoutCard.classList.remove('scale-100'); logoutCard.classList.add('scale-95'); }
    };
    if (logoutModal) { logoutModal.addEventListener('click', (e) => { if (e.target === logoutModal) closeLogoutModal(); }); }

    // 兌換試算
    const RATE = <?= json_encode($rate) ?>;
    const FEE = <?= (int)$feePercent ?>;
    const MIN = <?= (int)$minPoints ?>;
    const AVAILABLE = <?= (int)$wallet['points'] ?>;
    const input = document.getElementById('points-input');
    const hidden = document.getElementById('points-hidden');
    const preview = document.getElementById('preview-yuanbao');
    const detail = document.getElementById('preview-detail');
    const form = document.getElementById('exchange-form');
    function fmt(n) { return Number(n || 0).toLocaleString('en-US'); }
    function update() {
        if (!input || !preview) return;
        let v = parseInt(input.value, 10);
        if (isNaN(v) || v < 0) v = 0;
        const gross = Math.floor(v * RATE);
        const fee = Math.floor(gross * FEE / 100);
        const gain = Math.max(0, gross - fee);
        preview.textContent = fmt(gain);
        if (v <= 0) { detail.textContent = '請輸入兌換點數'; }
        else if (v < MIN) { detail.textContent = '未達最低兌換 ' + fmt(MIN) + ' 點'; }
        else if (v > AVAILABLE) { detail.textContent = '超過可用點數（' + fmt(AVAILABLE) + '）'; }
        else { detail.textContent = fmt(v) + ' 點' + (FEE > 0 ? '，手續費 ' + FEE + '%（' + fmt(fee) + '）' : '') + '，可得 ' + fmt(gain) + ' 元寶'; }
        if (hidden) hidden.value = v;
    }
    if (input) {
        input.addEventListener('input', update);
        document.querySelectorAll('.quick-amt').forEach(function (b) {
            b.addEventListener('click', function () { input.value = b.dataset.amt; update(); });
        });
        const maxBtn = document.getElementById('max-btn');
        if (maxBtn) maxBtn.addEventListener('click', function () { input.value = AVAILABLE; update(); });
        update();
    }
    if (form) {
        form.addEventListener('submit', function (e) {
            let v = parseInt(input.value, 10);
            if (isNaN(v) || v <= 0) { e.preventDefault(); alert('請輸入有效的兌換點數。'); return; }
            if (v < MIN) { e.preventDefault(); alert('最低兌換 ' + MIN + ' 點。'); return; }
            if (v > AVAILABLE) { e.preventDefault(); alert('點數餘額不足。'); return; }
            if (!confirm('確認使用 ' + fmt(v) + ' 點兌換元寶？')) { e.preventDefault(); return; }
            hidden.value = v;
        });
    }

    (function initSnow() {
        const canvas = document.getElementById('snow-canvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        let width = (canvas.width = window.innerWidth), height = (canvas.height = window.innerHeight);
        window.addEventListener('resize', () => { width = canvas.width = window.innerWidth; height = canvas.height = window.innerHeight; });
        const particles = [];
        const count = Math.min(Math.floor(window.innerWidth / 30), 45);
        for (let i = 0; i < count; i++) { particles.push({ x: Math.random() * width, y: Math.random() * height, radius: Math.random() * 2 + 0.8, speedY: Math.random() * 0.6 + 0.25, speedX: (Math.random() - 0.5) * 0.35, opacity: Math.random() * 0.5 + 0.2, pulsePhase: Math.random() * Math.PI * 2 }); }
        function render() {
            ctx.clearRect(0, 0, width, height);
            for (let i = 0; i < particles.length; i++) {
                const p = particles[i]; p.y += p.speedY; p.x += p.speedX; p.pulsePhase += 0.01;
                if (p.y > height) { p.y = -10; p.x = Math.random() * width; }
                if (p.x > width) p.x = 0; if (p.x < 0) p.x = width;
                const op = Math.max(0.12, Math.min(0.75, p.opacity + Math.sin(p.pulsePhase) * 0.12));
                ctx.beginPath(); ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2); ctx.fillStyle = 'rgba(255,250,240,' + op + ')'; ctx.fill();
            }
            requestAnimationFrame(render);
        }
        render();
    })();
})();
</script>
</body>
</html>

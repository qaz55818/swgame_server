<?php
require_once __DIR__ . '/session_bootstrap.php';
app_session_start(['remember' => true]);
@include_once "config.php";
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/credit.php';
require_once __DIR__ . '/account_tiers.php';
require_once __DIR__ . '/messages.php';

// Require login
if (!isset($_SESSION['username']) || $_SESSION['username'] === '') {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$userId = $_SESSION['user_id'] ?? '';

// 訊息通知中心：CSRF 權杖與未讀數
if (empty($_SESSION['msg_csrf'])) {
    $_SESSION['msg_csrf'] = bin2hex(random_bytes(32));
}
$msgCsrf = $_SESSION['msg_csrf'];
$messageUnread = 0;

// 讀取帳號資料庫的註冊資訊
$accountInfo = null;
$wallet = ['points' => 0, 'yuanbao' => 0, 'total_points_in' => 0, 'total_yuanbao_in' => 0];
$currencySettings = currency_default_settings();
$memberPerms = member_permission_defaults();
$creditSettings = credit_default_settings();
$creditScore = (int)$creditSettings['default_score'];
$creditLevel = credit_level($creditScore, $creditSettings);
$creditLedger = [];
$creditVisible = true;
$userTier = null;
$Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
if ($Link) {
    mysqli_set_charset($Link, "utf8");
    if ($userId !== '') {
        $stmt = $Link->prepare("SELECT `name`, `email`, `mobilenumber`, `creatime` FROM `users` WHERE `ID` = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
    } else {
        $stmt = $Link->prepare("SELECT `name`, `email`, `mobilenumber`, `creatime` FROM `users` WHERE `name` = ? LIMIT 1");
        $stmt->bind_param("s", $username);
    }
    $stmt->execute();
    $accountInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 會員帳號層級
    mysqli_set_charset($Link, "utf8mb4");
    account_tier_ensure_tables($Link);

    // 讀取錢包（點數 / 元寶）；舊用戶以 point(aid=23) 回填、新用戶自動建立
    $curUser = currency_resolve_user($Link, $username, $userId);
    $userTier = account_tier_for_user($Link, (int)$curUser['uid']);
    mysqli_set_charset($Link, "utf8");
    $wallet = currency_get_wallet($Link, (int)$curUser['uid']);
    $currencySettings = currency_load_settings($Link);
    $memberPerms = member_permissions_load($Link, (int)$curUser['uid']);

    // 讀取帳號信用分數與異動流水
    $creditSettings = credit_load_settings($Link);
    $curUid = (int)$curUser['uid'];
    if ($curUid > 0) {
        $creditUser = credit_get($Link, $curUid);
        $creditScore = (int)$creditUser['score'];
        $creditLevel = $creditUser['level'];
        $creditLedger = credit_recent_ledger($Link, $curUid, 8);
    }
    $creditVisible = ((int)$creditSettings['show_on_home'] === 1);

    // 訊息通知中心：未讀訊息數
    member_message_ensure_table($Link);
    $messageUnread = member_message_unread_count($Link, $curUid);

    mysqli_close($Link);
}
$creditMaxScore = (int)($creditSettings['max_score'] ?? 100);
if ($creditMaxScore <= 0) { $creditMaxScore = 100; }
$creditTierBadge = [
    'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'sky'     => 'bg-sky-50 text-sky-700 border-sky-200',
    'amber'   => 'bg-amber-50 text-amber-700 border-amber-200',
    'orange'  => 'bg-amber-50 text-amber-700 border-amber-200',
    'rose'    => 'bg-rose-50 text-rose-700 border-rose-200',
];
$creditTierClass = $creditTierBadge[$creditLevel['color']] ?? $creditTierBadge['emerald'];
$creditRingColor = [
    'emerald' => '#059669', 'sky' => '#0284c7', 'amber' => '#d97706',
    'orange' => '#ea580c', 'rose' => '#be123c',
];
$creditRing = $creditRingColor[$creditLevel['color']] ?? '#059669';
$pointsName = $currencySettings['points_name'] ?? '點數';
$yuanbaoName = $currencySettings['yuanbao_name'] ?? '元寶';

// 會員權限旗標
$canRecharge       = !empty($memberPerms['can_recharge']);
$canExchange       = !empty($memberPerms['can_exchange']);
$canChangePassword = !empty($memberPerms['can_change_password']);
$canSupport        = !empty($memberPerms['can_support']);
$canViewAssets     = !empty($memberPerms['can_view_assets']);
$canDownload       = !empty($memberPerms['can_download']);
function perm_class($allowed, $base = '') {
    return $allowed ? $base : trim($base . ' perm-locked');
}
function perm_data($allowed, $label) {
    return $allowed ? '' : ' data-perm-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"';
}

// 整理顯示值；無法取得之資訊採常規描述
$displayEmail = (is_array($accountInfo) && !empty($accountInfo['email'])) ? $accountInfo['email'] : '尚未綁定電子信箱';
$hasCreatime = is_array($accountInfo) && !empty($accountInfo['creatime']) && $accountInfo['creatime'] !== '0000-00-00 00:00:00';
$displayRegDate = $hasCreatime ? date('Y-m-d', strtotime($accountInfo['creatime'])) : '註冊時間未提供';
$displayDays = '—';
if ($hasCreatime) {
    $ts = strtotime($accountInfo['creatime']);
    if ($ts !== false) {
        $days = (int)floor((time() - $ts) / 86400);
        if ($days >= 0) {
            $displayDays = $days;
        }
    }
}
$displayDaysText = is_numeric($displayDays) ? ('/ ' . $displayDays . ' 天') : '/ 註冊歷程未提供';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>踏雪笑傲 · 會員中心與帳號管理</title>
<!-- Tailwind CSS -->
<link rel="stylesheet" href="assets/tailwind.css">
<!-- 本頁專用：補齊 Tailwind 工具類（貨幣資產顯示） -->
<link rel="stylesheet" href="assets/front-currency-tailwind.css">
<!-- Google Fonts: Noto Serif TC & Cinzel -->
<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&amp;family=Noto+Serif+TC:wght@400;500;600;700;900&amp;family=Noto+Sans+TC:wght@300;400;500;700&amp;display=swap" rel="stylesheet">
<!-- FontAwesome -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<!-- 樣式由 assets/tailwind.css 提供（編譯後靜態檔，不再使用 Tailwind CDN） -->
<style>
        :root {
            --vermilion: #be123c;
            --ice-blue: #0284c7;
            --ice-cyan: #38bdf8;
            --ink-deep: #080c14;
            --white-jade: rgba(255, 255, 255, 0.94);
        }

        body {
            background-color: var(--ink-deep);
            color: #1e293b;
            font-family: 'Noto Sans TC', sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
            background-image:
                radial-gradient(ellipse at 50% 0%, rgba(30, 41, 59, 0.7) 0%, rgba(8, 12, 20, 0.98) 75%),
                radial-gradient(circle at 80% 20%, rgba(2, 132, 199, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 15% 75%, rgba(190, 18, 60, 0.05) 0%, transparent 45%);
        }

        #snow-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            pointer-events: none;
            z-index: 1;
            transform: translateZ(0);
            backface-visibility: hidden;
        }

        .snow-card {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(226, 232, 240, 0.9);
            box-shadow: 0 20px 45px -12px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(226, 232, 240, 0.6);
            position: relative;
            transition: all 0.32s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .snow-card-interactive {
            transition: all 0.32s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .snow-card-interactive:hover {
            transform: translateY(-6px);
            box-shadow: 0 28px 55px -12px rgba(0, 0, 0, 0.42), 0 0 0 1px rgba(255, 255, 255, 0.95);
        }

        .ornament-tl {
            position: absolute;
            top: -1px;
            left: -1px;
            width: 14px;
            height: 14px;
            border-top: 2.5px solid var(--vermilion);
            border-left: 2.5px solid var(--vermilion);
            pointer-events: none;
            border-top-left-radius: 4px;
        }
        .ornament-br {
            position: absolute;
            bottom: -1px;
            right: -1px;
            width: 14px;
            height: 14px;
            border-bottom: 2.5px solid var(--ice-blue);
            border-right: 2.5px solid var(--ice-blue);
            pointer-events: none;
            border-bottom-right-radius: 4px;
        }

        .seal-tag {
            background-color: #fff1f2;
            color: #be123c;
            border: 1px solid #fecdd3;
            font-weight: 600;
        }

        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #0f172a;
        }
        ::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }
        /* 補齊精簡版 Tailwind 缺少的工具類 */
        .shadow-xs { box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); }
        .py-0\.2 { padding-top: 0.05rem; padding-bottom: 0.05rem; }
        .group:hover .group-hover\:scale-102 { transform: scale(1.02); }
        /* 訊息通知中心補充工具類 */
        .p-2\.5 { padding: 0.625rem; }
        .py-10 { padding-top: 2.5rem; padding-bottom: 2.5rem; }
        .bg-white\/60 { background-color: rgba(255, 255, 255, 0.6); }
        .max-h-\[90vh\] { max-height: 90vh; }
        .hover\:underline:hover { text-decoration: underline; }
        @media (min-width: 640px) { .sm\:p-5 { padding: 1.25rem; } }
        #msg-readall:disabled, #msg-deleteall:disabled { color: #cbd5e1; text-decoration: none; cursor: default; opacity: 0.7; }
        /* 訊息內容（所見即所得輸出）樣式 */
        .msg-content { word-break: break-word; }
        .msg-content img { max-width: 100%; height: auto; border-radius: 8px; }
        .msg-content ul { list-style: disc; padding-left: 1.25rem; margin: .3em 0; }
        .msg-content ol { list-style: decimal; padding-left: 1.25rem; margin: .3em 0; }
        .msg-content a { color: #0369a1; text-decoration: underline; }
        .msg-content h1, .msg-content h2, .msg-content h3 { font-weight: 700; margin: .4em 0; }
        .msg-content h1 { font-size: 1.25rem; }
        .msg-content h2 { font-size: 1.1rem; }
        .msg-content blockquote { border-left: 3px solid #cbd5e1; padding-left: .75rem; color: #64748b; margin: .4em 0; }
        .msg-content pre { background: #f1f5f9; padding: .6rem .75rem; border-radius: 8px; overflow-x: auto; font-family: monospace; }
        /* 即時訊息提示（Toast） */
        .msg-toast-wrap { position: fixed; top: 88px; right: 16px; z-index: 60; display: flex; flex-direction: column; gap: 10px; width: 300px; max-width: calc(100vw - 32px); }
        .msg-toast { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: 14px; background: rgba(8,12,20,.97); border: 1px solid rgba(56,189,248,.45); box-shadow: 0 18px 40px rgba(0,0,0,.5); color: #e2e8f0; cursor: pointer; transform: translateX(120%); opacity: 0; transition: transform .35s cubic-bezier(.34,1.56,.64,1), opacity .3s; backdrop-filter: blur(10px); }
        .msg-toast.is-in { transform: translateX(0); opacity: 1; }
        .msg-toast__ico { width: 34px; height: 34px; border-radius: 10px; flex: none; display: flex; align-items: center; justify-content: center; background: rgba(2,132,199,.2); border: 1px solid rgba(56,189,248,.45); color: #7dd3fc; }
        .msg-toast__t { font-size: 12.5px; font-weight: 700; color: #fff; font-family: 'Noto Serif TC', serif; display: block; }
        .msg-toast__s { font-size: 11px; color: #94a3b8; margin-top: 2px; display: block; }
        .msg-toast__x { margin-left: auto; color: #64748b; font-size: 12px; }
        /* 權限未開放：鎖定樣式 */
        .perm-locked { cursor: not-allowed !important; }
        a.perm-locked.snow-card { opacity: 0.72; filter: grayscale(0.35); }
        a.perm-locked.snow-card::before {
            content: '\f023';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            top: 14px;
            right: 14px;
            z-index: 6;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #be123c;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            box-shadow: 0 4px 12px rgba(190, 18, 60, 0.5);
        }
        .perm-locked .perm-lock-inline { display: inline-block !important; }
        .perm-lock-inline { display: none !important; }
    </style>
</head>
<body class="relative flex flex-col justify-between selection:bg-rose-900 selection:text-white min-h-screen">
<!-- 背景落雪 Canvas -->
<canvas id="snow-canvas"></canvas>
<!-- 頂部氛圍光暈 -->
<div class="fixed top-0 left-1/2 -translate-x-1/2 w-[800px] h-[280px] bg-sky-500/10 rounded-full blur-[140px] pointer-events-none -z-0"></div>
<!-- 頂部全域導覽列 -->
<header class="relative z-20 border-b border-slate-800/80 bg-[#0c101a]/90 backdrop-blur-md sticky top-0 shadow-lg">
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
<div class="flex items-center justify-between h-20">
<!-- 品牌 Logo 與站名 -->
<a class="group flex items-center space-x-3.5 no-underline" href="home.php">
<div class="w-11 h-11 rounded-xl bg-white p-1 shadow-md border border-slate-200 flex items-center justify-center overflow-hidden transition-transform group-hover:scale-105">
<img alt="踏雪笑傲 Logo" class="w-full h-full object-contain" src="assets/logo.svg">
</div>
<div>
<div class="flex items-center space-x-2">
<span class="text-2xl font-bold tracking-wider text-slate-100 font-serif">踏雪笑傲</span>
<span class="seal-tag text-[11px] px-1.5 py-0.5 rounded tracking-normal">會員中心</span>
</div>
<p class="text-[11px] text-slate-400 font-mono tracking-widest uppercase">Snow Wanderer Online</p>
</div>
</a>
<!-- 桌面端主導航連結 -->
<nav class="hidden md:flex items-center space-x-2">
<a class="px-3.5 py-2 text-sm text-sky-400 font-semibold bg-sky-950/40 border border-sky-800/60 rounded-lg flex items-center space-x-2 transition-all shadow-sm" href="home.php">
<i class="fa-solid fa-house-chimney text-xs"></i>
<span class="">帳號總覽</span>
</a>
<a class="px-3.5 py-2 text-sm text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg transition-colors flex items-center space-x-2" href="swo/index.php">
<i class="fa-solid fa-globe text-xs text-amber-400"></i>
<span class="">官網首頁</span>
</a>
<a class="<?= perm_class($canChangePassword, 'px-3.5 py-2 text-sm text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg transition-colors flex items-center space-x-2') ?>" href="usercontrol.php"<?= perm_data($canChangePassword, '修改密碼') ?>>
<i class="fa-regular fa-address-card text-xs text-slate-400"></i>
<span class="">帳號安全<i class="fa-solid fa-lock perm-lock-inline ml-1 text-rose-400"></i></span>
</a>
<a class="<?= perm_class($canRecharge, 'px-3.5 py-2 text-sm text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg transition-colors flex items-center space-x-2') ?>" href="recharge.php"<?= perm_data($canRecharge, '儲值點數') ?>>
<i class="fa-solid fa-coins text-xs text-amber-400"></i>
<span class="">儲值<i class="fa-solid fa-lock perm-lock-inline ml-1 text-rose-400"></i></span>
</a>
<a class="<?= perm_class($canExchange, 'px-3.5 py-2 text-sm text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg transition-colors flex items-center space-x-2') ?>" href="exchange.php"<?= perm_data($canExchange, '兌換元寶') ?>>
<i class="fa-solid fa-right-left text-xs text-slate-400"></i>
<span class="">兌換中心<i class="fa-solid fa-lock perm-lock-inline ml-1 text-rose-400"></i></span>
</a>
<a class="<?= perm_class($canSupport, 'px-3.5 py-2 text-sm text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg transition-colors flex items-center space-x-2') ?>" href="support.php"<?= perm_data($canSupport, '申訴回報') ?>>
<i class="fa-solid fa-headset text-xs text-slate-400"></i>
<span class="">申訴回報<i class="fa-solid fa-lock perm-lock-inline ml-1 text-rose-400"></i></span>
</a>


<!-- 訊息通知中心鈴鐺 -->
<button class="relative p-2.5 rounded-xl text-slate-300 hover:text-white hover:bg-slate-800/60 transition-colors" id="msg-bell" onclick="openMsgModal()" title="訊息通知中心" type="button">
<i class="fa-regular fa-bell text-lg"></i>
<span id="msg-badge" style="display:<?= $messageUnread > 0 ? 'flex' : 'none' ?>;position:absolute;top:-3px;right:-3px;min-width:18px;height:18px;padding:0 4px;border-radius:9999px;background:#e11d48;color:#fff;font-size:10px;font-weight:700;align-items:center;justify-content:center;border:1px solid #0c101a;box-shadow:0 1px 4px rgba(0,0,0,.4);"><?= $messageUnread > 99 ? '99+' : (int)$messageUnread ?></span>
</button>
<!-- 使用者身分與登出 -->
<div class="pl-4 ml-2 border-l border-slate-800 flex items-center space-x-3">
<div class="flex items-center space-x-2.5 bg-slate-900/90 border border-slate-700/70 px-3.5 py-1.5 rounded-full">
<div class="w-6 h-6 rounded-full bg-gradient-to-tr from-rose-700 to-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-sm">
                                俠
                            </div>
<div class="text-left">
<p class="text-xs font-semibold text-slate-200 leading-tight max-w-[120px] truncate" id="user-display-name">
                                    <?= htmlspecialchars($username) ?>
                                </p>
<span class="text-[10px] text-emerald-400 flex items-center gap-1 font-mono">
<span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>已登入
                                </span>
</div>
</div>
<button class="px-3 py-1.5 rounded-lg border border-rose-800/50 bg-rose-950/30 text-rose-300 hover:bg-rose-900/50 hover:text-white text-xs font-medium transition-colors flex items-center gap-1.5 shadow-sm" onclick="openLogoutModal()" title="登出系統">
<i class="fa-solid fa-arrow-right-from-bracket text-xs"></i>
<span class="">登出</span>
</button>
</div>
</nav>
<!-- 行動端漢堡選單按鈕 -->
<div class="flex md:hidden items-center">
<button class="p-2 text-slate-300 hover:text-white rounded-lg focus:outline-none bg-slate-800/60" id="mobile-toggle">
<i class="fa-solid fa-bars text-lg"></i>
</button>
</div>
</div>
</div>
<!-- 行動端選單展開抽屜 -->
<div class="hidden md:hidden border-t border-slate-800 bg-[#0c101a] px-5 py-4 space-y-2" id="mobile-menu">
<div class="pb-3 border-b border-slate-800 flex items-center justify-between">
<span class="text-xs text-slate-400">當前登入帳號：</span>
<span class="text-xs text-sky-400 font-bold"><?= htmlspecialchars($username) ?></span>
</div>
<a class="block px-3 py-2 rounded-lg text-sm text-sky-400 bg-sky-950/40 font-medium" href="home.php">
<i class="fa-solid fa-house-chimney mr-2"></i> 帳號總覽
            </a>
<a class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60" href="swo/index.php">
<i class="fa-solid fa-globe mr-2 text-amber-400"></i> 官網首頁
            </a>
<a class="<?= perm_class($canChangePassword, 'block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60') ?>" href="usercontrol.php"<?= perm_data($canChangePassword, '修改密碼') ?>>
<i class="fa-regular fa-address-card mr-2 text-slate-400"></i> 帳號安全<i class="fa-solid fa-lock perm-lock-inline ml-1 text-rose-400"></i>
            </a>
<a class="<?= perm_class($canRecharge, 'block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60') ?>" href="recharge.php"<?= perm_data($canRecharge, '儲值點數') ?>>
<i class="fa-solid fa-coins mr-2 text-amber-400"></i> 儲值點數<i class="fa-solid fa-lock perm-lock-inline ml-1 text-rose-400"></i>
            </a>
<a class="<?= perm_class($canExchange, 'block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60') ?>" href="exchange.php"<?= perm_data($canExchange, '兌換元寶') ?>>
<i class="fa-solid fa-right-left mr-2 text-amber-400"></i> 兌換中心<i class="fa-solid fa-lock perm-lock-inline ml-1 text-rose-400"></i>
            </a>
<a class="<?= perm_class($canSupport, 'block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60') ?>" href="support.php"<?= perm_data($canSupport, '申訴回報') ?>>
<i class="fa-solid fa-headset mr-2 text-slate-400"></i> 申訴回報<i class="fa-solid fa-lock perm-lock-inline ml-1 text-rose-400"></i>
            </a>
<a class="<?= perm_class($canDownload, 'block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60') ?>" href="download.php"<?= perm_data($canDownload, '遊戲下載') ?>>
<i class="fa-solid fa-download mr-2 text-slate-400"></i> 遊戲下載<i class="fa-solid fa-lock perm-lock-inline ml-1 text-rose-400"></i>
            </a>

<button class="w-full text-left px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60 flex items-center justify-between" onclick="openMsgModal()" type="button">
<span><i class="fa-regular fa-bell mr-2 text-sky-400"></i> 訊息通知</span>
<span id="msg-badge-mobile" style="display:<?= $messageUnread > 0 ? 'flex' : 'none' ?>;min-width:18px;height:18px;padding:0 5px;border-radius:9999px;background:#e11d48;color:#fff;font-size:10px;font-weight:700;align-items:center;justify-content:center;"><?= $messageUnread > 99 ? '99+' : (int)$messageUnread ?></span>
            </button>
<button class="w-full text-left px-3 py-2 rounded-lg text-sm text-rose-400 hover:bg-rose-950/40" onclick="openLogoutModal()">
<i class="fa-solid fa-arrow-right-from-bracket mr-2"></i> 安全登出
            </button>
</div>
</header>
<!-- 主內容區塊 (雪白主題琉璃視窗) -->
<main class="relative z-10 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10 flex-1 w-full space-y-8">
<!-- 玩家帳號核心資訊頂部面板 -->
<div class="snow-card rounded-2xl p-6 sm:p-8">
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 pb-6 border-b border-slate-200/80">
<!-- 左側：頭像與帳號名稱 -->
<div class="flex items-start sm:items-center space-x-5">
<div class="relative">
<div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-rose-700 to-rose-800 border-2 border-white shadow-md flex items-center justify-center overflow-hidden" title="玩家頭像">
<span class="text-white text-3xl font-bold font-serif select-none"><?= htmlspecialchars(($username !== '') ? mb_strtoupper(mb_substr($username, 0, 1)) : '俠') ?></span>
</div>
<span class="absolute -bottom-1 -right-1 px-2 py-0.5 bg-rose-700 text-white text-[10px] font-bold rounded-full shadow-sm">
                            正式帳號
                        </span>
</div>
<div class="space-y-2">
<div class="flex items-center gap-3 flex-wrap">
<h1 class="text-2xl sm:text-3xl font-bold text-slate-800 font-serif tracking-wide"><?= htmlspecialchars($username) ?></h1>
<?php if ($userTier): ?>
<?= account_tier_badge_html($userTier, ['size' => 'lg', 'title' => '帳號層級']) ?>
<?php endif; ?>
<?php if ($userId !== ''): ?>
<span class="seal-tag text-xs px-2.5 py-0.5 rounded font-mono">UID: <?= htmlspecialchars($userId) ?></span>
<?php endif; ?>
<span class="bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs px-2.5 py-0.5 rounded font-medium flex items-center gap-1.5">
<i class="fa-solid fa-circle-check text-xs"></i> 認證良好
                            </span>
</div>
<div class="flex items-center gap-4 flex-wrap text-xs text-slate-600 pt-0.5">
<span class="flex items-center gap-1.5">
<span class="text-slate-400">通行帳號</span>
<strong class="font-mono font-semibold text-slate-800"><?= htmlspecialchars($username) ?></strong>
<button class="text-slate-400 hover:text-sky-700 transition-colors" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($username, ENT_QUOTES) ?>')" title="複製帳號" type="button">
<i class="fa-regular fa-clone text-xs"></i>
</button>
</span>
<span class="text-slate-300">|</span>
<span class="flex items-center gap-1.5">
<span class="text-slate-400">綁定郵箱</span>
<strong class="font-mono text-slate-700"><?= htmlspecialchars($displayEmail) ?></strong>
</span>
<span class="text-slate-300">|</span>
<span class="flex items-center gap-1.5">
<span class="text-slate-400">註冊時間</span>
<span class="font-mono text-slate-700"><?= htmlspecialchars($displayRegDate) ?></span>
</span>
</div>
</div>
</div>
<!-- 右側：帳號操作捷徑 -->
<div class="flex items-center gap-3 shrink-0 flex-wrap">
<a class="<?= perm_class($canExchange, 'px-4 py-2.5 rounded-xl border border-amber-300 bg-gradient-to-r from-amber-50 to-white hover:from-amber-100 text-amber-800 text-xs font-semibold flex items-center gap-2 shadow-sm transition-all hover:border-amber-400') ?>" href="exchange.php"<?= perm_data($canExchange, '兌換元寶') ?>>
<i class="fa-solid fa-right-left text-amber-600"></i>
<span class="">兌換元寶<i class="fa-solid fa-lock perm-lock-inline ml-1 text-rose-500"></i></span>
</a>
<a class="<?= perm_class($canChangePassword, 'px-4 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold flex items-center gap-2 shadow-sm transition-all hover:border-slate-400') ?>" href="usercontrol.php"<?= perm_data($canChangePassword, '修改密碼') ?>>
<i class="fa-solid fa-key text-rose-600"></i>
<span class="">修改密碼<i class="fa-solid fa-lock perm-lock-inline ml-1 text-rose-500"></i></span>
</a>

</div>
</div>
<!-- 帳號數據指標欄 -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-6 text-left">
                <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200/80">
                    <span class="text-[11px] text-slate-500 font-medium block mb-1">帳號信用評分</span>
                    <?php if ($creditVisible): ?>
                    <button class="flex items-baseline gap-1.5 text-left w-full cursor-pointer" onclick="openCreditModal()" type="button" title="檢視信用明細">
                        <span class="text-xl font-bold <?= $creditLevel['color'] === 'rose' ? 'text-rose-600' : ($creditLevel['color'] === 'amber' || $creditLevel['color'] === 'orange' ? 'text-amber-600' : 'text-emerald-600') ?> font-mono"><?= (int)$creditScore ?></span>
                        <span class="text-xs text-slate-400">/ <?= (int)$creditMaxScore ?> 分</span>
                        <span class="text-[10px] <?= htmlspecialchars($creditTierClass) ?> border px-1.5 py-0.2 rounded font-medium ml-1"><?= htmlspecialchars($creditLevel['label']) ?></span>
                    </button>
                    <?php else: ?>
                    <div class="flex items-center gap-1.5 text-slate-400">
                        <i class="fa-solid fa-eye-slash text-xs"></i>
                        <span class="text-xs">信用資訊未開放</span>
                    </div>
                    <?php endif; ?>
                </div>
<div class="p-3.5 rounded-xl bg-gradient-to-br from-amber-50 to-white border border-amber-200/90">
<span class="text-[11px] text-slate-500 font-medium block mb-1.5">我的資產</span>
<?php if ($canViewAssets): ?>
<div class="flex items-center justify-between gap-2">
<div class="space-y-0.5">
<div class="flex items-baseline gap-1">
<i class="fa-solid fa-coins text-sky-500 text-[11px]"></i>
<span class="text-lg font-bold text-sky-700 font-mono"><?= number_format((int)$wallet['points']) ?></span>
<span class="text-[10px] text-slate-400"><?= htmlspecialchars($pointsName) ?></span>
</div>
<div class="flex items-baseline gap-1">
<i class="fa-solid fa-gem text-amber-500 text-[11px]"></i>
<span class="text-lg font-bold text-amber-700 font-mono"><?= number_format((int)$wallet['yuanbao']) ?></span>
<span class="text-[10px] text-slate-400"><?= htmlspecialchars($yuanbaoName) ?></span>
</div>
</div>
<div class="flex flex-col items-end gap-1">
<a class="<?= perm_class($canExchange, 'text-[11px] text-amber-700 hover:text-amber-800 font-semibold hover:underline') ?>" href="exchange.php"<?= perm_data($canExchange, '兌換元寶') ?>>兌換</a>
<a class="<?= perm_class($canRecharge, 'text-[11px] text-rose-700 hover:text-rose-800 font-medium hover:underline') ?>" href="recharge.php"<?= perm_data($canRecharge, '儲值點數') ?>>儲值</a>
</div>
</div>
<?php else: ?>
<div class="flex items-center gap-2 text-slate-400">
<i class="fa-solid fa-lock text-rose-400"></i>
<span class="text-xs">資產資訊未開放檢視</span>
</div>
<?php endif; ?>
</div>
<div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200/80">
<span class="text-[11px] text-slate-500 font-medium block mb-1">帳號安全等級</span>
<div class="flex items-baseline gap-1.5">
<span class="text-xl font-bold text-emerald-600 font-mono">高等級</span>
<span class="text-[11px] text-emerald-600">(已啟雙重驗證)</span>
</div>
</div>
<div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200/80">
<span class="text-[11px] text-slate-500 font-medium block mb-1">帳號狀態 / 註冊歷程</span>
<div class="flex items-baseline gap-1.5">
<span class="text-xl font-bold text-slate-800 font-mono">正常</span>
<span class="text-xs text-slate-400"><?= htmlspecialchars($displayDaysText) ?></span>
<span class="text-[10px] text-emerald-600 font-semibold ml-1">● 無違規</span>
</div>
</div>
</div>
</div>
<!-- 核心功能模組導航 -->
<div>
<div class="flex items-center justify-between mb-4">
<h2 class="text-lg font-bold text-slate-100 font-serif flex items-center gap-2">
<span class="w-2 h-4 bg-rose-700 rounded-sm"></span>
<span class="">系統功能選單</span>
</h2>
<span class="text-xs text-slate-400">點選以進入各項帳號管理與支援功能</span>
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
<!-- 卡片 1: 帳號資料 (PROFILE) -->
<a class="<?= perm_class($canChangePassword, 'snow-card snow-card-interactive rounded-2xl p-7 sm:p-8 group no-underline block text-left bg-gradient-to-b from-white/95 via-sky-50/20 to-sky-50/40 relative overflow-hidden') ?>" href="usercontrol.php"<?= perm_data($canChangePassword, '修改密碼') ?>>
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="flex items-center justify-between mb-6">
<div class="relative w-20 h-20 flex items-center justify-center">
<div class="absolute inset-0 rounded-2xl bg-sky-400/25 blur-xl group-hover:bg-sky-400/40 group-hover:scale-110 transition-all duration-300"></div>
<div class="relative w-20 h-20 rounded-2xl bg-gradient-to-br from-white via-sky-50 to-sky-100 p-[1.5px] shadow-lg shadow-sky-500/10 border border-sky-200/90 group-hover:border-sky-400 transition-all duration-300">
<div class="w-full h-full rounded-[14px] bg-gradient-to-br from-sky-500/10 via-sky-50/50 to-white flex items-center justify-center border border-sky-100/80 group-hover:scale-102 transition-transform duration-300">
<div class="w-12 h-12 rounded-xl bg-gradient-to-tr from-sky-600 to-sky-400 text-white flex items-center justify-center text-2xl shadow-md shadow-sky-600/30 group-hover:rotate-3 transition-transform duration-300">
<i class="fa-solid fa-id-badge"></i>
</div>
</div>
</div>
</div>
<div class="flex items-center px-3 py-1 rounded-full bg-sky-50 border border-sky-200/80 shadow-xs">
<span class="w-1.5 h-1.5 rounded-full bg-sky-500 mr-1.5 animate-pulse"></span>
<span class="text-[11px] font-bold text-sky-700 font-mono tracking-widest leading-none">PROFILE</span>
</div>
</div>
<h3 class="text-lg font-bold text-slate-800 group-hover:text-sky-700 transition-colors mb-2 flex items-center justify-between font-serif">
<span class="tracking-wide">帳號資料</span>
<i class="fa-solid fa-arrow-right text-xs text-sky-600 opacity-0 group-hover:opacity-100 group-hover:translate-x-1 transition-all"></i>
</h3>
<p class="text-xs text-slate-500 leading-relaxed min-h-[36px]">
                        檢視管理基本設定、綁定安全憑證
                    </p>
<div class="mt-5 pt-3.5 border-t border-slate-200/80 flex items-center justify-between text-xs text-sky-700 font-semibold group-hover:translate-x-0.5 transition-transform">
<span class="">前往設定 →</span>
<i class="fa-solid fa-chevron-right text-[10px] opacity-70"></i>
</div>
</a>
<!-- 卡片 2: 儲值點數 (TOP-UP) -->
<a class="<?= perm_class($canRecharge, 'snow-card snow-card-interactive rounded-2xl p-7 sm:p-8 group no-underline block text-left bg-gradient-to-b from-white/95 via-amber-50/20 to-amber-50/40 relative overflow-hidden') ?>" href="recharge.php"<?= perm_data($canRecharge, '儲值點數') ?>>
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="flex items-center justify-between mb-6">
<div class="relative w-20 h-20 flex items-center justify-center">
<div class="absolute inset-0 rounded-2xl bg-amber-400/25 blur-xl group-hover:bg-amber-400/40 group-hover:scale-110 transition-all duration-300"></div>
<div class="relative w-20 h-20 rounded-2xl bg-gradient-to-br from-white via-amber-50 to-amber-100 p-[1.5px] shadow-lg shadow-amber-500/10 border border-amber-300/80 group-hover:border-amber-400 transition-all duration-300">
<div class="w-full h-full rounded-[14px] bg-gradient-to-br from-amber-500/10 via-amber-50/50 to-white flex items-center justify-center border border-amber-100/80 group-hover:scale-102 transition-transform duration-300">
<div class="w-12 h-12 rounded-xl bg-gradient-to-tr from-amber-500 via-amber-400 to-yellow-300 text-white flex items-center justify-center text-2xl shadow-md shadow-amber-500/30 group-hover:rotate-3 transition-transform duration-300">
<i class="fa-solid fa-coins"></i>
</div>
</div>
</div>
</div>
<div class="flex items-center px-3 py-1 rounded-full bg-amber-50 border border-amber-200/80 shadow-xs">
<span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1.5"></span>
<span class="text-[11px] font-bold text-amber-700 font-mono tracking-widest leading-none">TOP-UP</span>
</div>
</div>
<h3 class="text-lg font-bold text-slate-800 group-hover:text-amber-800 transition-colors mb-2 flex items-center justify-between font-serif">
<span class="tracking-wide">儲值點數</span>
<i class="fa-solid fa-arrow-right text-xs text-amber-600 opacity-0 group-hover:opacity-100 group-hover:translate-x-1 transition-all"></i>
</h3>
<p class="text-xs text-slate-500 leading-relaxed min-h-[36px]">
                        儲值點數即刻入帳、輕鬆兌換遊戲元寶
                    </p>
<div class="mt-5 pt-3.5 border-t border-slate-200/80 flex items-center justify-between text-xs text-amber-700 font-semibold group-hover:translate-x-0.5 transition-transform">
<span class="">立即儲值 →</span>
<i class="fa-solid fa-chevron-right text-[10px] opacity-70"></i>
</div>
</a>
<!-- 卡片 3: 帳號信用 (CREDIT) -->
<a class="snow-card snow-card-interactive rounded-2xl p-7 sm:p-8 group no-underline block text-left bg-gradient-to-b from-white/95 via-emerald-50/20 to-emerald-50/40 relative overflow-hidden" href="javascript:void(0)" onclick="openCreditModal()">
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="flex items-center justify-between mb-6">
<div class="relative w-20 h-20 flex items-center justify-center">
<div class="absolute inset-0 rounded-2xl bg-emerald-400/25 blur-xl group-hover:bg-emerald-400/40 group-hover:scale-110 transition-all duration-300"></div>
<div class="relative w-20 h-20 rounded-2xl bg-gradient-to-br from-white via-emerald-50 to-emerald-100 p-[1.5px] shadow-lg shadow-emerald-500/10 border border-emerald-300/80 group-hover:border-emerald-400 transition-all duration-300">
<div class="w-full h-full rounded-[14px] bg-gradient-to-br from-emerald-500/10 via-emerald-50/50 to-white flex items-center justify-center border border-emerald-100/80 group-hover:scale-102 transition-transform duration-300">
<div class="w-12 h-12 rounded-xl bg-gradient-to-tr from-emerald-600 via-emerald-500 to-teal-400 text-white flex items-center justify-center text-2xl shadow-md shadow-emerald-600/30 group-hover:rotate-3 transition-transform duration-300">
<i class="fa-solid fa-shield-halved"></i>
</div>
</div>
</div>
</div>
<div class="flex items-center px-3 py-1 rounded-full bg-emerald-50 border border-emerald-200/80 shadow-xs">
<span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5"></span>
<span class="text-[11px] font-bold text-emerald-700 font-mono tracking-widest leading-none">CREDIT</span>
</div>
</div>
<h3 class="text-lg font-bold text-slate-800 group-hover:text-emerald-700 transition-colors mb-2 flex items-center justify-between font-serif">
<span class="tracking-wide">帳號信用</span>
<i class="fa-solid fa-arrow-right text-xs text-emerald-600 opacity-0 group-hover:opacity-100 group-hover:translate-x-1 transition-all"></i>
</h3>
<p class="text-xs text-slate-500 leading-relaxed min-h-[36px]">
                        即時信譽等級評定與專屬免鎖定特權
                    </p>
<div class="mt-5 pt-3.5 border-t border-slate-200/80 flex items-center justify-between text-xs text-emerald-700 font-semibold group-hover:translate-x-0.5 transition-transform">
<span class="">信用明細 →</span>
<i class="fa-solid fa-chevron-right text-[10px] opacity-70"></i>
</div>
</a>
<!-- 卡片 4: 申訴回報 (SUPPORT) -->
<a class="<?= perm_class($canSupport, 'snow-card snow-card-interactive rounded-2xl p-7 sm:p-8 group no-underline block text-left bg-gradient-to-b from-white/95 via-rose-50/20 to-rose-50/40 relative overflow-hidden') ?>" href="support.php"<?= perm_data($canSupport, '申訴回報') ?>>
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="flex items-center justify-between mb-6">
<div class="relative w-20 h-20 flex items-center justify-center">
<div class="absolute inset-0 rounded-2xl bg-rose-400/25 blur-xl group-hover:bg-rose-400/40 group-hover:scale-110 transition-all duration-300"></div>
<div class="relative w-20 h-20 rounded-2xl bg-gradient-to-br from-white via-rose-50 to-rose-100 p-[1.5px] shadow-lg shadow-rose-500/10 border border-rose-300/80 group-hover:border-rose-400 transition-all duration-300">
<div class="w-full h-full rounded-[14px] bg-gradient-to-br from-rose-500/10 via-rose-50/50 to-white flex items-center justify-center border border-rose-100/80 group-hover:scale-102 transition-transform duration-300">
<div class="w-12 h-12 rounded-xl bg-gradient-to-tr from-rose-700 via-rose-600 to-pink-500 text-white flex items-center justify-center text-2xl shadow-md shadow-rose-600/30 group-hover:rotate-3 transition-transform duration-300">
<i class="fa-solid fa-headset"></i>
</div>
</div>
</div>
</div>
<div class="flex items-center px-3 py-1 rounded-full bg-rose-50 border border-rose-200/80 shadow-xs">
<span class="w-1.5 h-1.5 rounded-full bg-rose-500 mr-1.5"></span>
<span class="text-[11px] font-bold text-rose-700 font-mono tracking-widest leading-none">SUPPORT</span>
</div>
</div>
<h3 class="text-lg font-bold text-slate-800 group-hover:text-rose-700 transition-colors mb-2 flex items-center justify-between font-serif">
<span class="tracking-wide">申訴回報</span>
<i class="fa-solid fa-arrow-right text-xs text-rose-600 opacity-0 group-hover:opacity-100 group-hover:translate-x-1 transition-all"></i>
</h3>
<p class="text-xs text-slate-500 leading-relaxed min-h-[36px]">
                        專屬客服即時諮詢與帳號異常申訴
                    </p>
<div class="mt-5 pt-3.5 border-t border-slate-200/80 flex items-center justify-between text-xs text-rose-700 font-semibold group-hover:translate-x-0.5 transition-transform">
<span class="">填寫回報 →</span>
<i class="fa-solid fa-chevron-right text-[10px] opacity-70"></i>
</div>
</a>
</div>
</div>
</main>
<!-- 頁尾 -->
<footer class="relative z-10 border-t border-slate-800 bg-[#090d16] py-8 text-center text-xs text-slate-400 mt-auto">
<div class="max-w-7xl mx-auto px-4 space-y-2.5">
<div class="flex items-center justify-center space-x-2 text-slate-300 font-serif font-semibold text-sm">
<span class="">踏雪笑傲 · 繁體中文官方正版</span>
</div>
<p class="text-slate-400">
<a class="hover:text-slate-200 mx-2" href="home.php">帳號總覽</a> ·
                <a class="hover:text-slate-200 mx-2" href="swo/index.php">官網首頁</a> ·
                <a class="hover:text-slate-200 mx-2" href="usercontrol.php">安全中心</a> ·
                <a class="hover:text-slate-200 mx-2" href="download.php">遊戲下載</a> ·
                <a class="hover:text-slate-200 mx-2" href="#">服務條款</a> ·
                <a class="hover:text-slate-200 mx-2" href="#">隱私政策</a>
</p>
<p class="text-[11px] text-slate-400 font-mono">
                © 2025 踏雪笑傲營運團隊 All Rights Reserved.
            </p>
</div>
</footer>
<!-- 登出確認對話框 -->
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-300 px-4" id="logout-modal">
<div class="snow-card max-w-sm w-full rounded-2xl p-6 text-center border-slate-300 shadow-2xl transform scale-95 transition-transform duration-300" id="logout-card">
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="w-14 h-14 mx-auto rounded-full bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-700 text-2xl mb-4 shadow-sm">
<i class="fa-solid fa-arrow-right-from-bracket"></i>
</div>
<h3 class="text-lg font-bold text-slate-800 font-serif mb-2">確認登出帳號？</h3>
<p class="text-xs text-slate-500 leading-relaxed mb-6">
                您即將結束本次登入工作階段。登出後若需查閱帳號資訊或管理設定，請重新輸入帳號與密碼登入。
            </p>
<div class="flex items-center justify-center space-x-3">
<button class="w-1/2 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold transition-all shadow-sm" onclick="closeLogoutModal()">
                    取消並返回
                </button>
<a class="w-1/2 py-2.5 rounded-xl bg-gradient-to-r from-rose-700 to-rose-800 hover:from-rose-800 hover:to-rose-900 text-white text-xs font-semibold transition-all shadow-md text-center no-underline" href="logout.php">
                    確認登出
                </a>
</div>
</div>
</div>
<!-- 權限不足提示對話框 -->
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-300 px-4" id="perm-modal">
<div class="snow-card max-w-sm w-full rounded-2xl p-6 text-center border-slate-300 shadow-2xl transform scale-95 transition-transform duration-300" id="perm-card">
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="w-14 h-14 mx-auto rounded-full bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-700 text-2xl mb-4 shadow-sm">
<i class="fa-solid fa-lock"></i>
</div>
<h3 class="text-lg font-bold text-slate-800 font-serif mb-2">功能使用權限未開放</h3>
<p class="text-xs text-slate-500 leading-relaxed mb-6" id="perm-modal-message">
                您的帳號目前未被授予此功能權限。
            </p>
<div class="flex items-center justify-center space-x-3">
<button class="w-1/2 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold transition-all shadow-sm" onclick="closePermModal()">
                    我知道了
                </button>
<a class="w-1/2 py-2.5 rounded-xl bg-gradient-to-r from-rose-700 to-rose-800 hover:from-rose-800 hover:to-rose-900 text-white text-xs font-semibold transition-all shadow-md text-center no-underline" href="support.php">
                    聯繫客服
                </a>
</div>
</div>
</div>
<!-- 帳號信用明細 彈出子視窗 -->
<?php
$creditLegend = [
    ['label' => '極佳',   'min' => (int)$creditSettings['tier_excellent'], 'color' => '#059669', 'bg' => '#ecfdf5', 'bd' => '#a7f3d0'],
    ['label' => '良好',   'min' => (int)$creditSettings['tier_good'],      'color' => '#0284c7', 'bg' => '#f0f9ff', 'bd' => '#bae6fd'],
    ['label' => '尚可',   'min' => (int)$creditSettings['tier_fair'],      'color' => '#d97706', 'bg' => '#fffbeb', 'bd' => '#fde68a'],
    ['label' => '待加強', 'min' => (int)$creditSettings['tier_poor'],      'color' => '#ea580c', 'bg' => '#fff7ed', 'bd' => '#fed7aa'],
    ['label' => '風險',   'min' => null,                                    'color' => '#be123c', 'bg' => '#fff1f2', 'bd' => '#fecdd3'],
];
$creditBarPct = $creditMaxScore > 0 ? max(0, min(100, (int)round($creditScore / $creditMaxScore * 100))) : 0;
?>
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-300 px-4" id="credit-modal">
<div class="snow-card max-w-lg w-full max-h-[90vh] overflow-y-auto rounded-2xl p-6 sm:p-7 border-slate-300 shadow-2xl transform scale-95 transition-transform duration-300" id="credit-card">
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="flex items-start justify-between gap-4 mb-5">
<div class="flex items-center gap-3">
<div class="w-11 h-11 rounded-xl bg-emerald-50 border border-emerald-200 flex items-center justify-center text-emerald-700 text-lg shrink-0">
<i class="fa-solid fa-shield-halved"></i>
</div>
<div>
<h3 class="text-base font-bold text-slate-800 font-serif tracking-wide">帳號信用明細</h3>
<p class="text-[11px] text-slate-500 mt-0.5">信用分數、等級評定與最近異動紀錄</p>
</div>
</div>
<button class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors" onclick="closeCreditModal()" type="button" title="關閉">
<i class="fa-solid fa-xmark text-lg"></i>
</button>
</div>
<?php if (!$creditVisible): ?>
<div class="flex items-center gap-2 p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-500 text-sm">
<i class="fa-solid fa-eye-slash text-rose-400"></i>
<span>信用資訊目前未開放檢視，如有疑問請聯繫客服。</span>
</div>
<?php else: ?>
<div class="flex items-center gap-5 p-4 rounded-xl bg-gradient-to-br from-slate-50 to-white border border-slate-200 mb-4">
<div class="w-24 h-24 rounded-full flex flex-col items-center justify-center shrink-0 bg-white" style="border:6px solid <?= htmlspecialchars($creditRing) ?>;box-shadow:0 10px 24px -12px <?= htmlspecialchars($creditRing) ?>;">
<span class="text-3xl font-extrabold font-mono text-slate-800 leading-none"><?= (int)$creditScore ?></span>
<span class="text-[10px] text-slate-400 mt-0.5">/ <?= (int)$creditMaxScore ?> 分</span>
</div>
<div class="flex-1 min-w-0">
<span class="<?= htmlspecialchars($creditTierClass) ?> border text-xs font-bold px-2.5 py-1 rounded-full inline-flex items-center gap-1.5">
<i class="fa-solid fa-circle-check text-[10px]"></i><?= htmlspecialchars($creditLevel['label']) ?>
</span>
<p class="text-xs text-slate-500 leading-relaxed mt-2.5"><?= htmlspecialchars($creditLevel['desc']) ?></p>
</div>
</div>
<div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden mb-5">
<div class="h-full rounded-full" style="width:<?= (int)$creditBarPct ?>%;background:<?= htmlspecialchars($creditRing) ?>;"></div>
</div>
<div class="mb-5">
<h4 class="text-xs font-bold text-slate-700 mb-2 flex items-center gap-1.5"><i class="fa-solid fa-layer-group text-emerald-600"></i>信用等級門檻</h4>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
<?php foreach ($creditLegend as $lg): ?>
<div class="flex items-center justify-between gap-2 px-3 py-2 rounded-lg border text-xs" style="background:<?= $lg['bg'] ?>;border-color:<?= $lg['bd'] ?>;color:<?= $lg['color'] ?>;">
<span class="font-semibold"><?= htmlspecialchars($lg['label']) ?></span>
<span class="font-mono"><?= $lg['min'] === null ? ('< ' . (int)$creditSettings['tier_poor'] . ' 分') : ('>= ' . (int)$lg['min'] . ' 分') ?></span>
</div>
<?php endforeach; ?>
</div>
</div>
<div>
<h4 class="text-xs font-bold text-slate-700 mb-2 flex items-center gap-1.5"><i class="fa-solid fa-clock-rotate-left text-sky-600"></i>最近異動紀錄</h4>
<?php if (!$creditLedger): ?>
<div class="text-center text-slate-400 text-xs py-6 border border-dashed border-slate-200 rounded-xl">
<i class="fa-regular fa-folder-open mr-1"></i> 尚無信用異動紀錄
</div>
<?php else: ?>
<div class="overflow-hidden rounded-xl border border-slate-200">
<table class="w-full text-left text-xs">
<thead class="bg-slate-50 text-slate-500">
<tr>
<th class="px-3 py-2 font-semibold">時間</th>
<th class="px-3 py-2 font-semibold">變動</th>
<th class="px-3 py-2 font-semibold">說明</th>
</tr>
</thead>
<tbody class="divide-y divide-slate-100">
<?php foreach ($creditLedger as $lg): $chg = (int)$lg['change_amount']; ?>
<tr>
<td class="px-3 py-2 font-mono text-slate-500 whitespace-nowrap"><?= htmlspecialchars(date('Y-m-d', strtotime($lg['created_at']))) ?></td>
<td class="px-3 py-2 font-mono font-bold <?= $chg >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= ($chg > 0 ? '+' : '') . $chg ?></td>
<td class="px-3 py-2 text-slate-600"><?= htmlspecialchars($lg['reason'] !== '' ? $lg['reason'] : '信用分數調整') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
<div class="mt-6">
<button class="w-full py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold transition-all shadow-sm" onclick="closeCreditModal()" type="button">
關閉視窗
</button>
</div>
</div>
</div>
<!-- 即時訊息提示容器 -->
<div class="msg-toast-wrap" id="msg-toast-wrap"></div>
<!-- 訊息通知中心 彈出子視窗 -->
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-300 px-4" id="msg-modal">
<div class="snow-card max-w-2xl w-full max-h-[90vh] flex flex-col rounded-2xl p-0 border-slate-300 shadow-2xl transform scale-95 transition-transform duration-300 overflow-hidden" id="msg-card">
<div class="ornament-tl"></div>
<div class="ornament-br"></div>
<div class="flex items-start justify-between gap-4 p-5 sm:p-6 border-b border-slate-200">
<div class="flex items-center gap-3">
<div class="w-11 h-11 rounded-xl bg-sky-50 border border-sky-200 flex items-center justify-center text-sky-700 text-lg shrink-0">
<i class="fa-regular fa-bell"></i>
</div>
<div>
<h3 class="text-base font-bold text-slate-800 font-serif tracking-wide flex items-center gap-2">
                                訊息通知中心
                                <span class="text-[10px] font-mono text-slate-400 border border-slate-200 px-1.5 py-0.5 rounded" id="msg-total-label">0 則</span>
                            </h3>
<p class="text-[11px] text-slate-500 mt-0.5">接收系統通知與 GM 訊息</p>
</div>
</div>
<button class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors" onclick="closeMsgModal()" type="button" title="關閉">
<i class="fa-solid fa-xmark text-lg"></i>
</button>
</div>
<div class="flex items-center justify-between gap-2 px-5 sm:px-6 py-3 border-b border-slate-100 bg-slate-50/70">
<div class="flex items-center gap-1.5" id="msg-tabs">
<button class="msg-tab text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors bg-sky-600 text-white" data-filter="all" type="button">全部</button>
<button class="msg-tab text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors text-slate-500 hover:bg-slate-100" data-filter="unread" type="button">未讀 <span id="msg-unread-tab"><?= (int)$messageUnread ?></span></button>
</div>
<div class="flex items-center gap-2">
<button class="text-xs font-semibold text-sky-700 hover:underline" id="msg-readall" onclick="markAllMsgRead()" type="button">
<i class="fa-solid fa-check-double mr-1"></i>一鍵已讀
</button>
<span class="text-slate-300">·</span>
<button class="text-xs font-semibold text-rose-600 hover:underline" id="msg-deleteall" onclick="deleteAllMsg()" type="button">
<i class="fa-solid fa-trash-can mr-1"></i>一鍵刪除
</button>
</div>
</div>
<div class="flex-1 overflow-y-auto p-4 sm:p-5 space-y-2.5 bg-white/60" id="msg-list">
<div class="text-center text-slate-400 text-xs py-10">
<i class="fa-solid fa-circle-notch fa-spin mr-1"></i> 載入中…
                            </div>
</div>
</div>
</div>
<!-- 交互動效與落雪腳本 -->
<script>
        // 0. 會員功能權限：鎖定項目彈出提示
        const permModal = document.getElementById('perm-modal');
        const permCard = document.getElementById('perm-card');
        const permMsgEl = document.getElementById('perm-modal-message');

        function openPermModal(label) {
            if (!permModal) return;
            if (permMsgEl) {
                permMsgEl.innerHTML = '您的帳號目前未被授予「<span class="font-semibold text-slate-700">' +
                    String(label || '此功能').replace(/[<>&"]/g, '') + '</span>」權限。<br>如需使用，請聯繫客服或管理員協助開通。';
            }
            permModal.classList.remove('opacity-0', 'pointer-events-none');
            permModal.classList.add('opacity-100');
            if (permCard) {
                permCard.classList.remove('scale-95');
                permCard.classList.add('scale-100');
            }
        }

        function closePermModal() {
            if (!permModal) return;
            permModal.classList.add('opacity-0', 'pointer-events-none');
            permModal.classList.remove('opacity-100');
            if (permCard) {
                permCard.classList.remove('scale-100');
                permCard.classList.add('scale-95');
            }
        }

        document.querySelectorAll('.perm-locked').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openPermModal(el.getAttribute('data-perm-label'));
                return false;
            });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openPermModal(el.getAttribute('data-perm-label'));
                }
            });
        });

        if (permModal) {
            permModal.addEventListener('click', function (e) {
                if (e.target === permModal) { closePermModal(); }
            });
        }

        // 0.5 帳號信用明細彈出子視窗
        const creditModal = document.getElementById('credit-modal');
        const creditCard = document.getElementById('credit-card');

        function openCreditModal() {
            if (!creditModal) return;
            creditModal.classList.remove('opacity-0', 'pointer-events-none');
            creditModal.classList.add('opacity-100');
            if (creditCard) {
                creditCard.classList.remove('scale-95');
                creditCard.classList.add('scale-100');
            }
        }

        function closeCreditModal() {
            if (!creditModal) return;
            creditModal.classList.add('opacity-0', 'pointer-events-none');
            creditModal.classList.remove('opacity-100');
            if (creditCard) {
                creditCard.classList.remove('scale-100');
                creditCard.classList.add('scale-95');
            }
        }

        if (creditModal) {
            creditModal.addEventListener('click', function (e) {
                if (e.target === creditModal) { closeCreditModal(); }
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && creditModal && creditModal.classList.contains('opacity-100')) {
                closeCreditModal();
            }
        });

        // 1. 行動端導覽列開關
        const mobileToggle = document.getElementById('mobile-toggle');
        const mobileMenu = document.getElementById('mobile-menu');
        if (mobileToggle && mobileMenu) {
            mobileToggle.addEventListener('click', () => {
                mobileMenu.classList.toggle('hidden');
            });
        }

        // 2. 登出確認對話框控制
        const logoutModal = document.getElementById('logout-modal');
        const logoutCard = document.getElementById('logout-card');

        function openLogoutModal() {
            if (!logoutModal) return;
            logoutModal.classList.remove('opacity-0', 'pointer-events-none');
            logoutModal.classList.add('opacity-100');
            if (logoutCard) {
                logoutCard.classList.remove('scale-95');
                logoutCard.classList.add('scale-100');
            }
        }

        function closeLogoutModal() {
            if (!logoutModal) return;
            logoutModal.classList.add('opacity-0', 'pointer-events-none');
            logoutModal.classList.remove('opacity-100');
            if (logoutCard) {
                logoutCard.classList.remove('scale-100');
                logoutCard.classList.add('scale-95');
            }
        }

        if (logoutModal) {
            logoutModal.addEventListener('click', (e) => {
                if (e.target === logoutModal) {
                    closeLogoutModal();
                }
            });
        }

        // 3. 背景平滑無閃爍落雪 Canvas 系統
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
            const particleCount = Math.min(Math.floor(window.innerWidth / 28), 50);

            for (let i = 0; i < particleCount; i++) {
                particles.push({
                    x: Math.random() * width,
                    y: Math.random() * height,
                    radius: Math.random() * 2.2 + 0.8,
                    speedY: Math.random() * 0.7 + 0.3,
                    speedX: (Math.random() - 0.5) * 0.4,
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

                    if (p.y > height) {
                        p.y = -10;
                        p.x = Math.random() * width;
                    }
                    if (p.x > width) p.x = 0;
                    if (p.x < 0) p.x = width;

                    const currentOpacity = p.opacity + Math.sin(p.pulsePhase) * 0.15;
                    const safeOpacity = Math.max(0.15, Math.min(0.8, currentOpacity));

                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
                    ctx.fillStyle = `rgba(240, 249, 255, ${safeOpacity})`;
                    ctx.shadowBlur = 4;
                    ctx.shadowColor = 'rgba(255, 255, 255, 0.4)';
                    ctx.fill();
                }

                requestAnimationFrame(render);
            }

            render();
        })();

        // 4. 訊息通知中心
        (function initMessageCenter() {
            const MSG_API = 'message_api.php';
            const MSG_CSRF = <?= json_encode($msgCsrf) ?>;
            const msgModal = document.getElementById('msg-modal');
            const msgCard = document.getElementById('msg-card');
            const msgList = document.getElementById('msg-list');
            const msgTotalLabel = document.getElementById('msg-total-label');
            const msgUnreadTab = document.getElementById('msg-unread-tab');
            const msgReadAllBtn = document.getElementById('msg-readall');
            const msgDeleteAllBtn = document.getElementById('msg-deleteall');
            const badgeDesk = document.getElementById('msg-badge');
            const badgeMobile = document.getElementById('msg-badge-mobile');
            if (!msgModal) return;

            let items = [];
            let filter = 'all';
            let expanded = {};
            let loaded = false;

            function esc(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }
            function setBadge(n) {
                n = Number(n) || 0;
                [badgeDesk, badgeMobile].forEach(function (el) {
                    if (!el) return;
                    el.textContent = n > 99 ? '99+' : String(n);
                    el.style.display = n > 0 ? 'flex' : 'none';
                });
                if (msgUnreadTab) msgUnreadTab.textContent = n;
                if (msgReadAllBtn) msgReadAllBtn.disabled = (n <= 0);
            }

            function openMsgModal() {
                msgModal.classList.remove('opacity-0', 'pointer-events-none');
                msgModal.classList.add('opacity-100');
                if (msgCard) { msgCard.classList.remove('scale-95'); msgCard.classList.add('scale-100'); }
                loadList();
            }
            function closeMsgModal() {
                msgModal.classList.add('opacity-0', 'pointer-events-none');
                msgModal.classList.remove('opacity-100');
                if (msgCard) { msgCard.classList.remove('scale-100'); msgCard.classList.add('scale-95'); }
            }
            window.openMsgModal = openMsgModal;
            window.closeMsgModal = closeMsgModal;

            function loadList() {
                msgList.innerHTML = '<div class="text-center text-slate-400 text-xs py-10"><i class="fa-solid fa-circle-notch fa-spin mr-1"></i> 載入中…</div>';
                fetch(MSG_API + '?action=list', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.ok) throw new Error('bad');
                        items = data.items || [];
                        loaded = true;
                        setBadge(data.unread);
                        render();
                    })
                    .catch(function () {
                        msgList.innerHTML = '<div class="text-center text-rose-400 text-xs py-10"><i class="fa-solid fa-triangle-exclamation mr-1"></i> 訊息載入失敗，請稍後再試</div>';
                    });
            }

            function render() {
                const shown = items.filter(function (m) { return filter === 'all' || Number(m.is_read) === 0; });
                if (msgTotalLabel) msgTotalLabel.textContent = items.length + ' 則';
                if (msgDeleteAllBtn) msgDeleteAllBtn.disabled = (items.length === 0);
                if (!shown.length) {
                    msgList.innerHTML = '<div class="text-center text-slate-400 text-xs py-12 border border-dashed border-slate-200 rounded-xl"><i class="fa-regular ' + (filter === 'unread' ? 'fa-envelope-open' : 'fa-bell-slash') + ' text-2xl block mb-2"></i>' + (filter === 'unread' ? '目前沒有未讀訊息' : '目前沒有訊息通知') + '</div>';
                    return;
                }
                msgList.innerHTML = shown.map(function (m) {
                    const unread = Number(m.is_read) === 0;
                    const isOpen = !!expanded[m.id];
                    const content = m.content || '';
                    const sender = m.sender_type === 'gm' ? ('GM：' + esc(m.sender_name || '管理員')) : '系統通知';
                    let titleStyle = '';
                    if (m.title_color) { titleStyle += 'color:' + m.title_color + ';'; }
                    if (Number(m.title_bold) === 1) { titleStyle += 'font-weight:800;'; }
                    return '' +
                        '<div class="rounded-xl border ' + (unread ? 'border-sky-200 bg-sky-50/50' : 'border-slate-200 bg-white') + ' overflow-hidden transition-all">' +
                            '<div class="flex items-start gap-1 px-3 py-3">' +
                                '<button type="button" class="flex-1 min-w-0 text-left flex items-start gap-3" onclick="window.__msgToggle(' + m.id + ')">' +
                                    '<span class="mt-0.5 shrink-0 w-8 h-8 rounded-lg flex items-center justify-center text-sm ' + (unread ? 'bg-sky-100 text-sky-700' : 'bg-slate-100 text-slate-500') + '"><i class="' + esc(m.type_icon || 'fa-solid fa-bell') + '"></i></span>' +
                                    '<span class="flex-1 min-w-0">' +
                                        '<span class="flex items-center gap-2 flex-wrap">' +
                                            '<span class="text-[10px] font-bold px-1.5 py-0.5 rounded border ' + esc(m.type_badge || 'bg-slate-100 text-slate-600 border-slate-300') + '">' + esc(m.type_label) + '</span>' +
                                            (unread ? '<span class="text-[10px] font-bold text-rose-600 flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span>未讀</span>' : '') +
                                        '</span>' +
                                        '<span class="block mt-1 text-sm ' + (unread ? 'font-semibold' : 'font-medium') + ' truncate" style="' + titleStyle + '">' + esc(m.title) + '</span>' +
                                        '<span class="block mt-0.5 text-[11px] text-slate-400 font-mono">' + esc(sender) + ' · ' + esc(m.ago) + '</span>' +
                                    '</span>' +
                                    '<i class="fa-solid fa-chevron-down text-xs text-slate-400 mt-1 transition-transform ' + (isOpen ? 'rotate-180' : '') + '"></i>' +
                                '</button>' +
                                '<button type="button" class="shrink-0 w-7 h-7 rounded-lg text-slate-300 hover:text-rose-600 hover:bg-rose-50 flex items-center justify-center" title="刪除訊息" onclick="window.__msgDelete(' + m.id + ')"><i class="fa-solid fa-trash-can text-xs"></i></button>' +
                            '</div>' +
                            '<div class="msg-content px-4 pb-4 pt-1 text-xs text-slate-600 leading-relaxed border-t border-slate-100" style="display:' + (isOpen ? 'block' : 'none') + '">' + (content || '（無內容）') + '</div>' +
                        '</div>';
                }).join('');
            }

            window.__msgToggle = function (id) {
                const m = items.find(function (x) { return Number(x.id) === Number(id); });
                if (!m) return;
                expanded[id] = !expanded[id];
                if (Number(m.is_read) === 0) {
                    m.is_read = 1;
                    fetch(MSG_API + '?action=read', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'csrf=' + encodeURIComponent(MSG_CSRF) + '&id=' + encodeURIComponent(id)
                    }).then(function (r) { return r.json(); }).then(function (d) { if (d && d.ok) setBadge(d.unread); }).catch(function () {});
                }
                render();
            };

            window.markAllMsgRead = function () {
                fetch(MSG_API + '?action=read_all', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf=' + encodeURIComponent(MSG_CSRF)
                }).then(function (r) { return r.json(); }).then(function (d) {
                    if (d && d.ok) {
                        items.forEach(function (m) { m.is_read = 1; });
                        setBadge(d.unread);
                        render();
                    }
                }).catch(function () {});
            };

            window.__msgDelete = function (id) {
                if (!window.confirm('確定要刪除此訊息嗎？')) return;
                fetch(MSG_API + '?action=delete', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf=' + encodeURIComponent(MSG_CSRF) + '&id=' + encodeURIComponent(id)
                }).then(function (r) { return r.json(); }).then(function (d) {
                    if (d && d.ok) {
                        items = items.filter(function (m) { return Number(m.id) !== Number(id); });
                        delete expanded[id];
                        setBadge(d.unread);
                        render();
                    }
                }).catch(function () {});
            };

            window.deleteAllMsg = function () {
                if (!items.length) return;
                if (!window.confirm('確定要刪除全部訊息嗎？此操作無法復原。')) return;
                fetch(MSG_API + '?action=delete_all', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf=' + encodeURIComponent(MSG_CSRF)
                }).then(function (r) { return r.json(); }).then(function (d) {
                    if (d && d.ok) {
                        items = [];
                        expanded = {};
                        setBadge(d.unread);
                        render();
                    }
                }).catch(function () {});
            };

            document.querySelectorAll('#msg-tabs .msg-tab').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    filter = btn.getAttribute('data-filter');
                    document.querySelectorAll('#msg-tabs .msg-tab').forEach(function (b) {
                        const active = b === btn;
                        b.classList.toggle('bg-sky-600', active);
                        b.classList.toggle('text-white', active);
                        b.classList.toggle('text-slate-500', !active);
                        b.classList.toggle('hover:bg-slate-100', !active);
                    });
                    render();
                });
            });

            msgModal.addEventListener('click', function (e) { if (e.target === msgModal) closeMsgModal(); });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && msgModal.classList.contains('opacity-100')) closeMsgModal();
            });

            // 即時推送（SSE）+ 輪詢後援
            var lastLatest = null;
            var toastWrap = document.getElementById('msg-toast-wrap');

            function removeToast(t) {
                t.classList.remove('is-in');
                setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 350);
            }
            function showToast(title, sub) {
                if (!toastWrap) return;
                var t = document.createElement('div');
                t.className = 'msg-toast';
                t.innerHTML = '<span class="msg-toast__ico"><i class="fa-solid fa-bell"></i></span>' +
                    '<span style="min-width:0;flex:1;"><span class="msg-toast__t">' + esc(title) + '</span>' +
                    '<span class="msg-toast__s">' + esc(sub) + '</span></span>' +
                    '<span class="msg-toast__x"><i class="fa-solid fa-xmark"></i></span>';
                t.addEventListener('click', function () { openMsgModal(); removeToast(t); });
                toastWrap.appendChild(t);
                requestAnimationFrame(function () { t.classList.add('is-in'); });
                setTimeout(function () { removeToast(t); }, 6000);
            }

            function onUnread(d) {
                if (!d || !d.ok) return;
                setBadge(d.unread);
                if (typeof d.latest !== 'undefined') {
                    if (lastLatest !== null && Number(d.latest) > Number(lastLatest)) {
                        showToast('您有新訊息', '點擊查看訊息通知中心');
                        if (msgModal.classList.contains('opacity-100')) { loadList(); }
                    }
                    lastLatest = Number(d.latest);
                }
            }

            function pollUnread() {
                fetch(MSG_API + '?action=unread', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { onUnread(d); })
                    .catch(function () {});
            }

            var fallbackTimer = null;
            function startFallback() { if (!fallbackTimer) { fallbackTimer = setInterval(function () { if (!document.hidden) pollUnread(); }, 15000); } }
            function stopFallback() { if (fallbackTimer) { clearInterval(fallbackTimer); fallbackTimer = null; } }

            function connectStream() {
                if (!window.EventSource) { startFallback(); return; }
                var es = new EventSource('message_stream.php');
                es.addEventListener('update', function (e) {
                    var d; try { d = JSON.parse(e.data); } catch (err) { return; }
                    onUnread({ ok: true, unread: d.unread, latest: d.latest });
                });
                es.onopen = function () { stopFallback(); };
                es.onerror = function () { startFallback(); };
            }

            pollUnread();
            connectStream();
            document.addEventListener('visibilitychange', function () { if (!document.hidden) pollUnread(); });
        })();
    </script>
</body>
</html>

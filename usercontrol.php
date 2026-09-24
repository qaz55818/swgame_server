<?php
require_once __DIR__ . '/session_bootstrap.php';
app_session_start();
include_once "config.php";
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/web_auth.php';

// users 資料表的密碼欄位（來自你的資料庫結構 sa.sql）。
$PasswordColumn = "passwd";

if (!isset($_SESSION['username']) || $_SESSION['username'] === '') {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$userId = $_SESSION['user_id'];

if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$formToken = $_SESSION['form_token'];

$errorMessage = "";
$successMessage = "";

$Link = null;
try {
    $Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName, $port ?? 3306);
} catch (Throwable $e) {
    $Link = null;
}
if (!$Link) {
    http_response_code(500);
    exit('系統暫時無法使用，請稍後再試。');
}
// 必須與寫入密碼時所使用的字元集一致（請參見 index.php）
mysqli_set_charset($Link, "latin1");

// 會員功能權限：修改密碼權限
member_permission_require($Link, (int)$userId, 'can_change_password', ['back' => 'home.php', 'countdown' => 5]);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
        $errorMessage = "你的工作階段已過期，請再試一次。";
    } else {
        unset($_SESSION['form_token']);

        $oldPass = trim($_POST['old_passwd']);
        $newPass = trim($_POST['new_passwd']);
        $newRepass = trim($_POST['new_repasswd']);

        $stmt = $Link->prepare("SELECT `$PasswordColumn` FROM `users` WHERE `ID` = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $auth = $row ? web_authenticate($Link, (int)$userId, $username, $oldPass, $row[$PasswordColumn]) : ['ok' => false];

        if (!is_array($row) || empty($auth['ok'])) {
            $errorMessage = "目前的密碼不正確。";
        } elseif ($newPass !== $newRepass) {
            $errorMessage = "兩次輸入的新密碼不一致。";
        } elseif (strlen($newPass) < 4 || strlen($newPass) > 10) {
            $errorMessage = "新密碼長度必須為 4-10 個字元。";
        } else {
            $newHash = md5($username . $newPass, true);
            $update = $Link->prepare("UPDATE `users` SET `$PasswordColumn` = ? WHERE `ID` = ?");
            $update->bind_param("si", $newHash, $userId);
            if ($update->execute()) {
                // 同步更新網站端 bcrypt 憑證（遊戲端 MD5 已保留）
                web_password_sync($Link, (int)$userId, $newPass, $newHash);
                $successMessage = "密碼更新成功。";
            } else {
                $errorMessage = "無法更新密碼，請稍後再試。";
            }
            $update->close();
        }

        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        $formToken = $_SESSION['form_token'];
    }
}

// 取得帳號資訊以供顯示
$infoStmt = $Link->prepare("SELECT `name`, `email`, `mobilenumber`, `creatime` FROM `users` WHERE `ID` = ?");
$infoStmt->bind_param("i", $userId);
$infoStmt->execute();
$accountInfo = $infoStmt->get_result()->fetch_assoc();
$infoStmt->close();
mysqli_close($Link);

// 顯示值（無法取得時採常規描述）
$displayName = (is_array($accountInfo) && !empty($accountInfo['name'])) ? $accountInfo['name'] : $username;
$displayEmail = (is_array($accountInfo) && !empty($accountInfo['email'])) ? $accountInfo['email'] : '尚未綁定電子信箱';
$displayMobile = (is_array($accountInfo) && !empty($accountInfo['mobilenumber'])) ? $accountInfo['mobilenumber'] : '尚未綁定手機';
$displayRegDate = (is_array($accountInfo) && !empty($accountInfo['creatime']) && $accountInfo['creatime'] !== '0000-00-00 00:00:00')
    ? date('Y-m-d', strtotime($accountInfo['creatime'])) : '未提供';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>踏雪笑傲 · 帳號安全與密碼修改</title>
<link rel="stylesheet" href="assets/tailwind.css">
<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Noto+Serif+TC:wght@400;500;600;700;900&family=Noto+Sans+TC:wght@300;400;500;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root {
        --vermilion: #be123c;
        --ice-blue: #0284c7;
        --ink-deep: #080c14;
    }
    body {
        background-color: var(--ink-deep);
        color: #1e293b;
        font-family: 'Noto Sans TC', sans-serif;
        min-height: 100vh;
        overflow-x: hidden;
        background-image:
            radial-gradient(ellipse at 50% 0%, rgba(30, 41, 59, 0.7) 0%, rgba(8, 12, 20, 0.98) 75%),
            radial-gradient(circle at 85% 15%, rgba(2, 132, 199, 0.08) 0%, transparent 45%),
            radial-gradient(circle at 15% 80%, rgba(190, 18, 60, 0.06) 0%, transparent 50%);
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
        border: 1px solid rgba(226, 232, 240, 0.9);
        box-shadow: 0 20px 45px -12px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(226, 232, 240, 0.6);
        position: relative;
    }
    .ornament-tl {
        position: absolute;
        top: -1px;
        left: -1px;
        width: 16px;
        height: 16px;
        border-top: 3px solid var(--vermilion);
        border-left: 3px solid var(--vermilion);
        border-top-left-radius: 6px;
        pointer-events: none;
    }
    .ornament-br {
        position: absolute;
        bottom: -1px;
        right: -1px;
        width: 16px;
        height: 16px;
        border-bottom: 3px solid var(--ice-blue);
        border-right: 3px solid var(--ice-blue);
        border-bottom-right-radius: 6px;
        pointer-events: none;
    }
    .seal-tag {
        background-color: #fff1f2;
        color: #be123c;
        border: 1px solid #fecdd3;
        font-weight: 600;
    }
    .input-box {
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .input-box:focus-within {
        border-color: var(--ice-blue);
        box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
    }
    .input-plain {
        border: 0;
        background: transparent;
        outline: none;
        box-shadow: none;
    }
    .input-plain:focus {
        outline: none;
        box-shadow: none;
    }
    .pwd-strength-bar {
        transition: width 0.3s ease, background-color 0.3s ease;
    }
</style>
</head>
<body class="relative flex flex-col justify-between min-h-screen selection:bg-rose-900 selection:text-white">
<canvas id="snow-canvas"></canvas>

<!-- 頂部氣氛光效 -->
<div class="fixed top-0 left-1/2 -translate-x-1/2 w-[850px] h-[300px] bg-sky-500/10 rounded-full blur-[140px] pointer-events-none -z-0"></div>

<!-- 導覽列（與全站踏雪笑傲規範一致） -->
<header class="relative z-20 border-b border-slate-800/80 bg-[#090d16]/90 backdrop-blur-md sticky top-0 shadow-lg">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-20">
            <a class="group flex items-center space-x-3.5 no-underline" href="home.php">
                <div class="w-11 h-11 rounded-xl bg-white p-1 shadow-md border border-slate-200 flex items-center justify-center overflow-hidden transition-transform group-hover:scale-105">
                    <img alt="踏雪笑傲 Logo" class="w-full h-full object-contain" src="assets/logo.svg">
                </div>
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="text-2xl font-bold tracking-wider text-slate-100 font-serif">踏雪笑傲</span>
                        <span class="seal-tag text-[11px] px-1.5 py-0.5 rounded tracking-normal">安全中心</span>
                    </div>
                    <p class="text-[11px] text-slate-400 font-mono tracking-widest uppercase">Snow Wanderer Online</p>
                </div>
            </a>
            <nav class="hidden md:flex items-center space-x-2">
                <a class="px-3.5 py-2 text-sm text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg transition-colors flex items-center space-x-2" href="home.php">
                    <i class="fa-solid fa-house-chimney text-xs text-slate-400"></i><span>帳號總覽</span>
                </a>
                <a class="px-3.5 py-2 text-sm text-sky-300 font-semibold bg-sky-950/40 border border-sky-800/60 rounded-lg flex items-center space-x-2" href="usercontrol.php">
                    <i class="fa-solid fa-shield-halved text-xs text-sky-400"></i><span>帳號安全</span>
                </a>
                <a class="px-3.5 py-2 text-sm text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg transition-colors flex items-center space-x-2" href="recharge.php">
                    <i class="fa-solid fa-coins text-xs text-amber-400"></i><span>儲值元寶</span>
                </a>
                <div class="pl-4 ml-2 border-l border-slate-800 flex items-center space-x-3">
                    <div class="flex items-center space-x-2.5 bg-slate-900/90 border border-slate-700/70 px-3.5 py-1.5 rounded-full">
                        <div class="w-6 h-6 rounded-full bg-gradient-to-tr from-rose-700 to-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-sm">俠</div>
                        <div class="text-left">
                            <p class="text-xs font-semibold text-slate-200 leading-tight max-w-[120px] truncate"><?= htmlspecialchars($username) ?></p>
                            <span class="text-[10px] text-emerald-400 flex items-center gap-1 font-mono"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>已登入</span>
                        </div>
                    </div>
                    <button class="px-3 py-1.5 rounded-lg border border-rose-800/50 bg-rose-950/30 text-rose-300 hover:bg-rose-900/50 hover:text-white text-xs font-medium transition-colors flex items-center gap-1.5" onclick="openLogoutModal()" title="登出系統">
                        <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i><span>登出</span>
                    </button>
                </div>
            </nav>
            <div class="flex md:hidden items-center">
                <button class="p-2 text-slate-300 hover:text-white rounded-lg focus:outline-none bg-slate-800/60" id="mobile-toggle"><i class="fa-solid fa-bars text-lg"></i></button>
            </div>
        </div>
    </div>
    <!-- 行動端選單 -->
    <div class="hidden md:hidden border-t border-slate-800 bg-[#090d16] px-5 py-4 space-y-2" id="mobile-menu">
        <div class="pb-3 border-b border-slate-800 flex items-center justify-between">
            <span class="text-xs text-slate-400">當前登入帳號：</span>
            <span class="text-xs text-sky-400 font-bold font-mono"><?= htmlspecialchars($username) ?></span>
        </div>
        <a class="block px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800/60" href="home.php"><i class="fa-solid fa-house-chimney mr-2 text-slate-400"></i> 帳號總覽</a>
        <a class="block px-3 py-2 rounded-lg text-sm text-sky-300 bg-sky-950/40 font-medium" href="usercontrol.php"><i class="fa-solid fa-shield-halved mr-2 text-sky-400"></i> 帳號安全</a>
        <a class="block px-3 py-2 rounded-lg text-sm text-amber-300 hover:bg-slate-800/60" href="recharge.php"><i class="fa-solid fa-coins mr-2 text-amber-400"></i> 儲值元寶</a>
        <button class="w-full text-left px-3 py-2 rounded-lg text-sm text-rose-400 hover:bg-rose-950/40" onclick="openLogoutModal()"><i class="fa-solid fa-arrow-right-from-bracket mr-2"></i> 安全登出</button>
    </div>
</header>

<!-- 主內容區 -->
<main class="relative z-10 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10 flex-1 w-full space-y-6">

    <!-- 頂部頁面導覽與標題 -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-2 border-b border-slate-800/80">
        <div>
            <div class="flex items-center gap-2 text-xs text-slate-400 mb-1 font-mono">
                <a href="home.php" class="hover:text-slate-200 transition-colors">會員中心</a>
                <span>/</span>
                <span class="text-sky-400">帳號安全設定</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-100 font-serif tracking-wide flex items-center gap-2.5">
                <span class="w-2 h-6 bg-gradient-to-b from-rose-700 to-sky-600 rounded-full inline-block"></span>
                帳號安全與密碼修改
            </h1>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-950/40 border border-emerald-500/30 text-emerald-400 text-xs font-medium font-mono">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                安全防護：256-bit 加密連線
            </span>
        </div>
    </div>

    <!-- 動態狀態提示 (成功/失敗) -->
    <div id="alert-container" class="space-y-3">
        <?php if ($errorMessage !== ""): ?>
            <div class="p-3.5 rounded-xl border border-rose-300 bg-rose-50 text-rose-700 text-xs flex items-center gap-2.5">
                <i class="fa-solid fa-circle-exclamation text-sm"></i>
                <span><?= htmlspecialchars($errorMessage) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($successMessage !== ""): ?>
            <div class="p-3.5 rounded-xl border border-emerald-300 bg-emerald-50 text-emerald-700 text-xs flex items-center gap-2.5">
                <i class="fa-solid fa-circle-check text-sm"></i>
                <span><?= htmlspecialchars($successMessage) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- 兩欄式佈局：左側帳號基本資訊與安全防護指標，右側變更密碼表單 -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

        <!-- 左側：帳號資料卡片 & 安全檢核 (佔 5 欄) -->
        <div class="lg:col-span-5 space-y-6">

            <!-- 帳號資訊展示卡 -->
            <div class="snow-card rounded-2xl p-6">
                <div class="ornament-tl"></div><div class="ornament-br"></div>

                <div class="flex items-center gap-4 pb-4 mb-5 border-b border-slate-200/80">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-slate-100 to-slate-200 border-2 border-white shadow-md flex items-center justify-center text-slate-500 text-2xl">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="text-lg font-bold text-slate-800 font-serif"><?= htmlspecialchars($displayName) ?></h2>
                            <span class="seal-tag text-[10px] px-1.5 py-0.5 rounded font-mono">UID: <?= htmlspecialchars($userId) ?></span>
                        </div>
                        <p class="text-xs text-slate-500 font-mono mt-0.5">通行帳號：<?= htmlspecialchars($username) ?></p>
                    </div>
                </div>

                <!-- 資訊列列表 -->
                <div class="space-y-3 text-xs">
                    <div class="flex items-center justify-between p-3 rounded-xl bg-slate-50/80 border border-slate-200/70">
                        <span class="text-slate-500 flex items-center gap-2">
                            <i class="fa-regular fa-id-badge text-slate-400 w-4 text-center"></i>帳號身分
                        </span>
                        <span class="font-semibold text-slate-800"><?= htmlspecialchars($displayName) ?></span>
                    </div>

                    <div class="flex items-center justify-between p-3 rounded-xl bg-slate-50/80 border border-slate-200/70">
                        <span class="text-slate-500 flex items-center gap-2">
                            <i class="fa-regular fa-envelope text-slate-400 w-4 text-center"></i>電子信箱
                        </span>
                        <span class="font-mono text-slate-800 font-medium"><?= htmlspecialchars($displayEmail) ?></span>
                    </div>

                    <div class="flex items-center justify-between p-3 rounded-xl bg-slate-50/80 border border-slate-200/70">
                        <span class="text-slate-500 flex items-center gap-2">
                            <i class="fa-solid fa-mobile-screen text-slate-400 w-4 text-center"></i>綁定手機
                        </span>
                        <span class="font-mono text-slate-800 font-medium"><?= htmlspecialchars($displayMobile) ?></span>
                    </div>

                    <div class="flex items-center justify-between p-3 rounded-xl bg-slate-50/80 border border-slate-200/70">
                        <span class="text-slate-500 flex items-center gap-2">
                            <i class="fa-regular fa-calendar-check text-slate-400 w-4 text-center"></i>註冊時間
                        </span>
                        <span class="font-mono text-slate-800 font-medium"><?= htmlspecialchars($displayRegDate) ?></span>
                    </div>
                </div>

                <!-- 安全提醒事項 -->
                <div class="mt-5 pt-4 border-t border-slate-200/80 text-[11px] text-slate-500 leading-relaxed space-y-1.5">
                    <div class="flex items-start gap-2">
                        <i class="fa-solid fa-circle-info text-sky-600 mt-0.5 shrink-0"></i>
                        <span>密碼修改成功後，系統將強制更新該帳號工作階段。</span>
                    </div>
                    <div class="flex items-start gap-2">
                        <i class="fa-solid fa-triangle-exclamation text-amber-600 mt-0.5 shrink-0"></i>
                        <span>官方營運團隊人員絕不會主動索取您的遊戲密碼。</span>
                    </div>
                </div>
            </div>

            <!-- 快捷返回導向 -->
            <a href="home.php" class="snow-card rounded-xl p-4 flex items-center justify-between group hover:border-sky-300 transition-all no-underline block">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-sky-50 border border-sky-200 flex items-center justify-center text-sky-600">
                        <i class="fa-solid fa-arrow-left text-sm"></i>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-800">返回會員中心總覽</div>
                        <div class="text-[10px] text-slate-500">查閱元寶餘額、帳號信譽與儲值功能</div>
                    </div>
                </div>
                <i class="fa-solid fa-angle-right text-slate-400 text-xs group-hover:translate-x-0.5 transition-transform"></i>
            </a>

        </div>

        <!-- 右側：密碼修改表單主卡片 (佔 7 欄) -->
        <div class="lg:col-span-7">
            <div class="snow-card rounded-2xl p-6 sm:p-8">
                <div class="ornament-tl"></div><div class="ornament-br"></div>

                <div class="flex items-center justify-between pb-4 mb-6 border-b border-slate-200">
                    <div>
                        <h2 class="text-base font-bold text-slate-800 font-serif flex items-center gap-2">
                            <span class="w-7 h-7 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs flex items-center justify-center">
                                <i class="fa-solid fa-key"></i>
                            </span>
                            變更登入密碼
                        </h2>
                        <p class="text-xs text-slate-500 mt-1">請定期更換密碼以維護俠客帳號與遊戲資產安全</p>
                    </div>
                    <span class="text-[11px] font-mono text-slate-400 bg-slate-100 px-2.5 py-1 rounded-md border border-slate-200">長度限制 4-10 字元</span>
                </div>

                <!-- 表單主體 -->
                <form method="POST" action="" id="password-form" class="space-y-5">
                    <!-- CSRF Token 隱藏欄位 -->
                    <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken) ?>">

                    <!-- 欄位一：目前密碼 -->
                    <div>
                        <label for="old_passwd" class="block text-xs font-semibold text-slate-700 mb-1.5">
                            目前密碼 <span class="text-rose-600">*</span>
                        </label>
                        <div class="relative input-box rounded-xl border border-slate-300 bg-white">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 text-sm">
                                <i class="fa-solid fa-lock"></i>
                            </div>
                            <input type="password" id="old_passwd" name="old_passwd" required
                                placeholder="請輸入當前使用的密碼"
                                class="input-plain w-full pl-10 pr-10 py-2.5 text-sm text-slate-800 placeholder-slate-400">
                            <button type="button" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 text-sm" onclick="togglePassword('old_passwd', this)" tabindex="-1">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- 欄位二：新密碼 -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label for="new_passwd" class="block text-xs font-semibold text-slate-700">
                                新密碼 <span class="text-rose-600">*</span>
                            </label>
                            <span id="strength-label" class="text-[11px] text-slate-400 font-mono">密碼強度：未輸入</span>
                        </div>
                        <div class="relative input-box rounded-xl border border-slate-300 bg-white">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 text-sm">
                                <i class="fa-solid fa-shield-keyhole"></i>
                            </div>
                            <input type="password" id="new_passwd" name="new_passwd" required minlength="4" maxlength="10"
                                placeholder="請輸入 4-10 位新密碼"
                                class="input-plain w-full pl-10 pr-10 py-2.5 text-sm text-slate-800 placeholder-slate-400">
                            <button type="button" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 text-sm" onclick="togglePassword('new_passwd', this)" tabindex="-1">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>

                        <!-- 密碼強度指示器與規範提示 -->
                        <div class="mt-2 space-y-1.5">
                            <div class="h-1.5 w-full bg-slate-100 rounded-full overflow-hidden border border-slate-200">
                                <div id="strength-bar" class="pwd-strength-bar h-full bg-rose-500 rounded-full" style="width:0%;"></div>
                            </div>
                            <div class="flex items-center justify-between text-[10px] text-slate-400">
                                <span>需包含 4 至 10 個英數或符號字元</span>
                                <span id="char-count" class="font-mono">0 / 10</span>
                            </div>
                        </div>
                    </div>

                    <!-- 欄位三：確認新密碼 -->
                    <div>
                        <label for="new_repasswd" class="block text-xs font-semibold text-slate-700 mb-1.5">
                            確認新密碼 <span class="text-rose-600">*</span>
                        </label>
                        <div class="relative input-box rounded-xl border border-slate-300 bg-white">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 text-sm">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                            <input type="password" id="new_repasswd" name="new_repasswd" required minlength="4" maxlength="10"
                                placeholder="請再次輸入相同的新密碼"
                                class="input-plain w-full pl-10 pr-10 py-2.5 text-sm text-slate-800 placeholder-slate-400">
                            <button type="button" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 text-sm" onclick="togglePassword('new_repasswd', this)" tabindex="-1">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                        <!-- 即時比對錯誤提示容器 -->
                        <p id="match-error" class="hidden text-[11px] text-rose-600 mt-1.5 flex items-center gap-1">
                            <i class="fa-solid fa-circle-xmark"></i> 兩次輸入的新密碼不一致
                        </p>
                    </div>

                    <!-- 提交按鈕與安全規範 -->
                    <div class="pt-4 border-t border-slate-200 space-y-4">
                        <button type="submit" id="submit-btn" class="w-full py-3 px-6 rounded-xl bg-gradient-to-r from-rose-700 to-rose-800 hover:from-rose-800 hover:to-rose-900 hover:shadow-md hover:-translate-y-0.5 text-white font-bold text-sm shadow-md transition-all flex items-center justify-center gap-2 group">
                            <i class="fa-solid fa-lock group-hover:scale-110 transition-transform"></i>
                            <span>確認變更密碼</span>
                        </button>

                        <div class="flex items-center justify-between text-xs text-slate-500 px-1">
                            <span class="flex items-center gap-1">
                                <i class="fa-solid fa-shield-halved text-emerald-600 text-xs"></i>
                                安全雜湊加密驗證
                            </span>
                            <a href="home.php" class="text-slate-500 hover:text-slate-800 underline transition-colors">取消並返回</a>
                        </div>
                    </div>
                </form>

            </div>
        </div>

    </div>

</main>

<!-- 頁腳 -->
<footer class="relative z-10 border-t border-slate-800 bg-[#070a12] py-8 text-center text-xs text-slate-400 mt-auto">
    <div class="max-w-7xl mx-auto px-4 space-y-2.5">
        <div class="flex items-center justify-center space-x-2 text-slate-300 font-serif font-semibold text-sm">
            <span>踏雪笑傲 · 繁體中文官方正版</span>
        </div>
        <p class="text-slate-400">
            <a class="hover:text-slate-200 mx-2" href="home.php">帳號總覽</a> ·
            <a class="text-sky-400 font-medium mx-2" href="usercontrol.php">帳號安全</a> ·
            <a class="hover:text-slate-200 mx-2" href="recharge.php">儲值元寶</a> ·
            <a class="hover:text-slate-200 mx-2" href="download.php">遊戲下載</a>
        </p>
        <p class="text-[11px] text-slate-500 font-mono">© 2025 踏雪笑傲營運團隊 All Rights Reserved.</p>
    </div>
</footer>

<!-- 登出確認對話框 -->
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-300 px-4" id="logout-modal">
    <div class="snow-card max-w-sm w-full rounded-2xl p-6 text-center transform scale-95 transition-transform duration-300" id="logout-card">
        <div class="ornament-tl"></div><div class="ornament-br"></div>
        <div class="w-14 h-14 mx-auto rounded-full bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-700 text-2xl mb-4">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-800 font-serif mb-2">確認登出帳號？</h3>
        <p class="text-xs text-slate-500 leading-relaxed mb-6">您即將結束本次登入工作階段。如需繼續管理帳號或遊戲角色，請再次登入。</p>
        <div class="flex items-center justify-center space-x-3">
            <button class="w-1/2 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold transition-all" onclick="closeLogoutModal()">取消並返回</button>
            <a class="w-1/2 py-2.5 rounded-xl bg-gradient-to-r from-rose-700 to-rose-800 hover:from-rose-800 hover:to-rose-900 text-white text-xs font-semibold transition-all text-center no-underline" href="logout.php">確認登出</a>
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

    // 密碼顯示/隱藏切換
    function togglePassword(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    // 密碼長度與強度即時檢驗
    const newPassInput = document.getElementById('new_passwd');
    const repassInput = document.getElementById('new_repasswd');
    const strengthBar = document.getElementById('strength-bar');
    const strengthLabel = document.getElementById('strength-label');
    const charCount = document.getElementById('char-count');
    const matchError = document.getElementById('match-error');

    if (newPassInput) {
        newPassInput.addEventListener('input', function() {
            const val = this.value;
            charCount.textContent = `${val.length} / 10`;

            if (val.length === 0) {
                strengthBar.style.width = '0%';
                strengthLabel.textContent = '密碼強度：未輸入';
                strengthLabel.className = 'text-[11px] text-slate-400 font-mono';
                return;
            }

            let score = 0;
            if (val.length >= 4) score += 1;
            if (val.length >= 7) score += 1;
            if (/[0-9]/.test(val) && /[a-zA-Z]/.test(val)) score += 1;
            if (/[^a-zA-Z0-9]/.test(val)) score += 1;

            if (score <= 1) {
                strengthBar.style.width = '30%';
                strengthBar.className = 'pwd-strength-bar h-full bg-rose-500 rounded-full';
                strengthLabel.textContent = '密碼強度：偏弱';
                strengthLabel.className = 'text-[11px] text-rose-600 font-medium font-mono';
            } else if (score === 2) {
                strengthBar.style.width = '65%';
                strengthBar.className = 'pwd-strength-bar h-full bg-amber-500 rounded-full';
                strengthLabel.textContent = '密碼強度：中等';
                strengthLabel.className = 'text-[11px] text-amber-600 font-medium font-mono';
            } else {
                strengthBar.style.width = '100%';
                strengthBar.className = 'pwd-strength-bar h-full bg-emerald-500 rounded-full';
                strengthLabel.textContent = '密碼強度：強健';
                strengthLabel.className = 'text-[11px] text-emerald-600 font-semibold font-mono';
            }

            checkMatch();
        });
    }

    if (repassInput) {
        repassInput.addEventListener('input', checkMatch);
    }

    function checkMatch() {
        if (!repassInput || !newPassInput) return;
        if (repassInput.value.length > 0 && repassInput.value !== newPassInput.value) {
            matchError.classList.remove('hidden');
        } else {
            matchError.classList.add('hidden');
        }
    }

    // 登出 Modal
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

    // 飄雪粒子特效 (無閃爍分層渲染)
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

                if (p.y > height) { p.y = -10; p.x = Math.random() * width; }
                if (p.x > width) p.x = 0;
                if (p.x < 0) p.x = width;

                const o = Math.max(0.15, Math.min(0.8, p.opacity + Math.sin(p.pulsePhase) * 0.15));
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(240, 249, 255, ${o})`;
                ctx.shadowBlur = 4;
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

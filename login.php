<?php
require_once __DIR__ . '/session_bootstrap.php';
app_session_start(['remember' => true]);
include_once "config.php";
require_once __DIR__ . '/login_security.php';
require_once __DIR__ . '/web_auth.php';
require_once __DIR__ . '/remember_me.php';

$PasswordColumn = "passwd";

$flash = $_SESSION['flash'] ?? null;
if ($flash !== null) {
    unset($_SESSION['flash']);
}

if (isset($_SESSION['username']) && $_SESSION['username'] !== '' && $flash === null) {
    header("Location: home.php");
    exit();
}

function flashAndRedirect($type, $title, $message) {
    $_SESSION['flash'] = ['type' => $type, 'title' => $title, 'message' => $message];
    header("Location: login.php");
    exit();
}

if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$formToken = $_SESSION['form_token'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
        flashAndRedirect('danger', '表單已失效', '你的工作階段已過期，請重新登入。');
    }

    unset($_SESSION['form_token']);

    $Login = trim((string)($_POST['login'] ?? ''));
    $Pass  = trim((string)($_POST['passwd'] ?? ''));

    $Link = null;
    try {
        $Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName, $port ?? 3306);
    } catch (Throwable $e) {
        $Link = null;
    }
    if (!$Link) {
        flashAndRedirect('danger', '系統連線異常', '資料庫連線失敗，請稍後再試。');
    }
    // 登入紀錄與統計以 UTF-8 處理
    mysqli_set_charset($Link, "utf8");

    // ---- 安全防護：同一 IP / 帳號於時間視窗內的失敗次數 ----
    $clientIp = login_client_ip($Link);
    $limit = login_check_rate_limit($Link, $Login, $clientIp);
    if ($limit['blocked']) {
        login_record($Link, $Login, 'blocked', ($limit['scope'] === 'ip' ? 'IP 嘗試次數過多' : '帳號嘗試次數過多'), 0);
        mysqli_close($Link);
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        flashAndRedirect('danger', '登入暫時鎖定', '嘗試次數過多，基於安全考量帳號已暫時鎖定，請於 ' . LOGIN_WINDOW_MINUTES . ' 分鐘後再試。');
    }

    // ---- 帳密驗證（users.passwd 為 latin1 原始位元組）----
    mysqli_set_charset($Link, "latin1");
    $stmt = $Link->prepare("SELECT `ID`, `name`, `$PasswordColumn` FROM `users` WHERE `name` = ?");
    $stmt->bind_param("s", $Login);
    $stmt->execute();
    $result = $stmt->get_result();

    $authOk = false;
    $row = null;
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        // 網站端優先以 bcrypt 驗證（web_credentials），並在偵測到舊格式或
        // 離線變更時自動以遊戲端 MD5 驗證並升級，確保遊戲端登入不受影響。
        $auth = web_authenticate($Link, (int)$row['ID'], $row['name'], $Pass, $row[$PasswordColumn]);
        if (!empty($auth['ok'])) {
            $authOk = true;
        }
    }
    $stmt->close();

    if ($authOk && $row) {
        mysqli_set_charset($Link, "utf8");
        login_record($Link, $row['name'], 'success', '登入成功', (int)$row['ID']);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $row['ID'];
        $_SESSION['username'] = $row['name'];
        $_SESSION['is_gm'] = 0;
        $_SESSION['login_time'] = time();
        $_SESSION['login_ip'] = $clientIp;

        // 勾選「記住帳號」時，發放長效權杖以維持登入
        if (!empty($_POST['remember'])) {
            remember_me_issue($Link, (int)$row['ID']);
        } else {
            remember_me_clear($Link);
        }

        mysqli_close($Link);

        header("Location: home.php");
        exit();
    }

    // ---- 登入失敗：紀錄並延遲回應以抑止暴力嘗試 ----
    mysqli_set_charset($Link, "utf8");
    login_record($Link, $Login, 'failed', '帳號或密碼不正確', 0);
    mysqli_close($Link);
    usleep(400000);

    $_SESSION['form_token'] = bin2hex(random_bytes(32));
    flashAndRedirect('danger', '登入失敗', '帳號或密碼不正確，請重新輸入。');
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>踏雪笑傲 · 登入</title>
    <link rel="stylesheet" href="assets/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+TC:wght@400;600;700;900&family=Cinzel:wght@400;600&display=swap" rel="stylesheet">
    <!-- 樣式由 assets/tailwind.css 提供（編譯後靜態檔，不再使用 Tailwind CDN） -->
    <style>
        html {
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }
        body {
            font-family: 'Noto Serif TC', serif;
            background-color: #070b14;
            background-image:
                radial-gradient(circle at 50% 12%, rgba(26, 41, 66, 0.7) 0%, transparent 60%),
                radial-gradient(circle at 80% 80%, rgba(14, 23, 42, 0.8) 0%, transparent 70%),
                #070b14;
            min-height: 100vh;
            min-height: 100dvh;
            overflow-x: hidden;
            /* 關閉下拉重新整理 / 橡皮筋回彈，避免頁面被拖動位移。 */
            overscroll-behavior: none;
        }
        #snow-canvas {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 1;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(28px);
            -webkit-backdrop-filter: blur(28px);
            box-shadow:
                0 30px 60px -15px rgba(0, 0, 0, 0.5),
                0 0 0 1px rgba(255, 255, 255, 0.8) inset,
                0 0 40px rgba(56, 189, 248, 0.08);
            position: relative;
            z-index: 10;
            contain: layout style;
        }
        .zen-input {
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            background: #fbfcfe;
            border: 1px solid #e2e8f0;
        }
        .zen-input:focus {
            background: #ffffff;
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18);
            outline: none;
        }
        .btn-crimson {
            background: linear-gradient(135deg, #c71f45 0%, #be123c 50%, #9f1239 100%);
            box-shadow: 0 6px 20px -2px rgba(190, 18, 60, 0.35);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-crimson:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 25px -2px rgba(190, 18, 60, 0.48);
            background: linear-gradient(135deg, #d32750 0%, #c71f45 50%, #881337 100%);
        }
        .brand-glow {
            position: absolute;
            width: 260px;
            height: 260px;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.15) 0%, rgba(190, 18, 60, 0.05) 50%, transparent 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            filter: blur(45px);
            pointer-events: none;
        }
        .wuxia-frame-base {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.95) 100%);
            box-shadow:
                0 25px 60px -15px rgba(2, 6, 23, 0.35),
                0 0 0 1px rgba(255, 255, 255, 0.9),
                0 0 35px rgba(56, 189, 248, 0.18);
            border-radius: 12px;
            position: relative;
        }
        .corner-ornament {
            position: absolute;
            width: 22px;
            height: 22px;
            pointer-events: none;
            opacity: 0.85;
            z-index: 5;
        }
        .corner-tl { top: -2px; left: -2px; border-top: 3px solid #be123c; border-left: 3px solid #be123c; border-top-left-radius: 6px; }
        .corner-tr { top: -2px; right: -2px; border-top: 3px solid #be123c; border-right: 3px solid #be123c; border-top-right-radius: 6px; }
        .corner-bl { bottom: -2px; left: -2px; border-bottom: 3px solid #0284c7; border-left: 3px solid #0284c7; border-bottom-left-radius: 6px; }
        .corner-br { bottom: -2px; right: -2px; border-bottom: 3px solid #0284c7; border-right: 3px solid #0284c7; border-bottom-right-radius: 6px; }
        .inner-border-line {
            position: absolute;
            inset: 6px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 8px;
            pointer-events: none;
            z-index: 0;
        }
        .ink-header-line {
            background: linear-gradient(90deg, transparent 0%, rgba(15, 23, 42, 0.25) 25%, rgba(190, 18, 60, 0.6) 50%, rgba(15, 23, 42, 0.25) 75%, transparent 100%);
            height: 1px;
            width: 100%;
        }
        .seal-stamp {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #be123c;
            color: #fff;
            border-radius: 3px;
            font-size: 11px;
            padding: 1px 6px;
            letter-spacing: 1px;
            box-shadow: 0 2px 4px rgba(190, 18, 60, 0.25);
            font-family: 'Noto Serif TC', serif;
            white-space: nowrap;
        }
        @keyframes frostPulseSmooth {
            0%, 100% { box-shadow: 0 22px 50px -12px rgba(2, 6, 23, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.92), 0 0 25px rgba(56, 189, 248, 0.16); }
            50% { box-shadow: 0 25px 60px -12px rgba(2, 6, 23, 0.38), 0 0 0 1px rgba(255, 255, 255, 1), 0 0 36px rgba(56, 189, 248, 0.26); }
        }
        .glow-frost { animation: frostPulseSmooth 6s ease-in-out infinite; }

        /* ---- 行動端 / 低效能裝置：關閉高成本效果，確保捲動與操作流暢 ---- */
        @media (max-width: 768px), (prefers-reduced-motion: reduce) {
            /* backdrop-filter 疊在持續重繪的 canvas 上，每幀都要重新模糊整個背板，
               是行動端卡頓的主因，改為接近不透明的純色底。 */
            .glass-card {
                background: rgba(255, 255, 255, 0.985);
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                contain: layout style paint;
            }
            #flash-overlay {
                backdrop-filter: none !important;
                -webkit-backdrop-filter: none !important;
                background: rgba(2, 6, 23, 0.72) !important;
            }
            /* 動畫 box-shadow 會觸發主執行緒重繪，行動端停用脈動光暈。 */
            .glow-frost { animation: none; }
            .brand-glow { filter: blur(28px); }
        }

        /* ---- 行動端版面穩定：防止聚焦縮放與觸控位移 ---- */
        @media (max-width: 768px) {
            /* iOS 聚焦 font-size < 16px 的輸入框時會自動放大整個頁面，
               造成畫面「跑掉」縮放位移；提高至 16px 可完全避免，
               同時保留原設計的 placeholder 大小。 */
            .zen-input { font-size: 16px; }
            .zen-input::placeholder { font-size: 14px; }
            /* 消除 300ms 點擊延遲與雙擊縮放，操作更即時、畫面不亂動。 */
            .zen-input,
            .btn-crimson,
            #toggle-password,
            #remember,
            a { touch-action: manipulation; }
            html { -webkit-tap-highlight-color: transparent; }
        }

        /* 小螢幕縮減留白與主視覺尺寸，讓登入卡盡量一屏可見、減少捲動位移。 */
        @media (max-width: 640px) {
            .hero-panel { padding-top: 1.75rem; padding-bottom: 1.75rem; }
            .hero-logo { width: 6.5rem; height: 6.5rem; margin-bottom: 1rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            #snow-canvas { display: none; }
        }
    </style>
</head>
<body class="flex items-center justify-center p-4 md:p-8 relative text-slate-800 selection:bg-rose-100 selection:text-rose-900">

<?php if ($flash !== null):
    $flashType = $flash['type'] ?? 'info';
    $flashTitle = $flash['title'] ?? '提示';
    $flashMessage = $flash['message'] ?? '';
    $flashStyles = [
        'danger'  => ['wrap' => 'border-rose-200/90',  'badge' => 'bg-rose-50 border-rose-200 text-rose-600',   'btn' => 'bg-rose-700 hover:bg-rose-600'],
        'warning' => ['wrap' => 'border-amber-200/90', 'badge' => 'bg-amber-50 border-amber-200 text-amber-600', 'btn' => 'bg-amber-600 hover:bg-amber-500'],
        'success' => ['wrap' => 'border-sky-200/90',   'badge' => 'bg-sky-50 border-sky-200 text-sky-600',       'btn' => 'bg-gradient-to-r from-sky-700 to-sky-600 hover:from-sky-600 hover:to-sky-500'],
        'info'    => ['wrap' => 'border-sky-200/90',   'badge' => 'bg-sky-50 border-sky-200 text-sky-600',       'btn' => 'bg-gradient-to-r from-sky-700 to-sky-600 hover:from-sky-600 hover:to-sky-500'],
    ];
    $st = $flashStyles[$flashType] ?? $flashStyles['info'];
?>
    <div id="flash-overlay" class="fixed inset-0 z-[60] flex items-center justify-center p-4" style="background: rgba(2, 6, 23, 0.55); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);">
        <div class="wuxia-frame-base glow-frost relative p-6 sm:p-7 w-full max-w-md border <?php echo $st['wrap']; ?>">
            <div class="corner-ornament corner-tl"></div>
            <div class="corner-ornament corner-tr"></div>
            <div class="corner-ornament corner-bl"></div>
            <div class="corner-ornament corner-br"></div>
            <div class="inner-border-line"></div>
            <div class="relative z-10 text-center space-y-3">
                <div class="w-12 h-12 mx-auto rounded-full border flex items-center justify-center shadow-inner <?php echo $st['badge']; ?>">
                    <?php if ($flashType === 'success'): ?>
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                    <?php else: ?>
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                    <?php endif; ?>
                </div>
                <h3 class="text-lg font-bold font-serif text-slate-900"><?php echo htmlspecialchars($flashTitle); ?></h3>
                <p class="text-xs text-slate-600 leading-relaxed px-4"><?php echo htmlspecialchars($flashMessage); ?></p>
                <div class="pt-3 flex items-center justify-center gap-3">
                    <button type="button" onclick="closeFlash()" class="px-6 py-1.5 text-xs font-medium text-white rounded-md shadow transition-colors <?php echo $st['btn']; ?>">知道了</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<canvas id="snow-canvas"></canvas>
<main class="glass-card w-full max-w-4xl rounded-2xl grid grid-cols-1 md:grid-cols-12 overflow-hidden border border-white/70">
    <section class="hero-panel md:col-span-5 bg-gradient-to-b from-slate-50/90 to-slate-100/70 p-10 md:p-14 flex flex-col items-center justify-center border-b md:border-b-0 md:border-r border-slate-200/70 relative overflow-hidden text-center">
        <div class="brand-glow"></div>
        <div class="relative z-10 flex flex-col items-center">
            <div class="hero-logo w-36 h-36 mb-6 flex items-center justify-center">
                <img alt="踏雪笑傲" class="w-full h-full object-contain filter drop-shadow-sm select-none" src="assets/logo.svg"/>
            </div>
            <h1 class="text-3xl font-black text-slate-900 tracking-[0.25em] pl-[0.25em] mb-2 font-serif">踏雪笑傲</h1>
            <p class="text-xs font-cinzel tracking-[0.3em] uppercase text-slate-400 font-medium pl-[0.3em]">Snow Wanderer</p>
        </div>
    </section>
    <section class="md:col-span-7 p-6 md:p-10 bg-white flex flex-col justify-center">
        <div class="wuxia-frame-base glow-frost relative p-6 sm:p-8 w-full max-w-sm mx-auto">
            <div class="corner-ornament corner-tl"></div>
            <div class="corner-ornament corner-tr"></div>
            <div class="corner-ornament corner-bl"></div>
            <div class="corner-ornament corner-br"></div>
            <div class="inner-border-line"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 mb-3">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 bg-rose-600 rotate-45 shadow-sm"></span>
                        <h2 class="text-xl font-bold text-slate-900 tracking-wider">登入</h2>
                    </div>
                    <span class="seal-stamp">雪境通行</span>
                </div>
                <div class="ink-header-line mb-6"></div>
                <form action="" class="space-y-5" id="login-form" method="POST">
                    <input name="form_token" type="hidden" value="<?php echo htmlspecialchars($formToken); ?>">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 tracking-wider mb-2" for="login">帳號</label>
                        <input class="zen-input w-full px-3.5 py-2.5 rounded-lg text-sm text-slate-800 placeholder-slate-400" id="login" name="login" placeholder="請輸入帳號" required type="text">
                    </div>
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <label class="block text-xs font-medium text-slate-700 tracking-wider" for="passwd">密碼</label>
                        </div>
                        <div class="relative">
                            <input class="zen-input w-full pl-3.5 pr-10 py-2.5 rounded-lg text-sm text-slate-800 placeholder-slate-400" id="passwd" name="passwd" placeholder="請輸入密碼" required type="password">
                            <button aria-label="顯示或隱藏密碼" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 transition-colors" id="toggle-password" type="button">
                                <svg id="eye-open" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                <svg id="eye-closed" class="w-4 h-4 hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                            </button>
                        </div>
                    </div>
                    <div class="flex items-center pt-1">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input class="w-4 h-4 text-rose-600 border-slate-300 rounded focus:ring-rose-500" id="remember" name="remember" type="checkbox">
                            <span class="text-xs text-slate-600">記住我 · 保持登入</span>
                        </label>
                    </div>
                    <div class="pt-2">
                        <button class="btn-crimson w-full py-2.5 px-6 rounded-lg text-white font-semibold text-sm tracking-widest" type="submit">登入</button>
                    </div>
                </form>
                <div class="mt-8 text-center text-xs text-slate-500">
                    尚未擁有帳號？
                    <a class="text-rose-700 hover:text-rose-800 font-semibold ml-1" href="index.php">立即註冊</a>
                </div>
            </div>
        </div>
    </section>
</main>
<script>
function closeFlash() {
    var el = document.getElementById('flash-overlay');
    if (el) el.style.display = 'none';
}
document.addEventListener('DOMContentLoaded', function() {
    var flashOverlay = document.getElementById('flash-overlay');
    if (flashOverlay) {
        flashOverlay.addEventListener('click', function(e) {
            if (e.target === flashOverlay) closeFlash();
        });
    }

    const loginInput = document.getElementById('login');
    const rememberBox = document.getElementById('remember');
    const passwordInput = document.getElementById('passwd');
    const togglePasswordBtn = document.getElementById('toggle-password');
    const eyeOpen = document.getElementById('eye-open');
    const eyeClosed = document.getElementById('eye-closed');
    const form = document.getElementById('login-form');
    const isCoarsePointer = window.matchMedia('(pointer: coarse)').matches;

    try {
        const savedLogin = localStorage.getItem('remember_login');
        if (savedLogin) {
            loginInput.value = savedLogin;
            rememberBox.checked = true;
            if (!isCoarsePointer) passwordInput.focus();
        } else if (!isCoarsePointer) {
            loginInput.focus();
        }
    } catch (e) {
        if (!isCoarsePointer) loginInput.focus();
    }

    form.addEventListener('submit', function() {
        try {
            if (rememberBox.checked) {
                localStorage.setItem('remember_login', loginInput.value.trim());
            } else {
                localStorage.removeItem('remember_login');
            }
        } catch (e) {}
    });

    togglePasswordBtn.addEventListener('click', function() {
        const show = passwordInput.type === 'password';
        passwordInput.type = show ? 'text' : 'password';
        eyeOpen.classList.toggle('hidden', show);
        eyeClosed.classList.toggle('hidden', !show);
    });

    const canvas = document.getElementById('snow-canvas');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (canvas && !reduceMotion) {
        const ctx = canvas.getContext('2d', { alpha: true });
        const isSmallScreen = window.innerWidth < 768;
        const dpr = isSmallScreen ? 1 : Math.min(window.devicePixelRatio || 1, 1.5);
        let width = 0;
        let height = 0;

        function resize() {
            width = window.innerWidth;
            height = window.innerHeight;
            canvas.width = Math.floor(width * dpr);
            canvas.height = Math.floor(height * dpr);
            canvas.style.width = width + 'px';
            canvas.style.height = height + 'px';
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        }
        resize();

        let resizeTimer = null;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(resize, 150);
        }, { passive: true });

        const flakeCount = Math.floor(Math.min(width * (isSmallScreen ? 0.018 : 0.03), isSmallScreen ? 18 : 36));
        const flakes = [];
        for (let i = 0; i < flakeCount; i++) {
            flakes.push({
                x: Math.random() * width,
                y: Math.random() * height,
                radius: Math.random() * 1.5 + 0.6,
                drift: Math.cos(Math.random() * 0.8 + 0.3) * 0.5 + 0.45,
                opacity: Math.random() * 0.35 + 0.15,
                step: Math.random() * 10,
                speed: Math.random() * 0.6 + 0.4
            });
        }

        let rafId = null;
        function render() {
            ctx.clearRect(0, 0, width, height);
            for (let i = 0; i < flakes.length; i++) {
                const f = flakes[i];
                f.step += 0.01 * f.speed;
                f.y += f.drift;
                f.x += Math.sin(f.step) * 0.4;
                if (f.y > height) { f.y = -8; f.x = Math.random() * width; }
                if (f.x > width + 8) f.x = -8;
                else if (f.x < -8) f.x = width + 8;
                ctx.beginPath();
                ctx.fillStyle = 'rgba(255,255,255,' + f.opacity + ')';
                ctx.arc(f.x, f.y, f.radius, 0, 6.2832, false);
                ctx.fill();
            }
            rafId = requestAnimationFrame(render);
        }
        function start() {
            if (rafId === null) rafId = requestAnimationFrame(render);
        }
        function stop() {
            if (rafId !== null) { cancelAnimationFrame(rafId); rafId = null; }
        }

        document.addEventListener('visibilitychange', function() {
            document.hidden ? stop() : start();
        });
        if (document.hidden) { stop(); } else { start(); }
    }
});
</script>
</body>
</html>

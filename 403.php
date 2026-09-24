<?php
/**
 * =============================================================
 *  踏雪笑傲 · 403 禁地頁（權限不足 / 禁止存取）
 *  -------------------------------------------------------------
 *  依設計稿建置；適用於全站。伺服器層可以下列方式導向本頁：
 *    Apache : ErrorDocument 403 /sw/403.php
 *    Nginx  : error_page 403 /sw/403.php;
 *  樣式沿用站台共用設計權杖（assets/404-tailwind.css，離線靜態檔）。
 * =============================================================
 */
if (!headers_sent()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<meta content="noindex, nofollow" name="robots">
<meta content="web_standard" name="shell-type">
<title>踏雪笑傲 - 宗門禁地 (403)</title>
<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&amp;family=Bodoni+Moda:ital,opsz,wght@0,6..96,400;0,6..96,600;0,6..96,700;1,400;1,600&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="/sw/assets/403-tailwind.css?v=1">
<style>
/* 基礎重置、隱藏捲軸與進場動畫（非 Tailwind 工具類） */
@layer base{html,body{margin:0;padding:0;}body{overscroll-behavior:none;}}
::-webkit-scrollbar{display:none;}
.forbidden-enter { opacity: 0; transform: translateY(8px); }
.forbidden-enter.is-in { opacity: 1; transform: translateY(0); transition: opacity .6s ease-out, transform .6s ease-out; }
</style>
</head>
<body class="bg-surface font-body-md text-on-surface antialiased min-h-screen flex flex-col justify-between overflow-x-hidden selection:bg-primary-fixed selection:text-on-primary-fixed">

<!-- 頁首導覽 -->
<header class="fixed top-0 left-0 right-0 z-50 bg-surface/85 backdrop-blur-xl shadow-[0_1px_8px_rgba(0,0,0,0.04)]">
<div class="h-20 w-full px-margin-mobile md:px-margin flex items-center justify-between">
<div class="flex items-center gap-space-lg">
<a class="flex items-center gap-space-sm group focus:outline-none" href="/sw/swo/index.php">
<img alt="踏雪笑傲 Logo" class="h-8 w-auto object-contain" src="/sw/swo/assets/images/idx-logo.png">
<div class="flex flex-col leading-none">
<span class="font-headline-sm text-headline-sm text-on-surface tracking-wider font-semibold">踏雪笑傲</span>
<span class="font-label-sm text-label-sm text-on-surface-variant tracking-widest uppercase opacity-75 mt-space-xs">Snow Wanderer</span>
</div>
</a>
<nav class="hidden xl:flex items-center gap-space-md">
<a class="font-label-lg text-label-lg text-on-surface-variant hover:text-on-surface transition-colors px-space-sm py-space-xs" href="/sw/swo/index.php">首頁</a>
<a class="font-label-lg text-label-lg text-on-surface-variant hover:text-on-surface transition-colors px-space-sm py-space-xs" href="/sw/swo/news.php">最新公告</a>
<a class="font-label-lg text-label-lg text-on-surface-variant hover:text-on-surface transition-colors px-space-sm py-space-xs" href="/sw/swo/events.php">江湖活動</a>
<a class="font-label-lg text-label-lg text-on-surface-variant hover:text-on-surface transition-colors px-space-sm py-space-xs" href="/sw/swo/download.php">遊戲下載</a>
<a class="font-label-lg text-label-lg text-on-surface-variant hover:text-on-surface transition-colors px-space-sm py-space-xs" href="/sw/home.php">會員中心</a>
</nav>
</div>
<div class="flex items-center gap-space-md">
<a class="relative group inline-flex items-center justify-center bg-primary-container text-on-primary px-space-lg py-space-sm rounded shadow-[0_4px_16px_rgba(190,18,60,0.2)] hover:bg-primary transition-all duration-300 transform hover:scale-[1.02]" href="/sw/swo/download.php">
<span class="font-label-lg text-label-lg font-semibold tracking-wider flex items-center gap-space-xs"><span class="material-symbols-outlined text-[18px]">play_arrow</span>啟動微端</span>
</a>
<a class="w-8 h-8 rounded-full bg-primary flex items-center justify-center shadow-sm hover:opacity-90 transition-opacity" href="/sw/home.php" title="會員中心">
<span class="material-symbols-outlined text-on-primary text-[18px]">person</span>
</a>
</div>
</div>
</header>

<!-- 主內容 -->
<main class="w-full pt-20 bg-surface min-h-screen flex-1">
<div class="flex flex-col w-full relative overflow-hidden">
<!-- 環境光暈 -->
<div class="absolute -top-40 left-1/4 w-96 h-96 rounded-full bg-secondary-fixed/40 blur-3xl pointer-events-none -z-10"></div>
<div class="absolute top-1/3 -right-20 w-[30rem] h-[30rem] rounded-full bg-primary-fixed/20 blur-3xl pointer-events-none -z-10"></div>

<section class="w-full px-margin-mobile md:px-margin py-space-xl min-h-[calc(100vh_-_10rem)] flex flex-col justify-center forbidden-enter">
<div class="max-w-7xl mx-auto w-full grid grid-cols-1 lg:grid-cols-12 gap-space-xl items-center">

<!-- 視覺插圖面板 -->
<div class="lg:col-span-6 relative flex flex-col items-center">
<div class="relative w-full aspect-[16/10] sm:aspect-[16/9] rounded-xl overflow-hidden shadow-2xl bg-surface-container-low group">
<img alt="踏雪笑傲 雪山禁地石碑與寒鐵封印門" class="w-full h-full object-cover transition-transform duration-700 ease-out group-hover:scale-105" src="/sw/swo/assets/images/idx-hero.jpg">
<div class="absolute inset-0 bg-gradient-to-t from-on-surface/60 via-transparent to-surface-bright/20 pointer-events-none"></div>
<div class="absolute top-3 left-3 w-4 h-4 border-t-2 border-l-2 border-primary pointer-events-none opacity-80"></div>
<div class="absolute top-3 right-3 w-4 h-4 border-t-2 border-r-2 border-primary pointer-events-none opacity-80"></div>
<div class="absolute bottom-3 left-3 w-4 h-4 border-b-2 border-l-2 border-primary pointer-events-none opacity-80"></div>
<div class="absolute bottom-3 right-3 w-4 h-4 border-b-2 border-r-2 border-primary pointer-events-none opacity-80"></div>
<div class="absolute bottom-4 left-4 right-4 flex items-center justify-between px-space-md py-space-xs bg-surface-container-lowest/85 backdrop-blur-md rounded-lg shadow-sm">
<div class="flex items-center gap-space-xs">
<span class="w-2 h-2 rounded-full bg-primary animate-ping"></span>
<span class="font-label-sm text-label-sm text-primary tracking-widest font-semibold uppercase">玄冰鎖閉 · 陣法運轉</span>
</div>
<span class="font-label-sm text-label-sm text-on-surface-variant font-medium">凌霄峰秘閣第三層</span>
</div>
</div>
<div class="w-11/12 h-3 bg-surface-container-high/60 blur-md rounded-full -mt-1 -z-10"></div>
</div>

<!-- 內容面板 -->
<div class="lg:col-span-6 flex flex-col justify-center gap-space-lg">
<div class="flex items-baseline gap-space-md">
<span class="font-display-lg text-display-lg tracking-tight text-primary leading-none select-none font-bold">403</span>
<div class="flex flex-col">
<div class="inline-flex items-center gap-space-xs px-space-sm py-0.5 bg-primary-fixed text-on-primary-fixed rounded font-label-sm text-label-sm font-semibold tracking-wider uppercase">
<span class="material-symbols-outlined text-[14px]">lock</span>
<span>禁地印信</span>
</div>
<span class="font-label-md text-label-md text-on-surface-variant uppercase tracking-widest mt-1">FORBIDDEN ACCESS</span>
</div>
</div>

<div class="space-y-space-xs">
<h1 class="font-headline-lg text-headline-lg text-on-surface font-semibold tracking-wide">宗門禁地 · 止步回步</h1>
<p class="font-body-lg text-body-lg text-on-surface-variant leading-relaxed pt-space-xs">
少俠留步。此處為宗門禁地或無相心法秘閣，尚未向您的俠士身分或權限開放。風雪沉重，前路受阻，請攜長劍原路折返，或向前山掌門執事申請通行玉牒。
</p>
</div>

<div class="flex flex-wrap gap-space-sm py-space-xs">
<div class="inline-flex items-center gap-space-xs px-space-md py-space-xs bg-surface-container-low rounded shadow-sm">
<span class="material-symbols-outlined text-outline text-[16px]">info</span>
<span class="font-label-sm text-label-sm text-on-surface-variant">狀態代碼：<strong class="text-on-surface font-medium">HTTP 403</strong></span>
</div>
<div class="inline-flex items-center gap-space-xs px-space-md py-space-xs bg-surface-container-low rounded shadow-sm">
<span class="material-symbols-outlined text-primary text-[16px]">key_off</span>
<span class="font-label-sm text-label-sm text-on-surface-variant">通行授權：<strong class="text-on-surface font-medium">身分不足 (Insufficient)</strong></span>
</div>
<div class="inline-flex items-center gap-space-xs px-space-md py-space-xs bg-surface-container-low rounded shadow-sm">
<span class="material-symbols-outlined text-secondary text-[16px]">shield</span>
<span class="font-label-sm text-label-sm text-on-surface-variant">結界節點：<strong class="text-on-surface font-medium">華山玉真峰監控中</strong></span>
</div>
</div>

<div class="flex flex-wrap items-center gap-space-md pt-space-sm">
<a class="relative group inline-flex items-center justify-center bg-primary text-on-primary px-space-xl py-space-md rounded shadow-md hover:bg-primary-container transition-all duration-300 transform hover:scale-[1.02]" href="/sw/swo/index.php">
<span class="font-label-lg text-label-lg font-semibold tracking-wider flex items-center gap-space-xs">
<span class="material-symbols-outlined text-[18px] transition-transform group-hover:-translate-x-1">west</span>
重返宗門首頁
</span>
</a>
<button class="inline-flex items-center justify-center bg-surface-container-lowest text-on-surface px-space-lg py-space-md rounded shadow-sm hover:bg-surface-container transition-all duration-300 transform hover:scale-[1.01]" onclick="window.history.length > 1 ? window.history.back() : window.location.href='/sw/swo/index.php';" type="button">
<span class="font-label-lg text-label-lg font-medium tracking-wider flex items-center gap-space-xs">
<span class="material-symbols-outlined text-[18px]">undo</span>
原路折返
</span>
</button>
<a class="inline-flex items-center justify-center bg-secondary-fixed text-on-secondary-fixed px-space-lg py-space-md rounded hover:bg-secondary-fixed-dim transition-colors duration-200" href="/sw/index.php">
<span class="font-label-lg text-label-lg font-semibold tracking-wider flex items-center gap-space-xs">
<span class="material-symbols-outlined text-[18px]">badge</span>
申請通行玉牒
</span>
</a>
</div>

<div class="mt-space-md p-space-md bg-surface-container rounded-lg flex items-start gap-space-md">
<span class="material-symbols-outlined text-outline text-[22px] mt-0.5">contact_support</span>
<div class="space-y-space-xs">
<h2 class="font-label-lg text-label-lg text-on-surface font-semibold tracking-wide">長老與掌門執事通行指引</h2>
<p class="font-body-sm text-body-sm text-on-surface-variant">
若您已身負門派要職或 GM 司職，請檢查是否已成功登入管理階層帳號；或請前往<a class="text-primary hover:underline font-medium mx-1" href="/sw/support.php">雪廬客服</a>核對俠名身分與封號權限。
</p>
</div>
</div>
</div>

</div>
</section>
</div>
</main>

<!-- 頁尾 -->
<footer class="w-full bg-surface-container-low shadow-[0_-1px_6px_rgba(0,0,0,0.02)]">
<div class="w-full px-margin-mobile md:px-margin py-space-xl flex flex-col gap-space-lg">
<div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-space-md">
<div class="flex items-center gap-space-sm">
<img alt="踏雪笑傲品牌印記" class="h-6 w-auto object-contain opacity-80" src="/sw/swo/assets/images/idx-logo.png">
<span class="font-headline-sm text-headline-sm text-on-surface font-semibold">踏雪笑傲</span>
<span class="font-label-sm text-label-sm text-on-surface-variant">| 凝冰踏雪 · 笑傲凌霄</span>
</div>
<div class="flex flex-wrap items-center gap-space-lg text-on-surface-variant">
<a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="/sw/support.php">服務條款</a>
<a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="/sw/support.php">隱私權協定</a>
<a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="/sw/support.php">雪廬客服</a>
<a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="/sw/support.php">分級規範</a>
</div>
</div>
<div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-space-md pt-space-md">
<div class="space-y-space-xs">
<p class="font-body-sm text-body-sm text-on-surface-variant">© <?= date('Y') ?> 踏雪笑傲 Snow Wanderer Studio. 著作權所有，並保留一切權利。</p>
<p class="font-label-sm text-label-sm text-on-surface-variant opacity-75">本遊戲情節涉及武俠格鬥、虛擬江湖對決。注意使用時間，避免沉迷於遊戲。</p>
</div>
<div class="flex items-center gap-space-sm bg-surface-container-high px-space-md py-space-xs rounded">
<div class="w-7 h-7 rounded bg-surface-container-highest flex items-center justify-center font-label-md text-label-md text-on-surface font-bold">15+</div>
<span class="font-label-sm text-label-sm text-on-surface-variant">輔導十五歲級 · 依遊戲軟體分級管理辦法標示</span>
</div>
</div>
</div>
</footer>

<script>
(function () {
  var hero = document.querySelector('section.forbidden-enter');
  if (hero) {
    requestAnimationFrame(function () { hero.classList.add('is-in'); });
  }
})();
</script>
</body>
</html>

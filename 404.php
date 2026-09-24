<?php
/**
 * =============================================================
 *  踏雪笑傲 · 404 尋路頁（找不到頁面）
 *  -------------------------------------------------------------
 *  依設計稿建置；伺服器層請以 ErrorDocument / error_page
 *  將 404 導向本頁：見同目錄 .htaccess（Apache）與
 *  nginx-404.conf（Nginx）說明。
 * =============================================================
 */
if (!headers_sent()) {
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
<title>踏雪笑傲 - 找不到頁面 (404)</title>
<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&amp;family=Bodoni+Moda:ital,opsz,wght@0,6..96,400;0,6..96,600;0,6..96,700;1,400;1,600&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="/sw/assets/404-tailwind.css?v=1">
</head>
<body class="bg-background font-body-md text-on-surface antialiased min-h-screen flex flex-col justify-between overflow-x-hidden selection:bg-primary-fixed selection:text-on-primary-fixed">

<!-- 頁首導覽 -->
<header class="fixed top-0 inset-x-0 z-50 bg-surface/85 backdrop-blur-md shadow-[0_1px_8px_rgba(0,0,0,0.04)]">
<div class="h-20 w-full px-margin-mobile md:px-margin flex items-center justify-between">
<a class="flex items-center gap-space-md focus:outline-none" href="/sw/swo/index.php">
<img alt="踏雪笑傲 Logo" class="h-8 w-auto object-contain" src="/sw/swo/assets/images/idx-logo.png">
<div class="flex flex-col leading-none">
<span class="font-headline-sm text-headline-sm tracking-wide text-on-surface font-semibold">踏雪笑傲</span>
<span class="font-label-sm text-label-sm tracking-widest text-on-surface-variant uppercase mt-space-xs">Snow Wanderer</span>
</div>
</a>
<nav class="hidden lg:flex items-center gap-space-xs">
<a class="px-space-md py-space-xs rounded-lg font-label-lg text-label-lg text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors" href="/sw/swo/index.php">首頁</a>
<a class="px-space-md py-space-xs rounded-lg font-label-lg text-label-lg text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors" href="/sw/swo/news.php">最新公告</a>
<a class="px-space-md py-space-xs rounded-lg font-label-lg text-label-lg text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors" href="/sw/swo/events.php">江湖活動</a>
<a class="px-space-md py-space-xs rounded-lg font-label-lg text-label-lg text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors" href="/sw/swo/download.php">遊戲下載</a>
<a class="px-space-md py-space-xs rounded-lg font-label-lg text-label-lg text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface transition-colors" href="/sw/home.php">會員中心</a>
</nav>
<div class="flex items-center gap-space-md">
<a class="hidden sm:inline-flex items-center justify-center px-space-md py-space-xs bg-primary text-on-primary font-label-md text-label-md rounded-lg shadow-sm hover:bg-primary-container hover:text-on-primary transition-all" href="/sw/swo/download.php">啟動微端</a>
<a class="w-8 h-8 rounded-full bg-primary flex items-center justify-center" href="/sw/home.php" aria-label="會員中心">
<span class="material-symbols-outlined text-on-primary text-[18px]">person</span>
</a>
</div>
</div>
</header>

<!-- 主內容 -->
<main class="w-full pt-20 bg-background flex-1 relative flex flex-col justify-center">
<div class="flex flex-col w-full relative overflow-hidden bg-background">
<div class="absolute inset-0 pointer-events-none z-0 opacity-25">
<div class="w-full h-full bg-cover bg-center" style="background-image: url(&quot;/sw/swo/assets/images/idx-hero.jpg&quot;);"></div>
<div class="absolute inset-0 bg-gradient-to-b from-background via-background/80 to-background"></div>
</div>
<div class="absolute inset-0 pointer-events-none z-0 overflow-hidden">
<div class="absolute -top-10 left-1/4 w-72 h-72 rounded-full bg-secondary-fixed-dim/20 blur-3xl"></div>
<div class="absolute top-1/3 right-1/6 w-96 h-96 rounded-full bg-primary-fixed-dim/25 blur-3xl"></div>
<div class="absolute bottom-10 left-1/3 w-80 h-80 rounded-full bg-surface-variant/40 blur-3xl"></div>
</div>

<div class="relative z-10 w-full px-margin-mobile md:px-margin py-space-xl flex flex-col items-center max-w-7xl mx-auto">
<div class="flex flex-wrap items-center justify-center text-center gap-space-sm mb-space-md">
<div class="h-px w-12 bg-outline-variant"></div>
<span class="font-label-sm text-label-sm tracking-widest text-primary uppercase font-bold">SNOW DRIFT · LOST PATHWAY · 迷蹤風雪</span>
<div class="h-px w-12 bg-outline-variant"></div>
</div>

<div class="w-full max-w-5xl bg-surface-container-lowest/90 backdrop-blur-md rounded-2xl shadow-xl overflow-hidden border border-outline-variant/40 mb-space-lg">
<div class="grid grid-cols-1 lg:grid-cols-12">
<div class="lg:col-span-7 relative min-h-[320px] lg:min-h-[440px] overflow-hidden bg-surface-container-low group">
<img alt="踏雪笑傲 風雪迷蹤" class="w-full h-full object-cover object-center group-hover:scale-105 transition-transform duration-700" src="/sw/swo/assets/images/idx-sect-1.jpg">
<div class="absolute inset-0 bg-gradient-to-t lg:bg-gradient-to-r from-transparent via-transparent to-surface-container-lowest/80"></div>
<div class="absolute top-4 left-4 bg-surface/90 backdrop-blur-md px-3 py-1.5 rounded-lg shadow-sm border border-outline-variant/30 flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-[18px]">ac_unit</span>
<span class="font-label-sm text-label-sm text-on-surface tracking-wider font-semibold">大雪封山 · 徑絕人蹤</span>
</div>
<div class="absolute bottom-4 left-4 bg-primary/90 backdrop-blur-sm text-on-primary px-3 py-1 rounded-DEFAULT text-label-sm font-bold [writing-mode:vertical-rl] tracking-widest shadow-md">天地茫茫</div>
</div>
<div class="lg:col-span-5 p-space-lg lg:p-space-xl flex flex-col justify-between relative bg-surface-container-lowest">
<div class="flex flex-col">
<div class="flex items-baseline gap-2 mb-space-xs">
<span class="font-display-lg text-[5.5rem] lg:text-[7rem] leading-none tracking-tighter text-primary font-bold drop-shadow-sm select-none">404</span>
<div class="flex flex-col ml-space-xs">
<span class="font-headline-sm text-headline-sm font-bold text-on-surface tracking-wide">前路冰封</span>
<span class="font-label-sm text-label-sm text-primary tracking-widest font-medium uppercase">PAGE NOT FOUND</span>
</div>
</div>
<div class="h-0.5 w-16 bg-primary mb-space-md"></div>
<h2 class="font-headline-md text-headline-md text-on-surface mb-space-sm font-semibold leading-tight">風雪漫天迷古徑，<br>少俠莫非走岔了道？</h2>
<p class="font-body-md text-body-md text-on-surface-variant leading-relaxed mb-space-md">您所探尋的武林秘境或已遷往他處、玉簡網址謄寫有誤，抑或前路為風雪所阻。請少俠稍歇腳步，依引路石碑折返或重尋秘途。</p>
<form action="/sw/swo/news.php" class="relative flex items-center shadow-sm rounded-lg overflow-hidden bg-surface-container-low border border-outline-variant/30 mb-space-md" method="GET">
<div class="pl-space-md flex items-center pointer-events-none text-outline"><span class="material-symbols-outlined text-[18px]">search</span></div>
<input class="w-full min-w-0 py-space-sm px-space-md bg-transparent text-on-surface font-body-sm text-body-sm placeholder:text-outline focus:outline-none" name="q" placeholder="尋覓江湖密令、門派或活動..." type="text">
<button class="px-space-md py-space-sm bg-primary text-on-primary font-label-md text-label-md hover:bg-primary-container transition-colors flex items-center gap-space-xs whitespace-nowrap shrink-0" type="submit">
<span class="whitespace-nowrap">尋路</span>
<span class="material-symbols-outlined text-[14px]">explore</span>
</button>
</form>
</div>
<div class="flex flex-wrap items-center gap-space-sm pt-space-xs">
<a class="flex-1 inline-flex items-center justify-center gap-space-xs px-space-md py-space-sm bg-primary text-on-primary font-label-md text-label-md rounded-lg shadow-sm hover:bg-primary-container transition-all transform hover:-translate-y-0.5 text-center whitespace-nowrap" href="/sw/swo/index.php">
<span class="material-symbols-outlined text-[18px]">home</span>
<span class="">重返首頁</span>
</a>
<button class="inline-flex items-center justify-center gap-space-xs px-space-md py-space-sm bg-surface-container text-on-surface font-label-md text-label-md rounded-lg hover:bg-surface-container-high transition-all text-center whitespace-nowrap" onclick="window.history.length > 1 ? window.history.back() : window.location.href='/sw/swo/index.php';">
<span class="material-symbols-outlined text-[18px]">arrow_back</span>
<span class="">返回前頁</span>
</button>
<a class="inline-flex items-center justify-center gap-space-xs px-space-md py-space-sm bg-secondary text-on-secondary font-label-md text-label-md rounded-lg hover:opacity-95 transition-all text-center whitespace-nowrap" href="/sw/support.php">
<span class="material-symbols-outlined text-[18px]">support_agent</span>
<span class="">回報迷途</span>
</a>
</div>
</div>
</div>
</div>

<div class="max-w-2xl w-full p-space-md bg-surface-container-low/80 backdrop-blur-sm border border-outline-variant/30 rounded-xl flex flex-col sm:flex-row items-center justify-between gap-space-md text-center sm:text-left">
<div class="flex flex-wrap items-center justify-center text-center sm:text-left gap-space-sm">
<div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary flex-shrink-0">
<span class="material-symbols-outlined text-[18px]">info</span>
</div>
<p class="font-body-sm text-body-sm text-on-surface-variant">仍舊找不到所求之物？可直接發送飛鴿傳書，江湖使者將即刻為您引路。</p>
</div>
<a class="font-label-md text-label-md text-primary hover:text-primary-container whitespace-nowrap flex items-center gap-space-xs flex-shrink-0" href="/sw/support.php">
<span class="">聯繫雪廬客服</span>
<span class="material-symbols-outlined text-[16px]">chevron_right</span>
</a>
</div>
</div>
</div>
</main>

<!-- 頁尾 -->
<footer class="w-full bg-surface-container-low py-space-xl shadow-[0_-1px_6px_rgba(0,0,0,0.02)]">
<div class="w-full px-margin-mobile md:px-margin">
<div class="flex flex-col md:flex-row items-center justify-between gap-space-md pb-space-lg">
<div class="flex flex-wrap items-center justify-center text-center gap-space-sm">
<span class="font-headline-sm text-headline-sm text-primary font-bold">踏雪笑傲</span>
<span class="text-outline font-body-sm text-body-sm">|</span>
<span class="font-body-sm text-body-sm text-on-surface-variant">千山暮雪，劍嘯長空。正統東方水墨武俠巨作</span>
</div>
<div class="flex flex-wrap items-center justify-center gap-space-lg">
<a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="/sw/support.php">隱私條款</a>
<a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="/sw/support.php">服務合約</a>
<a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="/sw/support.php">雪廬客服</a>
<a class="font-label-md text-label-md text-on-surface-variant hover:text-primary transition-colors" href="/sw/support.php">防沉迷指引</a>
</div>
</div>
<div class="flex flex-col sm:flex-row items-center justify-between gap-space-xs pt-space-md text-on-surface-variant">
<p class="font-body-sm text-body-sm">© 2024 踏雪笑傲 營運團隊. 俠道不孤，寒梅常在. All rights reserved.</p>
<p class="font-label-sm text-label-sm">普遍級 12+ 本遊戲涉及虛擬武俠打鬥</p>
</div>
</div>
</footer>

</body>
</html>

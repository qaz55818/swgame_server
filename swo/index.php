<?php
/**
 * =============================================================
 *  踏雪笑傲 · 官方首頁（Landing）
 *  由 swo/index.html 轉為 PHP，並整合會員登入 / 註冊、動態公告、
 *  統一全站字體與互動功能（宣傳影片、公告分類、粒子特效）。
 * =============================================================
 */
require_once __DIR__ . '/../session_bootstrap.php';
app_session_start(['remember' => true]);

/* 公告資料（若資料庫無法連線則自動使用示範資料） */
require_once __DIR__ . '/../news_config.php';
require_once __DIR__ . '/site_config.php';
require_once __DIR__ . '/../game_server_lib.php';

/* 目前遊戲伺服器版本（由後台遠端讀取後快取） */
$gameVersion = gameserver_display_version(game_version_current());

/* 首頁設定（後台可調整；未設定時回退預設值） */
$S = site_settings_all(site_db());
$newsLimit = max(1, min(20, (int)site_setting($S, 'news_limit', '8')));

/* 公告分類樣式對照（類別字串需實際出現於此檔，Tailwind 才能掃描產生樣式） */
$newsTagClass = [
    'maintenance' => 'bg-rose-100 text-rose-800 border-rose-200',
    'update'      => 'bg-sky-100 text-sky-800 border-sky-200',
    'event'       => 'bg-amber-100 text-amber-900 border-amber-200',
    'security'    => 'bg-slate-100 text-slate-800 border-slate-300',
    'guide'       => 'bg-teal-100 text-teal-800 border-teal-200',
];
$newsHoverClass = [
    'maintenance' => 'hover:border-rose-200',
    'update'      => 'hover:border-sky-200',
    'event'       => 'hover:border-amber-200',
    'security'    => 'hover:border-slate-300',
    'guide'       => 'hover:border-teal-200',
];

/** 將分類代碼轉為安全的 CSS class（供前端篩選比對） */
function idx_news_class(string $code): string
{
    $c = strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', $code));
    return $c !== '' ? $c : 'notice';
}

$newsDb = null;
$latestNews = [];
$newsCats = [];
try {
    $newsDb = news_db();
    if ($newsDb) {
        /* 先取得資料庫中的分類（代碼 → 名稱），供頁籤與標籤正確對應 */
        $catRes = mysqli_query($newsDb, "SELECT `code`, `name` FROM `news_categories` WHERE `enabled` = 1 ORDER BY `sort_order` ASC, `id` ASC");
        if ($catRes) {
            while ($c = $catRes->fetch_assoc()) { $newsCats[(string)$c['code']] = (string)$c['name']; }
        }

        $res = mysqli_query(
            $newsDb,
            "SELECT a.`id`, a.`title`, a.`summary`, a.`category_code`, a.`published_at`
               FROM `news_articles` a
              WHERE a.`status` = 'published' AND a.`published_at` IS NOT NULL AND a.`published_at` <= NOW()
              ORDER BY a.`is_sticky` DESC, a.`published_at` DESC
              LIMIT " . $newsLimit
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $code = (string)$r['category_code'];
                $latestNews[] = [
                    'title'   => (string)$r['title'],
                    'summary' => trim(strip_tags((string)($r['summary'] ?? ''))),
                    'code'    => $code,
                    'label'   => $newsCats[$code] ?? '公告',
                    'date'    => date('m-d', strtotime((string)$r['published_at'])),
                    'url'     => 'news.php?view=' . (int)$r['id'],
                ];
            }
        }
    }
} catch (Throwable $e) {
    $latestNews = [];
}

/* 分類後備（資料庫不可用時仍能正確顯示） */
if (empty($newsCats)) {
    $newsCats = [
        'maintenance' => '系統維護',
        'update'      => '重大更新',
        'event'       => '官方活動',
        'security'    => '防詐安全',
        'guide'       => '新手指南',
    ];
}

/* 示範資料（資料庫為空或連線失敗時） */
if (empty($latestNews)) {
    $latestNews = [
        ['title' => '每週三例行停機維護及平衡性調整公告', 'summary' => '預計於 06:00 - 10:00 進行伺服器陣列效能升級', 'code' => 'maintenance', 'label' => '系統維護', 'date' => '02-26', 'url' => 'news.php'],
        ['title' => '【踏雪尋梅】全服限時雙倍修為與玄鐵掉落', 'summary' => '每日完成宗門除魔委託，即可額外領取雪魄靈丹', 'code' => 'event', 'label' => '官方活動', 'date' => '02-24', 'url' => 'news.php'],
        ['title' => '跨服論劍大會第一賽季「傲雪驚鴻」規則公示', 'summary' => '前三甲隊伍專屬絕版神兵光武外觀與跨服雕像獎勵', 'code' => 'update', 'label' => '重大更新', 'date' => '02-20', 'url' => 'news.php'],
        ['title' => '防範第三方代儲與非官方外掛安全治理聲明', 'summary' => '維護公平健康的江湖生態，嚴打各類腳本違規行為', 'code' => 'security', 'label' => '防詐安全', 'date' => '02-18', 'url' => 'news.php'],
        ['title' => '老俠客重歸江湖！回流即贈專屬坐騎【踏霜踏雪鹿】', 'summary' => '等級直升特權與專屬回流禮包碼即刻發放中', 'code' => 'event', 'label' => '官方活動', 'date' => '02-15', 'url' => 'news.php'],
    ];
}

/* 只顯示列表實際出現的分類作為頁籤，避免空頁籤 */
$presentCodes = [];
foreach ($latestNews as $n) { $presentCodes[$n['code']] = true; }
$newsTabs = [];
foreach ($newsCats as $code => $name) {
    if (isset($presentCodes[$code])) { $newsTabs[$code] = $name; }
}

/* 宣傳影片：後台可設定 YouTube 影片 ID，留空則顯示精美佔位畫面 */
$pvEmbedId = trim((string)site_setting($S, 'pv_embed_id', ''));

/* 首頁導覽資料（連結可由後台調整） */
$navItems = [
    ['key' => 'home',     'label' => '官網首頁', 'href' => 'index.php'],
    ['key' => 'news',     'label' => '最新公告', 'href' => site_setting($S, 'nav_news_url', 'news.php')],
    ['key' => 'events',   'label' => '江湖活動', 'href' => site_setting($S, 'nav_events_url', 'events.php')],
    ['key' => 'download', 'label' => '遊戲下載', 'href' => site_setting($S, 'nav_download_url', 'download.php')],
    ['key' => 'member',   'label' => '會員中心', 'href' => site_setting($S, 'nav_member_url', '../home.php')],
];
$navActive = 'home';
?>
<!DOCTYPE html>
<html class="light" lang="zh-TW">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<meta content="web_standard" name="shell-type"/>
<title>踏雪笑傲 · 水墨武俠 MMO 官方網站</title>
<meta name="description" content="踏雪笑傲 — 正宗硬派水墨東方仙俠巨作。無拘御劍、千乘武學、雪境奇遇與千人跨服論劍，立即下載進入江湖。"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet"/>
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400..800;1,400..800&amp;family=Manrope:wght@300;400;500;600;700;800&amp;family=Noto+Serif+TC:wght@400;600;700;900&amp;family=Ma+Shan+Zheng&amp;family=ZCOOL+XiaoWei&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/base.css?v=1">
<link rel="stylesheet" href="assets/css/index.css?v=1">
<link rel="stylesheet" href="assets/css/index-tailwind.css?v=4">
<link rel="stylesheet" href="assets/css/site-ui.css?v=5">
<link rel="stylesheet" href="assets/css/swo-theme.css?v=1">
<script src="assets/site-ui.js?v=3" defer></script>
</head>
<body class="bg-slate-50 text-slate-900 font-body-md text-body-md antialiased selection:bg-rose-700 selection:text-white">

<!-- ===================== 頂部導覽 ===================== -->
<header class="fixed top-0 left-0 w-full z-50 bg-white/85 backdrop-blur-xl border-b border-slate-200/80 shadow-[0_4px_20px_-4px_rgba(148,163,184,0.15)]">
  <div class="h-20 max-w-7xl mx-auto px-6 lg:px-12 flex items-center justify-between gap-6">
    <div class="flex items-center gap-4 shrink-0">
      <?php
      $brandLogo = site_img_url(site_setting($S, 'brand_logo', 'assets/images/idx-logo.png'));
      $brandLogoVer = '';
      if ($brandLogo !== '' && !preg_match('#^(https?:)?//#i', $brandLogo) && strncmp($brandLogo, 'data:', 5) !== 0) {
          $logoFs = __DIR__ . '/' . ltrim($brandLogo, '/');
          if (is_file($logoFs)) {
              $brandLogoVer = '?v=' . filemtime($logoFs);
          }
      }
      ?>
      <?php if ($brandLogo !== ''): ?>
      <a class="inline-flex items-center focus:outline-none" href="index.php" aria-label="<?= site_h(site_setting($S, 'brand_name', '踏雪笑傲')) ?> 官網首頁">
        <img alt="<?= site_h(site_setting($S, 'brand_name', '踏雪笑傲')) ?> 官方標誌" class="header-brand-logo select-none" src="<?= site_h($brandLogo . $brandLogoVer) ?>" width="56" height="56"/>
      </a>
      <?php endif; ?>
      <a class="flex flex-col tracking-wider focus:outline-none shrink-0" href="index.php">
        <div class="flex items-center gap-2 whitespace-nowrap">
          <span class="font-headline-lg text-headline-lg text-slate-900 tracking-widest leading-none font-serif font-bold whitespace-nowrap"><?= site_h(site_setting($S, 'brand_name', '踏雪笑傲')) ?></span>
          <span class="font-label-sm text-[10px] px-1.5 py-0.5 bg-rose-700 text-white rounded-[2px] font-semibold tracking-wider whitespace-nowrap"><?= site_h(site_setting($S, 'brand_badge', '正宗武俠')) ?></span>
        </div>
        <span class="font-label-sm text-[10px] text-slate-500 tracking-[0.25em] uppercase font-medium mt-0.5 whitespace-nowrap"><?= site_h(site_setting($S, 'brand_subtitle', 'SNOW WANDERER ONLINE')) ?></span>
      </a>
    </div>
    <nav class="site-main-nav hidden xl:flex items-center gap-1 shrink-0">
      <?php foreach ($navItems as $it): $active = ($it['key'] === $navActive); ?>
        <a <?= $active ? 'aria-current="page"' : '' ?>
           class="swo-nav-link<?= $active ? ' is-active' : '' ?>"
           href="<?= htmlspecialchars($it['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="flex items-center gap-4 shrink-0">
      <div class="sau-status hidden md:flex items-center gap-2 px-3 py-1 bg-sky-50 border border-sky-100 rounded-full">
        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
        <span class="font-label-sm text-[11px] text-sky-800 font-medium tracking-wider"><?= site_h(site_setting($S, 'status_text', '全服營運中')) ?></span>
      </div>
      <?php if ($gameVersion !== ''): ?>
      <div class="hidden md:flex items-center gap-2 px-3 py-1 bg-rose-50 border border-rose-100 rounded-full" title="目前遊戲版本">
        <i class="fa-solid fa-code-branch text-[10px] text-rose-700"></i>
        <span class="font-label-sm text-[11px] text-rose-800 font-semibold tracking-wider font-mono"><?= site_h($gameVersion) ?></span>
      </div>
      <?php endif; ?>
      <a class="swo-discord-btn hidden md:inline-flex items-center justify-center px-5 py-2.5 text-white font-label-md text-label-md tracking-widest uppercase rounded-[2px] whitespace-nowrap shrink-0 leading-none" href="<?= site_h(site_setting($S, 'nav_cta_url', '#')) ?>"><?= site_h(site_setting($S, 'nav_cta_label', '加入 DISCORD')) ?></a>
      <?php include __DIR__ . '/site_auth.php'; ?>
    </div>
  </div>
</header>

<main class="w-full pt-20 bg-gradient-to-b from-sky-50/70 via-slate-50 to-white min-h-screen">
<div class="flex flex-col w-full">

<!-- ===================== Hero ===================== -->
<section class="relative w-full overflow-hidden min-h-[760px] lg:min-h-[860px] flex flex-col justify-between border-b border-slate-200/80 bg-slate-100">
  <div class="absolute inset-0 z-0 overflow-hidden pointer-events-none">
    <img alt="水墨雪嶺 · 孤劍踏雪" class="w-full h-full object-cover object-center scale-105 animate-bg-drift origin-center filter brightness-[1.03] contrast-[1.04]" src="<?= site_h(site_img_url(site_setting($S, 'hero_image', 'assets/images/idx-hero.jpg'))) ?>"/>
    <div class="absolute inset-0 bg-gradient-to-t from-slate-50 via-slate-50/45 to-slate-900/20 mix-blend-normal"></div>
    <div class="absolute inset-0 bg-gradient-to-b from-white/70 via-transparent to-slate-50"></div>
    <div class="absolute inset-0 bg-gradient-to-r from-slate-50/85 via-white/20 to-slate-50/75"></div>
    <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,_var(--tw-gradient-stops))] from-white/70 via-white/30 to-transparent"></div>
    <div class="absolute inset-x-0 bottom-0 h-32 bg-gradient-to-t from-slate-50 via-slate-50/60 to-transparent"></div>
  </div>
  <canvas class="absolute inset-0 z-10 pointer-events-none w-full h-full" id="snow-petals-canvas"></canvas>

  <div class="relative z-20 max-w-6xl mx-auto px-6 lg:px-12 pt-16 lg:pt-24 pb-12 flex flex-col items-center text-center my-auto animate-hero-float">
    <div class="inline-flex items-center gap-3 px-5 py-1.5 rounded-full bg-white/80 backdrop-blur-md border border-amber-300/60 shadow-[0_4px_16px_rgba(217,119,6,0.12)] mb-7">
      <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-gradient-to-tr from-rose-800 to-amber-600 text-[10px] text-amber-100 font-serif font-bold shadow-sm">雪</span>
      <span class="font-serif tracking-[0.25em] text-xs lg:text-sm font-semibold text-slate-800 uppercase"><?= site_h(site_setting($S, 'hero_badge', '正宗硬派水墨東方仙俠 · 旗艦巨獻')) ?></span>
      <div class="w-1.5 h-1.5 rounded-full bg-rose-700 animate-ping"></div>
    </div>

    <div class="relative flex flex-col items-center">
      <div class="flex items-center justify-center gap-4 mb-3 opacity-80">
        <svg class="h-3 w-24 text-amber-600/70" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 100 12"><path d="M0 6h40c8 0 10-5 18-5s10 5 18 5h24"></path></svg>
        <span class="font-serif text-[11px] tracking-[0.35em] text-rose-800 font-semibold"><?= site_h(site_setting($S, 'hero_kicker', '霜刃既出 · 笑傲蒼穹')) ?></span>
        <svg class="h-3 w-24 text-amber-600/70" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 100 12"><path d="M100 6H60c-8 0-10-5-18-5s-10 5-18 5H0"></path></svg>
      </div>
      <h1 class="text-4xl sm:text-6xl lg:text-7xl font-serif font-black tracking-[0.14em] sm:tracking-[0.18em] leading-[1.25] text-slate-900 drop-shadow-[0_4px_24px_rgba(255,255,255,0.9)]">
        <span class="block mb-2 gold-shimmer-text"><?= site_h(site_setting($S, 'hero_title_1', '雪落寒山孤劍影')) ?></span>
        <span class="inline-block relative">
          <span class="bg-gradient-to-r from-slate-950 via-rose-950 to-slate-900 bg-clip-text text-transparent"><?= site_h(site_setting($S, 'hero_title_2', '天地笑傲任平生')) ?></span>
          <span class="hidden md:inline-flex align-middle ml-4 px-2 py-1 bg-gradient-to-b from-rose-700 to-rose-900 text-amber-100 border border-amber-400/40 rounded-[2px] shadow-md text-[13px] font-serif font-bold tracking-widest leading-none rotate-3"><?= site_h(site_setting($S, 'hero_seal', '極境')) ?></span>
        </span>
      </h1>
      <p class="mt-6 max-w-2xl text-slate-700 font-serif text-base sm:text-lg lg:text-xl tracking-[0.12em] leading-relaxed font-medium">
        <?= site_h(site_setting($S, 'hero_subtitle', '一柄孤劍踏萬里雪原，一脈清霜引江湖浩蕩。')) ?><br class="hidden sm:inline"/>
        <span class="text-slate-600 text-sm sm:text-base font-normal tracking-[0.08em]"><?= site_h(site_setting($S, 'hero_subtitle_2', '沉浸式東方寫意武學 · 跨服千人同屏對決 · 今朝試鋒。')) ?></span>
      </p>
    </div>

    <div class="mt-10 flex flex-wrap items-center justify-center gap-4 sm:gap-6">
      <a class="gold-light-sweep group relative inline-flex items-center gap-3.5 px-8 py-4 bg-gradient-to-r from-rose-900 via-rose-700 to-red-600 text-white shadow-xl shadow-rose-950/25 hover:shadow-2xl hover:brightness-110 hover:-translate-y-0.5 transition-all duration-300 rounded-[2px] border border-rose-400/30" href="<?= site_h(site_setting($S, 'cta1_url', 'download.php')) ?>">
        <div class="w-8 h-8 rounded-full bg-white/15 backdrop-blur-sm flex items-center justify-center border border-white/20 group-hover:rotate-12 transition-transform">
          <span class="material-symbols-outlined text-[20px] text-amber-200">download</span>
        </div>
        <div class="text-left flex flex-col">
          <span class="font-serif text-base sm:text-lg font-bold tracking-widest text-white leading-tight"><?= site_h(site_setting($S, 'cta1_label', '立即下載遊戲')) ?></span>
          <span class="font-sans text-[11px] text-rose-100/90 tracking-wider"><?= site_h(site_setting($S, 'cta1_sub', 'PC 完整安裝包 · v1.8.5 (28.4 GB)')) ?></span>
        </div>
      </a>
      <a class="jade-glass group inline-flex items-center gap-2.5 px-7 py-4 text-slate-800 hover:text-rose-800 hover:border-rose-300 hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300 rounded-[2px]" href="<?= site_h(site_setting($S, 'cta2_url', '../index.php')) ?>">
        <span class="material-symbols-outlined text-[22px] text-rose-700 group-hover:scale-110 transition-transform">app_registration</span>
        <span class="font-serif text-base font-bold tracking-wider"><?= site_h(site_setting($S, 'cta2_label', '快速註冊帳號')) ?></span>
      </a>
      <button class="jade-glass group inline-flex items-center gap-2.5 px-6 py-4 text-slate-700 hover:text-rose-800 hover:border-slate-300 hover:shadow-md transition-all duration-300 rounded-[2px]" type="button" onclick="openPv()">
        <div class="w-7 h-7 rounded-full bg-rose-50 text-rose-700 flex items-center justify-center border border-rose-200 group-hover:bg-rose-700 group-hover:text-white transition-colors">
          <span class="material-symbols-outlined text-[17px]" style="font-variation-settings:'FILL' 1;">play_arrow</span>
        </div>
        <span class="font-serif text-sm font-semibold tracking-widest"><?= site_h(site_setting($S, 'cta3_label', '欣賞宣傳影片')) ?></span>
      </button>
    </div>
  </div>

  <div class="relative z-20 w-full max-w-4xl mx-auto px-6 mb-6">
    <div class="jade-glass rounded-xl p-3 sm:px-6 sm:py-3.5 border border-slate-200/90 flex flex-col sm:flex-row items-center justify-between gap-3 shadow-lg shadow-slate-200/50">
      <div class="flex items-center gap-2.5 shrink-0">
        <span class="relative flex h-2.5 w-2.5">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
          <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-600"></span>
        </span>
        <span class="font-serif text-xs sm:text-sm font-bold text-slate-800 tracking-wider">全境江湖營運狀態</span>
      </div>
      <div class="flex flex-wrap items-center justify-center gap-4 sm:gap-6 text-xs font-serif">
        <div class="flex items-center gap-2"><span class="text-slate-600 font-medium">踏雪尋梅</span><span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200 font-sans font-bold text-[10px] rounded-sm">流暢</span></div>
        <div class="w-px h-3 bg-slate-300 hidden sm:block"></div>
        <div class="flex items-center gap-2"><span class="text-slate-600 font-medium">笑傲江湖</span><span class="px-2 py-0.5 bg-rose-50 text-rose-700 border border-rose-200 font-sans font-bold text-[10px] rounded-sm">熱門爆滿</span></div>
        <div class="w-px h-3 bg-slate-300 hidden sm:block"></div>
        <div class="flex items-center gap-1.5 text-amber-800">
          <span class="material-symbols-outlined text-[15px] text-amber-600">notifications_active</span>
          <span class="font-medium text-slate-600">新服【凌霄絕頂】5日後開啟預約</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===================== 資訊樞紐 ===================== -->
<section class="w-full py-20 bg-slate-50/80 relative border-b border-slate-200/60">
  <div class="max-w-7xl mx-auto px-6 lg:px-12">
    <div class="flex flex-col md:flex-row md:items-end justify-between mb-12 gap-4">
      <div>
        <div class="flex items-center gap-2 mb-2">
          <span class="font-label-sm text-label-sm text-rose-700 tracking-widest uppercase font-bold">LATEST CHRONICLES</span>
          <div class="w-8 h-px bg-rose-600"></div>
        </div>
        <h2 class="font-headline-xl text-headline-xl text-slate-900 tracking-wider font-serif font-bold"><?= site_h(site_setting($S, 'news_title', '江湖風雲速遞')) ?></h2>
      </div>
      <p class="font-body-sm text-body-sm text-slate-500 max-w-md"><?= site_h(site_setting($S, 'news_subtitle', '掌握天下局勢與版本動態，例行維護、名劍大會與節氣奇遇活動一手掌握。')) ?></p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-stretch">
      <!-- 主打資料片 -->
      <a class="lg:col-span-7 flex flex-col bg-white border border-slate-200 shadow-xl shadow-slate-200/50 group relative overflow-hidden rounded-sm" href="<?= site_h(site_setting($S, 'feature_url', 'news.php')) ?>">
        <div class="relative h-80 sm:h-96 w-full overflow-hidden bg-slate-100">
          <img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 ease-out" alt="<?= site_h(site_setting($S, 'feature_title', '')) ?>" src="<?= site_h(site_img_url(site_setting($S, 'feature_image', 'assets/images/idx-news-featured.jpg'))) ?>" loading="lazy"/>
          <div class="absolute inset-0 bg-gradient-to-t from-white via-white/20 to-transparent"></div>
          <div class="absolute top-4 left-4 flex gap-2">
            <span class="px-2.5 py-1 bg-rose-700 text-white font-label-sm text-label-sm tracking-widest uppercase rounded-[2px] shadow-sm font-semibold"><?= site_h(site_setting($S, 'feature_badge_a', '年度資料片')) ?></span>
            <span class="px-2.5 py-1 bg-white/90 backdrop-blur text-sky-900 border border-slate-200 font-label-sm text-label-sm tracking-widest rounded-[2px] font-semibold"><?= site_h(site_setting($S, 'feature_badge_b', '寒淵破曉')) ?></span>
          </div>
        </div>
        <div class="p-6 lg:p-8 flex flex-col justify-between flex-1 bg-white">
          <div>
            <div class="flex items-center gap-4 text-slate-500 font-label-sm text-label-sm mb-3 font-medium">
              <span>更新發佈日期：<?= site_h(site_setting($S, 'feature_date', '2025-03-01')) ?></span><span>•</span><span class="text-sky-700 font-semibold"><?= site_h(site_setting($S, 'feature_tag', '跨服全新宗門戰')) ?></span>
            </div>
            <h3 class="font-headline-lg text-headline-lg text-slate-900 group-hover:text-rose-800 transition-colors tracking-wide mb-3 font-serif font-bold"><?= site_h(site_setting($S, 'feature_title', '')) ?></h3>
            <p class="font-body-md text-body-md text-slate-600 line-clamp-2"><?= site_h(site_setting($S, 'feature_desc', '')) ?></p>
          </div>
          <div class="mt-6 flex items-center justify-between pt-4 bg-slate-50 border border-slate-200/80 px-4 py-3 rounded-[2px]">
            <span class="font-body-sm text-body-sm text-slate-700 font-medium"><?= site_h(site_setting($S, 'feature_footer', '')) ?></span>
            <span class="material-symbols-outlined text-rose-700 group-hover:translate-x-1 transition-transform text-[20px]">arrow_forward</span>
          </div>
        </div>
      </a>

      <!-- 動態公告列表 -->
      <div class="lg:col-span-5 flex flex-col bg-white border border-slate-200 shadow-xl shadow-slate-200/50 p-6 lg:p-8 rounded-sm">
        <div class="news-tabs flex flex-wrap items-center gap-1.5 p-1.5 mb-6 bg-slate-100/90 rounded-full border border-slate-200/80 shadow-inner" role="tablist">
          <button class="news-tab-btn px-3.5 py-1.5 text-center text-xs font-semibold text-white bg-rose-700 rounded-full shadow-sm transition-all duration-300 tracking-wider whitespace-nowrap" onclick="filterTab('all', this)" type="button">全部</button>
          <?php foreach ($newsTabs as $code => $name): ?>
          <button class="news-tab-btn px-3.5 py-1.5 text-center text-xs font-medium text-slate-600 hover:text-rose-700 transition-all duration-300 tracking-wider rounded-full whitespace-nowrap" onclick="filterTab('<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>', this)" type="button"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></button>
          <?php endforeach; ?>
        </div>

        <div class="flex flex-col gap-3.5" id="news-feed-container">
          <?php foreach ($latestNews as $n):
            $nCls   = idx_news_class($n['code']);
            $nTag   = $newsTagClass[$n['code']] ?? 'bg-slate-100 text-slate-700 border-slate-200';
            $nHover = $newsHoverClass[$n['code']] ?? 'hover:border-slate-300';
          ?>
          <a class="news-item <?= $nCls ?> group flex items-center justify-between gap-4 py-3.5 px-4 bg-slate-50/80 hover:bg-white border border-slate-200/80 <?= $nHover ?> hover:shadow-md transition-all duration-300 rounded-xl" href="<?= htmlspecialchars($n['url'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="flex items-center gap-3.5 min-w-0 flex-1">
              <span class="px-2.5 py-1 <?= $nTag ?> text-xs font-serif font-bold tracking-wider rounded-md whitespace-nowrap shadow-sm"><?= htmlspecialchars($n['label'], ENT_QUOTES, 'UTF-8') ?></span>
              <div class="flex flex-col gap-1 min-w-0 flex-1">
                <h4 class="text-[15px] font-serif font-bold text-slate-800 group-hover:text-rose-700 transition-colors truncate tracking-wide leading-snug"><?= news_h($n['title']) ?></h4>
                <p class="text-xs text-slate-500 truncate leading-normal"><?= news_h($n['summary']) ?></p>
              </div>
            </div>
            <div class="flex items-center gap-2 text-slate-400 group-hover:text-rose-700 transition-colors shrink-0">
              <span class="text-xs font-mono tracking-tight font-medium"><?= news_h($n['date']) ?></span>
              <span class="material-symbols-outlined text-[18px] group-hover:translate-x-0.5 transition-transform">arrow_right_alt</span>
            </div>
          </a>
          <?php endforeach; ?>
        </div>

        <div id="news-empty" class="hidden flex-col items-center justify-center gap-2 py-10 text-center">
          <span class="material-symbols-outlined text-slate-300" style="font-size:40px;">inbox</span>
          <p class="text-sm text-slate-400">此分類目前尚無公告</p>
        </div>

        <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-end">
          <a class="group inline-flex items-center gap-2 px-4 py-2 rounded-full border border-slate-200 bg-slate-50/70 hover:bg-rose-50/60 hover:border-rose-200 text-xs font-serif font-bold text-slate-700 hover:text-rose-700 transition-all duration-300 shadow-sm" href="<?= site_h(site_setting($S, 'news_more_url', 'news.php')) ?>">
            <span><?= site_h(site_setting($S, 'news_more_label', '查看全部歷史公告')) ?></span>
            <span class="material-symbols-outlined text-[16px] text-rose-700 group-hover:translate-x-1 transition-transform">arrow_forward</span>
          </a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===================== 四大核心特色 ===================== -->
<section class="w-full py-24 bg-gradient-to-b from-white via-sky-50/40 to-slate-50 relative overflow-hidden border-b border-slate-200/60">
  <div class="max-w-7xl mx-auto px-6 lg:px-12">
    <div class="text-center max-w-3xl mx-auto mb-16">
      <div class="inline-block px-3 py-1 bg-white border border-sky-200 text-sky-800 font-label-sm text-label-sm tracking-widest uppercase mb-4 rounded-full font-bold shadow-sm">IMMERSIVE WUXIA UNIVERSE</div>
      <h2 class="font-headline-2xl text-headline-2xl text-slate-900 tracking-wider font-serif font-bold">獨步天下 · 江湖極境</h2>
      <p class="font-body-lg text-body-lg text-slate-600 mt-4 font-normal">打破傳統仙俠常規，以硬派水墨筆觸架構有血有肉、有招有勢的極致東方武道。</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
      <?php
      $features = [
          ['no' => '01', 'tone' => 'sky',  'tag' => '自由御空', 'title' => '無拘御劍 · 凌霄縱橫', 'desc' => '突破空氣牆限制，真正的 360 度御劍空戰。踏破雪山千里雲海，俯瞰大荒如畫江山，凡目之所及皆可破空而至。'],
          ['no' => '02', 'tone' => 'rose', 'tag' => '百派武學', 'title' => '以意馭氣 · 千乘武學', 'desc' => '招式無定形，水墨內勁刀刀見骨。自創千種武學連招套路，拳掌、重劍、暗器無縫切換，體驗真實格擋與破招爽快打擊感。'],
          ['no' => '03', 'tone' => 'slate','tag' => '動態風雪', 'title' => '雪境奇遇 · 機緣萬化', 'desc' => '動態天候物理引擎，暴風雪與凝冰直接影響角色身法。隱世古洞隨時機開放，遇機緣得神兵，一瞬逆轉江湖命格。'],
          ['no' => '04', 'tone' => 'sky',  'tag' => '千人同屏', 'title' => '千人陣營 · 跨服論劍', 'desc' => '萬人跨服激戰，冰封長城血戰到底。幫派領地搶奪、陣營旗幟插遍九州，唯有最強劍宗方能笑傲此方冰霜天地。'],
      ];
      foreach ($features as $f):
          $tone = $f['tone'];
          $toneBg = ['sky' => 'from-sky-700 via-sky-900 to-slate-900', 'rose' => 'from-rose-800 via-rose-950 to-slate-900', 'slate' => 'from-slate-600 via-slate-800 to-slate-900'][$tone];
          $toneText = ['sky' => 'text-sky-900/20', 'rose' => 'text-rose-900/20', 'slate' => 'text-slate-400'][$tone];
          $toneTag = ['sky' => 'bg-sky-50 text-sky-800 border-sky-200', 'rose' => 'bg-rose-50 text-rose-800 border-rose-200', 'slate' => 'bg-slate-100 text-slate-800 border-slate-200'][$tone];
      ?>
      <div class="group relative bg-white border border-slate-200/90 overflow-hidden shadow-xl shadow-slate-200/60 p-8 flex flex-col justify-between h-[380px] rounded-sm">
        <div class="absolute inset-0 z-0 bg-gradient-to-br <?= $toneBg ?> opacity-[0.14] group-hover:opacity-25 transition-all duration-700 ease-out"></div>
        <img class="absolute inset-0 z-0 w-full h-full object-cover opacity-25 group-hover:scale-105 group-hover:opacity-35 transition-all duration-700 ease-out filter contrast-110" alt="<?= htmlspecialchars($f['title'], ENT_QUOTES, 'UTF-8') ?>" src="assets/images/idx-feature-<?= (int)$f['no'] ?>.jpg" loading="lazy"/>
        <div class="absolute inset-0 z-0 bg-gradient-to-t from-white via-white/85 to-transparent"></div>
        <div class="relative z-10 flex justify-between items-start">
          <span class="font-headline-2xl text-5xl <?= $toneText ?> font-serif font-black"><?= $f['no'] ?></span>
          <div class="px-2.5 py-1 <?= $toneTag ?> border font-label-sm text-label-sm font-semibold rounded-[2px]"><?= $f['tag'] ?></div>
        </div>
        <div class="relative z-10">
          <h3 class="font-headline-xl text-headline-xl text-slate-900 mb-2 font-serif font-bold"><?= $f['title'] ?></h3>
          <p class="font-body-md text-body-md text-slate-600"><?= $f['desc'] ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ===================== 四大門派 ===================== -->
<section class="w-full py-20 bg-slate-50 relative border-b border-slate-200/60">
  <div class="max-w-7xl mx-auto px-6 lg:px-12">
    <div class="flex flex-col md:flex-row items-baseline justify-between mb-12">
      <div>
        <span class="font-label-sm text-label-sm text-rose-700 tracking-widest uppercase font-bold">THE FOUR NOBLE SECTS</span>
        <h2 class="font-headline-xl text-headline-xl text-slate-900 tracking-wider mt-1 font-serif font-bold">門派宗師 · 劍氣凌霜</h2>
      </div>
      <span class="font-body-sm text-body-sm text-slate-500 mt-2 md:mt-0">點擊宗門卡片即可領略獨門心法與兵器真容</span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
      <?php
      $sects = [
          ['idx' => 1, 'name' => '華山劍宗', 'tone' => 'rose',  'accent' => 'bg-rose-700',  'tag' => '近戰 · 凌厲破甲', 'emblem' => '劍', 'skill' => '以氣御劍，勢不可擋。擅長急速突進與連貫劍訣，一擊必殺。', 'weapon' => '三尺青鋒鋼劍'],
          ['idx' => 2, 'name' => '崑崙霜雪', 'tone' => 'sky',   'accent' => 'bg-sky-600',   'tag' => '遠程 · 冰魄控場', 'emblem' => '霜', 'skill' => '引天山九幽玄冰入體，大範圍減速封脈，化千里為絕對冰獄。', 'weapon' => '玄冰重刀 / 冰魄刺'],
          ['idx' => 3, 'name' => '武當太極', 'tone' => 'slate', 'accent' => 'bg-slate-600', 'tag' => '防禦 · 借力打力', 'emblem' => '太', 'skill' => '以柔克剛，陰陽化生。護體罡氣生生不息，團隊不可或缺的定海神針。', 'weapon' => '真武拂塵與太極劍'],
          ['idx' => 4, 'name' => '逍遙凌波', 'tone' => 'rose',  'accent' => 'bg-rose-600',  'tag' => '爆發 · 音律幻影', 'emblem' => '琴', 'skill' => '指下鳴泉，步步生蓮。琴音化刃無影無蹤，身形飄忽如神仙降世。', 'weapon' => '七弦焦尾琴'],
      ];
      foreach ($sects as $s):
          $tones = [
              'rose'  => ['card' => 'hover:border-rose-300', 'tag' => 'bg-rose-50 text-rose-800 border-rose-200', 'grad' => 'from-rose-800 via-rose-950 to-slate-900'],
              'sky'   => ['card' => 'hover:border-sky-300',  'tag' => 'bg-sky-50 text-sky-800 border-sky-200',   'grad' => 'from-sky-700 via-sky-900 to-slate-900'],
              'slate' => ['card' => 'hover:border-slate-400','tag' => 'bg-slate-100 text-slate-800 border-slate-200','grad' => 'from-slate-600 via-slate-800 to-slate-900'],
          ];
          $t = $tones[$s['tone']];
      ?>
      <div class="group bg-white border border-slate-200 p-5 flex flex-col justify-between shadow-md hover:shadow-2xl <?= $t['card'] ?> transition-all duration-300 relative rounded-sm">
        <div class="absolute top-2 left-2 w-2 h-2 <?= $s['accent'] ?>"></div>
        <div class="relative h-64 w-full overflow-hidden mb-4 rounded-[2px] bg-slate-100">
          <img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" alt="<?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>" src="assets/images/idx-sect-<?= (int)$s['idx'] ?>.jpg" loading="lazy"/>
        </div>
        <div>
          <div class="flex items-center justify-between mb-2">
            <span class="font-headline-lg text-xl text-slate-900 font-serif font-bold"><?= $s['name'] ?></span>
            <span class="px-2 py-0.5 <?= $t['tag'] ?> border font-label-sm text-[11px] font-semibold rounded-[2px]"><?= $s['tag'] ?></span>
          </div>
          <p class="font-body-sm text-body-sm text-slate-600">
            兵器：<?= $s['weapon'] ?>。<br/>
            <?= $s['skill'] ?>
          </p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ===================== 社群陣地 ===================== -->
<section class="w-full py-16 bg-white relative border-b border-slate-200/60">
  <div class="max-w-7xl mx-auto px-6 lg:px-12">
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
      <?php
      /* 社群卡片色系（類別字串需實際出現於此檔以供 Tailwind 掃描） */
      $socialTones = [
          'rose'    => ['hover:border-rose-300',    'bg-rose-50',    'border-rose-200/80',    'text-rose-700',    'group-hover:bg-rose-700'],
          'sky'     => ['hover:border-sky-300',     'bg-sky-50',     'border-sky-200/80',     'text-sky-700',     'group-hover:bg-sky-600'],
          'emerald' => ['hover:border-emerald-300', 'bg-emerald-50', 'border-emerald-200/80', 'text-emerald-700', 'group-hover:bg-emerald-600'],
          'slate'   => ['hover:border-slate-400',   'bg-slate-100',  'border-slate-300',      'text-slate-700',   'group-hover:bg-slate-800'],
          'amber'   => ['hover:border-amber-300',   'bg-amber-50',   'border-amber-200/80',   'text-amber-700',   'group-hover:bg-amber-600'],
          'violet'  => ['hover:border-violet-300',  'bg-violet-50',  'border-violet-200/80',  'text-violet-700',  'group-hover:bg-violet-600'],
          'cyan'    => ['hover:border-cyan-300',    'bg-cyan-50',    'border-cyan-200/80',    'text-cyan-700',    'group-hover:bg-cyan-600'],
      ];
      for ($i = 1; $i <= 4; $i++):
          $tone = (string)site_setting($S, "social{$i}_tone", 'rose');
          $c = $socialTones[$tone] ?? $socialTones['rose'];
      ?>
      <a class="group p-6 bg-slate-50/70 border border-slate-200/80 hover:bg-white <?= $c[0] ?> hover:shadow-lg transition-all flex items-center gap-4 rounded-sm" href="<?= site_h(site_setting($S, "social{$i}_url", '#')) ?>">
        <div class="w-12 h-12 rounded-full <?= $c[1] ?> border <?= $c[2] ?> flex items-center justify-center <?= $c[3] ?> <?= $c[4] ?> group-hover:text-white transition-colors"><span class="material-symbols-outlined text-[26px]"><?= site_h(site_setting($S, "social{$i}_icon", 'public')) ?></span></div>
        <div>
          <div class="font-headline-md text-base text-slate-900 font-serif font-bold"><?= site_h(site_setting($S, "social{$i}_title", '')) ?></div>
          <div class="font-label-sm text-label-sm text-slate-500 mt-0.5"><?= site_h(site_setting($S, "social{$i}_desc", '')) ?></div>
        </div>
      </a>
      <?php endfor; ?>
    </div>
  </div>
</section>

</div>
</main>

<!-- ===================== Footer ===================== -->
<footer class="w-full bg-slate-100/90 text-slate-600 border-t border-slate-200">
  <div class="max-w-7xl mx-auto px-6 lg:px-12 py-12 flex flex-col gap-8">
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
      <div class="flex items-center gap-4">
        <span class="font-headline-xl text-2xl text-slate-900 font-serif font-bold tracking-widest"><?= site_h(site_setting($S, 'brand_name', '踏雪笑傲')) ?></span>
        <div class="h-6 w-px bg-slate-300 hidden sm:block"></div>
        <span class="font-body-sm text-body-sm text-slate-500"><?= site_h(site_setting($S, 'footer_poem', '')) ?></span>
      </div>
      <div class="flex flex-wrap gap-6 font-label-md text-label-md">
        <?php for ($i = 1; $i <= 5; $i++): $fl = site_setting($S, "footer_link{$i}_label", ''); if ($fl === '') { continue; } ?>
          <a class="text-slate-600 hover:text-rose-700 transition-colors" href="<?= site_h(site_setting($S, "footer_link{$i}_url", '#')) ?>"><?= site_h($fl) ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 text-body-sm font-body-sm pt-6 border-t border-slate-200/80">
      <div class="flex items-center gap-4">
        <div class="px-2 py-0.5 bg-slate-200 text-slate-700 font-label-sm text-[10px] font-bold rounded-[2px]"><?= site_h(site_setting($S, 'footer_age_badge', '輔 15 級')) ?></div>
        <p class="text-slate-500 text-xs"><?= site_h(site_setting($S, 'footer_notice', '')) ?></p>
      </div>
      <div class="text-slate-400 font-label-sm text-xs">© <?= date('Y') ?> <?= site_h(site_setting($S, 'footer_copyright', '')) ?></div>
    </div>
  </div>
</footer>

<!-- ===================== 宣傳影片彈出視窗 ===================== -->
<div class="pv-modal" id="pvModal" aria-hidden="true">
  <div class="pv-modal__bd" onclick="closePv()"></div>
  <div class="pv-modal__dlg" role="dialog" aria-modal="true" aria-label="宣傳影片">
    <button type="button" class="pv-modal__close" onclick="closePv()" aria-label="關閉">&times;</button>
    <?php if ($pvEmbedId !== ''): ?>
      <iframe id="pvFrame" data-src="https://www.youtube.com/embed/<?= htmlspecialchars($pvEmbedId, ENT_QUOTES, 'UTF-8') ?>?autoplay=1&rel=0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen title="踏雪笑傲宣傳影片"></iframe>
    <?php else: ?>
      <div class="pv-placeholder">
        <span class="material-symbols-outlined">movie</span>
        <div class="font-serif text-2xl font-bold tracking-widest text-amber-100">踏雪笑傲 · 官方宣傳影片</div>
        <p class="font-body-sm text-slate-400 max-w-md px-6">高畫質宣傳影片即將上線，敬請期待。</p>
        <a href="<?= site_h(site_setting($S, 'cta1_url', 'download.php')) ?>" class="mt-2 inline-flex items-center gap-2 px-5 py-2.5 rounded-[2px] bg-gradient-to-r from-rose-800 via-rose-700 to-red-600 text-white font-serif text-sm font-bold tracking-widest hover:brightness-110 transition-all">
          <span class="material-symbols-outlined text-[18px]">download</span>搶先下載遊戲
        </a>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
/* ---------- 公告分類篩選 ---------- */
function filterTab(category, btn) {
  const buttons = document.querySelectorAll('.news-tab-btn');
  buttons.forEach(b => {
    b.classList.remove('bg-rose-700', 'text-white', 'font-semibold', 'shadow-sm');
    b.classList.add('text-slate-600', 'font-medium');
  });
  btn.classList.remove('text-slate-600', 'font-medium');
  btn.classList.add('bg-rose-700', 'text-white', 'font-semibold', 'shadow-sm');

  let visible = 0;
  document.querySelectorAll('#news-feed-container .news-item').forEach(item => {
    const show = (category === 'all') || item.classList.contains(category);
    item.style.display = show ? 'flex' : 'none';
    if (show) { visible++; }
  });

  const empty = document.getElementById('news-empty');
  if (empty) {
    empty.classList.toggle('hidden', visible > 0);
    empty.classList.toggle('flex', visible === 0);
  }
}

/* ---------- 宣傳影片彈出視窗 ---------- */
function openPv() {
  const m = document.getElementById('pvModal');
  const f = document.getElementById('pvFrame');
  if (f && !f.src) { f.src = f.dataset.src; }
  if (m) { m.classList.add('is-open'); m.setAttribute('aria-hidden', 'false'); }
}
function closePv() {
  const m = document.getElementById('pvModal');
  const f = document.getElementById('pvFrame');
  if (m) { m.classList.remove('is-open'); m.setAttribute('aria-hidden', 'true'); }
  if (f) { f.src = ''; }
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closePv(); });

/* ---------- 雪花與梅瓣粒子 ---------- */
(function () {
  const canvas = document.getElementById('snow-petals-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  let width, height;
  let particles = [];
  const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) return;

  function resize() {
    width = canvas.width = canvas.parentElement.offsetWidth;
    height = canvas.height = canvas.parentElement.offsetHeight;
  }
  window.addEventListener('resize', resize);
  resize();

  const PARTICLE_COUNT = Math.min(44, Math.floor(width / 34) + 16);
  for (let i = 0; i < PARTICLE_COUNT; i++) {
    const isPetal = Math.random() > 0.65;
    particles.push({
      x: Math.random() * width, y: Math.random() * height,
      radius: isPetal ? (Math.random() * 2.8 + 2) : (Math.random() * 1.8 + 0.8),
      speedY: Math.random() * 0.9 + 0.5, speedX: Math.random() * 1.2 + 0.4,
      opacity: Math.random() * 0.6 + 0.25, isPetal: isPetal,
      angle: Math.random() * Math.PI * 2, spin: (Math.random() - 0.5) * 0.03
    });
  }

  function draw() {
    ctx.clearRect(0, 0, width, height);
    for (let i = 0; i < particles.length; i++) {
      const p = particles[i];
      ctx.save();
      ctx.translate(p.x, p.y);
      ctx.rotate(p.angle);
      if (p.isPetal) {
        ctx.fillStyle = `rgba(190, 18, 60, ${p.opacity})`;
        ctx.beginPath(); ctx.ellipse(0, 0, p.radius * 1.8, p.radius, 0, 0, Math.PI * 2); ctx.fill();
      } else {
        ctx.fillStyle = `rgba(255, 255, 255, ${p.opacity})`;
        ctx.shadowColor = 'rgba(186, 230, 253, 0.6)'; ctx.shadowBlur = 4;
        ctx.beginPath(); ctx.arc(0, 0, p.radius, 0, Math.PI * 2); ctx.fill();
      }
      ctx.restore();
      p.y += p.speedY; p.x += p.speedX; p.angle += p.spin;
      if (p.y > height) { p.y = -10; p.x = Math.random() * width * 1.1 - width * 0.1; }
      if (p.x > width) { p.x = -10; }
    }
    requestAnimationFrame(draw);
  }
  requestAnimationFrame(draw);
})();
</script>
</body>
</html>

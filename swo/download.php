<?php
/**
 * =============================================================
 *  踏雪笑傲 · 客戶端下載中心 — 公開前台
 *  資料來源：獨立資料庫 swo_download（見 download_schema.sql）
 *  功能：版本資訊、主程式下載、備用分流、多平台、配備需求、FAQ
 * =============================================================
 */
require_once __DIR__ . '/../session_bootstrap.php';
app_session_start(['remember' => true]);
require_once __DIR__ . '/../download_config.php';

$db = download_db();
$SET = dl_settings_all($db);

$platforms   = dl_platforms($db, true);
$pcRelease   = dl_current_release($db, 'pc');
$pcMirrors   = $pcRelease ? dl_mirrors($db, (int)$pcRelease['id']) : [];
$requirements = dl_requirements($db);
$faqs        = dl_faqs($db);

$showServer = (($SET['show_server_status'] ?? '1') !== '0');
$showMirrors = (($SET['show_mirrors'] ?? '1') !== '0');
$showReq    = (($SET['show_requirements'] ?? '1') !== '0');
$showFaq    = (($SET['show_faq'] ?? '1') !== '0');

$heroBadge  = $SET['hero_badge'] ?? '官方客戶端發行中心 · CLIENT DISTRIBUTION';
$heroTitle  = $SET['hero_title'] ?? '踏雪啟程 · 客戶端下載';
$heroBlurb  = $SET['hero_subtitle'] ?? '沉浸體驗 4K 墨韻江湖，支援 PC 電腦端高速下載與行動雙平台跨端互通。';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<meta content="web_standard" name="shell-type">
<title><?= dl_h($heroTitle) ?> · 踏雪笑傲</title>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400..800;1,400..800&amp;family=Manrope:wght@300;400;500;600;700;800&amp;family=Noto+Serif+TC:wght@400;600;700;900&amp;family=Be+Vietnam+Pro:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/base.css?v=1">
 <link rel="stylesheet" href="assets/css/download.css?v=2">
<link rel="stylesheet" href="assets/css/download-tailwind.css?v=1">
<link rel="stylesheet" href="assets/css/site-ui.css?v=5">
<link rel="stylesheet" href="assets/css/swo-theme.css?v=1">
<script src="assets/site-ui.js?v=3" defer></script>
</head>
<body class="bg-[#f8fafc] text-on-background font-body-md text-body-md antialiased selection:bg-primary-container selection:text-white relative overflow-x-hidden">
<div aria-hidden="true" class="fixed inset-0 pointer-events-none z-0 overflow-hidden">
  <div class="snowflake w-2 h-2 opacity-60 left-[10%]" style="animation: snowfall 12s linear infinite;"></div>
  <div class="snowflake w-1.5 h-1.5 opacity-50 left-[25%]" style="animation: snowfall 16s 3s linear infinite;"></div>
  <div class="snowflake w-2.5 h-2.5 opacity-70 left-[45%]" style="animation: snowfall 10s 1s linear infinite;"></div>
  <div class="snowflake w-1 h-1 opacity-40 left-[68%]" style="animation: snowfall 14s 5s linear infinite;"></div>
  <div class="snowflake w-2 h-2 opacity-60 left-[85%]" style="animation: snowfall 11s 2s linear infinite;"></div>
  <div class="snowflake w-1.5 h-1.5 opacity-50 left-[92%]" style="animation: snowfall 15s 4s linear infinite;"></div>
</div>

<header class="fixed top-0 left-0 w-full z-50 bg-white/90 backdrop-blur-xl border-b border-sky-100/80 shadow-[0_4px_20px_rgba(15,23,42,0.03)]">
  <div class="h-20 max-w-7xl mx-auto px-6 lg:px-12 flex items-center justify-between gap-gutter">
    <a class="flex items-center gap-space-sm focus:outline-none shrink-0" href="index.php">
      <span class="swo-brand-mark" aria-hidden="true">傲</span>
      <span class="flex flex-col tracking-wider">
        <span class="flex items-center gap-space-xs whitespace-nowrap">
          <span class="font-headline-lg text-headline-lg text-[#0f172a] tracking-widest leading-none whitespace-nowrap">踏雪笑傲</span>
          <span class="font-label-sm text-label-sm px-space-xs py-0.5 bg-primary-container text-white font-semibold whitespace-nowrap">正宗武俠</span>
        </span>
        <span class="font-label-sm text-label-sm text-slate-500 tracking-[0.25em] uppercase font-medium whitespace-nowrap">SNOW WANDERER ONLINE</span>
      </span>
    </a>
    <nav class="site-main-nav hidden xl:flex items-center gap-1 shrink-0">
      <a class="swo-nav-link" href="index.php">官網首頁</a>
      <a class="swo-nav-link" href="news.php">最新公告</a>
      <a class="swo-nav-link" href="events.php">江湖活動</a>
      <a class="swo-nav-link is-active" aria-current="page" href="download.php">遊戲下載</a>
      <a class="swo-nav-link" href="../home.php">會員中心</a>
    </nav>
    <div class="flex items-center gap-space-md shrink-0">
      <?php if ($showServer): ?>
      <div class="sau-status hidden md:flex items-center gap-space-xs px-3 py-1 bg-sky-50 border border-sky-200/60 rounded">
        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
        <span class="font-label-sm text-label-sm text-secondary font-medium"><?= dl_h($SET['server_status_value'] ?? '全線路極速連線中') ?></span>
      </div>
      <?php endif; ?>
      <a class="hidden md:inline-flex items-center justify-center px-space-md py-2 bg-gradient-to-r from-[#95002a] to-[#be123c] text-white font-label-md text-label-md tracking-widest uppercase hover:from-[#be123c] hover:to-[#e11d48] shadow-sm transition-all whitespace-nowrap shrink-0" href="#mainDownloadBtn">立即下載</a>
      <?php include __DIR__ . '/site_auth.php'; ?>
    </div>
  </div>
</header>

<main class="w-full pt-20 relative z-10">

  <!-- Ambient Glow & Watermark -->
  <div class="relative w-full overflow-hidden">
    <div class="absolute -top-36 left-1/2 -translate-x-1/2 w-[850px] h-[340px] bg-sky-200/30 blur-[140px] pointer-events-none -z-10"></div>
    <div class="absolute top-12 right-6 lg:right-16 text-slate-200 font-headline-2xl text-4xl lg:text-5xl select-none pointer-events-none [writing-mode:vertical-rl] tracking-[0.6em] font-serif opacity-80">霜雪覆刃 · 意存天地</div>

    <!-- Section 1: Hero -->
    <section class="max-w-7xl mx-auto px-6 lg:px-12 pt-10 pb-12 w-full">
      <div class="flex flex-col items-start gap-4">
        <div class="inline-flex items-center gap-2 px-3 py-1 bg-white border border-sky-200/60 shadow-sm text-secondary font-label-sm text-xs tracking-[0.2em] uppercase rounded-sm">
          <span class="inline-block w-1.5 h-1.5 bg-secondary rounded-full"></span>
          <?= dl_h($heroBadge) ?>
        </div>
        <div class="flex flex-col lg:flex-row lg:items-end justify-between w-full gap-6">
          <div>
            <h1 class="font-headline-2xl text-4xl lg:text-5xl text-[#0f172a] font-bold tracking-wider" style="font-family:'Noto Serif TC', serif;"><?= dl_h($heroTitle) ?></h1>
            <p class="font-body-lg text-body-lg text-slate-600 max-w-3xl mt-3 font-normal"><?= dl_h($heroBlurb) ?></p>
          </div>
          <?php if ($showServer): ?>
          <div class="flex items-center gap-3 bg-white/90 border border-sky-100 shadow-sm px-4 py-2.5 rounded-sm">
            <span class="material-symbols-outlined text-secondary text-[22px]">cloud_sync</span>
            <div class="flex flex-col">
              <span class="font-label-sm text-xs text-slate-400 tracking-wider font-semibold"><?= dl_h($SET['server_status_label'] ?? '全球伺服器狀態') ?></span>
              <span class="font-body-sm text-sm text-[#0f172a] font-semibold"><?= dl_h($SET['server_status_value'] ?? '全線路極速連線中') ?></span>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>

  <!-- Section 2: Primary PC Client Download -->
  <section class="max-w-7xl mx-auto px-6 lg:px-12 py-8 w-full">
    <div class="relative bg-white/95 border border-sky-100/90 shadow-2xl shadow-slate-200/70 p-8 lg:p-12 overflow-hidden rounded-md backdrop-blur-sm">
      <div class="absolute -bottom-24 -right-24 w-96 h-96 bg-sky-100/60 blur-[90px] pointer-events-none"></div>
      <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-sky-400 via-rose-500 to-sky-400"></div>

      <?php if ($pcRelease): ?>
      <!-- Metadata -->
      <div class="flex flex-wrap items-center justify-between gap-4 pb-6 mb-8 bg-slate-50/80 border border-slate-200/60 p-5 rounded">
        <div class="flex flex-wrap items-center gap-6">
          <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-secondary text-[20px]">verified</span>
            <span class="font-label-md text-xs text-slate-500 uppercase tracking-wider font-semibold"><?= dl_h($pcRelease['badge'] ?: '正式版本') ?></span>
            <span class="font-headline-md text-lg text-secondary font-bold font-mono"><?= dl_h($pcRelease['version']) ?></span>
          </div>
          <div class="h-4 w-px bg-slate-300 hidden sm:block"></div>
          <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-slate-400 text-[20px]">folder_zip</span>
            <span class="font-label-md text-xs text-slate-500">檔案大小</span>
            <span class="font-body-md text-slate-800 font-semibold"><?= dl_h($pcRelease['size_label']) ?></span>
          </div>
          <div class="h-4 w-px bg-slate-300 hidden sm:block"></div>
          <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-slate-400 text-[20px]">calendar_today</span>
            <span class="font-label-md text-xs text-slate-500">最新發行</span>
            <span class="font-body-md text-slate-800"><?= dl_h($pcRelease['release_date'] ? date('Y-m-d', strtotime($pcRelease['release_date'])) : '—') ?></span>
          </div>
        </div>
        <div class="flex items-center gap-2 bg-emerald-50 text-emerald-800 border border-emerald-200/80 px-3 py-1 font-label-sm text-xs rounded-sm">
          <span class="material-symbols-outlined text-[16px] text-emerald-600">security</span>
          <?= dl_h($SET['security_badge'] ?? '數位簽章已通過 · 無毒無外掛保證') ?>
        </div>
      </div>

      <!-- Core Layout -->
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
        <div class="lg:col-span-5 relative h-72 lg:h-80 overflow-hidden bg-slate-100 rounded border border-slate-200/80 shadow-inner">
          <?php if (!empty($pcRelease['cover_image'])): ?>
            <img class="w-full h-full object-cover" alt="<?= dl_h($pcRelease['cover_caption'] ?: $pcRelease['version']) ?>" src="<?= dl_h($pcRelease['cover_image']) ?>">
          <?php endif; ?>
          <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-900/20 to-transparent"></div>
          <div class="absolute bottom-4 left-4 right-4 flex items-center justify-between">
            <div class="flex flex-col">
              <span class="font-label-sm text-xs text-sky-300 tracking-widest uppercase font-semibold"><?= dl_h($pcRelease['cover_engine']) ?></span>
              <span class="font-headline-md text-lg text-white font-medium" style="font-family:'Noto Serif TC', serif;"><?= dl_h($pcRelease['cover_caption']) ?></span>
            </div>
            <span class="font-label-sm text-xs px-2.5 py-1 bg-white/20 backdrop-blur-sm text-white rounded">4K 60FPS UHD</span>
          </div>
        </div>

        <div class="lg:col-span-7 flex flex-col gap-6">
          <div>
            <span class="font-label-sm text-xs text-secondary font-bold tracking-[0.25em] uppercase"><?= dl_h($SET['installer_kicker'] ?? 'RECOMMENDED INSTALLER') ?></span>
            <h2 class="font-headline-xl text-2xl lg:text-3xl font-bold text-[#0f172a] mt-1" style="font-family:'Noto Serif TC', serif;"><?= dl_h($SET['installer_title'] ?? '官方旗艦微端高速安裝器') ?></h2>
            <p class="font-body-md text-slate-600 mt-2"><?= dl_h($SET['installer_desc'] ?? '') ?></p>
          </div>

          <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
            <a class="flex-1 inline-flex items-center justify-center gap-3 px-8 py-4 bg-gradient-to-r from-[#95002a] to-[#be123c] hover:from-[#be123c] hover:to-[#e11d48] text-white transition-all shadow-lg shadow-rose-900/20 text-center font-label-md text-sm font-semibold tracking-widest uppercase rounded" href="download_go.php?id=<?= (int)$pcRelease['id'] ?>" id="mainDownloadBtn">
              <span class="material-symbols-outlined text-[24px]">download</span>
              <span><?= dl_h($pcRelease['installer_label'] ?: '立即下載完整客戶端') ?></span>
            </a>
            <button class="px-5 py-4 bg-white border border-slate-200 hover:bg-slate-50 text-secondary transition-colors font-label-md text-sm font-semibold tracking-wider flex items-center justify-center gap-2 shadow-sm rounded" id="speedTestBtn">
              <span class="material-symbols-outlined text-[18px]">speed</span>
              <span>測速診斷</span>
            </button>
          </div>

          <?php if ($showMirrors && !empty($pcMirrors)): ?>
          <div class="flex flex-col gap-3 pt-2">
            <span class="font-label-sm text-xs text-slate-500 tracking-wider uppercase font-semibold">備用分流載點 (MIRROR SERVERS)</span>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <?php foreach ($pcMirrors as $m): ?>
                <a class="flex items-center justify-between p-3.5 bg-slate-50/90 hover:bg-sky-50/70 border border-slate-200/80 hover:border-sky-300 transition-all rounded shadow-sm" href="<?= dl_h($m['url'] ?: '#') ?>" target="_blank" rel="noopener">
                  <div class="flex flex-col">
                    <span class="font-body-sm text-sm text-[#0f172a] font-semibold"><?= dl_h($m['label']) ?></span>
                    <span class="font-label-sm text-xs text-slate-500"><?= dl_h($m['sub_label']) ?></span>
                  </div>
                  <span class="material-symbols-outlined text-secondary text-[18px]"><?= dl_h($m['icon'] ?: 'open_in_new') ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (trim((string)$pcRelease['sha256']) !== ''): ?>
      <div class="mt-8 pt-6 bg-slate-50/90 border border-slate-200/80 rounded p-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div class="flex items-center gap-3 overflow-hidden w-full md:w-auto">
          <span class="material-symbols-outlined text-secondary text-[20px] flex-shrink-0">tag</span>
          <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 truncate">
            <span class="font-label-sm text-xs text-slate-500 uppercase font-semibold flex-shrink-0">SHA-256 校驗碼</span>
            <code class="font-body-sm text-xs sm:text-sm text-slate-800 font-mono truncate select-all bg-white px-2 py-0.5 border border-slate-200 rounded" id="shaHash"><?= dl_h($pcRelease['sha256']) ?></code>
          </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 self-end md:self-auto">
          <span class="font-label-sm text-xs text-emerald-600 font-medium hidden" id="copyFeedback">已複製到剪貼簿</span>
          <button class="px-3.5 py-1.5 bg-white hover:bg-slate-100 border border-slate-200 text-[#0f172a] font-label-sm text-xs font-semibold tracking-wider flex items-center gap-1 transition-colors rounded shadow-sm" id="copyHashBtn">
            <span class="material-symbols-outlined text-[16px] text-slate-500">content_copy</span>一鍵複製校驗碼
          </button>
        </div>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div class="p-10 text-center text-slate-500">目前尚無可下載的電腦端版本，請稍後再試。</div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Section 3: Multi-Platform -->
  <?php if (!empty($platforms)): ?>
  <section class="max-w-7xl mx-auto px-6 lg:px-12 py-10 w-full">
    <div class="flex flex-col gap-2 mb-8">
      <div class="inline-flex items-center gap-2"><span class="w-6 h-0.5 bg-secondary"></span><span class="font-label-sm text-xs text-secondary font-bold tracking-[0.2em] uppercase">MULTI-PLATFORM CROSS-PLAY</span></div>
      <h2 class="font-headline-xl text-3xl font-bold text-[#0f172a]" style="font-family:'Noto Serif TC', serif;">多平台跨端互通 · 一指乾坤</h2>
      <p class="font-body-md text-slate-600">無論身處何地，皆能以同一帳號登入江湖，資料跨端同步。</p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
      <?php foreach ($platforms as $p): $th = dl_accent_theme($p['accent']); $rel = dl_current_release($db, $p['code']); ?>
        <div class="bg-white/95 border border-slate-200/90 rounded-lg p-5 shadow-sm hover:shadow-lg hover:border-sky-300 transition-all flex flex-col gap-4">
          <div class="flex items-center gap-3">
            <span class="w-11 h-11 rounded-xl bg-gradient-to-br <?= dl_h($th['grad']) ?> text-white flex items-center justify-center shadow-md flex-none">
              <span class="material-symbols-outlined text-[22px]"><?= dl_h($p['icon'] ?: 'devices') ?></span>
            </span>
            <div class="min-w-0">
              <div class="font-headline-md text-lg font-bold text-[#0f172a]"><?= dl_h($p['name']) ?></div>
              <div class="font-label-sm text-[10px] text-slate-400 tracking-[0.18em] uppercase font-bold"><?= dl_h($p['name_en']) ?></div>
            </div>
          </div>
          <div class="flex items-center justify-between text-xs text-slate-500">
            <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">devices</span><?= dl_h($p['tagline']) ?></span>
            <?php if ($rel): ?><span class="font-mono text-secondary font-bold"><?= dl_h($rel['version']) ?></span><?php endif; ?>
          </div>
          <?php if ($rel): ?>
            <a href="download_go.php?id=<?= (int)$rel['id'] ?>" target="_blank" rel="noopener" class="mt-auto inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded bg-slate-900 hover:bg-slate-800 text-white font-label-sm text-xs font-semibold tracking-wider transition-colors">
              <span class="material-symbols-outlined text-[18px]">download</span><?= dl_h($rel['installer_label'] ?: '立即下載') ?>
            </a>
          <?php else: ?>
            <span class="mt-auto text-center text-xs text-slate-400 py-2.5 border border-dashed border-slate-300 rounded">即將推出</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Section 4: System Requirements -->
  <?php if ($showReq && !empty($requirements)): ?>
  <section class="max-w-7xl mx-auto px-6 lg:px-12 py-10 w-full">
    <div class="flex flex-col gap-2 mb-8">
      <div class="inline-flex items-center gap-2"><span class="w-6 h-0.5 bg-secondary"></span><span class="font-label-sm text-xs text-secondary font-bold tracking-[0.2em] uppercase">HARDWARE BENCHMARK</span></div>
      <h2 class="font-headline-xl text-3xl font-bold text-[#0f172a]" style="font-family:'Noto Serif TC', serif;"><?= dl_h($SET['requirements_title'] ?? '電腦配備需求 · 鑑機撫琴') ?></h2>
      <p class="font-body-md text-slate-600"><?= dl_h($SET['requirements_subtitle'] ?? '') ?></p>
    </div>
    <div class="overflow-x-auto shadow-lg shadow-slate-200/60 rounded-md border border-slate-200/90">
      <div class="min-w-[720px] bg-white flex flex-col">
        <div class="grid grid-cols-12 bg-slate-100/90 border-b border-slate-200 p-4 text-[#0f172a] font-label-md text-xs font-bold tracking-widest uppercase">
          <div class="col-span-3 flex items-center gap-2"><span class="material-symbols-outlined text-secondary text-[18px]">tune</span>硬體配置項目</div>
          <div class="col-span-4 flex items-center gap-2 text-slate-700"><span class="w-2 h-2 bg-slate-400 rounded-full"></span>最低配置 (1080P / 30FPS)</div>
          <div class="col-span-5 flex items-center gap-2 text-secondary font-bold"><span class="w-2 h-2 bg-secondary rounded-full"></span>推薦配置 (4K UHD / 60FPS 極致光影)</div>
        </div>
        <?php foreach ($requirements as $i => $r): ?>
          <div class="grid grid-cols-12 p-4 items-center <?= $i % 2 === 0 ? 'bg-white' : 'bg-[#f8fafc]' ?> hover:bg-sky-50/40 transition-colors <?= $i < count($requirements) - 1 ? 'border-b border-slate-100' : '' ?>">
            <div class="col-span-3 font-body-sm text-slate-500 font-medium flex items-center gap-2"><span class="material-symbols-outlined text-slate-400 text-[18px]"><?= dl_h($r['icon'] ?: 'memory') ?></span><?= dl_h($r['label']) ?></div>
            <div class="col-span-4 font-body-md text-slate-800"><?= dl_h($r['min_value']) ?></div>
            <div class="col-span-5 font-body-md text-secondary font-semibold"><?= dl_h($r['rec_value']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (trim((string)($SET['requirements_tip'] ?? '')) !== ''): ?>
    <div class="mt-4 p-4 bg-white border border-sky-100 rounded flex items-center gap-3 shadow-sm">
      <span class="material-symbols-outlined text-amber-600 text-[20px] flex-shrink-0">lightbulb</span>
      <span class="font-body-sm text-sm text-slate-600"><?= dl_h($SET['requirements_tip']) ?></span>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- Section 5: FAQ -->
  <?php if ($showFaq && !empty($faqs)): ?>
  <section class="max-w-7xl mx-auto px-6 lg:px-12 py-10 pb-20 w-full">
    <div class="flex flex-col gap-3 mb-10">
      <div class="inline-flex items-center gap-2"><span class="w-6 h-0.5 bg-secondary"></span><span class="font-label-sm text-xs text-secondary font-bold tracking-[0.2em] uppercase">TROUBLESHOOTING &amp; RUNTIME SUITE</span></div>
      <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
          <h2 class="font-headline-xl text-3xl lg:text-4xl font-bold text-[#0f172a]" style="font-family:'Noto Serif TC', serif;"><?= dl_h($SET['faq_title'] ?? '疑難排解 · 鑑機撫琴修復指引') ?></h2>
          <p class="font-body-md text-slate-600 mt-2"><?= dl_h($SET['faq_subtitle'] ?? '') ?></p>
        </div>
        <div class="flex items-center gap-2 text-xs font-label-sm text-slate-500 bg-white border border-slate-200/80 px-4 py-2 rounded shadow-sm flex-shrink-0">
          <span class="material-symbols-outlined text-secondary text-[18px]">verified</span><span>技術維護團隊即時編修</span>
          <span class="text-slate-300">|</span><span class="font-mono font-medium text-slate-700"><?= dl_h($pcRelease['version'] ?? 'v1.0.0') ?> 專用庫</span>
        </div>
      </div>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
      <div class="lg:col-span-12 flex flex-col gap-4">
        <?php foreach ($faqs as $f): ?>
          <div class="bg-white border border-slate-200/90 rounded-md p-6 shadow-sm hover:border-sky-300 transition-all cursor-pointer faq-item">
            <div class="flex items-start justify-between gap-4">
              <div class="flex items-start gap-3">
                <span class="px-2 py-0.5 bg-rose-50 text-rose-700 border border-rose-200 text-xs font-label-sm font-bold rounded flex-shrink-0 mt-0.5"><?= dl_h($f['tag']) ?></span>
                <div>
                  <h3 class="font-headline-md text-base sm:text-lg text-[#0f172a] font-bold"><?= dl_h($f['question']) ?></h3>
                  <?php if (trim((string)$f['note']) !== ''): ?><p class="font-body-sm text-xs text-slate-500 mt-1"><?= dl_h($f['note']) ?></p><?php endif; ?>
                </div>
              </div>
              <span class="material-symbols-outlined text-slate-400 text-[22px] transition-transform duration-200 faq-chevron flex-shrink-0 mt-1">expand_more</span>
            </div>
            <div class="mt-5 pt-5 border-t border-slate-100 bg-slate-50/70 p-5 font-body-md text-slate-600 rounded faq-content hidden"><?= $f['answer'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>
</main>

<footer class="w-full bg-white border-t border-slate-200 text-slate-500 relative z-20">
  <div class="max-w-7xl mx-auto px-6 lg:px-12 py-space-xl flex flex-col gap-space-lg">
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-gutter">
      <div class="flex items-center gap-space-md">
        <span class="font-headline-xl text-2xl text-[#0f172a] font-bold tracking-widest" style="font-family:'Noto Serif TC', serif;">踏雪笑傲</span>
        <div class="h-6 w-px bg-slate-300 hidden sm:block"></div>
        <span class="font-body-sm text-xs text-slate-500">霜刃未曾試，今日把示君。天下風雲出我輩，一入江湖歲月催。</span>
      </div>
      <div class="flex flex-wrap gap-space-md font-label-md text-xs">
        <a class="text-slate-600 hover:text-primary transition-colors" href="#">客服中心</a>
        <a class="text-slate-600 hover:text-primary transition-colors" href="#">服務條款</a>
        <a class="text-slate-600 hover:text-primary transition-colors" href="#">隱私權政策</a>
        <a class="text-slate-600 hover:text-primary transition-colors" href="#">家長監護</a>
        <a class="text-slate-600 hover:text-primary transition-colors" href="#">社群交流</a>
      </div>
    </div>
    <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-space-md text-xs font-body-sm">
      <div class="flex items-center gap-space-md">
        <div class="px-2 py-0.5 bg-rose-50 text-rose-700 border border-rose-200 font-label-sm text-xs font-bold rounded">輔 15 級</div>
        <p class="text-slate-500">本遊戲情節涉及性、暴力、虛擬戀愛或結婚。注意使用時間，避免沉迷於遊戲。遊戲部分內容須另行支付費用。</p>
      </div>
      <div class="text-slate-400 font-label-sm text-xs">© <?= date('Y') ?> SNOW WANDERER ONLINE. 踏雪笑傲工作室 版權所有. All Rights Reserved.</div>
    </div>
  </div>
</footer>

<script>
(function () {
  // FAQ Accordion
  const faqItems = document.querySelectorAll('.faq-item');
  faqItems.forEach(item => {
    item.addEventListener('click', () => {
      const content = item.querySelector('.faq-content');
      const chevron = item.querySelector('.faq-chevron');
      const isHidden = content.classList.contains('hidden');
      faqItems.forEach(other => {
        if (other !== item) {
          const oc = other.querySelector('.faq-content');
          const och = other.querySelector('.faq-chevron');
          if (oc) oc.classList.add('hidden');
          if (och) och.classList.remove('rotate-180');
        }
      });
      if (isHidden) { content.classList.remove('hidden'); chevron.classList.add('rotate-180'); }
      else { content.classList.add('hidden'); chevron.classList.remove('rotate-180'); }
    });
  });

  // Copy SHA-256
  const copyBtn = document.getElementById('copyHashBtn');
  const shaHash = document.getElementById('shaHash');
  const copyFeedback = document.getElementById('copyFeedback');
  if (copyBtn && shaHash) {
    copyBtn.addEventListener('click', () => {
      const text = shaHash.textContent.trim();
      const done = () => { if (copyFeedback) { copyFeedback.classList.remove('hidden'); setTimeout(() => copyFeedback.classList.add('hidden'), 3000); } };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(() => {});
      } else {
        const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(ta);
      }
    });
  }

  // Speed test simulation
  const speedTestBtn = document.getElementById('speedTestBtn');
  if (speedTestBtn) {
    speedTestBtn.addEventListener('click', () => {
      if (speedTestBtn.dataset.busy === '1') return;
      speedTestBtn.dataset.busy = '1';
      const originalHTML = speedTestBtn.innerHTML;
      speedTestBtn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">sync</span><span>測速中...</span>';
      setTimeout(() => {
        speedTestBtn.innerHTML = '<span class="material-symbols-outlined text-[18px] text-emerald-600">check_circle</span><span class="text-secondary font-bold">延遲 12ms · 94 MB/s</span>';
        setTimeout(() => { speedTestBtn.innerHTML = originalHTML; speedTestBtn.dataset.busy = '0'; }, 3500);
      }, 1200);
    });
  }
})();
</script>
</body>
</html>

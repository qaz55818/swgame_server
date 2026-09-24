<?php
/**
 * =============================================================
 *  踏雪笑傲 · 江湖盛典（活動）— 公開前台
 *  資料來源：獨立資料庫 swo_events（見 events_schema.sql）
 *  功能：活動列表、分類頁籤、精選主打、獎勵矩陣、倒數計時、
 *        單一活動檢視、注意事項（規則）
 * =============================================================
 */
require_once __DIR__ . '/../session_bootstrap.php';
app_session_start(['remember' => true]);
require_once __DIR__ . '/../events_config.php';

$db = events_db();
$SET = ev_settings_all($db);
$categories = ev_categories($db, true);
$catMap = ev_category_map($db);
$catCounts = ev_category_counts($db);
$totalEvents = array_sum($catCounts);
$ongoingCount = $catCounts['ongoing'] ?? 0;

$showStats = (($SET['show_stats'] ?? '1') !== '0');
$showRules = (($SET['show_rules'] ?? '1') !== '0');
$showAssist = (($SET['show_assist'] ?? '1') !== '0');

/* 單一活動檢視 */
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$event = null;
$eventRewards = [];
if ($viewId > 0) {
    $event = ev_event($db, $viewId);
    if ($event) {
        $eventRewards = ev_rewards($db, $viewId);
        if (empty($_SESSION['ev_viewed']) || !is_array($_SESSION['ev_viewed'])) { $_SESSION['ev_viewed'] = []; }
        if (!in_array($viewId, $_SESSION['ev_viewed'], true)) {
            $db->query("UPDATE `ev_events` SET `view_count` = `view_count` + 1 WHERE `id` = " . (int)$viewId);
            $event['view_count'] = (int)$event['view_count'] + 1;
            $_SESSION['ev_viewed'][] = $viewId;
        }
    }
}

/* 分類篩選 */
$filterCat = trim((string)($_GET['cat'] ?? ''));
if ($filterCat !== '' && !isset($catMap[$filterCat])) { $filterCat = ''; }

$featured = ($filterCat === '') ? ev_featured($db) : null;
$featuredRewards = $featured ? ev_rewards($db, (int)$featured['id']) : [];
$events = ev_events($db, $filterCat !== '' ? $filterCat : null, true);
$notes = ev_notes($db);

$heroKicker = $SET['hero_kicker'] ?? 'SNOW WANDERER GRAND CAMPAIGNS';
$heroSeason = $SET['hero_season'] ?? '季春 · 破曉盛會';
$heroTitle  = $SET['hero_title'] ?? '風雲際會 · 江湖盛典';
$heroBlurb  = $SET['hero_subtitle'] ?? '';

/* 計算剩餘天數 */
function ev_remaining(?string $dt): ?string
{
    if (empty($dt)) { return null; }
    $ts = strtotime($dt);
    if ($ts === false || $ts <= time()) { return null; }
    $d = (int)floor(($ts - time()) / 86400);
    return $d > 0 ? ('僅剩 ' . $d . ' 天') : '即將截止';
}
?>
<!DOCTYPE html>
<html class="light" lang="zh-TW">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<meta content="web_standard" name="shell-type">
<title><?= ev_h($event ? $event['title'] . ' · ' . $heroTitle : $heroTitle) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400..800;1,400..800&amp;family=Manrope:wght@300;400;500;600;700;800&amp;family=Noto+Serif+TC:wght@400;600;700;900&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/base.css?v=1">
<link rel="stylesheet" href="assets/css/events.css?v=1">
<link rel="stylesheet" href="assets/css/events-tailwind.css?v=1">
<link rel="stylesheet" href="assets/css/site-ui.css?v=5">
<link rel="stylesheet" href="assets/css/swo-theme.css?v=1">
<script src="assets/site-ui.js?v=3" defer></script>
</head>
<body class="bg-[#f8fafc] text-on-background font-body-md text-body-md antialiased selection:bg-primary-container selection:text-white">

<!-- 頂部導覽 -->
<header class="fixed top-0 left-0 w-full z-50 bg-white/90 backdrop-blur-xl border-b border-slate-200/70 shadow-[0_4px_20px_rgba(15,23,42,0.04)]">
  <div class="h-20 max-w-7xl mx-auto px-6 lg:px-12 flex items-center justify-between gap-gutter">
    <a class="flex items-center gap-space-sm focus:outline-none shrink-0" href="index.php">
      <span class="swo-brand-mark" aria-hidden="true">傲</span>
      <span class="flex flex-col tracking-wider">
        <span class="flex items-center gap-space-xs whitespace-nowrap">
          <span class="font-headline-lg text-headline-lg text-slate-900 tracking-widest leading-none whitespace-nowrap">踏雪笑傲</span>
          <span class="font-label-sm text-label-sm px-2 py-0.5 bg-primary text-white font-semibold whitespace-nowrap">正宗武俠</span>
        </span>
        <span class="font-label-sm text-label-sm text-slate-500 tracking-[0.25em] uppercase font-medium whitespace-nowrap">SNOW WANDERER ONLINE</span>
      </span>
    </a>
    <nav class="site-main-nav hidden xl:flex items-center gap-1 shrink-0">
      <a class="swo-nav-link" href="index.php">官網首頁</a>
      <a class="swo-nav-link" href="news.php">最新公告</a>
      <a class="swo-nav-link is-active" aria-current="page" href="events.php">江湖活動</a>
      <a class="swo-nav-link" href="download.php">遊戲下載</a>
      <a class="swo-nav-link" href="../home.php">會員中心</a>
    </nav>
    <div class="flex items-center gap-space-md shrink-0">
      <div class="sau-status hidden md:flex items-center gap-space-xs px-3 py-1 bg-rose-50 border border-rose-200/60 rounded">
        <span class="relative flex w-2 h-2"><span class="absolute inline-flex w-full h-full rounded-full bg-rose-500" style="animation:evPing 1.8s cubic-bezier(0,0,.2,1) infinite;"></span><span class="relative inline-flex w-2 h-2 rounded-full bg-rose-600"></span></span>
        <span class="font-label-sm text-label-sm text-primary font-semibold">盛典進行中</span>
      </div>
      <a class="swo-discord-btn hidden md:inline-flex items-center justify-center px-5 py-2 text-white font-label-md text-label-md tracking-widest uppercase rounded whitespace-nowrap shrink-0 leading-none" href="#">加入 DISCORD</a>
      <?php include __DIR__ . '/site_auth.php'; ?>
    </div>
  </div>
</header>

<main class="w-full pt-20">

<?php if ($event): ?>
  <!-- ============ 單一活動檢視 ============ -->
  <?php $eth = ev_accent_theme($event['accent']); $eremain = ev_remaining($event['countdown_at']); $ecat = $catMap[$event['category_code']] ?? null; ?>
  <div class="relative w-full overflow-hidden bg-gradient-to-b from-slate-100 via-sky-50/40 to-slate-50 border-b border-slate-200/60">
    <div class="absolute right-0 top-0 w-96 h-96 bg-sky-200/25 blur-[120px] rounded-full pointer-events-none"></div>
    <div class="relative max-w-5xl mx-auto px-6 lg:px-12 pt-12 pb-10">
      <a href="events.php" class="inline-flex items-center gap-1 font-label-md text-label-md text-slate-500 hover:text-primary transition-colors mb-5"><span class="material-symbols-outlined text-[16px]">west</span> 返回盛典列表</a>
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <?php if ($event['badge_text'] !== ''): ?><span class="px-3 py-1 font-label-sm text-label-sm tracking-widest uppercase font-bold rounded-sm shadow-sm <?= ev_h($event['badge_class'] ?: 'bg-primary text-white') ?>"><?= ev_h($event['badge_text']) ?></span><?php endif; ?>
        <?php if ($ecat): ?><span class="px-2.5 py-1 bg-white border border-slate-200 text-slate-600 font-label-sm text-label-sm tracking-wider font-semibold rounded-sm"><?= ev_h($ecat['name']) ?></span><?php endif; ?>
        <?php if ($event['period_text'] !== ''): ?><span class="font-label-sm text-label-sm text-slate-500">活動時間：<?= ev_h($event['period_text']) ?></span><?php endif; ?>
        <?php if ($eremain): ?><span class="text-amber-700 font-label-sm text-label-sm font-semibold flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">lock_clock</span><?= ev_h($eremain) ?></span><?php endif; ?>
      </div>
      <h1 class="font-headline-2xl text-3xl lg:text-5xl text-slate-900 tracking-wide leading-tight font-bold" style="font-family:'Noto Serif TC', serif;"><?= ev_h($event['title']) ?></h1>
      <?php if ($event['summary'] !== ''): ?><p class="mt-4 font-body-lg text-slate-600 leading-relaxed border-l-2 border-primary pl-4 max-w-3xl"><?= ev_h($event['summary']) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="max-w-5xl mx-auto w-full px-6 lg:px-12 py-12">
    <?php if (!empty($event['image'])): ?>
      <div class="relative w-full overflow-hidden rounded-lg border border-slate-200 shadow-lg mb-8">
        <img class="w-full max-h-[440px] object-cover" alt="<?= ev_h($event['title']) ?>" src="<?= ev_h($event['image']) ?>">
        <div class="absolute inset-0 bg-gradient-to-t from-slate-900/50 to-transparent"></div>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2">
        <article class="ev-content bg-white border border-slate-200/90 rounded-lg p-6 sm:p-8 shadow-[0_10px_30px_rgba(15,23,42,0.05)]"><?= $event['description'] ?></article>
      </div>
      <aside class="space-y-6">
        <?php if (!empty($eventRewards)): ?>
        <div class="bg-white border border-slate-200/90 rounded-lg p-5 shadow-sm">
          <h3 class="font-headline-md text-lg font-bold text-slate-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-primary">redeem</span>獎勵清單</h3>
          <div class="flex flex-col gap-2">
            <?php foreach ($eventRewards as $r): ?>
              <div class="rounded-lg border p-3 <?= (int)$r['highlight'] === 1 ? 'bg-gradient-to-br from-rose-50 to-white border-rose-200' : 'bg-slate-50 border-slate-200' ?>">
                <div class="font-label-sm text-label-sm <?= (int)$r['highlight'] === 1 ? 'text-primary' : 'text-slate-500' ?> font-bold"><?= ev_h($r['stage_label']) ?></div>
                <div class="font-body-md <?= (int)$r['highlight'] === 1 ? 'text-rose-950 font-bold' : 'text-slate-800 font-semibold' ?>"><?= ev_h($r['item']) ?></div>
                <?php if ($r['note'] !== ''): ?><div class="font-body-sm text-body-sm text-slate-500"><?= ev_h($r['note']) ?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <div class="bg-slate-900 text-slate-300 rounded-lg p-5">
          <h3 class="text-sm font-bold text-white mb-2"><i class="material-symbols-outlined align-middle text-[18px] mr-1">info</i>參與須知</h3>
          <p class="text-[13px] leading-relaxed">參與活動即視同同意活動規範，獎勵發送與領取期限請參閱下方「活動領賞規則與注意事項」，或洽詢線上客服。</p>
          <a href="events.php#rules" class="mt-3 inline-flex items-center gap-1 text-rose-300 hover:text-rose-200 text-[13px] font-semibold">查看注意事項 <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
        </div>
      </aside>
    </div>

    <div class="mt-10 flex justify-center">
      <a href="events.php" class="px-8 py-3 bg-gradient-to-r from-[#95002a] to-[#be123c] text-white font-label-md text-label-md tracking-widest uppercase rounded shadow-md hover:from-[#be123c] hover:to-[#e11d48] transition-all flex items-center gap-2"><span class="material-symbols-outlined text-[18px]">west</span> 返回盛典列表</a>
    </div>
  </div>

<?php else: ?>
  <!-- ============ 活動首頁 ============ -->
  <div class="relative w-full overflow-hidden bg-gradient-to-b from-slate-100 via-sky-50/40 to-slate-50 border-b border-slate-200/60">
    <div class="absolute inset-0 pointer-events-none opacity-60 bg-[radial-gradient(ellipse_80%_50%_at_50%_-10%,rgba(186,230,253,0.4),rgba(248,250,252,0))]"></div>
    <div class="absolute right-0 top-0 w-96 h-96 bg-sky-200/25 blur-[120px] rounded-full pointer-events-none"></div>
    <div class="absolute -left-20 top-40 w-80 h-80 bg-rose-100/30 blur-[100px] rounded-full pointer-events-none"></div>

    <div class="relative max-w-7xl mx-auto px-6 lg:px-12 pt-12 pb-16 lg:pb-24">
      <div class="flex flex-col lg:flex-row items-start lg:items-end justify-between gap-gutter">
        <div class="flex flex-col max-w-3xl">
          <div class="inline-flex items-center gap-3 mb-4 flex-wrap">
            <span class="w-2 h-2 bg-primary rotate-45"></span>
            <span class="font-label-sm text-label-sm tracking-[0.3em] text-primary uppercase font-bold"><?= ev_h($heroKicker) ?></span>
            <span class="h-px w-12 bg-slate-300"></span>
            <span class="font-label-sm text-label-sm text-slate-500 font-medium"><?= ev_h($heroSeason) ?></span>
          </div>
          <div class="flex items-baseline gap-4 flex-wrap">
            <h1 class="font-headline-2xl text-4xl lg:text-5xl text-slate-900 tracking-wider leading-tight font-bold" style="font-family:'Noto Serif TC', serif;"><?= ev_h($heroTitle) ?></h1>
            <div class="px-2.5 py-0.5 bg-rose-50 border border-rose-200 text-primary text-label-sm font-label-sm tracking-widest uppercase rounded-sm">SEAL: VERMILION FROST</div>
          </div>
          <p class="mt-6 font-body-lg text-lg text-slate-600 max-w-2xl leading-relaxed"><?= ev_h($heroBlurb) ?></p>
        </div>
        <?php if ($showStats): ?>
        <div class="flex items-center gap-6 self-stretch sm:self-auto p-5 bg-white/95 border border-slate-200/90 shadow-[0_10px_30px_rgba(15,23,42,0.06)] rounded-lg backdrop-blur-sm">
          <div class="flex flex-col border-r border-slate-200 pr-6">
            <span class="font-label-sm text-label-sm text-slate-500 uppercase tracking-widest font-semibold"><?= ev_h($SET['stat1_label'] ?? '全服已發送豪禮') ?></span>
            <span class="font-headline-lg text-2xl text-primary tracking-tight font-bold mt-1"><?= ev_h($SET['stat1_value'] ?? '128,450+') ?></span>
            <span class="font-label-sm text-label-sm text-slate-500 mt-0.5"><?= ev_h($SET['stat1_sub'] ?? '份江湖令簽') ?></span>
          </div>
          <div class="flex flex-col">
            <span class="font-label-sm text-label-sm text-slate-500 uppercase tracking-widest font-semibold"><?= ev_h($SET['stat2_label'] ?? '當前運行活動') ?></span>
            <div class="flex items-center gap-2 mt-1">
              <span class="relative flex w-2 h-2"><span class="absolute inline-flex w-full h-full rounded-full bg-rose-500" style="animation:evPing 1.6s cubic-bezier(0,0,.2,1) infinite;"></span><span class="relative inline-flex w-2 h-2 rounded-full bg-rose-600"></span></span>
              <span class="font-headline-lg text-2xl text-slate-900 font-bold"><?= (int)$ongoingCount ?></span>
              <span class="font-label-sm text-label-sm text-slate-500"><?= ev_h($SET['stat2_sub'] ?? '項進行中') ?></span>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 分類頁籤 -->
  <div class="sticky top-20 z-40 bg-white/90 backdrop-blur-md border-b border-slate-200 shadow-sm">
    <div class="max-w-7xl mx-auto px-6 lg:px-12 flex items-center justify-between overflow-x-auto no-scrollbar py-3 gap-4">
      <div class="flex items-center gap-2 p-1 bg-slate-100/70 border border-slate-200/80 rounded-lg">
        <a href="events.php" class="px-5 py-2 font-headline-md text-headline-md tracking-wider transition-all flex items-center gap-2 rounded-md <?= $filterCat === '' ? 'bg-gradient-to-r from-[#95002a] to-[#be123c] text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 hover:bg-white' ?>" style="font-family:'Noto Serif TC', serif;">
          <span>全部盛典</span><span class="font-label-sm text-label-sm px-1.5 py-0.5 rounded <?= $filterCat === '' ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-700' ?>"><?= (int)$totalEvents ?></span>
        </a>
        <?php foreach ($categories as $c): $isActive = ($filterCat === $c['code']); ?>
          <a href="events.php?cat=<?= urlencode($c['code']) ?>" class="px-5 py-2 font-headline-md text-headline-md tracking-wider transition-all flex items-center gap-2 rounded-md <?= $isActive ? 'bg-gradient-to-r from-[#95002a] to-[#be123c] text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 hover:bg-white' ?>" style="font-family:'Noto Serif TC', serif;">
            <span><?= ev_h($c['name']) ?></span><span class="font-label-sm text-label-sm px-1.5 py-0.5 rounded <?= $isActive ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-700' ?>"><?= (int)($catCounts[$c['code']] ?? 0) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if ($featured): ?>
    <?php $fth = ev_accent_theme($featured['accent']); $fRemain = ev_remaining($featured['countdown_at']); $deadlineTs = $featured['countdown_at'] ? strtotime($featured['countdown_at']) : 0; ?>
    <!-- 精選主打 -->
    <div class="max-w-7xl mx-auto px-6 lg:px-12 pt-12 pb-16 w-full">
      <div class="relative bg-white border border-slate-200/90 shadow-[0_20px_50px_rgba(15,23,42,0.08)] rounded-xl overflow-hidden p-1 lg:p-2">
        <div class="bg-gradient-to-br from-white via-slate-50 to-sky-50/30 border border-slate-100 relative p-6 sm:p-10 lg:p-12 overflow-hidden flex flex-col lg:flex-row gap-10 items-stretch rounded-lg">
          <div class="absolute -top-20 -left-20 w-72 h-72 bg-rose-100/40 blur-[80px] rounded-full pointer-events-none"></div>
          <div class="absolute -bottom-24 right-0 w-80 h-80 bg-sky-100/50 blur-[90px] rounded-full pointer-events-none"></div>

          <div class="flex-1 flex flex-col justify-between z-10">
            <div>
              <div class="flex flex-wrap items-center gap-3 mb-4">
                <span class="px-3 py-1 font-label-sm text-label-sm tracking-widest uppercase font-bold rounded-sm shadow-sm ev-shimmer text-white"><?= ev_h($featured['badge_text'] ?: '限時盛典') ?></span>
                <?php if ($featured['period_text'] !== ''): ?><span class="px-2.5 py-1 bg-sky-50 border border-sky-100 text-secondary font-label-sm text-label-sm tracking-wider font-semibold rounded-sm">活動時間：<?= ev_h($featured['period_text']) ?></span><?php endif; ?>
                <?php if ($fRemain): ?><span class="text-amber-700 font-label-sm text-label-sm flex items-center gap-1 font-semibold"><span class="material-symbols-outlined text-[14px]">lock_clock</span><?= ev_h($fRemain) ?></span><?php endif; ?>
              </div>
              <h2 class="font-headline-xl text-2xl lg:text-3xl text-slate-900 tracking-wide mt-2 font-bold" style="font-family:'Noto Serif TC', serif;"><?= ev_h($featured['title']) ?></h2>
              <p class="font-body-md text-slate-600 mt-4 leading-relaxed max-w-xl"><?= ev_h($featured['summary']) ?></p>

              <?php if (!empty($featuredRewards)): ?>
              <div class="mt-8">
                <span class="font-label-sm text-label-sm text-slate-500 tracking-[0.2em] uppercase block mb-3 font-semibold">—— 盛典封賞清單 · 累計登入即得 ——</span>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                  <?php foreach ($featuredRewards as $r): ?>
                    <div class="p-3 border shadow-sm flex flex-col gap-1 rounded-sm transition-all <?= (int)$r['highlight'] === 1 ? 'bg-gradient-to-b from-rose-50 to-white border-rose-200 hover:shadow-md' : 'bg-white border-slate-200/80 hover:border-slate-300' ?>">
                      <span class="font-label-sm text-label-sm text-primary font-bold"><?= ev_h($r['stage_label']) ?></span>
                      <span class="font-headline-md text-headline-md <?= (int)$r['highlight'] === 1 ? 'text-rose-950 font-bold' : 'text-slate-900 font-semibold' ?>"><?= ev_h($r['item']) ?></span>
                      <span class="font-body-sm text-body-sm <?= (int)$r['highlight'] === 1 ? 'text-rose-700 font-medium' : 'text-slate-500' ?>"><?= ev_h($r['note']) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>
            </div>

            <div class="mt-8 pt-6 border-t border-slate-200/80 flex flex-col xl:flex-row xl:items-center justify-between gap-6">
              <div class="flex flex-col gap-2.5">
                <div class="flex items-center gap-2">
                  <span class="w-1.5 h-1.5 bg-primary rotate-45 animate-pulse"></span>
                  <span class="font-label-sm text-label-sm text-slate-600 tracking-[0.2em] uppercase font-bold">盛典限時 · 劍魄歸心</span>
                  <span class="px-2 py-0.5 bg-rose-50 border border-rose-200 text-primary font-label-sm text-label-sm font-bold rounded-sm ml-1">限時進行中</span>
                </div>
                <div class="flex items-center gap-2 sm:gap-3" id="countdown" data-deadline="<?= (int)$deadlineTs ?>">
                  <div class="flex flex-col items-center justify-center bg-white border border-slate-200/90 shadow-[0_4px_12px_rgba(15,23,42,0.06)] px-3 py-2 min-w-[58px] sm:min-w-[64px] rounded-sm relative overflow-hidden">
                    <div class="absolute top-0 left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-primary to-transparent opacity-50"></div>
                    <span class="text-2xl font-bold text-slate-900 leading-none tracking-tight font-serif tabular-nums" id="days">--</span>
                    <div class="flex items-center gap-1 mt-1.5"><span class="text-xs font-semibold text-slate-700 leading-none">天</span><span class="text-[10px] tracking-widest text-slate-400 font-medium uppercase leading-none">DAYS</span></div>
                  </div>
                  <span class="text-primary font-bold text-lg opacity-70 animate-pulse">:</span>
                  <div class="flex flex-col items-center justify-center bg-white border border-slate-200/90 shadow-[0_4px_12px_rgba(15,23,42,0.06)] px-3 py-2 min-w-[58px] sm:min-w-[64px] rounded-sm relative overflow-hidden">
                    <div class="absolute top-0 left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-primary to-transparent opacity-50"></div>
                    <span class="text-2xl font-bold text-slate-900 leading-none tracking-tight font-serif tabular-nums" id="hours">--</span>
                    <div class="flex items-center gap-1 mt-1.5"><span class="text-xs font-semibold text-slate-700 leading-none">時</span><span class="text-[10px] tracking-widest text-slate-400 font-medium uppercase leading-none">HOURS</span></div>
                  </div>
                  <span class="text-primary font-bold text-lg opacity-70 animate-pulse">:</span>
                  <div class="flex flex-col items-center justify-center bg-white border border-slate-200/90 shadow-[0_4px_12px_rgba(15,23,42,0.06)] px-3 py-2 min-w-[58px] sm:min-w-[64px] rounded-sm relative overflow-hidden">
                    <div class="absolute top-0 left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-primary to-transparent opacity-50"></div>
                    <span class="text-2xl font-bold text-slate-900 leading-none tracking-tight font-serif tabular-nums" id="minutes">--</span>
                    <div class="flex items-center gap-1 mt-1.5"><span class="text-xs font-semibold text-slate-700 leading-none">分</span><span class="text-[10px] tracking-widest text-slate-400 font-medium uppercase leading-none">MINS</span></div>
                  </div>
                  <span class="text-primary font-bold text-lg opacity-70 animate-pulse">:</span>
                  <div class="flex flex-col items-center justify-center bg-white border border-slate-200/90 shadow-[0_4px_12px_rgba(15,23,42,0.06)] px-3 py-2 min-w-[58px] sm:min-w-[64px] rounded-sm relative overflow-hidden">
                    <div class="absolute top-0 left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-primary to-transparent opacity-50"></div>
                    <span class="text-2xl font-bold text-slate-900 leading-none tracking-tight font-serif tabular-nums" id="seconds">--</span>
                    <div class="flex items-center gap-1 mt-1.5"><span class="text-xs font-semibold text-slate-700 leading-none">秒</span><span class="text-[10px] tracking-widest text-slate-400 font-medium uppercase leading-none">SECS</span></div>
                  </div>
                </div>
              </div>
              <a href="events.php?view=<?= (int)$featured['id'] ?>" class="inline-flex items-center justify-center gap-2 px-7 py-3.5 bg-gradient-to-r from-[#95002a] to-[#be123c] hover:from-[#be123c] hover:to-[#e11d48] text-white font-label-md text-label-md tracking-widest uppercase rounded shadow-lg shadow-rose-900/20 transition-all">
                <span class="material-symbols-outlined text-[20px]">local_fire_department</span> 立即參與盛典
              </a>
            </div>
          </div>

          <div class="w-full lg:w-96 flex flex-col justify-between relative min-h-[320px] rounded-lg overflow-hidden border border-slate-200 shadow-lg">
            <div class="relative w-full h-full overflow-hidden bg-slate-100">
              <?php if (!empty($featured['image'])): ?><img class="w-full h-full object-cover object-center ev-float" alt="<?= ev_h($featured['title']) ?>" src="<?= ev_h($featured['image']) ?>"><?php endif; ?>
              <div class="absolute inset-0 bg-gradient-to-t from-slate-900/70 via-transparent to-transparent"></div>
              <div class="absolute top-4 right-4 bg-white/90 backdrop-blur-md px-3 py-1 shadow-sm rounded-sm border border-slate-200"><span class="font-label-sm text-label-sm text-primary font-bold tracking-widest">水墨外觀 · 典藏絕版</span></div>
              <div class="absolute bottom-4 left-4 right-4 flex items-center justify-between text-body-sm text-white">
                <span class="flex items-center gap-1.5 drop-shadow"><span class="w-1.5 h-1.5 rounded-full bg-sky-400 animate-pulse"></span> 限定特效 · 雪鶴凌雲</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- 活動矩陣 -->
  <div class="max-w-7xl mx-auto px-6 lg:px-12 pb-24 w-full">
    <div class="flex flex-col sm:flex-row sm:items-end justify-between pb-8 gap-4 border-b border-slate-200/80 mb-8">
      <div>
        <div class="flex items-center gap-2"><span class="h-3 w-1 bg-primary"></span><span class="font-label-sm text-label-sm text-slate-500 tracking-[0.2em] uppercase font-bold">ONGOING GRAND EXPEDITIONS</span></div>
        <h3 class="font-headline-xl text-3xl font-bold text-slate-900 tracking-wider mt-1" style="font-family:'Noto Serif TC', serif;"><?= $filterCat !== '' ? ev_h($catMap[$filterCat]['name']) : ev_h($SET['grid_title'] ?? '精選江湖活動指南') ?></h3>
      </div>
      <span class="font-body-sm text-body-sm text-slate-500">共 <?= count($events) ?> 項活動</span>
    </div>

    <?php if (empty($events)): ?>
      <div class="p-16 bg-white border border-slate-200 rounded-lg text-center text-slate-400">
        <span class="material-symbols-outlined text-[40px] block mb-2">event_busy</span>目前此分類尚無活動，敬請期待。
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <?php foreach ($events as $e): $eth = ev_accent_theme($e['accent']); ?>
        <a href="events.php?view=<?= (int)$e['id'] ?>" class="group relative flex flex-col bg-white hover:bg-slate-50/50 ev-card shadow-[0_10px_25px_rgba(15,23,42,0.06)] hover:shadow-[0_20px_40px_rgba(15,23,42,0.12)] border border-slate-200/80 rounded-lg overflow-hidden">
          <span class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r <?= ev_h($eth['bar']) ?> z-10"></span>
          <div class="relative h-48 w-full overflow-hidden bg-slate-100">
            <?php if (!empty($e['image'])): ?><img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700" alt="<?= ev_h($e['title']) ?>" src="<?= ev_h($e['image']) ?>"><?php endif; ?>
            <div class="absolute inset-0 bg-gradient-to-t from-slate-900/65 via-transparent to-transparent"></div>
            <div class="absolute top-3 left-3 flex gap-2">
              <?php if ($e['badge_text'] !== ''): ?><span class="px-2.5 py-0.5 font-label-sm text-label-sm uppercase font-bold tracking-wider rounded-sm shadow-sm <?= ev_h($e['badge_class'] ?: 'bg-primary text-white') ?>"><?= ev_h($e['badge_text']) ?></span><?php endif; ?>
            </div>
            <?php if ($e['corner_text'] !== ''): ?><div class="absolute bottom-3 right-3 px-2 py-0.5 bg-white/90 backdrop-blur-sm font-label-sm text-label-sm <?= ev_h($e['side_class'] ?: 'text-slate-800') ?> font-semibold rounded-sm"><?= ev_h($e['corner_text']) ?></div><?php endif; ?>
          </div>
          <div class="p-6 flex flex-col flex-1 justify-between">
            <div>
              <div class="flex items-center justify-between text-body-sm text-slate-500 mb-1 gap-2">
                <span class="truncate">門檻：<?= ev_h($e['threshold']) ?></span>
                <?php if ($e['side_tag'] !== ''): ?><span class="<?= ev_h($e['side_class'] ?: 'text-primary') ?> font-label-sm text-label-sm font-bold whitespace-nowrap"><?= ev_h($e['side_tag']) ?></span><?php endif; ?>
              </div>
              <h4 class="font-headline-md text-lg text-slate-900 group-hover:text-primary transition-colors tracking-wide font-bold" style="font-family:'Noto Serif TC', serif;"><?= ev_h($e['title']) ?></h4>
              <p class="font-body-sm text-body-sm text-slate-600 mt-2 line-clamp-2"><?= ev_h($e['summary']) ?></p>
            </div>
            <div class="mt-6 pt-4 bg-slate-50 border border-slate-100 p-3 rounded-sm">
              <span class="font-label-sm text-label-sm text-slate-400 tracking-wider uppercase block mb-1 font-semibold">重點獎勵一覽</span>
              <div class="flex items-center justify-between text-slate-800 font-body-sm gap-2">
                <span class="text-slate-700 font-medium truncate"><?= ev_h($e['reward_summary']) ?></span>
                <span class="text-primary font-label-md text-label-md font-bold whitespace-nowrap group-hover:translate-x-0.5 transition-transform">查看詳情 →</span>
              </div>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($showRules && !empty($notes)): ?>
  <!-- 注意事項 -->
  <div class="w-full bg-slate-100/70 border-t border-slate-200 py-16 lg:py-24" id="rules">
    <div class="max-w-4xl mx-auto px-6 lg:px-12">
      <div class="text-center mb-12">
        <div class="inline-flex items-center gap-2 mb-2">
          <span class="w-1.5 h-1.5 bg-primary rotate-45"></span>
          <span class="font-label-sm text-label-sm text-primary tracking-[0.25em] uppercase font-bold">REGULATIONS &amp; INSTRUCTIONS</span>
          <span class="w-1.5 h-1.5 bg-primary rotate-45"></span>
        </div>
        <h3 class="font-headline-xl text-3xl font-bold text-slate-900 tracking-wider" style="font-family:'Noto Serif TC', serif;"><?= ev_h($SET['rules_title'] ?? '江湖活動領賞規則與注意事項') ?></h3>
        <p class="font-body-sm text-body-sm text-slate-600 mt-2 max-w-lg mx-auto"><?= ev_h($SET['rules_subtitle'] ?? '') ?></p>
      </div>
      <div class="flex flex-col gap-3" id="accordionGroup">
        <?php foreach ($notes as $n): ?>
          <div class="bg-white border border-slate-200/90 rounded-lg shadow-sm overflow-hidden transition-colors">
            <button class="w-full p-5 text-left flex items-center justify-between hover:bg-slate-50 transition-colors" onclick="toggleAccordion('rule<?= (int)$n['id'] ?>', this)">
              <span class="font-headline-md text-headline-md text-slate-800 flex items-center gap-3">
                <span class="font-label-sm text-label-sm text-primary px-2 py-0.5 bg-rose-50 border border-rose-100 font-bold rounded-sm"><?= ev_h($n['no_label']) ?></span>
                <span><?= ev_h($n['question']) ?></span>
              </span>
              <span class="material-symbols-outlined text-primary text-[22px] transition-transform duration-300 accordion-icon">expand_more</span>
            </button>
            <div class="hidden p-5 pt-0 text-body-md text-slate-600 leading-relaxed bg-white border-t border-slate-100" id="rule<?= (int)$n['id'] ?>"><?= $n['answer'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($showAssist): ?>
      <div class="mt-8 p-6 bg-white border border-slate-200 shadow-sm rounded-lg flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-3 text-left">
          <div class="w-10 h-10 bg-rose-50 border border-rose-100 rounded-full flex items-center justify-center text-primary"><span class="material-symbols-outlined text-[20px]">support_agent</span></div>
          <div>
            <span class="font-headline-md text-headline-md text-slate-900 block font-semibold"><?= ev_h($SET['assist_title'] ?? '仍有活動相關疑問？') ?></span>
            <span class="font-body-sm text-body-sm text-slate-600"><?= ev_h($SET['assist_text'] ?? '') ?></span>
          </div>
        </div>
        <a class="px-6 py-2.5 bg-slate-900 hover:bg-primary text-white font-label-md text-label-md uppercase tracking-wider transition-colors whitespace-nowrap rounded-sm shadow-sm" href="<?= ev_h($SET['assist_btn_url'] ?? '#') ?>"><?= ev_h($SET['assist_btn_text'] ?? '聯繫線上客棧') ?></a>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>
</main>

<footer class="w-full bg-slate-900 text-slate-400 border-t border-slate-800">
  <div class="max-w-7xl mx-auto px-6 lg:px-12 py-12 flex flex-col gap-6">
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-gutter">
      <div class="flex items-center gap-space-md">
        <span class="font-headline-xl text-2xl text-white tracking-widest" style="font-family:'Noto Serif TC', serif;">踏雪笑傲</span>
        <div class="h-8 w-px bg-slate-700 hidden sm:block"></div>
        <span class="font-body-sm text-body-sm text-slate-400">霜刃未曾試，今日把示君。天下風雲出我輩，一入江湖歲月催。</span>
      </div>
      <div class="flex flex-wrap gap-space-md font-label-md text-label-md">
        <a class="text-slate-400 hover:text-white transition-colors" href="#">客服中心</a>
        <a class="text-slate-400 hover:text-white transition-colors" href="#">服務條款</a>
        <a class="text-slate-400 hover:text-white transition-colors" href="#">隱私權政策</a>
        <a class="text-slate-400 hover:text-white transition-colors" href="#">家長監護</a>
        <a class="text-slate-400 hover:text-white transition-colors" href="#">社群交流</a>
      </div>
    </div>
    <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-space-md text-body-sm font-body-sm border-t border-slate-800/80 pt-6">
      <div class="flex items-center gap-space-md">
        <div class="px-2 py-0.5 bg-slate-800 text-slate-300 font-label-sm text-label-sm border border-slate-700 rounded-sm">輔 15 級</div>
        <p class="text-slate-500">本遊戲情節涉及性、暴力、虛擬戀愛或結婚。注意使用時間，避免沉迷於遊戲。遊戲部分內容須另行支付費用。</p>
      </div>
      <div class="text-slate-500 font-label-sm text-label-sm">© <?= date('Y') ?> SNOW WANDERER ONLINE. 踏雪笑傲工作室 版權所有. All Rights Reserved.</div>
    </div>
  </div>
</footer>

<script>
  // 精選活動倒數
  (function initCountdown() {
    const box = document.getElementById('countdown');
    if (!box) return;
    const deadline = parseInt(box.getAttribute('data-deadline') || '0', 10);
    if (!deadline) return;
    function update() {
      const diff = deadline * 1000 - Date.now();
      const dEl = document.getElementById('days'), hEl = document.getElementById('hours');
      const mEl = document.getElementById('minutes'), sEl = document.getElementById('seconds');
      if (!dEl) return;
      if (diff <= 0) { dEl.innerText = hEl.innerText = mEl.innerText = sEl.innerText = '00'; return; }
      const d = Math.floor(diff / 86400000);
      const h = Math.floor((diff % 86400000) / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000) / 1000);
      dEl.innerText = String(d).padStart(2, '0');
      hEl.innerText = String(h).padStart(2, '0');
      mEl.innerText = String(m).padStart(2, '0');
      sEl.innerText = String(s).padStart(2, '0');
    }
    update(); setInterval(update, 1000);
  })();

  // 注意事項手風琴
  function toggleAccordion(id, btn) {
    const content = document.getElementById(id);
    const icon = btn.querySelector('.accordion-icon');
    const isHidden = content.classList.contains('hidden');
    document.querySelectorAll("#accordionGroup [id^='rule']").forEach(el => el.classList.add('hidden'));
    document.querySelectorAll("#accordionGroup .accordion-icon").forEach(el => el.style.transform = 'rotate(0deg)');
    if (isHidden) { content.classList.remove('hidden'); if (icon) icon.style.transform = 'rotate(180deg)'; }
    else { content.classList.add('hidden'); if (icon) icon.style.transform = 'rotate(0deg)'; }
  }
</script>
</body>
</html>

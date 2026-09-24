<?php
/**
 * =============================================================
 *  踏雪笑傲 · 江湖邸報（官方公告）— 公開前台
 *  資料來源：獨立資料庫 swo_news（見 news_schema.sql）
 *  功能：公告列表、分類/月份/關鍵字篩選、置頂精選、單篇檢視、飛鴿訂閱
 * =============================================================
 */
require_once __DIR__ . '/../session_bootstrap.php';
app_session_start(['remember' => true]);
require_once __DIR__ . '/../news_config.php';

$db = news_db();
$SET = news_settings_all($db);
$catMap = news_category_map($db);
$categories = news_categories($db, true);

$notice = '';
$noticeType = 'success';

if (empty($_SESSION['news_form_token'])) {
    $_SESSION['news_form_token'] = bin2hex(random_bytes(32));
}
$formToken = $_SESSION['news_form_token'];

/* ---------------- 飛鴿傳書訂閱 ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'subscribe') {
    if (($_POST['form_token'] ?? '') !== $formToken) {
        $notice = '工作階段已過期，請重新送出。';
        $noticeType = 'danger';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $notice = '請輸入正確的電子信箱地址。';
            $noticeType = 'danger';
        } else {
            $stmt = $db->prepare("INSERT INTO `news_subscribers` (`email`, `is_active`, `source`) VALUES (?, 1, 'news_page') ON DUPLICATE KEY UPDATE `is_active` = 1, `unsubscribed_at` = NULL");
            $stmt->bind_param('s', $email);
            if ($stmt->execute()) {
                $notice = '訂閱成功！江湖快報將以飛鴿傳書直送您的信箱。';
            } else {
                $notice = '訂閱失敗，請稍後再試。';
                $noticeType = 'danger';
            }
            $stmt->close();
        }
    }
}

/* ---------------- 單篇檢視 ---------------- */
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$article = null;
if ($viewId > 0) {
    $stmt = $db->prepare(
        "SELECT a.*, c.`name` AS category_name, c.`badge_class`, c.`icon`, c.`accent`
           FROM `news_articles` a
           LEFT JOIN `news_categories` c ON c.`id` = a.`category_id`
          WHERE a.`id` = ? AND a.`status` = 'published' LIMIT 1"
    );
    $stmt->bind_param('i', $viewId);
    $stmt->execute();
    $article = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($article) {
        if (!isset($_SESSION['news_viewed']) || !is_array($_SESSION['news_viewed'])) {
            $_SESSION['news_viewed'] = [];
        }
        if (!in_array($viewId, $_SESSION['news_viewed'], true)) {
            $db->query("UPDATE `news_articles` SET `view_count` = `view_count` + 1 WHERE `id` = " . (int)$viewId);
            $article['view_count'] = (int)$article['view_count'] + 1;
            $_SESSION['news_viewed'][] = $viewId;
        }
        $attachments = [];
        $stmt = $db->prepare("SELECT * FROM `news_attachments` WHERE `article_id` = ? ORDER BY `sort_order` ASC, `id` ASC");
        $stmt->bind_param('i', $viewId);
        $stmt->execute();
        $attachments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

/* ---------------- 列表篩選 ---------------- */
$filterCat    = trim((string)($_GET['cat'] ?? ''));
$filterYm     = trim((string)($_GET['ym'] ?? ''));
$filterQ      = trim((string)($_GET['q'] ?? ''));
$onlySticky   = !empty($_GET['sticky']);
$page         = max(1, (int)($_GET['page'] ?? 1));
$pageSize     = max(1, (int)($SET['list_page_size'] ?? 8));

if ($filterCat !== '' && !isset($catMap[$filterCat])) { $filterCat = ''; }
if ($filterYm !== '' && !preg_match('/^\d{4}-\d{2}$/', $filterYm)) { $filterYm = ''; }

$where = ["a.`status` = 'published'", 'a.`published_at` IS NOT NULL', 'a.`published_at` <= NOW()'];
$params = [];
$types = '';
if ($filterCat !== '') { $where[] = 'a.`category_code` = ?'; $params[] = $filterCat; $types .= 's'; }
if ($filterYm !== '')  { $where[] = "DATE_FORMAT(a.`published_at`, '%Y-%m') = ?"; $params[] = $filterYm; $types .= 's'; }
if ($filterQ !== '') {
    $where[] = '(a.`title` LIKE ? OR a.`summary` LIKE ?)';
    $like = '%' . $filterQ . '%';
    $params[] = $like; $params[] = $like; $types .= 'ss';
}
if ($onlySticky) { $where[] = 'a.`is_sticky` = 1'; }
$whereSql = implode(' AND ', $where);

$totalRows = 0;
$sql = "SELECT COUNT(*) AS c FROM `news_articles` a WHERE $whereSql";
$stmt = $db->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$totalRows = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$totalPages = max(1, (int)ceil($totalRows / $pageSize));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $pageSize;

$articles = [];
$sql = "SELECT a.*, c.`name` AS category_name, c.`badge_class`, c.`icon`, c.`accent`
          FROM `news_articles` a
          LEFT JOIN `news_categories` c ON c.`id` = a.`category_id`
         WHERE $whereSql
         ORDER BY a.`is_sticky` DESC, FIELD(a.`priority`,'urgent','high','normal','low'), a.`published_at` DESC
         LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$bindTypes = $types . 'ii';
$bindParams = array_merge($params, [$pageSize, $offset]);
$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$articles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* 精選置頂（僅在無任何篩選時顯示） */
$filtersActive = ($filterCat !== '' || $filterYm !== '' || $filterQ !== '' || $onlySticky);
$featured = [];
if (!$filtersActive && $page === 1) {
    $res = mysqli_query(
        $db,
        "SELECT a.*, c.`name` AS category_name, c.`badge_class`, c.`icon`, c.`accent`
           FROM `news_articles` a
           LEFT JOIN `news_categories` c ON c.`id` = a.`category_id`
          WHERE a.`status` = 'published' AND a.`is_sticky` = 1 AND a.`published_at` <= NOW()
          ORDER BY a.`is_featured` DESC, FIELD(a.`priority`,'urgent','high','normal','low'), a.`published_at` DESC
          LIMIT 2"
    );
    if ($res) { $featured = $res->fetch_all(MYSQLI_ASSOC); }
}

/* 分類計數 */
$catCounts = [];
$res = mysqli_query($db, "SELECT `category_code`, COUNT(*) AS c FROM `news_articles` WHERE `status`='published' AND `published_at` IS NOT NULL AND `published_at` <= NOW() GROUP BY `category_code`");
if ($res) { while ($r = $res->fetch_assoc()) { $catCounts[$r['category_code']] = (int)$r['c']; } }
$totalPublished = array_sum($catCounts);

$months = news_month_options($db);

/* 月份顯示 */
function news_ym_label(string $ym): string
{
    [$y, $m] = array_pad(explode('-', $ym), 2, '');
    return $y . '年 ' . (int)$m . '月';
}

/* 分類動作按鈕文字 */
function news_action_text(string $code): string
{
    switch ($code) {
        case 'security': return '查看名單';
        case 'event':    return '活動詳情';
        case 'guide':    return '閱讀指引';
        default:         return '查看細則';
    }
}

/* 分類色系主題（列表分類徽章／色條用） */
function news_accent_theme(string $accent): array
{
    $map = [
        'rose' => [
            'grad' => 'from-rose-500 to-rose-700',
            'bar'  => 'from-rose-400 via-rose-500 to-rose-700',
            'text' => 'text-rose-700',
            'soft' => 'bg-rose-100',
            'glow' => 'shadow-rose-500/30',
            'hover' => 'group-hover:text-rose-800',
        ],
        'sky' => [
            'grad' => 'from-sky-500 to-sky-700',
            'bar'  => 'from-sky-400 via-sky-500 to-sky-700',
            'text' => 'text-sky-700',
            'soft' => 'bg-sky-100',
            'glow' => 'shadow-sky-500/30',
            'hover' => 'group-hover:text-sky-800',
        ],
        'amber' => [
            'grad' => 'from-amber-400 to-amber-600',
            'bar'  => 'from-amber-300 via-amber-400 to-amber-600',
            'text' => 'text-amber-700',
            'soft' => 'bg-amber-100',
            'glow' => 'shadow-amber-500/30',
            'hover' => 'group-hover:text-amber-900',
        ],
        'teal' => [
            'grad' => 'from-teal-500 to-teal-700',
            'bar'  => 'from-teal-400 via-teal-500 to-teal-700',
            'text' => 'text-teal-700',
            'soft' => 'bg-teal-100',
            'glow' => 'shadow-teal-500/30',
            'hover' => 'group-hover:text-teal-800',
        ],
        'slate' => [
            'grad' => 'from-slate-500 to-slate-700',
            'bar'  => 'from-slate-400 via-slate-500 to-slate-700',
            'text' => 'text-slate-700',
            'soft' => 'bg-slate-200',
            'glow' => 'shadow-slate-500/30',
            'hover' => 'group-hover:text-slate-900',
        ],
    ];
    return $map[$accent] ?? $map['rose'];
}

/* 保留篩選參數 */
function news_query(array $overrides = []): string
{
    $base = [
        'cat'    => $_GET['cat'] ?? '',
        'ym'     => $_GET['ym'] ?? '',
        'q'      => $_GET['q'] ?? '',
        'sticky' => !empty($_GET['sticky']) ? '1' : '',
        'page'   => $_GET['page'] ?? '',
    ];
    $merged = array_merge($base, $overrides);
    foreach ($merged as $k => $v) {
        if ($v === '' || $v === null) { unset($merged[$k]); }
    }
    return $merged ? '?' . http_build_query($merged) : '';
}

$serverStatusMap = [
    'online'  => ['label' => '全服線路 暢通', 'cls' => 'bg-emerald-500/10 border-emerald-500/30 text-emerald-800', 'dot' => 'bg-emerald-500'],
    'busy'    => ['label' => '全服線路 繁忙', 'cls' => 'bg-amber-500/10 border-amber-500/30 text-amber-800',       'dot' => 'bg-amber-500'],
    'offline' => ['label' => '全服維護 中',   'cls' => 'bg-rose-500/10 border-rose-500/30 text-rose-800',          'dot' => 'bg-rose-500'],
];
$srv = $serverStatusMap[$SET['server_status'] ?? 'online'] ?? $serverStatusMap['online'];

/* 首頁資訊列顯示與否（後台可設定） */
$showVersion     = (($SET['show_version'] ?? '1') !== '0');
$showMaintenance = (($SET['show_maintenance'] ?? '1') !== '0');
$showServer      = (($SET['show_server_status'] ?? '1') !== '0');
$showInfoCard    = ($showVersion || $showMaintenance || $showServer);

$heroTitle = $SET['hero_title'] ?? '江湖邸報 · 官方公告';
$heroBlurb = $SET['hero_subtitle'] ?? '第一手掌握伺服器動態、版本更新、維護時程與江湖重要通知。';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<meta content="web_standard" name="shell-type">
<title><?= news_h($article ? $article['title'] . ' · ' . $heroTitle : $heroTitle) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400..800;1,400..800&amp;family=Manrope:wght@300;400;500;600;700;800&amp;family=Noto+Serif+TC:wght@400;600;700;900&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/base.css?v=1">
<link rel="stylesheet" href="assets/css/news.css?v=1">
<link rel="stylesheet" href="assets/css/news-tailwind.css?v=2">
<link rel="stylesheet" href="assets/css/site-ui.css?v=5">
<link rel="stylesheet" href="assets/css/swo-theme.css?v=1">
<script src="assets/site-ui.js?v=3" defer></script>
</head>
<body class="bg-slate-50 text-slate-900 font-body-md text-body-md antialiased selection:bg-rose-800 selection:text-white">
<header class="fixed top-0 left-0 w-full z-50 bg-white/85 backdrop-blur-xl border-b border-slate-200/80 shadow-[0_4px_20px_rgba(15,23,42,0.05)]">
  <div class="h-20 max-w-7xl mx-auto px-6 lg:px-12 flex items-center justify-between gap-gutter">
    <div class="flex items-center gap-space-md shrink-0">
      <a class="flex items-center gap-space-sm focus:outline-none shrink-0" href="index.php">
        <span class="swo-brand-mark" aria-hidden="true">傲</span>
        <span class="flex flex-col tracking-wider">
          <span class="flex items-center gap-space-xs whitespace-nowrap">
            <span class="font-headline-lg text-headline-lg text-slate-900 tracking-widest leading-none whitespace-nowrap">踏雪笑傲</span>
            <span class="font-label-sm text-label-sm px-space-xs py-0.5 bg-rose-700 text-white font-semibold whitespace-nowrap">正宗武俠</span>
          </span>
          <span class="font-label-sm text-label-sm text-slate-500 tracking-[0.25em] uppercase font-medium whitespace-nowrap">SNOW WANDERER ONLINE</span>
        </span>
      </a>
    </div>
    <nav class="site-main-nav hidden xl:flex items-center gap-1 shrink-0">
      <a class="swo-nav-link" href="index.php">官網首頁</a>
      <a class="swo-nav-link is-active" aria-current="page" href="news.php">最新公告</a>
      <a class="swo-nav-link" href="events.php">江湖活動</a>
      <a class="swo-nav-link" href="download.php">遊戲下載</a>
      <a class="swo-nav-link" href="../home.php">會員中心</a>
    </nav>
    <div class="flex items-center gap-space-md shrink-0">
      <div class="sau-status hidden md:flex items-center gap-space-xs px-3 py-1 bg-sky-50 border border-sky-200/60 rounded">
        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
        <span class="font-label-sm text-label-sm text-sky-900 font-medium">全服營運中</span>
      </div>
      <a class="swo-discord-btn hidden md:inline-flex items-center justify-center px-space-md py-2 text-white font-label-md text-label-md tracking-widest uppercase whitespace-nowrap shrink-0 leading-none" href="#">加入 DISCORD</a>
      <?php include __DIR__ . '/site_auth.php'; ?>
    </div>
  </div>
</header>

<main class="w-full pt-20 bg-gradient-to-b from-slate-50 via-sky-50/30 to-slate-100 min-h-screen">
<div class="flex flex-col w-full">

<?php if ($article): ?>
  <!-- ============ 單篇公告檢視 ============ -->
  <section class="relative w-full overflow-hidden bg-gradient-to-b from-white via-sky-50/40 to-slate-100/60 border-b border-slate-200/80">
    <div class="relative max-w-5xl mx-auto px-6 lg:px-12 pt-12 pb-10">
      <a href="news.php" class="inline-flex items-center gap-1 font-label-md text-label-md text-slate-500 hover:text-rose-800 transition-colors mb-5">
        <span class="material-symbols-outlined text-[16px]">west</span> 返回邸報列表
      </a>
      <div class="flex items-center gap-space-xs flex-wrap mb-3">
        <span class="px-2 py-0.5 border font-label-sm text-label-sm tracking-widest font-bold <?= news_h($article['badge_class'] ?: 'bg-rose-100 text-rose-800 border-rose-200') ?>"><?= news_h($article['category_name'] ?: '公告') ?></span>
        <?php if ((int)$article['is_sticky'] === 1): ?>
          <span class="px-2 py-0.5 bg-rose-700 text-white font-label-sm text-label-sm tracking-widest font-semibold flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">push_pin</span> 置頂</span>
        <?php endif; ?>
        <span class="font-label-sm text-label-sm text-slate-400 font-medium"><?= news_h(date('Y-m-d H:i', strtotime($article['published_at']))) ?></span>
        <span class="font-label-sm text-label-sm text-slate-400">· <?= news_h($article['author']) ?></span>
      </div>
      <h1 class="font-headline-2xl text-headline-2xl-mobile sm:text-headline-2xl text-slate-900 tracking-wide leading-snug font-bold" style="font-family: &quot;Noto Serif TC&quot;, serif;"><?= news_h($article['title']) ?></h1>
      <?php if (!empty($article['summary'])): ?>
        <p class="font-body-md text-body-md text-slate-600 leading-relaxed mt-4 border-l-2 border-rose-700 pl-4"><?= news_h($article['summary']) ?></p>
      <?php endif; ?>
      <div class="flex items-center gap-space-lg mt-5 text-slate-500 font-label-md text-label-md">
        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">visibility</span> <?= number_format((int)$article['view_count']) ?> 閱覽</span>
        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">chat_bubble_outline</span> <?= number_format((int)$article['comment_count']) ?> 條回應</span>
      </div>
    </div>
  </section>

  <div class="max-w-5xl mx-auto w-full px-6 lg:px-12 py-space-xl">
    <?php if (!empty($article['cover_image'])): ?>
      <div class="relative w-full overflow-hidden border border-slate-200 shadow-sm mb-space-lg">
        <img alt="<?= news_h($article['cover_caption'] ?: $article['title']) ?>" class="w-full max-h-[460px] object-cover" src="<?= news_h($article['cover_image']) ?>">
      </div>
    <?php endif; ?>
    <article class="news-content bg-white border border-slate-200/90 p-6 sm:p-10 shadow-[0_4px_24px_rgba(15,23,42,0.06)]">
      <?= $article['content'] ?>
    </article>

    <?php if (!empty($attachments)): ?>
      <div class="mt-space-lg p-space-lg bg-white border border-slate-200 shadow-sm">
        <h3 class="font-headline-lg text-headline-lg text-slate-900 tracking-wider font-bold flex items-center gap-2 mb-4" style="font-family: &quot;Noto Serif TC&quot;, serif;">
          <span class="material-symbols-outlined text-rose-700">attach_file</span> 相關附件
        </h3>
        <div class="flex flex-col gap-2">
          <?php foreach ($attachments as $att): ?>
            <a href="<?= news_h($att['file_path']) ?>" target="_blank" class="flex items-center justify-between px-4 py-2.5 bg-slate-50 border border-slate-200 hover:border-rose-300 hover:bg-rose-50/50 transition-colors">
              <span class="flex items-center gap-2 font-body-sm text-body-sm text-slate-700"><span class="material-symbols-outlined text-[18px] text-slate-400">description</span><?= news_h($att['file_name']) ?></span>
              <span class="font-label-sm text-label-sm text-slate-400"><?= $att['file_size'] > 0 ? number_format($att['file_size'] / 1024, 1) . ' KB' : '連結' ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="mt-space-lg flex justify-center">
      <a href="news.php" class="px-space-lg py-2.5 bg-gradient-to-r from-rose-800 to-rose-700 text-white font-label-md text-label-md tracking-widest uppercase hover:from-rose-900 hover:to-rose-800 shadow-md transition-all flex items-center gap-1.5 font-semibold">
        <span class="material-symbols-outlined text-[16px]">west</span> 返回邸報列表
      </a>
    </div>
  </div>

<?php else: ?>
  <!-- ============ 公告首頁 ============ -->
  <section class="relative w-full overflow-hidden bg-gradient-to-b from-white via-sky-50/40 to-slate-100/60 border-b border-slate-200/80">
    <div class="absolute inset-0 opacity-15 pointer-events-none mix-blend-multiply" style="background-image: url(&quot;https://lh3.googleusercontent.com/aida-public/AB6AXuB5JE-PiNT9sBYjsWv8bCxj3mYFJ-4-jwLqhTjsYMI2aofjWQ2n72fc2t4TqlQv4aBsBOUb_n3AdLEDVZWmuQlF932nf3__uBUzaEBR5P67Psko5I4EgRtrxBJoTaohgcJbAGxnciIJ_-7_fnSdq7n-EofPLmZ0ZtAY8SvxRy75G9vXlintxCda3GMCPkP_4PnqAXqcHLbHeHiKGKzWntWK4KFx0RWZgnqetSqRigVx&quot;); background-position: center 20%; background-size: cover;"></div>
    <div class="absolute inset-0 bg-gradient-to-b from-white/85 via-white/60 to-slate-100/95 pointer-events-none"></div>
    <div class="relative max-w-7xl mx-auto px-6 lg:px-12 pt-12 pb-14">
      <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-space-lg">
        <div class="flex flex-col gap-space-xs max-w-2xl">
          <div class="flex items-center gap-space-xs flex-wrap">
            <span class="px-2 py-0.5 bg-rose-700 text-white font-label-sm text-label-sm tracking-widest uppercase font-semibold flex items-center gap-1 shadow-sm"><span class="material-symbols-outlined text-[13px]">local_post_office</span> 官報速遞</span>
            <span class="px-2 py-0.5 bg-rose-50 text-rose-800 border border-rose-200 font-label-sm text-label-sm tracking-widest font-semibold">萬象更新 · 江湖邸報</span>
            <span class="text-slate-400 font-label-sm text-label-sm tracking-widest font-medium ml-1">OFFICIAL DISPATCH &amp; ARCHIVES</span>
          </div>
          <h1 class="font-headline-2xl text-headline-2xl text-slate-900 tracking-widest flex items-center gap-2" style="font-family: &quot;Noto Serif TC&quot;, serif;">
            <span><?= news_h($heroTitle) ?></span>
            <span class="hidden sm:inline-block w-6 h-6 border border-rose-300 bg-rose-50 text-rose-700 text-center font-headline-md text-[13px] leading-5 font-bold shadow-inner">令</span>
          </h1>
          <p class="font-body-md text-body-md text-slate-600 leading-relaxed"><?= news_h($heroBlurb) ?></p>
        </div>
        <?php if ($showInfoCard): ?>
        <div class="flex flex-wrap items-stretch gap-x-space-lg gap-y-space-md p-space-md bg-white/95 border border-sky-100 shadow-[0_4px_24px_rgba(15,23,42,0.06)] backdrop-blur-md relative">
          <div class="absolute top-0 right-0 w-2 h-2 border-t-2 border-r-2 border-rose-700"></div>
          <div class="absolute bottom-0 left-0 w-2 h-2 border-b-2 border-l-2 border-sky-600"></div>

          <?php if ($showVersion): ?>
          <div class="flex flex-col justify-center min-w-0">
            <div class="flex items-center gap-1.5 mb-1.5">
              <span class="w-1.5 h-1.5 rounded-full bg-rose-600 flex-none"></span>
              <span class="font-label-sm text-label-sm text-slate-500 tracking-[0.12em] font-semibold whitespace-nowrap">當前江湖版本</span>
            </div>
            <div class="flex items-baseline gap-2 flex-wrap">
              <span class="font-headline-md text-headline-md text-rose-800 tracking-wide font-bold leading-none whitespace-nowrap" style="font-family: &quot;Noto Serif TC&quot;, serif;"><?= news_h($SET['site_version'] ?? 'v1.0.0') ?></span>
              <?php if (trim((string)($SET['site_codename'] ?? '')) !== ''): ?>
                <span class="font-body-sm text-body-sm text-slate-500 font-medium whitespace-nowrap"><?= news_h($SET['site_codename']) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($showMaintenance): ?>
          <div class="flex flex-col justify-center min-w-0">
            <div class="flex items-center gap-1.5 mb-1.5">
              <span class="w-1.5 h-1.5 rounded-full bg-sky-600 flex-none"></span>
              <span class="font-label-sm text-label-sm text-slate-500 tracking-[0.12em] font-semibold whitespace-nowrap">下次例行維護</span>
            </div>
            <span class="font-headline-md text-headline-md text-sky-800 tracking-wide font-semibold leading-none whitespace-nowrap" style="font-family: &quot;Noto Serif TC&quot;, serif;"><?= news_h(trim((string)($SET['maintenance_next'] ?? '')) !== '' ? $SET['maintenance_next'] : '—') ?></span>
          </div>
          <?php endif; ?>

          <?php if ($showServer): ?>
          <div class="flex flex-col justify-center min-w-0">
            <div class="flex items-center gap-1.5 mb-1.5">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-600 flex-none"></span>
              <span class="font-label-sm text-label-sm text-slate-500 tracking-[0.12em] font-semibold whitespace-nowrap">線路狀態</span>
            </div>
            <div class="flex items-center gap-1.5 px-2.5 py-1 border whitespace-nowrap <?= news_h($srv['cls']) ?>">
              <span class="w-2 h-2 rounded-full flex-none <?= news_h($srv['dot']) ?> animate-pulse"></span>
              <span class="font-label-sm text-label-sm font-semibold tracking-wider"><?= news_h($srv['label']) ?></span>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- 搜尋 / 篩選 -->
      <form method="GET" action="news.php" class="mt-space-lg p-space-md bg-white/95 border border-slate-200 shadow-[0_4px_20px_rgba(15,23,42,0.05)]">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-space-sm items-center">
          <div class="md:col-span-6 relative flex items-center">
            <span class="material-symbols-outlined text-slate-400 absolute left-space-md text-[20px]">search</span>
            <input class="w-full pl-12 pr-space-md py-2.5 bg-slate-50 border border-slate-200 text-slate-900 placeholder:text-slate-400 text-body-md focus:outline-none focus:border-rose-700 focus:bg-white transition-all" name="q" value="<?= news_h($filterQ) ?>" placeholder="輸入公告標題關鍵字（例：寒霜驚鴻劍、停機維護、防詐...）" type="text">
          </div>
          <div class="md:col-span-3">
            <select name="ym" class="w-full px-space-md py-2.5 bg-slate-50 border border-slate-200 text-slate-800 text-body-md focus:outline-none focus:border-rose-700 focus:bg-white transition-all cursor-pointer">
              <option value="">全年度公告</option>
              <?php foreach ($months as $m): ?>
                <option value="<?= news_h($m['ym']) ?>" <?= ($filterYm === $m['ym']) ? 'selected' : '' ?>><?= news_h(news_ym_label($m['ym'])) ?> (<?= (int)$m['c'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="md:col-span-3 flex items-center justify-between md:justify-end gap-space-sm px-space-xs">
            <label class="flex items-center gap-space-xs cursor-pointer select-none">
              <input class="w-4 h-4 accent-rose-700 bg-white border-slate-300 cursor-pointer" name="sticky" value="1" type="checkbox" <?= $onlySticky ? 'checked' : '' ?>>
              <span class="font-label-md text-label-md text-slate-700 font-semibold">僅看置頂重點</span>
            </label>
            <button type="submit" class="px-space-md py-2 bg-rose-700 hover:bg-rose-800 text-white font-label-md text-label-md transition-colors tracking-wider font-semibold flex items-center gap-1">
              <span class="material-symbols-outlined text-[15px]">search</span> 搜尋
            </button>
            <a href="news.php" class="px-space-md py-2 bg-slate-100 hover:bg-slate-200 border border-slate-200 text-slate-700 font-label-md text-label-md transition-colors tracking-wider font-semibold flex items-center gap-1" title="重設篩選">
              <span class="material-symbols-outlined text-[15px]">restart_alt</span>
            </a>
          </div>
        </div>
        <div class="mt-space-sm pt-space-xs border-t border-slate-100 flex items-center gap-space-xs flex-wrap font-label-sm text-label-sm text-slate-500">
          <span class="text-slate-400">熱門搜尋：</span>
          <a href="news.php?q=<?= urlencode('寒霜驚鴻劍') ?>" class="px-2 py-0.5 bg-slate-100 hover:bg-rose-50 hover:text-rose-800 text-slate-600 transition-colors font-medium border border-slate-200/80">寒霜驚鴻劍</a>
          <a href="news.php?q=<?= urlencode('維護') ?>" class="px-2 py-0.5 bg-slate-100 hover:bg-rose-50 hover:text-rose-800 text-slate-600 transition-colors font-medium border border-slate-200/80">例行維護</a>
          <a href="news.php?cat=security" class="px-2 py-0.5 bg-slate-100 hover:bg-rose-50 hover:text-rose-800 text-slate-600 transition-colors font-medium border border-slate-200/80">防詐安全</a>
          <a href="news.php?cat=guide" class="px-2 py-0.5 bg-slate-100 hover:bg-rose-50 hover:text-rose-800 text-slate-600 transition-colors font-medium border border-slate-200/80">新手指南</a>
        </div>
      </form>
    </div>
  </section>

  <!-- 分類頁籤 -->
  <nav class="w-full bg-white/95 border-b border-slate-200 shadow-sm sticky top-20 z-30 backdrop-blur-md">
    <div class="max-w-7xl mx-auto px-6 lg:px-12 flex items-center justify-start gap-space-xs overflow-x-auto scrollbar-none py-2">
      <a href="news.php<?= news_h(news_query(['cat' => '', 'page' => ''])) ?>" class="px-space-md py-2 font-headline-md text-[16px] whitespace-nowrap tracking-wider relative transition-all flex items-center gap-1.5 border <?= $filterCat === '' ? 'text-white font-semibold bg-rose-700 shadow-sm border-rose-700' : 'text-slate-700 hover:text-rose-800 hover:bg-slate-100 border-transparent' ?>" style="font-family: &quot;Noto Serif TC&quot;, serif;">
        <span>全部消息</span>
        <span class="font-label-sm text-label-sm px-1.5 py-0.5 font-sans <?= $filterCat === '' ? 'text-rose-100 bg-rose-800/80' : 'text-slate-500 bg-slate-100' ?>"><?= (int)$totalPublished ?></span>
      </a>
      <?php foreach ($categories as $c): ?>
        <?php $isActive = ($filterCat === $c['code']); ?>
        <a href="news.php<?= news_h(news_query(['cat' => $c['code'], 'page' => ''])) ?>" class="px-space-md py-2 font-headline-md text-[16px] whitespace-nowrap tracking-wider relative transition-all flex items-center gap-1.5 border <?= $isActive ? 'text-white font-semibold bg-rose-700 shadow-sm border-rose-700' : 'text-slate-700 hover:text-rose-800 hover:bg-slate-100 border-transparent' ?>" style="font-family: &quot;Noto Serif TC&quot;, serif;">
          <span><?= news_h($c['name']) ?></span>
          <span class="font-label-sm text-label-sm px-1.5 py-0.5 font-sans <?= $isActive ? 'text-rose-100 bg-rose-800/80' : 'text-slate-500 bg-slate-100' ?>"><?= (int)($catCounts[$c['code']] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </nav>

  <div class="max-w-7xl mx-auto w-full px-6 lg:px-12 py-space-xl flex flex-col gap-space-xl">

    <?php if ($notice !== ''): ?>
      <div class="p-3.5 text-body-sm border flex items-center gap-2 <?= $noticeType === 'danger' ? 'border-rose-300 bg-rose-50 text-rose-800' : 'border-emerald-300 bg-emerald-50 text-emerald-800' ?>">
        <span class="material-symbols-outlined text-[18px]"><?= $noticeType === 'danger' ? 'error' : 'check_circle' ?></span>
        <?= news_h($notice) ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($featured)): ?>
      <!-- 重要置頂告示 -->
      <div class="flex flex-col gap-space-md">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-space-sm">
            <span class="w-1.5 h-5 bg-rose-700"></span>
            <h2 class="font-headline-lg text-headline-lg text-slate-900 tracking-widest font-bold flex items-center gap-2" style="font-family: &quot;Noto Serif TC&quot;, serif;">
              <span>重要置頂告示</span>
              <span class="w-5 h-5 bg-rose-50 border border-rose-300 text-rose-700 font-headline-md text-[11px] leading-4 flex items-center justify-center font-bold">急</span>
            </h2>
          </div>
          <span class="font-label-sm text-label-sm text-slate-400 tracking-widest uppercase font-semibold">CRITICAL DISPATCHES</span>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-space-lg">
          <?php foreach ($featured as $fi => $f): ?>
            <?php $isBig = ($fi === 0); ?>
            <article class="<?= $isBig ? 'lg:col-span-7' : 'lg:col-span-5' ?> bg-white border border-slate-200/90 p-space-lg flex flex-col justify-between relative shadow-[0_4px_24px_rgba(15,23,42,0.06)] hover:shadow-[0_8px_30px_rgba(15,23,42,0.09)] transition-all group">
              <?php if (!empty($f['cover_image'])): ?>
                <div class="relative w-full h-44 overflow-hidden mb-space-md border border-slate-200/80">
                  <img alt="<?= news_h($f['cover_caption'] ?: $f['title']) ?>" class="w-full h-full object-cover object-center group-hover:scale-105 transition-all duration-700" src="<?= news_h($f['cover_image']) ?>">
                  <div class="absolute inset-0 bg-gradient-to-t from-slate-900/80 via-slate-900/20 to-transparent"></div>
                  <?php if (!empty($f['cover_caption'])): ?>
                    <div class="absolute bottom-2.5 left-3 flex items-center gap-2">
                      <span class="px-2 py-0.5 bg-rose-700/90 text-white font-label-sm text-[11px] font-semibold tracking-wider backdrop-blur-sm"><?= news_h($f['cover_caption']) ?></span>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="absolute top-0 right-0 w-8 h-8 pointer-events-none overflow-hidden"><div class="w-12 h-1 bg-rose-700 rotate-45 transform translate-x-3 -translate-y-1 shadow-sm"></div></div>
              <div class="flex flex-col gap-space-md">
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-space-xs">
                    <span class="px-2 py-0.5 bg-rose-700 text-white font-label-sm text-label-sm tracking-widest uppercase flex items-center gap-1 font-semibold shadow-sm">
                      <span class="material-symbols-outlined text-[13px]" style="font-variation-settings: 'FILL' 1;">push_pin</span> 置頂 · <?= news_h($f['category_name'] ?: '公告') ?>
                    </span>
                    <span class="font-label-sm text-label-sm text-slate-400 ml-2 font-medium"><?= news_h(date('Y-m-d H:i', strtotime($f['published_at']))) ?></span>
                  </div>
                  <div class="w-8 h-8 border-2 border-rose-800 bg-rose-50 text-rose-800 flex items-center justify-center font-headline-md text-[18px] select-none font-bold shadow-inner" style="font-family: &quot;Noto Serif TC&quot;, serif;">印</div>
                </div>
                <div class="flex flex-col gap-space-xs">
                  <h3 class="<?= $isBig ? 'font-headline-xl text-headline-xl' : 'font-headline-lg text-headline-lg' ?> text-slate-900 group-hover:text-rose-800 transition-colors tracking-wide leading-snug font-bold" style="font-family: &quot;Noto Serif TC&quot;, serif;">
                    <a href="news.php?view=<?= (int)$f['id'] ?>"><?= news_h($f['title']) ?></a>
                  </h3>
                  <p class="font-body-md text-body-md text-slate-600 leading-relaxed"><?= news_h($f['summary']) ?></p>
                </div>
                <?php if ($isBig): ?>
                  <div class="grid grid-cols-3 gap-space-xs p-space-sm bg-slate-50 border border-slate-200/70">
                    <div class="flex flex-col"><span class="font-label-sm text-label-sm text-slate-400 uppercase font-semibold">發布者</span><span class="font-body-sm text-body-sm text-slate-900 font-bold truncate mt-0.5"><?= news_h($f['author']) ?></span></div>
                    <div class="flex flex-col border-l border-slate-200/70 pl-space-xs"><span class="font-label-sm text-label-sm text-slate-400 uppercase font-semibold">分類</span><span class="font-body-sm text-body-sm text-rose-800 font-bold mt-0.5"><?= news_h($f['category_name'] ?: '公告') ?></span></div>
                    <div class="flex flex-col border-l border-slate-200/70 pl-space-xs"><span class="font-label-sm text-label-sm text-slate-400 uppercase font-semibold">閱覽次數</span><span class="font-body-sm text-body-sm text-sky-800 font-bold mt-0.5"><?= number_format((int)$f['view_count']) ?></span></div>
                  </div>
                <?php else: ?>
                  <div class="p-space-sm bg-sky-50/80 border border-sky-100 flex items-center gap-space-sm">
                    <span class="material-symbols-outlined text-sky-700 text-[24px]">verified_user</span>
                    <p class="font-body-sm text-body-sm text-slate-700 font-semibold">官方發布之重要公告，請俠士務必詳閱，以維護自身權益。</p>
                  </div>
                <?php endif; ?>
              </div>
              <div class="flex items-center justify-between pt-space-md mt-space-md border-t border-slate-100">
                <div class="flex items-center gap-space-md text-slate-500 font-label-md text-label-md">
                  <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">visibility</span> <?= number_format((int)$f['view_count']) ?> 閱覽</span>
                  <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">chat_bubble_outline</span> <?= (int)$f['comment_count'] ?> 條回應</span>
                </div>
                <a class="px-space-md py-2 bg-gradient-to-r from-rose-800 to-rose-700 text-white font-label-md text-label-md tracking-widest uppercase hover:from-rose-900 hover:to-rose-800 shadow-sm transition-all flex items-center gap-1 font-semibold" href="news.php?view=<?= (int)$f['id'] ?>">
                  閱讀全文細則 <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                </a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- 近期公告列表 -->
    <div class="flex flex-col gap-space-md">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-space-sm">
          <span class="w-1.5 h-4 bg-sky-700"></span>
          <h2 class="font-headline-lg text-headline-lg text-slate-900 tracking-widest font-semibold" style="font-family: 'Noto Serif TC', serif;">
            <?= $filtersActive ? '篩選結果' : '近期公告列表' ?>
          </h2>
        </div>
        <span class="font-label-sm text-label-sm text-slate-400 tracking-widest uppercase font-semibold">NEWS ARCHIVE</span>
      </div>

      <div class="hidden md:grid grid-cols-12 gap-space-md px-space-lg py-space-sm bg-white border border-slate-200 font-label-sm text-label-sm text-slate-500 uppercase tracking-wider font-semibold">
        <div class="col-span-3">公告分類 / 發布日期</div>
        <div class="col-span-6">公告主題與摘要</div>
        <div class="col-span-1 text-center">閱覽次數</div>
        <div class="col-span-2 text-right">操 作</div>
      </div>

      <div class="flex flex-col gap-space-xs" id="newsListContainer">
        <?php if (empty($articles)): ?>
          <div class="p-space-xl bg-white border border-slate-200 text-center text-slate-400 font-body-md">
            <span class="material-symbols-outlined text-[36px] block mb-2">search_off</span>
            目前沒有符合條件的江湖快報，請調整篩選條件。
          </div>
        <?php endif; ?>
        <?php foreach ($articles as $a): ?>
          <?php
            $catInfo   = $catMap[$a['category_code']] ?? [];
            $accentKey = $a['accent'] ?? ($catInfo['accent'] ?? 'rose');
            $th        = news_accent_theme((string)$accentKey);
            $catName   = $a['category_name'] ?: ($catInfo['name'] ?? '公告');
            $catNameEn = $catInfo['name_en'] ?? '';
            $catIcon   = $a['icon'] ?: ($catInfo['icon'] ?? 'campaign');
          ?>
          <article class="news-item relative overflow-hidden grid grid-cols-1 md:grid-cols-12 gap-space-md p-space-lg bg-white border border-slate-200/90 hover:border-slate-300 transition-all shadow-[0_2px_12px_rgba(15,23,42,0.03)] hover:shadow-[0_10px_30px_rgba(15,23,42,0.08)] group" data-category="<?= news_h($a['category_code']) ?>">
            <!-- 左側分類色條 -->
            <span class="absolute left-0 top-0 h-full w-1 bg-gradient-to-b <?= news_h($th['bar']) ?> opacity-80 group-hover:w-1.5 transition-all"></span>
            <!-- 柔光裝飾 -->
            <span class="pointer-events-none absolute -right-12 -top-12 w-32 h-32 rounded-full <?= news_h($th['soft']) ?> opacity-40 group-hover:opacity-70 transition-opacity"></span>

            <!-- 分類 / 日期 -->
            <div class="md:col-span-3 relative flex flex-row md:flex-col justify-between md:justify-center items-start gap-2 pl-1">
              <a href="news.php?cat=<?= urlencode($a['category_code']) ?>" class="flex items-center gap-2.5 min-w-0 group/cat" title="查看「<?= news_h($catName) ?>」全部公告">
                <span class="relative w-10 h-10 rounded-xl bg-gradient-to-br <?= news_h($th['grad']) ?> text-white flex items-center justify-center flex-none shadow-lg <?= news_h($th['glow']) ?> ring-1 ring-inset ring-white/30 transition-transform group-hover/cat:scale-105">
                  <span class="material-symbols-outlined text-[20px]"><?= news_h($catIcon) ?></span>
                </span>
                <span class="flex flex-col min-w-0">
                  <span class="font-headline-md text-[16px] font-bold tracking-wide leading-tight whitespace-nowrap <?= news_h($th['text']) ?> transition-colors"><?= news_h($catName) ?></span>
                  <?php if ($catNameEn !== ''): ?>
                    <span class="font-label-sm text-[9px] text-slate-400 tracking-[0.18em] uppercase font-bold leading-tight mt-0.5"><?= news_h($catNameEn) ?></span>
                  <?php endif; ?>
                </span>
              </a>
              <span class="flex items-center gap-1 font-label-sm text-label-sm text-slate-400 font-medium whitespace-nowrap">
                <span class="material-symbols-outlined text-[14px]">schedule</span><?= news_h(date('Y-m-d H:i', strtotime($a['published_at']))) ?>
              </span>
            </div>

            <!-- 標題 / 摘要 -->
            <div class="md:col-span-6 relative flex flex-col justify-center gap-1.5">
              <a class="font-headline-md text-[18px] text-slate-900 <?= news_h($th['hover']) ?> transition-colors tracking-wide font-semibold leading-snug" href="news.php?view=<?= (int)$a['id'] ?>" style="font-family: &quot;Noto Serif TC&quot;, serif;">
                <?php if ((int)$a['is_sticky'] === 1): ?><span class="material-symbols-outlined text-[15px] text-rose-700 align-middle mr-1" style="font-variation-settings: 'FILL' 1;">push_pin</span><?php endif; ?><?= news_h($a['title']) ?>
              </a>
              <p class="font-body-sm text-body-sm text-slate-500" style="display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;"><?= news_h($a['summary']) ?></p>
            </div>

            <!-- 閱覽次數 -->
            <div class="md:col-span-1 relative flex items-center justify-start md:justify-center text-slate-500 font-label-sm text-label-sm">
              <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[15px]">visibility</span> <?= number_format((int)$a['view_count']) ?></span>
            </div>

            <!-- 操作 -->
            <div class="md:col-span-2 relative flex items-center justify-end">
              <a class="px-space-md py-1.5 bg-slate-100 group-hover:bg-rose-800 group-hover:text-white text-slate-700 border border-slate-200 group-hover:border-rose-800 font-label-sm text-label-sm tracking-wider uppercase transition-all font-semibold flex items-center gap-1" href="news.php?view=<?= (int)$a['id'] ?>">
                <span><?= news_h(news_action_text($a['category_code'])) ?></span>
                <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 分頁 -->
    <div class="flex flex-col sm:flex-row items-center justify-between gap-space-md py-space-lg">
      <div class="text-slate-500 font-label-md text-label-md">
        顯示第 <span class="text-slate-900 font-semibold"><?= $totalRows > 0 ? $offset + 1 : 0 ?></span> 至 <span class="text-slate-900 font-semibold"><?= min($offset + $pageSize, $totalRows) ?></span> 筆，共 <span class="text-rose-800 font-semibold"><?= (int)$totalRows ?></span> 筆江湖快報
      </div>
      <?php if ($totalPages > 1): ?>
        <div class="flex items-center gap-space-xs select-none">
          <?php if ($page > 1): ?>
            <a href="news.php<?= news_h(news_query(['page' => $page - 1])) ?>" class="px-space-md py-2 bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-label-md text-label-md flex items-center gap-1 transition-colors font-medium"><span class="material-symbols-outlined text-[16px]">west</span> 上一頁</a>
          <?php else: ?>
            <button class="px-space-md py-2 bg-white border border-slate-200 text-slate-400 font-label-md text-label-md flex items-center gap-1 opacity-60 cursor-not-allowed" disabled><span class="material-symbols-outlined text-[16px]">west</span> 上一頁</button>
          <?php endif; ?>
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php if ($p === $page): ?>
              <button class="w-10 h-10 bg-rose-800 text-white font-label-md text-[15px] flex items-center justify-center font-bold shadow-sm"><?= $p ?></button>
            <?php else: ?>
              <a href="news.php<?= news_h(news_query(['page' => $p])) ?>" class="w-10 h-10 bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-label-md text-[15px] flex items-center justify-center transition-colors font-medium"><?= $p ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <a href="news.php<?= news_h(news_query(['page' => $page + 1])) ?>" class="px-space-md py-2 bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 font-label-md text-label-md flex items-center gap-1 transition-colors font-medium">下一頁 <span class="material-symbols-outlined text-[16px]">east</span></a>
          <?php else: ?>
            <button class="px-space-md py-2 bg-white border border-slate-200 text-slate-400 font-label-md text-label-md flex items-center gap-1 opacity-60 cursor-not-allowed" disabled>下一頁 <span class="material-symbols-outlined text-[16px]">east</span></button>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- 訂閱飛鴿傳書 -->
    <div class="p-space-xl bg-gradient-to-r from-white via-slate-50 to-sky-50/40 border border-slate-200 flex flex-col lg:flex-row items-center justify-between gap-space-lg shadow-[0_4px_20px_rgba(15,23,42,0.05)] relative overflow-hidden">
      <div class="absolute -right-6 -bottom-6 w-36 h-36 bg-rose-50 rounded-full opacity-60 pointer-events-none"></div>
      <div class="flex items-center gap-space-lg z-10">
        <div class="w-14 h-14 bg-sky-50 border border-sky-200 flex items-center justify-center text-sky-800 shadow-sm">
          <span class="material-symbols-outlined text-[32px]">mark_email_read</span>
        </div>
        <div class="flex flex-col">
          <div class="flex items-center gap-2">
            <h3 class="font-headline-lg text-headline-lg text-slate-900 tracking-wider font-bold" style="font-family: &quot;Noto Serif TC&quot;, serif;">訂閱飛鴿傳書 · 伺服器即時情報</h3>
            <span class="px-2 py-0.5 bg-rose-100 text-rose-800 font-label-sm text-label-sm font-semibold tracking-wider">專屬速遞</span>
          </div>
          <p class="font-body-sm text-body-sm text-slate-600 mt-1"><?= news_h($SET['subscribe_blurb'] ?? '重大改版與停機維護前主動推送至您的電子信箱。') ?></p>
        </div>
      </div>
      <form method="POST" action="news.php" class="flex w-full lg:w-auto items-center gap-space-xs z-10">
        <input type="hidden" name="action" value="subscribe">
        <input type="hidden" name="form_token" value="<?= news_h($formToken) ?>">
        <input class="px-space-md py-2.5 bg-white border border-slate-300 text-slate-900 placeholder:text-slate-400 text-body-md focus:outline-none focus:border-rose-700 w-full sm:w-80 shadow-inner transition-colors" name="email" placeholder="輸入俠士之信箱地址..." type="email" required>
        <button type="submit" class="px-space-lg py-2.5 bg-gradient-to-r from-rose-800 to-rose-700 hover:from-rose-900 hover:to-rose-800 text-white font-label-md text-label-md tracking-widest uppercase transition-all whitespace-nowrap font-semibold shadow-md flex items-center gap-1.5">
          <span class="material-symbols-outlined text-[16px]">send</span> 立即訂閱
        </button>
      </form>
    </div>
  </div>
<?php endif; ?>
</div>
</main>

<footer class="w-full bg-white border-t border-slate-200 text-slate-500">
  <div class="max-w-7xl mx-auto px-6 lg:px-12 py-space-xl flex flex-col gap-space-lg">
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-gutter">
      <div class="flex items-center gap-space-md">
        <span class="font-headline-xl text-headline-xl text-slate-900 tracking-widest font-semibold" style="font-family: &quot;Noto Serif TC&quot;, serif;">踏雪笑傲</span>
        <div class="h-8 w-px bg-slate-200 hidden sm:block"></div>
        <span class="font-body-sm text-body-sm text-slate-500">霜刃未曾試，今日把示君。天下風雲出我輩，一入江湖歲月催。</span>
      </div>
      <div class="flex flex-wrap gap-space-md font-label-md text-label-md">
        <a class="text-slate-600 hover:text-rose-800 transition-colors" href="#">客服中心</a>
        <a class="text-slate-600 hover:text-rose-800 transition-colors" href="#">服務條款</a>
        <a class="text-slate-600 hover:text-rose-800 transition-colors" href="#">隱私權政策</a>
        <a class="text-slate-600 hover:text-rose-800 transition-colors" href="#">家長監護</a>
        <a class="text-slate-600 hover:text-rose-800 transition-colors" href="#">社群交流</a>
      </div>
    </div>
    <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-space-md text-body-sm font-body-sm">
      <div class="flex items-center gap-space-md">
        <div class="px-space-xs py-1 bg-slate-100 text-rose-800 border border-slate-200 font-label-sm text-label-sm font-semibold">輔 15 級</div>
        <p class="text-slate-500">本遊戲情節涉及性、暴力、虛擬戀愛或結婚。注意使用時間，避免沉迷於遊戲。遊戲部分內容須另行支付費用。</p>
      </div>
      <div class="text-slate-400 font-label-sm text-label-sm">© <?= date('Y') ?> SNOW WANDERER ONLINE. 踏雪笑傲工作室 版權所有. All Rights Reserved.</div>
    </div>
  </div>
</footer>
</body>
</html>

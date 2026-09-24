<?php
/**
 * =============================================================
 *  踏雪笑傲 · 江湖邸報（官方公告）系統 — 共用設定 / 資料庫工具
 *  獨立資料庫：swo_news（與主遊戲庫 sa 分離）
 *  使用頁面：
 *    公開前台  swo/news.php
 *    管理後台  admin/news_admin.php
 * =============================================================
 */

require_once __DIR__ . '/config.php'; // 取得 $DBHost / $DBUser / $DBPassword

if (!defined('NEWS_DB_NAME')) {
    define('NEWS_DB_NAME', 'swo_news');
}

/** 取得 swo_news 資料庫連線（utf8mb4） */
function news_db(): mysqli
{
    global $DBHost, $DBUser, $DBPassword;
    $db = @mysqli_connect($DBHost, $DBUser, $DBPassword, NEWS_DB_NAME);
    if (!$db) {
        die('公告資料庫連線失敗：' . mysqli_connect_error());
    }
    mysqli_set_charset($db, 'utf8mb4');
    return $db;
}

/** HTML 轉義 */
function news_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** 讀取全部系統設定為關聯陣列（key => value） */
function news_settings_all(mysqli $db): array
{
    $out = [];
    $res = mysqli_query($db, "SELECT `setting_key`, `setting_value` FROM `news_settings`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[$row['setting_key']] = (string)$row['setting_value'];
        }
    }
    return $out;
}

/** 讀取單一設定值 */
function news_setting(mysqli $db, string $key, string $default = ''): string
{
    $stmt = $db->prepare("SELECT `setting_value` FROM `news_settings` WHERE `setting_key` = ? LIMIT 1");
    if (!$stmt) { return $default; }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['setting_value'] : $default;
}

/** 新增或更新設定值 */
function news_setting_put(mysqli $db, string $key, string $value, string $type = 'text', string $name = '', string $desc = ''): void
{
    $stmt = $db->prepare(
        "INSERT INTO `news_settings` (`setting_key`,`setting_value`,`setting_type`,`display_name`,`description`)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)"
    );
    if (!$stmt) { return; }
    $stmt->bind_param('sssss', $key, $value, $type, $name, $desc);
    $stmt->execute();
    $stmt->close();
}

/** 讀取啟用中的公告分類（依 sort_order 排序） */
function news_categories(mysqli $db, bool $onlyEnabled = true): array
{
    $sql = "SELECT * FROM `news_categories`" . ($onlyEnabled ? " WHERE `enabled` = 1" : "") . " ORDER BY `sort_order` ASC, `id` ASC";
    $res = mysqli_query($db, $sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** 分類代碼 => 分類資料 */
function news_category_map(mysqli $db): array
{
    $map = [];
    foreach (news_categories($db, false) as $c) {
        $map[$c['code']] = $c;
    }
    return $map;
}

/** 公告狀態顯示對照 */
function news_status_meta(string $status): array
{
    $map = [
        'draft'     => ['label' => '草稿',     'badge' => 'bg-slate-100 text-slate-600 border-slate-300',    'dot' => 'bg-slate-400'],
        'published' => ['label' => '已發布',   'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'dot' => 'bg-emerald-500'],
        'scheduled' => ['label' => '預約發布', 'badge' => 'bg-sky-50 text-sky-700 border-sky-200',          'dot' => 'bg-sky-500'],
        'archived'  => ['label' => '已封存',   'badge' => 'bg-amber-50 text-amber-700 border-amber-200',    'dot' => 'bg-amber-500'],
    ];
    return $map[$status] ?? $map['draft'];
}

/** 公告優先度顯示對照 */
function news_priority_meta(string $priority): array
{
    $map = [
        'low'    => ['label' => '低',   'badge' => 'bg-slate-100 text-slate-500 border-slate-200'],
        'normal' => ['label' => '一般', 'badge' => 'bg-sky-50 text-sky-700 border-sky-200'],
        'high'   => ['label' => '高',   'badge' => 'bg-orange-50 text-orange-700 border-orange-200'],
        'urgent' => ['label' => '緊急', 'badge' => 'bg-rose-50 text-rose-700 border-rose-200'],
    ];
    return $map[$priority] ?? $map['normal'];
}

/** 產生 URL 代稱（slug） */
function news_slugify(string $text): string
{
    $text = trim($text);
    $text = preg_replace('/\s+/u', '-', $text);
    $text = preg_replace('/[^\p{L}\p{N}\-_]+/u', '', $text);
    $text = trim($text, '-');
    if ($text === '') {
        $text = 'news-' . date('YmdHis');
    }
    return mb_strtolower($text, 'UTF-8');
}

/** 相對時間 */
function news_ago(?string $dt): string
{
    if (empty($dt) || $dt === '0000-00-00 00:00:00') { return '—'; }
    $ts = strtotime($dt);
    if ($ts === false) { return '—'; }
    $d = time() - $ts;
    if ($d < 0) { $d = 0; }
    if ($d < 60) { return '剛剛'; }
    if ($d < 3600) { return floor($d / 60) . ' 分鐘前'; }
    if ($d < 86400) { return floor($d / 3600) . ' 小時前'; }
    if ($d < 2592000) { return floor($d / 86400) . ' 天前'; }
    return date('Y-m-d', $ts);
}

/** 依月份產生年度篩選清單（前台用） */
function news_month_options(mysqli $db): array
{
    $out = [];
    $res = mysqli_query(
        $db,
        "SELECT DATE_FORMAT(`published_at`, '%Y-%m') AS ym, COUNT(*) AS c
           FROM `news_articles`
          WHERE `status` = 'published' AND `published_at` IS NOT NULL
          GROUP BY ym ORDER BY ym DESC"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) { $out[] = $row; }
    }
    return $out;
}

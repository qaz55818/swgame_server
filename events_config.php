<?php
/**
 * =============================================================
 *  踏雪笑傲 · 江湖活動（盛典）系統 — 共用設定 / 資料庫工具
 *  獨立資料庫：swo_events
 *  使用頁面：
 *    公開前台  swo/events.php
 *    管理後台  admin/events_admin.php
 * =============================================================
 */

require_once __DIR__ . '/config.php';

if (!defined('EVENTS_DB_NAME')) {
    define('EVENTS_DB_NAME', 'swo_events');
}

/** 取得 swo_events 連線（utf8mb4） */
function events_db(): mysqli
{
    global $DBHost, $DBUser, $DBPassword;
    $db = @mysqli_connect($DBHost, $DBUser, $DBPassword, EVENTS_DB_NAME);
    if (!$db) {
        die('活動資料庫連線失敗：' . mysqli_connect_error());
    }
    mysqli_set_charset($db, 'utf8mb4');
    return $db;
}

/** HTML 轉義 */
function ev_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** 讀取全部設定 */
function ev_settings_all(mysqli $db): array
{
    $out = [];
    $res = mysqli_query($db, "SELECT `setting_key`, `setting_value` FROM `ev_settings`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[$row['setting_key']] = (string)$row['setting_value'];
        }
    }
    return $out;
}

/** 讀取單一設定 */
function ev_setting(mysqli $db, string $key, string $default = ''): string
{
    $stmt = $db->prepare("SELECT `setting_value` FROM `ev_settings` WHERE `setting_key` = ? LIMIT 1");
    if (!$stmt) { return $default; }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['setting_value'] : $default;
}

/** 新增或更新設定 */
function ev_setting_put(mysqli $db, string $key, string $value, string $type = 'text', string $name = '', string $desc = ''): void
{
    $stmt = $db->prepare(
        "INSERT INTO `ev_settings` (`setting_key`,`setting_value`,`setting_type`,`display_name`,`description`)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)"
    );
    if (!$stmt) { return; }
    $stmt->bind_param('sssss', $key, $value, $type, $name, $desc);
    $stmt->execute();
    $stmt->close();
}

/** 活動分類 */
function ev_categories(mysqli $db, bool $onlyEnabled = true): array
{
    $sql = "SELECT * FROM `ev_categories`" . ($onlyEnabled ? " WHERE `enabled` = 1" : "") . " ORDER BY `sort_order` ASC, `id` ASC";
    $res = mysqli_query($db, $sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** 分類代碼 => 資料 */
function ev_category_map(mysqli $db): array
{
    $map = [];
    foreach (ev_categories($db, false) as $c) {
        $map[$c['code']] = $c;
    }
    return $map;
}

/** 活動列表 */
function ev_events(mysqli $db, ?string $categoryCode = null, bool $onlyEnabled = true, int $limit = 0): array
{
    $w = [];
    if ($onlyEnabled) { $w[] = "`enabled` = 1"; }
    if ($categoryCode !== null && $categoryCode !== '') {
        $w[] = "`category_code` = '" . mysqli_real_escape_string($db, $categoryCode) . "'";
    }
    $sql = "SELECT * FROM `ev_events`" . ($w ? " WHERE " . implode(' AND ', $w) : "")
         . " ORDER BY `is_featured` DESC, `sort_order` ASC, `id` DESC";
    if ($limit > 0) { $sql .= " LIMIT " . (int)$limit; }
    $res = mysqli_query($db, $sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** 精選主打活動 */
function ev_featured(mysqli $db): ?array
{
    $res = mysqli_query($db, "SELECT * FROM `ev_events` WHERE `is_featured` = 1 AND `enabled` = 1 ORDER BY `sort_order` ASC, `id` DESC LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) { return $row; }
    return null;
}

/** 單一活動 */
function ev_event(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare("SELECT * FROM `ev_events` WHERE `id` = ? AND `enabled` = 1 LIMIT 1");
    if (!$stmt) { return null; }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** 活動獎勵清單 */
function ev_rewards(mysqli $db, int $eventId): array
{
    $res = $db->query("SELECT * FROM `ev_rewards` WHERE `event_id` = " . (int)$eventId . " ORDER BY `sort_order` ASC, `id` ASC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** 活動規則 / 注意事項 */
function ev_notes(mysqli $db, bool $onlyEnabled = true): array
{
    $sql = "SELECT * FROM `ev_notes`" . ($onlyEnabled ? " WHERE `enabled` = 1" : "") . " ORDER BY `sort_order` ASC, `id` ASC";
    $res = mysqli_query($db, $sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** 各分類活動數量 */
function ev_category_counts(mysqli $db): array
{
    $out = [];
    $res = mysqli_query($db, "SELECT `category_code`, COUNT(*) AS c FROM `ev_events` WHERE `enabled` = 1 GROUP BY `category_code`");
    if ($res) { while ($r = $res->fetch_assoc()) { $out[$r['category_code']] = (int)$r['c']; } }
    return $out;
}

/** 取色系主題 */
function ev_accent_theme(string $accent): array
{
    $map = [
        'rose'    => ['bar' => 'from-rose-400 via-rose-500 to-rose-700', 'text' => 'text-rose-700', 'soft' => 'bg-rose-50', 'ring' => 'ring-rose-200', 'glow' => 'shadow-rose-500/25'],
        'sky'     => ['bar' => 'from-sky-400 via-sky-500 to-sky-700', 'text' => 'text-sky-700', 'soft' => 'bg-sky-50', 'ring' => 'ring-sky-200', 'glow' => 'shadow-sky-500/25'],
        'emerald' => ['bar' => 'from-emerald-400 via-emerald-500 to-emerald-700', 'text' => 'text-emerald-700', 'soft' => 'bg-emerald-50', 'ring' => 'ring-emerald-200', 'glow' => 'shadow-emerald-500/25'],
        'amber'   => ['bar' => 'from-amber-300 via-amber-400 to-amber-600', 'text' => 'text-amber-700', 'soft' => 'bg-amber-50', 'ring' => 'ring-amber-200', 'glow' => 'shadow-amber-500/25'],
        'violet'  => ['bar' => 'from-violet-400 via-violet-500 to-violet-700', 'text' => 'text-violet-700', 'soft' => 'bg-violet-50', 'ring' => 'ring-violet-200', 'glow' => 'shadow-violet-500/25'],
        'indigo'  => ['bar' => 'from-indigo-400 via-indigo-500 to-indigo-700', 'text' => 'text-indigo-700', 'soft' => 'bg-indigo-50', 'ring' => 'ring-indigo-200', 'glow' => 'shadow-indigo-500/25'],
        'teal'    => ['bar' => 'from-teal-400 via-teal-500 to-teal-700', 'text' => 'text-teal-700', 'soft' => 'bg-teal-50', 'ring' => 'ring-teal-200', 'glow' => 'shadow-teal-500/25'],
        'slate'   => ['bar' => 'from-slate-400 via-slate-500 to-slate-700', 'text' => 'text-slate-600', 'soft' => 'bg-slate-100', 'ring' => 'ring-slate-300', 'glow' => 'shadow-slate-500/25'],
    ];
    return $map[$accent] ?? $map['rose'];
}

/** 相對時間 */
function ev_ago(?string $dt): string
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

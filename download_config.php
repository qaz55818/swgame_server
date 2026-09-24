<?php
/**
 * =============================================================
 *  踏雪笑傲 · 客戶端下載中心 — 共用設定 / 資料庫工具
 *  獨立資料庫：swo_download（與遊戲主庫 sa、公告庫 swo_news 分離）
 *  使用頁面：
 *    公開前台  swo/download.php
 *    管理後台  admin/download_admin.php
 * =============================================================
 */

require_once __DIR__ . '/config.php'; // 取得 $DBHost / $DBUser / $DBPassword

if (!defined('DOWNLOAD_DB_NAME')) {
    define('DOWNLOAD_DB_NAME', 'swo_download');
}

/** 取得 swo_download 資料庫連線（utf8mb4） */
function download_db(): mysqli
{
    global $DBHost, $DBUser, $DBPassword;
    $db = @mysqli_connect($DBHost, $DBUser, $DBPassword, DOWNLOAD_DB_NAME);
    if (!$db) {
        die('下載資料庫連線失敗：' . mysqli_connect_error());
    }
    mysqli_set_charset($db, 'utf8mb4');
    return $db;
}

/** HTML 轉義 */
function dl_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** 讀取全部設定為關聯陣列 */
function dl_settings_all(mysqli $db): array
{
    $out = [];
    $res = mysqli_query($db, "SELECT `setting_key`, `setting_value` FROM `dl_settings`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[$row['setting_key']] = (string)$row['setting_value'];
        }
    }
    return $out;
}

/** 讀取單一設定值 */
function dl_setting(mysqli $db, string $key, string $default = ''): string
{
    $stmt = $db->prepare("SELECT `setting_value` FROM `dl_settings` WHERE `setting_key` = ? LIMIT 1");
    if (!$stmt) { return $default; }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['setting_value'] : $default;
}

/** 新增或更新設定值 */
function dl_setting_put(mysqli $db, string $key, string $value, string $type = 'text', string $name = '', string $desc = ''): void
{
    $stmt = $db->prepare(
        "INSERT INTO `dl_settings` (`setting_key`,`setting_value`,`setting_type`,`display_name`,`description`)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)"
    );
    if (!$stmt) { return; }
    $stmt->bind_param('sssss', $key, $value, $type, $name, $desc);
    $stmt->execute();
    $stmt->close();
}

/** 讀取平台 */
function dl_platforms(mysqli $db, bool $onlyEnabled = true): array
{
    $sql = "SELECT * FROM `dl_platforms`" . ($onlyEnabled ? " WHERE `enabled` = 1" : "") . " ORDER BY `sort_order` ASC, `id` ASC";
    $res = mysqli_query($db, $sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** 平台代碼 => 平台資料 */
function dl_platform_map(mysqli $db): array
{
    $map = [];
    foreach (dl_platforms($db, false) as $p) {
        $map[$p['code']] = $p;
    }
    return $map;
}

/** 讀取版本（可依平台、僅啟用） */
function dl_releases(mysqli $db, ?string $platformCode = null, bool $onlyEnabled = true): array
{
    $w = [];
    if ($onlyEnabled) { $w[] = "`enabled` = 1"; }
    if ($platformCode !== null && $platformCode !== '') { $w[] = "`platform_code` = '" . mysqli_real_escape_string($db, $platformCode) . "'"; }
    $sql = "SELECT * FROM `dl_releases`" . ($w ? " WHERE " . implode(' AND ', $w) : "") . " ORDER BY `sort_order` ASC, `id` DESC";
    $res = mysqli_query($db, $sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** 取得某平台當前版本 */
function dl_current_release(mysqli $db, string $platformCode): ?array
{
    $stmt = $db->prepare("SELECT * FROM `dl_releases` WHERE `platform_code` = ? AND `enabled` = 1 ORDER BY `is_current` DESC, `sort_order` ASC, `id` DESC LIMIT 1");
    if (!$stmt) { return null; }
    $stmt->bind_param('s', $platformCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** 讀取某版本的分流載點 */
function dl_mirrors(mysqli $db, int $releaseId, bool $onlyEnabled = true): array
{
    $sql = "SELECT * FROM `dl_mirrors` WHERE `release_id` = " . (int)$releaseId . ($onlyEnabled ? " AND `enabled` = 1" : "") . " ORDER BY `sort_order` ASC, `id` ASC";
    $res = mysqli_query($db, $sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** 讀取配備需求 */
function dl_requirements(mysqli $db, bool $onlyEnabled = true): array
{
    $sql = "SELECT * FROM `dl_requirements`" . ($onlyEnabled ? " WHERE `enabled` = 1" : "") . " ORDER BY `sort_order` ASC, `id` ASC";
    $res = mysqli_query($db, $sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** 讀取 FAQ */
function dl_faqs(mysqli $db, bool $onlyEnabled = true): array
{
    $sql = "SELECT * FROM `dl_faqs`" . ($onlyEnabled ? " WHERE `enabled` = 1" : "") . " ORDER BY `sort_order` ASC, `id` ASC";
    $res = mysqli_query($db, $sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** 平台色系主題 */
function dl_accent_theme(string $accent): array
{
    $map = [
        'sky' => ['grad' => 'from-sky-500 to-sky-700', 'text' => 'text-sky-700', 'soft' => 'bg-sky-50', 'border' => 'border-sky-200', 'dot' => 'bg-sky-500'],
        'rose' => ['grad' => 'from-rose-500 to-rose-700', 'text' => 'text-rose-700', 'soft' => 'bg-rose-50', 'border' => 'border-rose-200', 'dot' => 'bg-rose-500'],
        'emerald' => ['grad' => 'from-emerald-500 to-emerald-700', 'text' => 'text-emerald-700', 'soft' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'dot' => 'bg-emerald-500'],
        'violet' => ['grad' => 'from-violet-500 to-violet-700', 'text' => 'text-violet-700', 'soft' => 'bg-violet-50', 'border' => 'border-violet-200', 'dot' => 'bg-violet-500'],
        'slate' => ['grad' => 'from-slate-500 to-slate-700', 'text' => 'text-slate-700', 'soft' => 'bg-slate-100', 'border' => 'border-slate-300', 'dot' => 'bg-slate-500'],
    ];
    return $map[$accent] ?? $map['sky'];
}

/** 相對時間 */
function dl_ago(?string $dt): string
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

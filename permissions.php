<?php
/**
 * =============================================================
 *  踏雪笑傲 · 會員功能權限工具庫
 *  -------------------------------------------------------------
 *  資料表：user_permissions（每位會員一列，未建立時預設全部開啟）
 *  管理端：admin/member_admin.php
 *  前台：home.php / recharge.php / exchange.php / usercontrol.php
 *        / support.php / download.php
 *
 *  使用方式：
 *    require_once __DIR__ . '/permissions.php';
 *    member_permission_require($Link, $uid, 'can_recharge', ['back' => 'home.php']);
 *
 *  未授權時會輸出一致的「彈出視窗」提示頁並結束執行。
 * =============================================================
 */

if (!defined('MEMBER_PERMISSION_TABLE')) {
    define('MEMBER_PERMISSION_TABLE', 'user_permissions');
}

/** 全部權限鍵（順序即後台顯示順序） */
function member_permission_keys()
{
    return [
        'can_recharge',
        'can_exchange',
        'can_change_password',
        'can_support',
        'can_view_assets',
        'can_download',
    ];
}

/** 權限定義（標題、說明、圖示、對應頁面） */
function member_permission_map()
{
    return [
        'can_recharge' => [
            'label' => '儲值點數',
            'short' => '儲值權限',
            'desc'  => '允許使用儲值點數功能，進入儲值頁面付款入帳。',
            'icon'  => 'fa-coins',
            'color' => 'amber',
            'page'  => 'recharge.php',
        ],
        'can_exchange' => [
            'label' => '兌換元寶',
            'short' => '兌換權限',
            'desc'  => '允許使用兌換中心，將點數兌換為遊戲元寶。',
            'icon'  => 'fa-right-left',
            'color' => 'sky',
            'page'  => 'exchange.php',
        ],
        'can_change_password' => [
            'label' => '修改密碼',
            'short' => '修改密碼權限',
            'desc'  => '允許進入帳號安全中心檢視資料並修改登入密碼。',
            'icon'  => 'fa-key',
            'color' => 'rose',
            'page'  => 'usercontrol.php',
        ],
        'can_support' => [
            'label' => '申訴回報',
            'short' => '申訴回報權限',
            'desc'  => '允許使用申訴回報系統與客服團隊聯繫。',
            'icon'  => 'fa-headset',
            'color' => 'violet',
            'page'  => 'support.php',
        ],
        'can_view_assets' => [
            'label' => '檢視資產',
            'short' => '資產檢視權限',
            'desc'  => '允許在會員中心檢視點數與元寶餘額（關閉則隱藏資產面板）。',
            'icon'  => 'fa-gem',
            'color' => 'emerald',
            'page'  => 'home.php',
        ],
        'can_download' => [
            'label' => '遊戲下載',
            'short' => '遊戲下載權限',
            'desc'  => '允許下載遊戲主程式、更新檔與啟動器。',
            'icon'  => 'fa-download',
            'color' => 'slate',
            'page'  => 'download.php',
        ],
    ];
}

/** 預設權限（未設定者一律允許） */
function member_permission_defaults()
{
    $defaults = [];
    foreach (member_permission_keys() as $key) {
        $defaults[$key] = 1;
    }
    return $defaults;
}

/** 確保權限資料表存在，並為既有會員補上預設列（可重複執行、非破壞性） */
function member_permissions_ensure_table($Link)
{
    if (!$Link) {
        return;
    }
    $keys = member_permission_keys();
    $cols = [];
    foreach ($keys as $key) {
        $cols[] = "`$key` tinyint(1) NOT NULL DEFAULT 1";
    }
    $sql = "CREATE TABLE IF NOT EXISTS `" . MEMBER_PERMISSION_TABLE . "` (
        `uid` int(11) NOT NULL,
        " . implode(",\n        ", $cols) . ",
        `updated_by` varchar(50) NOT NULL DEFAULT '',
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`uid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try {
        $Link->query($sql);
        $Link->query("INSERT INTO `" . MEMBER_PERMISSION_TABLE . "` (`uid`) SELECT `ID` FROM `users` WHERE `ID` NOT IN (SELECT `uid` FROM `" . MEMBER_PERMISSION_TABLE . "`)");
    } catch (Throwable $e) {
        // 資料庫權限不足時略過，前台仍以預設值運作
    }
}

/** 儲存會員功能權限（upsert）；成功回傳 true */
function member_permissions_save($Link, $uid, array $perms, $updatedBy = '')
{
    $uid = (int)$uid;
    if (!$Link || $uid <= 0) {
        return false;
    }
    $keys = member_permission_keys();
    $values = [];
    foreach ($keys as $key) {
        $values[$key] = (!empty($perms[$key])) ? 1 : 0;
    }
    $cols = '`uid`,`' . implode('`,`', $keys) . '`,`updated_by`';
    $holders = implode(',', array_fill(0, count($keys) + 2, '?'));
    $updates = [];
    foreach ($keys as $key) {
        $updates[] = "`$key`=VALUES(`$key`)";
    }
    $updates[] = "`updated_by`=VALUES(`updated_by`)";
    $sql = "INSERT INTO `" . MEMBER_PERMISSION_TABLE . "` ($cols) VALUES ($holders)
            ON DUPLICATE KEY UPDATE " . implode(',', $updates);
    $types = 'i' . str_repeat('i', count($keys)) . 's';
    $params = array_merge([$uid], array_values($values), [(string)$updatedBy]);
    try {
        $stmt = $Link->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 讀取會員權限（資料表不存在或查無資料時回傳預設值） */
function member_permissions_load($Link, $uid)
{
    $perms = member_permission_defaults();
    $uid = (int)$uid;
    if (!$Link || $uid <= 0) {
        return $perms;
    }
    try {
        $keys = member_permission_keys();
        $cols = '`' . implode('`,`', $keys) . '`';
        $stmt = $Link->prepare("SELECT $cols FROM `" . MEMBER_PERMISSION_TABLE . "` WHERE `uid` = ? LIMIT 1");
        if (!$stmt) {
            return $perms;
        }
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            foreach ($keys as $key) {
                $perms[$key] = ((int)($row[$key] ?? 1) === 1) ? 1 : 0;
            }
        }
    } catch (Throwable $e) {
        // 資料表尚未建立時沿用預設值
    }
    return $perms;
}

/** 檢查單一權限是否允許 */
function member_permission_allowed($Link, $uid, $key)
{
    $perms = member_permissions_load($Link, $uid);
    return !empty($perms[$key]);
}

/** 產生授權提示視窗 HTML（供前台/後台共用） */
function member_permission_modal_html($title, $message, $backUrl, $backText = '返回會員中心', $countdown = 0)
{
    $title = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');
    $message = $message; // 由呼叫端提供，可含 HTML
    $backUrl = htmlspecialchars((string)$backUrl, ENT_QUOTES, 'UTF-8');
    $backText = htmlspecialchars((string)$backText, ENT_QUOTES, 'UTF-8');
    $countdown = (int)$countdown;
    return <<<HTML
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm px-4" id="perm-modal">
    <div class="snow-card max-w-sm w-full rounded-2xl p-7 text-center" id="perm-modal-card">
        <div class="w-16 h-16 mx-auto rounded-full bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-700 text-2xl mb-4 shadow-sm">
            <i class="fa-solid fa-lock"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-800 font-serif mb-2">{$title}</h3>
        <p class="text-sm text-slate-500 leading-relaxed mb-6">{$message}</p>
        <a href="{$backUrl}" class="inline-flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-gradient-to-r from-rose-700 to-rose-800 hover:from-rose-800 hover:to-rose-900 text-white text-sm font-semibold transition-all shadow-md no-underline">
            <i class="fa-solid fa-arrow-left"></i> {$backText}
        </a>
    </div>
</div>
<script>
(function () {
    var sec = {$countdown};
    var el = document.getElementById('perm-countdown');
    if (el && sec > 0) {
        var t = setInterval(function () {
            sec--;
            el.textContent = sec;
            if (sec <= 0) { clearInterval(t); window.location.href = '{$backUrl}'; }
        }, 1000);
    }
})();
</script>
HTML;
}

/**
 * 檢查權限；若未授權則輸出提示頁並結束執行。
 * 已授權時回傳 true，呼叫端可繼續流程。
 */
function member_permission_require($Link, $uid, $key, $opts = [])
{
    if (member_permission_allowed($Link, $uid, $key)) {
        return true;
    }
    $map = member_permission_map();
    $meta = $map[$key] ?? ['label' => '此功能', 'short' => '功能使用'];
    $label = $meta['label'];
    $backUrl = $opts['back'] ?? 'home.php';
    $title = $opts['title'] ?? '功能使用權限未開放';
    $countdown = isset($opts['countdown']) ? (int)$opts['countdown'] : 0;
    if (isset($opts['message'])) {
        $message = $opts['message'];
    } else {
        $tail = $countdown > 0 ? ('系統將於 <span id="perm-countdown" class="font-bold text-rose-600">' . $countdown . '</span> 秒後返回會員中心。<br>') : '';
        $message = '您的帳號目前未被授予「<span class="font-semibold text-slate-700">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>」權限。<br>' . $tail . '如需使用此功能，請聯繫客服或管理員協助開通。';
    }
    $modal = member_permission_modal_html($title, $message, $backUrl, $opts['backText'] ?? '返回會員中心', $countdown);

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
<title>踏雪笑傲 · 權限不足</title>
<link rel="stylesheet" href="assets/tailwind.css">
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+TC:wght@400;600;700;900&family=Noto+Sans+TC:wght@300;400;500;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    body { background-color:#080c14; font-family:'Noto Sans TC','Noto Serif TC',sans-serif;
        background-image: radial-gradient(ellipse at 50% 0%, rgba(190,18,60,0.12) 0%, transparent 60%),
            radial-gradient(ellipse at 80% 90%, rgba(2,132,199,0.10) 0%, transparent 55%); }
    .snow-card { background: rgba(255,255,255,0.96); backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px); box-shadow: 0 20px 45px -12px rgba(0,0,0,0.35); }
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
<?= $modal ?>
</body>
</html>
    <?php
    exit();
}

<?php
/**
 * =============================================================
 *  踏雪笑傲 · 帳號層級／類型（Account Tiers）共用工具庫
 *  -------------------------------------------------------------
 *  資料表：account_tiers（層級定義）、user_tiers（會員對應）
 *  前台：home.php（會員中心顯示層級）
 *  後台：admin/gmpanel.php、admin/account_tier_api.php
 *
 *  使用方式：
 *    require_once __DIR__ . '/account_tiers.php';
 *    account_tier_ensure_tables($Link);
 *    $tier = account_tier_for_user($Link, $uid);
 *    echo account_tier_badge_html($tier);
 * =============================================================
 */

if (defined('ACCOUNT_TIERS_LIB')) {
    return;
}
define('ACCOUNT_TIERS_LIB', 1);

/** 預設層級資料（首次建立時寫入，不覆蓋既有） */
function account_tier_default_seed()
{
    return [
        ['normal', '普通帳號', 'fa-user',          '#64748b', 10, '一般註冊會員',            1, 1],
        ['honor',  '榮譽會員', 'fa-medal',         '#0ea5e9', 30, '對遊戲有貢獻的榮譽會員',  1, 2],
        ['vip',    'VIP 會員', 'fa-crown',         '#f59e0b', 50, '付費 VIP 會員',           1, 3],
        ['gm',     'GM 管理員', 'fa-shield-halved', '#8b5cf6', 90, '遊戲管理團隊',            1, 4],
    ];
}

/** 建立資料表並補上預設層級（冪等、非破壞性） */
function account_tier_ensure_tables($Link)
{
    if (!$Link) {
        return;
    }
    try {
        $Link->query("CREATE TABLE IF NOT EXISTS `account_tiers` (
            `id` int NOT NULL AUTO_INCREMENT,
            `code` varchar(32) NOT NULL DEFAULT '',
            `name` varchar(60) NOT NULL DEFAULT '',
            `icon` varchar(60) NOT NULL DEFAULT 'fa-user',
            `color` varchar(20) NOT NULL DEFAULT '#64748b',
            `rank` int NOT NULL DEFAULT 0,
            `description` varchar(200) NOT NULL DEFAULT '',
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `sort_order` int NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_tier_code` (`code`),
            KEY `idx_tier_rank` (`rank`),
            KEY `idx_tier_enabled` (`enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $Link->query("CREATE TABLE IF NOT EXISTS `user_tiers` (
            `uid` int NOT NULL,
            `tier_id` int NOT NULL DEFAULT 0,
            `updated_by` varchar(50) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`uid`),
            KEY `idx_user_tier` (`tier_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $Link->prepare("INSERT IGNORE INTO `account_tiers` (`code`,`name`,`icon`,`color`,`rank`,`description`,`enabled`,`sort_order`) VALUES (?,?,?,?,?,?,?,?)");
        foreach (account_tier_default_seed() as $row) {
            $stmt->bind_param("ssssisii", $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7]);
            $stmt->execute();
        }
        $stmt->close();
    } catch (Throwable $e) {
        // 權限不足等情形忽略，呼叫端會再處理
    }
}

/** 正規化顏色為安全的 hex 色碼 */
function account_tier_normalize_color($color)
{
    $color = strtolower(trim((string)$color));
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $color)) {
        return $color;
    }
    if (preg_match('/^([0-9a-f]{3}|[0-9a-f]{6})$/', $color)) {
        return '#' . $color;
    }
    return '#64748b';
}

/**
 * 正規化圖示字串。
 * 支援：`fa-crown`（預設實心）、`fa-solid fa-crown`、`fa-regular fa-star`、`fa-brands fa-github`。
 * 回傳僅含合法 token 的短字串；實心時省略 `fa-solid` 以精簡儲存。
 */
function account_tier_sanitize_icon($icon)
{
    $icon = strtolower(trim((string)$icon));
    $icon = preg_replace('/[^a-z0-9\- ]/', '', $icon);
    $icon = trim(preg_replace('/\s+/', ' ', (string)$icon));
    if ($icon === '') {
        return 'fa-user';
    }
    $styleMap = ['fas' => 'fa-solid', 'far' => 'fa-regular', 'fab' => 'fa-brands'];
    $style = '';
    $name = '';
    foreach (explode(' ', $icon) as $token) {
        if ($token === '') {
            continue;
        }
        if (in_array($token, ['fa-solid', 'fa-regular', 'fa-brands'], true)) {
            $style = $token;
        } elseif (isset($styleMap[$token])) {
            $style = $styleMap[$token];
        } elseif ($name === '' && preg_match('/^fa-[a-z0-9-]{1,20}$/', $token)) {
            $name = $token;
        }
    }
    if ($name === '') {
        return 'fa-user';
    }
    // 實心為預設，不額外儲存樣式以維持相容
    return ($style !== '' && $style !== 'fa-solid') ? ($style . ' ' . $name) : $name;
}

/** 將圖示字串轉為完整的 FontAwesome class（供 <i class="..."> 使用） */
function account_tier_icon_class($icon)
{
    $icon = account_tier_sanitize_icon($icon);
    if (preg_match('/^fa-(solid|regular|brands)\s/', $icon)) {
        return $icon;
    }
    return 'fa-solid ' . $icon;
}

/** 正規化層級代碼 */
function account_tier_normalize_code($code)
{
    $code = strtolower(trim((string)$code));
    $code = preg_replace('/[^a-z0-9_-]/', '', $code);
    if ($code === null || !preg_match('/^[a-z0-9_-]{2,32}$/', $code)) {
        return '';
    }
    return $code;
}

/** 讀取所有層級（預設依等級高至低） */
function account_tiers_load_all($Link, $onlyEnabled = false)
{
    $out = [];
    if (!$Link) {
        return $out;
    }
    account_tier_ensure_tables($Link);
    try {
        $sql = "SELECT `id`,`code`,`name`,`icon`,`color`,`rank`,`description`,`enabled`,`sort_order`,`created_at`,`updated_at`
                FROM `account_tiers`";
        if ($onlyEnabled) {
            $sql .= " WHERE `enabled` = 1";
        }
        $sql .= " ORDER BY `rank` DESC, `sort_order` ASC, `id` ASC";
        $res = $Link->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
        }
    } catch (Throwable $e) {
        // 略過
    }
    return $out;
}

/** 依 id 取得層級 */
function account_tiers_get($Link, $id)
{
    $id = (int)$id;
    if (!$Link || $id <= 0) {
        return null;
    }
    try {
        $stmt = $Link->prepare("SELECT * FROM `account_tiers` WHERE `id` = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** 依代碼取得層級 */
function account_tiers_by_code($Link, $code)
{
    $code = account_tier_normalize_code($code);
    if (!$Link || $code === '') {
        return null;
    }
    try {
        $stmt = $Link->prepare("SELECT * FROM `account_tiers` WHERE `code` = ? LIMIT 1");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** 取得預設層級（優先 code=normal，否則取啟用中最低等級） */
function account_tiers_default_tier($Link)
{
    $normal = account_tiers_by_code($Link, 'normal');
    if ($normal) {
        return $normal;
    }
    $all = account_tiers_load_all($Link, true);
    return $all ? end($all) : null;
}

/** 取得某會員的層級（無對應時回傳預設層級） */
function account_tier_for_user($Link, $uid)
{
    $uid = (int)$uid;
    if (!$Link || $uid <= 0) {
        return account_tiers_default_tier($Link);
    }
    try {
        $stmt = $Link->prepare("SELECT t.* FROM `user_tiers` ut JOIN `account_tiers` t ON t.`id` = ut.`tier_id` WHERE ut.`uid` = ? LIMIT 1");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row;
        }
    } catch (Throwable $e) {
        // 略過
    }
    return account_tiers_default_tier($Link);
}

/** 批次取得多個會員的層級；回傳 [uid => tier]（無對應者給預設層級） */
function account_tiers_map_for_uids($Link, array $uids)
{
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids), function ($v) { return $v > 0; })));
    if (!$Link || !$uids) {
        return [];
    }
    $byId = [];
    foreach (account_tiers_load_all($Link, false) as $t) {
        $byId[(int)$t['id']] = $t;
    }
    $default = account_tiers_default_tier($Link);
    $map = [];
    foreach ($uids as $u) {
        $map[$u] = $default;
    }
    try {
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $types = str_repeat('i', count($uids));
        $stmt = $Link->prepare("SELECT `uid`,`tier_id` FROM `user_tiers` WHERE `uid` IN ($placeholders)");
        $stmt->bind_param($types, ...$uids);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $uid = (int)$row['uid'];
            $tid = (int)$row['tier_id'];
            if (isset($byId[$tid])) {
                $map[$uid] = $byId[$tid];
            }
        }
        $stmt->close();
    } catch (Throwable $e) {
        // 略過
    }
    return $map;
}

/** 產生層級徽章 HTML（顏色／圖示由資料決定，已做安全處理） */
function account_tier_badge_html($tier, $opts = [])
{
    if (!$tier || empty($tier['name'])) {
        return '';
    }
    $color = account_tier_normalize_color($tier['color'] ?? '#64748b');
    $icon = account_tier_icon_class($tier['icon'] ?? 'fa-user');
    $name = htmlspecialchars((string)$tier['name'], ENT_QUOTES, 'UTF-8');
    $size = ($opts['size'] ?? 'sm') === 'lg' ? 'lg' : 'sm';
    $fs = $size === 'lg' ? '12px' : '11px';
    $pad = $size === 'lg' ? '.2rem .7rem' : '.1rem .55rem';
    $extra = isset($opts['class']) ? ' ' . preg_replace('/[^A-Za-z0-9 _-]/', '', (string)$opts['class']) : '';
    $title = isset($opts['title']) ? ' title="' . htmlspecialchars((string)$opts['title'], ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<span class="acct-tier-badge' . $extra . '"' . $title . ' style="display:inline-flex;align-items:center;gap:.3rem;padding:' . $pad
        . ';border-radius:9999px;font-size:' . $fs . ';font-weight:700;line-height:1.4;color:' . $color
        . ';background:' . $color . '1a;border:1px solid ' . $color . '4d;white-space:nowrap;vertical-align:middle">'
        . '<i class="' . $icon . '" style="font-size:.85em"></i>' . $name . '</span>';
}

/**
 * 新增或更新層級。
 * @return array{ok:bool,id:int,errors:array<string,string>}
 */
function account_tier_save($Link, array $data, $id = 0, $by = '')
{
    $result = ['ok' => false, 'id' => 0, 'errors' => []];
    if (!$Link) {
        $result['errors']['_'] = '資料庫連線失敗。';
        return $result;
    }
    account_tier_ensure_tables($Link);

    $id = (int)$id;
    $code = account_tier_normalize_code($data['code'] ?? '');
    $name = trim((string)($data['name'] ?? ''));
    $icon = account_tier_sanitize_icon($data['icon'] ?? 'fa-user');
    $color = account_tier_normalize_color($data['color'] ?? '#64748b');
    $rank = (int)($data['rank'] ?? 0);
    $desc = mb_substr(trim((string)($data['description'] ?? '')), 0, 200);
    $enabled = !empty($data['enabled']) ? 1 : 0;
    $sort = (int)($data['sort_order'] ?? 0);
    if ($rank < -100000 || $rank > 100000) { $rank = 0; }
    if ($sort < -100000 || $sort > 100000) { $sort = 0; }

    if ($name === '') { $result['errors']['name'] = '請輸入層級名稱。'; }
    if (mb_strlen($name) > 60) { $name = mb_substr($name, 0, 60); }
    if ($code === '') { $result['errors']['code'] = '代碼僅能使用 2-32 個英數字、底線或連字號。'; }
    if ($result['errors']) {
        return $result;
    }

    try {
        if ($id > 0) {
            $stmt = $Link->prepare("UPDATE `account_tiers` SET `code`=?,`name`=?,`icon`=?,`color`=?,`rank`=?,`description`=?,`enabled`=?,`sort_order`=? WHERE `id`=?");
            $stmt->bind_param("ssssisiii", $code, $name, $icon, $color, $rank, $desc, $enabled, $sort, $id);
            $stmt->execute();
            $stmt->close();
            $result['id'] = $id;
        } else {
            $stmt = $Link->prepare("INSERT INTO `account_tiers` (`code`,`name`,`icon`,`color`,`rank`,`description`,`enabled`,`sort_order`) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param("ssssisii", $code, $name, $icon, $color, $rank, $desc, $enabled, $sort);
            $stmt->execute();
            $result['id'] = (int)$Link->insert_id;
            $stmt->close();
        }
        $result['ok'] = true;
    } catch (Throwable $e) {
        $msg = (string)$e->getMessage();
        if (strpos($msg, '1062') !== false || stripos($msg, 'uk_tier_code') !== false || stripos($msg, 'Duplicate') !== false) {
            $result['errors']['code'] = '此層級代碼已存在，請改用其他代碼。';
        } else {
            $result['errors']['_'] = '儲存失敗，請稍後再試。';
        }
    }
    return $result;
}

/** 刪除層級（同時移除會員對應，會員將回到預設層級） */
function account_tier_delete($Link, $id)
{
    $id = (int)$id;
    if (!$Link || $id <= 0) {
        return false;
    }
    try {
        $stmt = $Link->prepare("DELETE FROM `user_tiers` WHERE `tier_id` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $stmt = $Link->prepare("DELETE FROM `account_tiers` WHERE `id` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $ok = $stmt->affected_rows >= 0;
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

/** 指派會員層級（tierId<=0 表示清除、回到預設） */
function account_tier_assign($Link, $uid, $tierId, $by = '')
{
    $uid = (int)$uid;
    $tierId = (int)$tierId;
    if (!$Link || $uid <= 0) {
        return false;
    }
    try {
        if ($tierId <= 0) {
            $stmt = $Link->prepare("DELETE FROM `user_tiers` WHERE `uid` = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $stmt->close();
            return true;
        }
        $stmt = $Link->prepare("INSERT INTO `user_tiers` (`uid`,`tier_id`,`updated_by`) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE `tier_id`=VALUES(`tier_id`), `updated_by`=VALUES(`updated_by`), `updated_at`=NOW()");
        $stmt->bind_param("iis", $uid, $tierId, $by);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

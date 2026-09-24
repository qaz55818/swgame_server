<?php
/**
 * =============================================================
 *  踏雪笑傲 · 會員訊息通知中心工具庫
 *  -------------------------------------------------------------
 *  用途：接收後台系統通知與 GM 主動寄送的訊息。
 *  資料表：member_messages（每位會員各自的訊息列，含已讀 / 已刪除狀態）
 *
 *  使用方式：
 *    require_once __DIR__ . '/messages.php';
 *    member_message_ensure_table($Link);
 *    member_message_send($Link, $uid, $title, $content, 'gm', 'gm', $gmName, [
 *        'title_color' => '#be123c', 'title_bold' => 1, 'content_mode' => 'html', 'batch_id' => $bid,
 *    ]);
 *    $unread = member_message_unread_count($Link, $uid);
 *    $rows   = member_message_list($Link, $uid, 50);
 * =============================================================
 */

if (!defined('MEMBER_MESSAGE_TABLE')) {
    define('MEMBER_MESSAGE_TABLE', 'member_messages');
}

/** 訊息類型定義（標題、圖示、配色 class、標籤） */
function member_message_types()
{
    return [
        'system'  => ['label' => '系統通知', 'icon' => 'fa-solid fa-bell',            'badge' => 'bg-slate-100 text-slate-600 border-slate-300',   'dot' => 'bg-slate-500'],
        'gm'      => ['label' => 'GM 訊息',  'icon' => 'fa-solid fa-user-shield',     'badge' => 'bg-sky-50 text-sky-700 border-sky-200',          'dot' => 'bg-sky-500'],
        'reward'  => ['label' => '獎勵通知', 'icon' => 'fa-solid fa-gift',            'badge' => 'bg-amber-50 text-amber-700 border-amber-200',    'dot' => 'bg-amber-500'],
        'notice'  => ['label' => '營運公告', 'icon' => 'fa-solid fa-bullhorn',        'badge' => 'bg-violet-50 text-violet-700 border-violet-200', 'dot' => 'bg-violet-500'],
        'warning' => ['label' => '帳號警示', 'icon' => 'fa-solid fa-triangle-exclamation', 'badge' => 'bg-rose-50 text-rose-700 border-rose-200', 'dot' => 'bg-rose-500'],
    ];
}

/** 取得類型顯示資訊（未知類型回退為系統通知） */
function member_message_type_meta($type)
{
    $map = member_message_types();
    return $map[$type] ?? $map['system'];
}

/** 允許的標題顏色（白名單；空字串 = 使用預設色） */
function member_message_allowed_colors()
{
    return ['', '#be123c', '#0284c7', '#059669', '#d97706', '#7c3aed', '#db2777', '#0f172a', '#475569'];
}

/** 正規化標題顏色（不在白名單者回退為空） */
function member_message_normalize_color($color)
{
    $color = strtolower(trim((string)$color));
    return in_array($color, member_message_allowed_colors(), true) ? $color : '';
}

/**
 * 淨化訊息 HTML（供所見即所得／代碼模式使用）。
 * 移除腳本與事件屬性，僅保留安全的排版標籤。
 */
function member_message_sanitize_html($html)
{
    $html = (string)$html;
    // 移除危險區塊（含內容）
    $html = preg_replace('#<(script|style|iframe|object|embed|link|meta|form|input|button|textarea|select)\b[^>]*>.*?</\1>#is', '', $html);
    // 移除殘留的危險單一標籤
    $html = preg_replace('#<(script|style|iframe|object|embed|link|meta|form|input|button|textarea|select)\b[^>]*/?>#is', '', $html);
    // 移除事件屬性（onclick 等）
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    // 移除 javascript: 連結
    $html = preg_replace('/\b(href|src)\s*=\s*("|\')?\s*javascript:[^"\'>\s]*("|\')?/i', '$1="#"', $html);
    // 標籤白名單
    $allowed = '<p><br><b><strong><i><em><u><s><strike><ul><ol><li><a><span><div><h1><h2><h3><h4><blockquote><pre><code><hr><table><thead><tbody><tr><th><td><font><img>';
    $html = strip_tags($html, $allowed);
    return trim($html);
}

/** 確保訊息資料表與欄位存在（可重複執行、非破壞性） */
function member_message_ensure_table($Link)
{
    static $done = false;
    if (!$Link || $done) {
        return;
    }
    $done = true;

    $sql = "CREATE TABLE IF NOT EXISTS `" . MEMBER_MESSAGE_TABLE . "` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `uid` int(11) NOT NULL,
        `title` varchar(128) NOT NULL DEFAULT '',
        `title_color` varchar(16) NOT NULL DEFAULT '',
        `title_bold` tinyint(1) NOT NULL DEFAULT 0,
        `content` mediumtext,
        `content_mode` varchar(10) NOT NULL DEFAULT 'html',
        `type` varchar(24) NOT NULL DEFAULT 'system',
        `sender_type` varchar(16) NOT NULL DEFAULT 'system',
        `sender_name` varchar(64) NOT NULL DEFAULT '',
        `batch_id` varchar(32) NOT NULL DEFAULT '',
        `is_read` tinyint(1) NOT NULL DEFAULT 0,
        `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `read_at` datetime NULL DEFAULT NULL,
        `deleted_at` datetime NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_uid_read` (`uid`, `is_read`, `is_deleted`),
        KEY `idx_uid_created` (`uid`, `created_at`),
        KEY `idx_batch` (`batch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try {
        $Link->query($sql);
    } catch (Throwable $e) {
        return;
    }

    // 既有資料表補欄位（升級用）
    $want = [
        'title_color'  => "varchar(16) NOT NULL DEFAULT '' AFTER `title`",
        'title_bold'   => "tinyint(1) NOT NULL DEFAULT 0 AFTER `title_color`",
        'content_mode' => "varchar(10) NOT NULL DEFAULT 'html' AFTER `content`",
        'batch_id'     => "varchar(32) NOT NULL DEFAULT '' AFTER `sender_name`",
        'is_deleted'   => "tinyint(1) NOT NULL DEFAULT 0 AFTER `is_read`",
        'deleted_at'   => "datetime NULL DEFAULT NULL AFTER `created_at`",
    ];
    try {
        $res = $Link->query("SELECT `COLUMN_NAME` FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . MEMBER_MESSAGE_TABLE . "'");
        $have = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $have[strtolower((string)$row['COLUMN_NAME'])] = true;
            }
        }
        foreach ($want as $col => $def) {
            if (!isset($have[$col])) {
                $Link->query("ALTER TABLE `" . MEMBER_MESSAGE_TABLE . "` ADD COLUMN `$col` $def");
            }
        }
        // content 升級為 mediumtext（大內容）
        $Link->query("ALTER TABLE `" . MEMBER_MESSAGE_TABLE . "` MODIFY `content` mediumtext");
    } catch (Throwable $e) {
        // 權限不足時略過
    }
}

/** 依帳號名稱／ID 解析會員 UID（找不到回傳 0） */
function member_message_resolve_uid($Link, $username = '', $userId = '')
{
    if (!$Link) {
        return 0;
    }
    try {
        if ($userId !== '' && (int)$userId > 0) {
            $uid = (int)$userId;
            $stmt = $Link->prepare("SELECT `ID` FROM `users` WHERE `ID` = ? LIMIT 1");
            $stmt->bind_param("i", $uid);
        } else {
            $stmt = $Link->prepare("SELECT `ID` FROM `users` WHERE `name` = ? LIMIT 1");
            $stmt->bind_param("s", $username);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['ID'] : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/** 建立批次 ID（同一批寄送共用） */
function member_message_new_batch_id()
{
    return date('YmdHis') . '-' . bin2hex(random_bytes(4));
}

/** 整理寄送選項 */
function member_message_normalize_opts(array $opts)
{
    $mode = ((string)($opts['content_mode'] ?? 'html') === 'code') ? 'code' : 'html';
    $batch = mb_substr((string)($opts['batch_id'] ?? ''), 0, 32);
    if ($batch === '') {
        $batch = member_message_new_batch_id();
    }
    return [
        'title_color'  => member_message_normalize_color($opts['title_color'] ?? ''),
        'title_bold'   => !empty($opts['title_bold']) ? 1 : 0,
        'content_mode' => $mode,
        'batch_id'     => $batch,
    ];
}

/**
 * 寄送一則訊息給指定會員。
 *
 * @return int 成功時回傳訊息 ID，失敗回傳 0
 */
function member_message_send($Link, $uid, $title, $content, $type = 'system', $senderType = 'system', $senderName = '', array $opts = [])
{
    $uid = (int)$uid;
    if (!$Link || $uid <= 0) {
        return 0;
    }
    $title = mb_substr(trim((string)$title), 0, 128);
    if ($title === '') {
        $title = '系統通知';
    }
    $content = member_message_sanitize_html($content);
    $validTypes = array_keys(member_message_types());
    if (!in_array($type, $validTypes, true)) {
        $type = 'system';
    }
    $senderType = ($senderType === 'gm') ? 'gm' : 'system';
    $senderName = mb_substr((string)$senderName, 0, 64);
    $o = member_message_normalize_opts($opts);

    try {
        member_message_ensure_table($Link);
        $stmt = $Link->prepare(
            "INSERT INTO `" . MEMBER_MESSAGE_TABLE . "`
                (`uid`, `title`, `title_color`, `title_bold`, `content`, `content_mode`,
                 `type`, `sender_type`, `sender_name`, `batch_id`, `is_read`, `is_deleted`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())"
        );
        $stmt->bind_param(
            "ississssss",
            $uid, $title, $o['title_color'], $o['title_bold'], $content, $o['content_mode'],
            $type, $senderType, $senderName, $o['batch_id']
        );
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    } catch (Throwable $e) {
        return 0;
    }
}

/** 批次寄送（單一 SQL 多列插入，適合全服發送；回傳成功寫入則數） */
function member_message_send_bulk($Link, array $uids, $title, $content, $type = 'system', $senderType = 'system', $senderName = '', array $opts = [])
{
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids), function ($v) { return $v > 0; })));
    if (!$Link || !$uids) {
        return 0;
    }
    $title = mb_substr(trim((string)$title), 0, 128);
    if ($title === '') {
        $title = '系統通知';
    }
    $content = member_message_sanitize_html($content);
    $validTypes = array_keys(member_message_types());
    if (!in_array($type, $validTypes, true)) {
        $type = 'system';
    }
    $senderType = ($senderType === 'gm') ? 'gm' : 'system';
    $senderName = mb_substr((string)$senderName, 0, 64);
    $o = member_message_normalize_opts($opts);
    member_message_ensure_table($Link);

    $sent = 0;
    foreach (array_chunk($uids, 400) as $chunk) {
        $placeholders = [];
        $params = [];
        $types = '';
        foreach ($chunk as $uid) {
            $placeholders[] = '(?,?,?,?,?,?,?,?,?,?,0,0,NOW())';
            $types .= 'ississssss';
            $params[] = $uid;
            $params[] = $title;
            $params[] = $o['title_color'];
            $params[] = $o['title_bold'];
            $params[] = $content;
            $params[] = $o['content_mode'];
            $params[] = $type;
            $params[] = $senderType;
            $params[] = $senderName;
            $params[] = $o['batch_id'];
        }
        $sql = "INSERT INTO `" . MEMBER_MESSAGE_TABLE . "`
                    (`uid`,`title`,`title_color`,`title_bold`,`content`,`content_mode`,
                     `type`,`sender_type`,`sender_name`,`batch_id`,`is_read`,`is_deleted`,`created_at`)
                VALUES " . implode(',', $placeholders);
        try {
            $stmt = $Link->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $sent += $stmt->affected_rows;
            $stmt->close();
        } catch (Throwable $e) {
            // 略過該批次
        }
    }
    return $sent;
}

/** 未讀訊息數（不含已刪除） */
function member_message_unread_count($Link, $uid)
{
    $uid = (int)$uid;
    if (!$Link || $uid <= 0) {
        return 0;
    }
    try {
        member_message_ensure_table($Link);
        $stmt = $Link->prepare("SELECT COUNT(*) AS c FROM `" . MEMBER_MESSAGE_TABLE . "` WHERE `uid` = ? AND `is_read` = 0 AND `is_deleted` = 0");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $c = (int)(($stmt->get_result()->fetch_assoc()['c'] ?? 0));
        $stmt->close();
        return $c;
    } catch (Throwable $e) {
        return 0;
    }
}

/** 讀取訊息列表（預設最新在前；不含已刪除） */
function member_message_list($Link, $uid, $limit = 50, $offset = 0, $onlyUnread = false)
{
    $uid = (int)$uid;
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    if (!$Link || $uid <= 0) {
        return [];
    }
    try {
        member_message_ensure_table($Link);
        $where = "`uid` = ? AND `is_deleted` = 0";
        if ($onlyUnread) {
            $where .= " AND `is_read` = 0";
        }
        $sql = "SELECT `id`,`title`,`title_color`,`title_bold`,`content`,`content_mode`,
                       `type`,`sender_type`,`sender_name`,`is_read`,`created_at`,`read_at`
                FROM `" . MEMBER_MESSAGE_TABLE . "`
                WHERE $where
                ORDER BY `id` DESC
                LIMIT ? OFFSET ?";
        $stmt = $Link->prepare($sql);
        $stmt->bind_param("iii", $uid, $limit, $offset);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/** 標記單則訊息為已讀（限本人） */
function member_message_mark_read($Link, $uid, $id)
{
    $uid = (int)$uid;
    $id = (int)$id;
    if (!$Link || $uid <= 0 || $id <= 0) {
        return false;
    }
    try {
        $stmt = $Link->prepare("UPDATE `" . MEMBER_MESSAGE_TABLE . "` SET `is_read` = 1, `read_at` = IFNULL(`read_at`, NOW()) WHERE `id` = ? AND `uid` = ? AND `is_deleted` = 0");
        $stmt->bind_param("ii", $id, $uid);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 標記全部訊息為已讀（不含已刪除） */
function member_message_mark_all_read($Link, $uid)
{
    $uid = (int)$uid;
    if (!$Link || $uid <= 0) {
        return false;
    }
    try {
        $stmt = $Link->prepare("UPDATE `" . MEMBER_MESSAGE_TABLE . "` SET `is_read` = 1, `read_at` = IFNULL(`read_at`, NOW()) WHERE `uid` = ? AND `is_read` = 0 AND `is_deleted` = 0");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 刪除單則訊息（軟刪除，保留後台可見狀態） */
function member_message_delete($Link, $uid, $id)
{
    $uid = (int)$uid;
    $id = (int)$id;
    if (!$Link || $uid <= 0 || $id <= 0) {
        return false;
    }
    try {
        $stmt = $Link->prepare("UPDATE `" . MEMBER_MESSAGE_TABLE . "` SET `is_deleted` = 1, `deleted_at` = NOW() WHERE `id` = ? AND `uid` = ? AND `is_deleted` = 0");
        $stmt->bind_param("ii", $id, $uid);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 刪除全部訊息（軟刪除） */
function member_message_delete_all($Link, $uid)
{
    $uid = (int)$uid;
    if (!$Link || $uid <= 0) {
        return false;
    }
    try {
        $stmt = $Link->prepare("UPDATE `" . MEMBER_MESSAGE_TABLE . "` SET `is_deleted` = 1, `deleted_at` = NOW() WHERE `uid` = ? AND `is_deleted` = 0");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 目前最大訊息 ID（供即時偵測新訊息；含已刪除，避免漏判） */
function member_message_latest_id($Link, $uid)
{
    $uid = (int)$uid;
    if (!$Link || $uid <= 0) {
        return 0;
    }
    try {
        $stmt = $Link->prepare("SELECT IFNULL(MAX(`id`), 0) AS m FROM `" . MEMBER_MESSAGE_TABLE . "` WHERE `uid` = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $m = (int)(($stmt->get_result()->fetch_assoc()['m'] ?? 0));
        $stmt->close();
        return $m;
    } catch (Throwable $e) {
        return 0;
    }
}

/** 後台：全域統計（含即時偵測用的最新 ID） */
function member_message_counts($Link)
{
    $out = ['total' => 0, 'unread' => 0, 'read' => 0, 'unread_deleted' => 0, 'read_deleted' => 0, 'latest' => 0];
    if (!$Link) {
        return $out;
    }
    try {
        member_message_ensure_table($Link);
        $rs = $Link->query("SELECT COUNT(*) AS total,
                SUM(`is_read`=0 AND `is_deleted`=0) AS unread_c,
                SUM(`is_read`=1 AND `is_deleted`=0) AS read_c,
                SUM(`is_read`=0 AND `is_deleted`=1) AS ud_c,
                SUM(`is_read`=1 AND `is_deleted`=1) AS rd_c,
                IFNULL(MAX(`id`),0) AS latest
            FROM `" . MEMBER_MESSAGE_TABLE . "`");
        if ($rs && ($row = $rs->fetch_assoc())) {
            $out['total'] = (int)$row['total'];
            $out['unread'] = (int)$row['unread_c'];
            $out['read'] = (int)$row['read_c'];
            $out['unread_deleted'] = (int)$row['ud_c'];
            $out['read_deleted'] = (int)$row['rd_c'];
            $out['latest'] = (int)$row['latest'];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** 相對時間（中文） */
function member_message_ago($dt)
{
    if (empty($dt) || $dt === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime((string)$dt);
    if ($ts === false) {
        return '';
    }
    $d = time() - $ts;
    if ($d < 0) {
        $d = 0;
    }
    if ($d < 60) {
        return '剛剛';
    }
    if ($d < 3600) {
        return floor($d / 60) . ' 分鐘前';
    }
    if ($d < 86400) {
        return floor($d / 3600) . ' 小時前';
    }
    if ($d < 2592000) {
        return floor($d / 86400) . ' 天前';
    }
    return date('Y-m-d', $ts);
}

/** 後台紀錄列：整理為前端可用結構 */
function member_message_admin_row(array $r)
{
    $meta = member_message_type_meta((string)$r['type']);
    $st = member_message_status_meta((int)$r['is_read'], (int)$r['is_deleted']);
    return [
        'id'           => (int)$r['id'],
        'uid'          => (int)$r['uid'],
        'uname'        => (string)($r['uname'] ?? ''),
        'title'        => (string)$r['title'],
        'title_color'  => (string)($r['title_color'] ?? ''),
        'title_bold'   => (int)($r['title_bold'] ?? 0),
        'type_label'   => $meta['label'],
        'sender_name'  => (string)($r['sender_name'] ?? ''),
        'status_key'   => $st['key'],
        'status_label' => $st['label'],
        'created_at'   => (string)($r['created_at'] ?? ''),
        'ago'          => member_message_ago((string)($r['created_at'] ?? '')),
    ];
}

/** 後台：狀態標籤（未讀 / 已讀 / 未讀已刪除 / 已讀已刪除） */
function member_message_status_meta($isRead, $isDeleted)
{
    $isRead = (int)$isRead === 1;
    $isDeleted = (int)$isDeleted === 1;
    if ($isDeleted && $isRead) {
        return ['key' => 'read_deleted', 'label' => '已讀已刪除', 'badge' => 'bg-slate-100 text-slate-500 border-slate-300'];
    }
    if ($isDeleted) {
        return ['key' => 'unread_deleted', 'label' => '未讀已刪除', 'badge' => 'bg-amber-50 text-amber-700 border-amber-200'];
    }
    if ($isRead) {
        return ['key' => 'read', 'label' => '已讀', 'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200'];
    }
    return ['key' => 'unread', 'label' => '未讀', 'badge' => 'bg-rose-50 text-rose-700 border-rose-200'];
}

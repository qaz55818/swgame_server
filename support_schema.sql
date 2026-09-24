-- =============================================================
--  申訴回報系統資料表（SA 資料庫）
--  對應頁面：
--    會員端  support.php
--    管理端  admin/support_admin.php
--  執行方式：
--    mysql -u root -p servbay < support_schema.sql
-- =============================================================

SET NAMES utf8mb3;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 申訴回報分類
-- ----------------------------
DROP TABLE IF EXISTS `support_categories`;
CREATE TABLE `support_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL DEFAULT '' COMMENT '分類代碼',
  `name` varchar(60) NOT NULL DEFAULT '' COMMENT '分類名稱',
  `icon` varchar(40) NOT NULL DEFAULT '' COMMENT 'FontAwesome 圖示',
  `description` varchar(200) NOT NULL DEFAULT '' COMMENT '分類說明',
  `default_priority` varchar(16) NOT NULL DEFAULT 'normal' COMMENT '預設優先度',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_category_code` (`code`),
  KEY `idx_category_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='申訴回報分類';

-- ----------------------------
-- 申訴回報主表
-- ----------------------------
DROP TABLE IF EXISTS `support_tickets`;
CREATE TABLE `support_tickets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_no` varchar(30) NOT NULL DEFAULT '' COMMENT '回報單號',
  `uid` int NOT NULL DEFAULT 0 COMMENT '會員 users.ID',
  `username` varchar(32) NOT NULL DEFAULT '' COMMENT '會員帳號',
  `category_id` int NOT NULL DEFAULT 0 COMMENT '分類 support_categories.id',
  `category_code` varchar(32) NOT NULL DEFAULT '' COMMENT '分類代碼（冗餘）',
  `subject` varchar(150) NOT NULL DEFAULT '' COMMENT '主旨',
  `content` text COMMENT '申訴內容',
  `server_zone` varchar(60) NOT NULL DEFAULT '' COMMENT '所在伺服器/區域',
  `role_name` varchar(50) NOT NULL DEFAULT '' COMMENT '角色名稱',
  `contact_email` varchar(64) NOT NULL DEFAULT '' COMMENT '聯絡信箱',
  `contact_mobile` varchar(32) NOT NULL DEFAULT '' COMMENT '聯絡手機',
  `priority` varchar(16) NOT NULL DEFAULT 'normal' COMMENT 'low/normal/high/urgent',
  `status` varchar(20) NOT NULL DEFAULT 'open' COMMENT 'open/processing/pending_user/resolved/closed',
  `handler` varchar(50) NOT NULL DEFAULT '' COMMENT '受理 GM',
  `verdict` varchar(200) NOT NULL DEFAULT '' COMMENT '處理結論',
  `internal_note` text COMMENT '內部備註（會員不可見）',
  `reply_count` int NOT NULL DEFAULT 0 COMMENT '訊息回覆數',
  `last_reply_by` varchar(16) NOT NULL DEFAULT '' COMMENT '最後回覆者 user/gm',
  `last_reply_at` datetime DEFAULT NULL COMMENT '最後回覆時間',
  `resolved_at` datetime DEFAULT NULL COMMENT '結案時間',
  `closed_at` datetime DEFAULT NULL COMMENT '關閉時間',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  KEY `idx_ticket_uid` (`uid`),
  KEY `idx_ticket_status` (`status`),
  KEY `idx_ticket_category` (`category_id`),
  KEY `idx_ticket_handler` (`handler`),
  KEY `idx_ticket_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='申訴回報主表';

-- ----------------------------
-- 申訴回報對話訊息
-- ----------------------------
DROP TABLE IF EXISTS `support_messages`;
CREATE TABLE `support_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL DEFAULT 0 COMMENT 'support_tickets.id',
  `sender_type` varchar(10) NOT NULL DEFAULT 'user' COMMENT 'user/gm/system',
  `sender_id` int NOT NULL DEFAULT 0 COMMENT '發送者 ID（會員或 GM）',
  `sender_name` varchar(50) NOT NULL DEFAULT '' COMMENT '發送者名稱',
  `content` text COMMENT '訊息內容',
  `is_internal` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=僅管理端可見',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_ticket` (`ticket_id`),
  KEY `idx_message_sender` (`sender_type`, `sender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='申訴回報對話訊息';

-- ----------------------------
-- 申訴回報附件
-- ----------------------------
DROP TABLE IF EXISTS `support_attachments`;
CREATE TABLE `support_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL DEFAULT 0 COMMENT 'support_tickets.id',
  `message_id` int NOT NULL DEFAULT 0 COMMENT 'support_messages.id（0=主單附件）',
  `uploader_type` varchar(10) NOT NULL DEFAULT 'user' COMMENT 'user/gm',
  `uploader_id` int NOT NULL DEFAULT 0 COMMENT '上傳者 ID',
  `file_name` varchar(180) NOT NULL DEFAULT '' COMMENT '原始檔名',
  `stored_name` varchar(180) NOT NULL DEFAULT '' COMMENT '實際儲存檔名',
  `file_path` varchar(255) NOT NULL DEFAULT '' COMMENT '相對儲存路徑',
  `file_size` int NOT NULL DEFAULT 0 COMMENT '檔案大小(bytes)',
  `mime_type` varchar(100) NOT NULL DEFAULT '' COMMENT 'MIME 類型',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attachment_ticket` (`ticket_id`),
  KEY `idx_attachment_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='申訴回報附件';

-- ----------------------------
-- 管理操作 / 狀態歷程
-- ----------------------------
DROP TABLE IF EXISTS `support_status_log`;
CREATE TABLE `support_status_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL DEFAULT 0 COMMENT 'support_tickets.id',
  `action` varchar(40) NOT NULL DEFAULT '' COMMENT 'create/reply/status/priority/assign/note/close',
  `operator_type` varchar(10) NOT NULL DEFAULT 'system' COMMENT 'user/gm/system',
  `operator_id` int NOT NULL DEFAULT 0 COMMENT '操作者 ID',
  `operator_name` varchar(50) NOT NULL DEFAULT '' COMMENT '操作者名稱',
  `old_status` varchar(20) NOT NULL DEFAULT '',
  `new_status` varchar(20) NOT NULL DEFAULT '',
  `old_priority` varchar(16) NOT NULL DEFAULT '',
  `new_priority` varchar(16) NOT NULL DEFAULT '',
  `assigned_to` varchar(50) NOT NULL DEFAULT '' COMMENT '指派 GM',
  `note` varchar(255) NOT NULL DEFAULT '' COMMENT '操作備註',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_statuslog_ticket` (`ticket_id`),
  KEY `idx_statuslog_action` (`action`),
  KEY `idx_statuslog_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='申訴回報管理操作歷程';

-- ----------------------------
-- 預設分類資料
-- ----------------------------
INSERT INTO `support_categories` (`code`, `name`, `icon`, `description`, `default_priority`, `enabled`, `sort_order`) VALUES
('account',   '帳號問題',   'fa-user-shield',      '帳號被盜、登入異常、資料變更等', 'high',  1, 1),
('game_bug',  '遊戲異常',   'fa-bug',              '技能、任務、地圖、掉寶等異常',   'normal', 1, 2),
('recharge',  '儲值點數',   'fa-coins',            '儲值未入帳、點數異常、退款問題', 'urgent', 1, 3),
('login',     '連線登入',   'fa-network-wired',    '無法登入、斷線、延遲等問題',     'normal', 1, 4),
('report',    '違規檢舉',   'fa-triangle-exclamation', '外掛、辱罵、詐騙等違規行為', 'normal', 1, 5),
('lost_item', '物品遺失',   'fa-box-open',         '道具、裝備、元寶遺失回報',       'high',   1, 6),
('suggest',   '建議回饋',   'fa-lightbulb',        '遊戲內容與營運建議',             'low',    1, 7),
('other',     '其他問題',   'fa-circle-question',  '其他無法歸類的申訴事項',         'normal', 1, 8);

SET FOREIGN_KEY_CHECKS = 1;

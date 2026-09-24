-- =============================================================
--  踏雪笑傲 · 帳號層級／類型（Account Tiers）
--  -------------------------------------------------------------
--  用途：
--    前台（會員中心）、後台（帳號查詢）顯示會員層級。
--    後台可新增／編輯層級名稱、圖示、顏色與等級高低。
--
--  對應程式：
--    account_tiers.php            （共用工具庫）
--    admin/account_tier_api.php   （後台 JSON API）
--    admin/gmpanel.php            （後台管理子視窗）
--
--  使用方式（可重複執行、非破壞性）：
--    mysql -u root -p servbay < account_tier_schema.sql
--  或於網站後台執行 admin/account_tier_api.php 時自動建立。
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1) 帳號層級定義表
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `account_tiers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL DEFAULT '' COMMENT '層級代碼（唯一）',
  `name` varchar(60) NOT NULL DEFAULT '' COMMENT '層級名稱',
  `icon` varchar(60) NOT NULL DEFAULT 'fa-user' COMMENT 'FontAwesome 圖示',
  `color` varchar(20) NOT NULL DEFAULT '#64748b' COMMENT '代表色（hex）',
  `rank` int NOT NULL DEFAULT 0 COMMENT '等級高低（數字越大越高）',
  `description` varchar(200) NOT NULL DEFAULT '' COMMENT '說明',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tier_code` (`code`),
  KEY `idx_tier_rank` (`rank`),
  KEY `idx_tier_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='帳號層級／類型定義';

-- -------------------------------------------------------------
-- 2) 會員層級對應表（每位會員一列）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_tiers` (
  `uid` int NOT NULL,
  `tier_id` int NOT NULL DEFAULT 0 COMMENT 'account_tiers.id',
  `updated_by` varchar(50) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`uid`),
  KEY `idx_user_tier` (`tier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會員所屬帳號層級';

-- -------------------------------------------------------------
-- 3) 預設層級資料（不覆蓋既有）
-- -------------------------------------------------------------
INSERT IGNORE INTO `account_tiers` (`code`,`name`,`icon`,`color`,`rank`,`description`,`enabled`,`sort_order`) VALUES
('normal', '普通帳號', 'fa-user',         '#64748b', 10, '一般註冊會員',       1, 1),
('honor',  '榮譽會員', 'fa-medal',        '#0ea5e9', 30, '對遊戲有貢獻的榮譽會員', 1, 2),
('vip',    'VIP 會員', 'fa-crown',        '#f59e0b', 50, '付費 VIP 會員',       1, 3),
('gm',     'GM 管理員', 'fa-shield-halved','#8b5cf6', 90, '遊戲管理團隊',        1, 4);

-- =============================================================
--  回復說明（僅在需要時手動執行）
--  DROP TABLE IF EXISTS `user_tiers`;
--  DROP TABLE IF EXISTS `account_tiers`;
-- =============================================================

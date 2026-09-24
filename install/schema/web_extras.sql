-- =============================================================
--  踏雪笑傲 · 安裝補齊資料表（web_extras.sql）
--  -------------------------------------------------------------
--  用途：補齊 sa.sql 與各 *_schema.sql 未定義、但程式執行時會用到
--        的資料表。本檔為「可重複執行、非破壞性」（IF NOT EXISTS）。
--  由 install/index.php 於匯入結構時自動執行。
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 點數卡（config.php redeemCard 使用）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cardrecord` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL DEFAULT '',
  `pointcard` int(11) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `used_by` int(11) NOT NULL DEFAULT 0,
  `starttime` datetime NULL DEFAULT NULL,
  `endtime` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='點數卡';

-- -------------------------------------------------------------
-- 儲值訂單（recharge*.php / ecpay / GM 後台使用）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `recharge_orders` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `order_no` varchar(40) NOT NULL DEFAULT '',
  `uid` int(11) NOT NULL DEFAULT 0,
  `username` varchar(64) NOT NULL DEFAULT '',
  `method_key` varchar(32) NOT NULL DEFAULT '',
  `method_name` varchar(64) NOT NULL DEFAULT '',
  `choose_payment` varchar(32) NOT NULL DEFAULT '',
  `amount` int(11) NOT NULL DEFAULT 0,
  `bonus` int(11) NOT NULL DEFAULT 0,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `rtn_code` varchar(32) NOT NULL DEFAULT '',
  `rtn_msg` varchar(255) NOT NULL DEFAULT '',
  `trade_no` varchar(64) NOT NULL DEFAULT '',
  `payment_type` varchar(32) NOT NULL DEFAULT '',
  `payment_info` text NULL,
  `callback_raw` text NULL,
  `paid_at` datetime NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_no` (`order_no`),
  KEY `idx_uid` (`uid`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='儲值訂單';

-- -------------------------------------------------------------
-- 「記住我」持久登入權杖（remember_me.php 使用）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `web_remember_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` int NOT NULL,
  `selector` char(32) NOT NULL,
  `validator_hash` char(64) NOT NULL,
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `expires_at` bigint unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_selector` (`selector`),
  KEY `idx_uid` (`uid`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='記住我登入權杖';

-- -------------------------------------------------------------
-- 結構版本紀錄（供日後升級比對；安裝時寫入基準版本）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` varchar(64) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='資料庫結構版本';

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('install:base');

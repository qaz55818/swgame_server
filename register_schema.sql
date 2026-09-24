-- =============================================================
--  踏雪笑傲 · 註冊管理／安全防護資料庫遷移（SA 資料庫）
--  -------------------------------------------------------------
--  本檔為「可重複執行、非破壞性」遷移：
--    - 僅新增資料表與欄位（IF NOT EXISTS / information_schema 檢查）。
--    - 不刪除、不修改既有遊戲帳號資料表 `users` 的任何結構或資料。
--    - 不重複保存密碼資訊於紀錄表。
--
--  使用方式（請先備份）：
--    mysqldump -u root -p sa > sa_backup_YYYYMMDD.sql
--    mysql -u root -p sa < register_schema.sql
--
--  回復方式：
--    本遷移只會新增物件，回復時可視需要執行檔尾「回復說明」中的語句。
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 1) 註冊設定（鍵／值）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `register_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` varchar(64) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='註冊功能設定';

INSERT IGNORE INTO `register_settings` (`setting_key`, `setting_value`) VALUES
  ('register_enabled', '1'),
  ('closed_notice', '註冊功能目前暫停開放，造成不便敬請見諒。'),
  ('rate_window_seconds', '300'),
  ('rate_max_per_ip', '5'),
  ('rate_max_per_account', '3'),
  ('rate_max_per_ip_hour', '20'),
  ('rate_cooldown_seconds', '300'),
  ('block_duration_seconds', '3600'),
  ('retention_days', '90'),
  ('trusted_proxies', ''),
  ('email_max_accounts', '1'),
  ('account_min_length', '4'),
  ('account_max_length', '10'),
  ('password_min_length', '4'),
  ('password_max_length', '10'),
  ('forbidden_words', ''),
  ('forbidden_symbols', ''),
  ('forbidden_email_patterns', '');

-- -------------------------------------------------------------
-- 2) 封鎖名單（IP／帳號）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `register_blocks` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `scope` varchar(16) NOT NULL DEFAULT 'ip' COMMENT 'ip / account',
  `block_key` varchar(64) NOT NULL DEFAULT '' COMMENT 'IP 或帳號（不以特殊字元拼接查詢）',
  `reason` varchar(255) NOT NULL DEFAULT '',
  `source` varchar(16) NOT NULL DEFAULT 'manual' COMMENT 'auto / manual',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NULL DEFAULT NULL COMMENT 'NULL 代表永久',
  `released_by` varchar(64) NOT NULL DEFAULT '',
  `released_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_block_scope_key` (`scope`, `block_key`, `active`),
  KEY `idx_block_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='註冊封鎖名單';

-- -------------------------------------------------------------
-- 3) 限流計數（原子遞增，可承受併發）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `register_rate_limits` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `scope` varchar(24) NOT NULL COMMENT 'ip / ip_hour / account / gm_login_ip / gm_login_account / check_ip',
  `bucket_key` varchar(64) NOT NULL,
  `window_start` bigint NOT NULL COMMENT '對齊後的視窗起始 unix 時間',
  `hits` int NOT NULL DEFAULT 0,
  `last_seen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate` (`scope`, `bucket_key`, `window_start`),
  KEY `idx_rate_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='註冊／登入限流計數';

-- -------------------------------------------------------------
-- 4) 管理操作紀錄
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `register_admin_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `gm_username` varchar(64) NOT NULL DEFAULT '',
  `action` varchar(48) NOT NULL DEFAULT '',
  `target` varchar(128) NOT NULL DEFAULT '',
  `detail` varchar(255) NOT NULL DEFAULT '' COMMENT '不含機密的變更摘要',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_created` (`created_at`),
  KEY `idx_admin_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='註冊管理操作紀錄';

-- -------------------------------------------------------------
-- 5) 擴充既有 register_log（新增結果欄位，可重複執行）
--    保留既有資料列；status 預設 success 以相容舊資料。
-- -------------------------------------------------------------
DROP PROCEDURE IF EXISTS `swo_register_migrate_log`;
DELIMITER ;;
CREATE PROCEDURE `swo_register_migrate_log`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'register_log' AND COLUMN_NAME = 'status'
  ) THEN
    ALTER TABLE `register_log`
      ADD COLUMN `status` varchar(16) NOT NULL DEFAULT 'success' AFTER `session_id`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'register_log' AND COLUMN_NAME = 'reason_code'
  ) THEN
    ALTER TABLE `register_log`
      ADD COLUMN `reason_code` varchar(48) NOT NULL DEFAULT '' AFTER `status`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'register_log' AND COLUMN_NAME = 'reason_text'
  ) THEN
    ALTER TABLE `register_log`
      ADD COLUMN `reason_text` varchar(255) NOT NULL DEFAULT '' AFTER `reason_code`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'register_log' AND INDEX_NAME = 'idx_register_status'
  ) THEN
    ALTER TABLE `register_log` ADD INDEX `idx_register_status` (`status`);
  END IF;
END ;;
DELIMITER ;

CALL `swo_register_migrate_log`();
DROP PROCEDURE IF EXISTS `swo_register_migrate_log`;

-- -------------------------------------------------------------
-- 6) Web 專用密碼雜湊側車（可選相容性強化，不影響遊戲端登入）
--    遊戲端仍使用 users.passwd（原始二進位 MD5）；本站登入優先驗證
--    bcrypt，成功或偵測到舊格式時自動升級本表，遊戲端不受影響。
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `web_credentials` (
  `uid` int NOT NULL,
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `legacy_fingerprint` char(64) NOT NULL DEFAULT '' COMMENT '設定當下 users.passwd 的 sha256，用於偵測離線變更',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='網站端 bcrypt 密碼（遊戲端相容）';

DROP PROCEDURE IF EXISTS `swo_register_migrate_creds`;
DELIMITER ;;
CREATE PROCEDURE `swo_register_migrate_creds`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_credentials' AND COLUMN_NAME = 'legacy_fingerprint'
  ) THEN
    ALTER TABLE `web_credentials` ADD COLUMN `legacy_fingerprint` char(64) NOT NULL DEFAULT '' AFTER `password_hash`;
  END IF;
END ;;
DELIMITER ;
CALL `swo_register_migrate_creds`();
DROP PROCEDURE IF EXISTS `swo_register_migrate_creds`;

-- =============================================================
--  回復說明（僅在需要時手動執行）
--  -------------------------------------------------------------
--  DROP TABLE IF EXISTS `register_admin_logs`;
--  DROP TABLE IF EXISTS `register_blocks`;
--  DROP TABLE IF EXISTS `register_rate_limits`;
--  DROP TABLE IF EXISTS `register_settings`;
--  DROP TABLE IF EXISTS `web_credentials`;
--  ALTER TABLE `register_log` DROP INDEX `idx_register_status`;
--  ALTER TABLE `register_log` DROP COLUMN `reason_text`;
--  ALTER TABLE `register_log` DROP COLUMN `reason_code`;
--  ALTER TABLE `register_log` DROP COLUMN `status`;
-- =============================================================

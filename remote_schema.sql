-- =============================================================
--  踏雪笑傲 · 遠端伺服器管理資料表（remote_schema.sql）
--  -------------------------------------------------------------
--  用途：後台「遠端伺服器管理」使用。可重複執行、非破壞性
--        （CREATE TABLE IF NOT EXISTS），安裝精靈會自動匯入。
--  憑證欄位以 AES-256-GCM 加密後以 base64 儲存，金鑰來自
--  config.local.php 的 REMOTE_CRED_KEY。
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- 遠端伺服器清單
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `remote_servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '',
  `host` varchar(255) NOT NULL DEFAULT '',
  `port` int(11) NOT NULL DEFAULT 22,
  `username` varchar(100) NOT NULL DEFAULT '',
  `auth_type` varchar(16) NOT NULL DEFAULT 'password' COMMENT 'password|key',
  `password_enc` text NULL COMMENT 'AES-256-GCM 加密後密碼',
  `private_key_enc` mediumtext NULL COMMENT 'AES-256-GCM 加密後私鑰',
  `key_passphrase_enc` text NULL COMMENT 'AES-256-GCM 加密後私鑰密語',
  `root_path` varchar(255) NOT NULL DEFAULT '/' COMMENT '允許存取的根目錄',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='遠端 SSH 伺服器清單';

-- -------------------------------------------------------------
-- 遠端操作稽核日誌
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `remote_audit_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL DEFAULT 0,
  `server_name` varchar(100) NOT NULL DEFAULT '',
  `gm_username` varchar(50) NOT NULL DEFAULT '',
  `action` varchar(40) NOT NULL DEFAULT '',
  `target` varchar(500) NOT NULL DEFAULT '',
  `detail` text NULL,
  `ip` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_server` (`server_id`),
  KEY `idx_gm` (`gm_username`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='遠端伺服器操作稽核';

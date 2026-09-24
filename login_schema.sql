-- =============================================================
--  踏雪笑傲 · 登入紀錄與安全防護資料表（SA 資料庫）
--  前台寫入：login.php（經 login_security.php）
--  後台查詢：admin/gm_login_logs_api.php（子視窗「登入管理」）
-- =============================================================

SET NAMES utf8mb3;

DROP TABLE IF EXISTS `login_logs`;
CREATE TABLE `login_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL DEFAULT '' COMMENT '嘗試登入的帳號',
  `user_id` int NOT NULL DEFAULT 0 COMMENT '成功時對應 users.ID',
  `status` varchar(16) NOT NULL DEFAULT 'failed' COMMENT 'success / failed / blocked',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '結果說明',
  `ip` varchar(45) NOT NULL DEFAULT '' COMMENT '來源 IP',
  `ip_forwarded` varchar(255) NOT NULL DEFAULT '' COMMENT 'X-Forwarded-For',
  `user_agent` varchar(512) NOT NULL DEFAULT '',
  `accept_language` varchar(128) NOT NULL DEFAULT '',
  `referer` varchar(255) NOT NULL DEFAULT '',
  `session_id` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_username` (`username`),
  KEY `idx_login_ip` (`ip`),
  KEY `idx_login_status` (`status`),
  KEY `idx_login_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='會員登入紀錄';

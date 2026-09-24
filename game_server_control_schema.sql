-- =============================================================
--  踏雪笑傲 · 遊戲伺服器啟停控制（game_server_control_schema.sql）
--  -------------------------------------------------------------
--  用途：後台「遊戲伺服器控制」使用。儲存啟動／關閉腳本路徑、
--        遊戲目錄與啟動參數，以及啟動防護時間戳。
--  可重複執行、非破壞性；安裝精靈會自動匯入（*_schema.sql）。
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `game_server_control` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `start_script` varchar(255) NOT NULL DEFAULT '/root/xa274/myqd',
  `stop_script` varchar(255) NOT NULL DEFAULT '/root/xa274/stop',
  `pw_path` varchar(255) NOT NULL DEFAULT '/root/xa274',
  `signup_port` int(11) NOT NULL DEFAULT 8888,
  `small` varchar(8) NOT NULL DEFAULT 'yes' COMMENT 'yes=小記憶體模式',
  `gangs` varchar(16) NOT NULL DEFAULT 'allow' COMMENT 'allow=啟動幫派 / notallow=不啟動',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `last_start_at` datetime NULL COMMENT '上次啟動時間（防止重複啟動）',
  `updated_by` varchar(50) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='遊戲伺服器啟停控制';

INSERT IGNORE INTO `game_server_control` (`id`) VALUES (1);

-- =============================================================
--  踏雪笑傲 · 遊戲伺服器版本設定（game_server_schema.sql）
--  -------------------------------------------------------------
--  用途：後台「遊戲伺服器版本設定」使用。儲存目標遠端伺服器、
--        glinkd gamesys.conf 路徑、目前版本快取、以及初始版本快照
--        （供「還原初始版本」使用）。可重複執行、非破壞性。
--  安裝精靈會自動匯入（符合 *_schema.sql 命名）。
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `game_server_config` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `server_id` int(11) NOT NULL DEFAULT 0 COMMENT 'remote_servers.id',
  `conf_path` varchar(255) NOT NULL DEFAULT '/root/xa274/glinkd/gamesys.conf',
  `version` varchar(64) NOT NULL DEFAULT '' COMMENT '目前版本號（快取）',
  `initial_version` varchar(64) NOT NULL DEFAULT '' COMMENT '初始版本號',
  `initial_raw` mediumtext NULL COMMENT '初始 gamesys.conf 內容快照（base64，支援非 UTF-8）',
  `raw_cache` mediumtext NULL COMMENT '最近一次讀取的 gamesys.conf 內容（base64，支援非 UTF-8）',
  `updated_by` varchar(50) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='遊戲伺服器版本設定';

INSERT IGNORE INTO `game_server_config` (`id`) VALUES (1);

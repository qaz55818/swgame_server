-- =============================================================
--  踏雪笑傲 · 遊戲核心設定檔（game_server_files_schema.sql）
--  -------------------------------------------------------------
--  用途：後台「遊戲核心設定」使用，可管理多個遠端設定檔
--        （例如 gamed/gs.conf）。每個檔案儲存路徑、目前內容快取
--        與初始內容快照（供「還原初始」）。可重複執行、非破壞性。
--  安裝精靈會自動匯入（符合 *_schema.sql 命名）。
--  原始內容以 base64 儲存，支援非 UTF-8（如 GBK）位元組。
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `game_server_files` (
  `file_key` varchar(64) NOT NULL COMMENT '設定檔代碼（見程式 registry）',
  `label` varchar(120) NOT NULL DEFAULT '',
  `path` varchar(255) NOT NULL DEFAULT '' COMMENT '遠端絕對路徑',
  `raw_cache` mediumtext NULL COMMENT '最近讀取內容（base64）',
  `initial_raw` mediumtext NULL COMMENT '初始內容快照（base64）',
  `updated_by` varchar(50) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`file_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='遊戲核心設定檔';

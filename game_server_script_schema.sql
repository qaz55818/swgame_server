-- =============================================================
--  踏雪笑傲 · 生產合成設定檔（game_server_script_schema.sql）
--  -------------------------------------------------------------
--  用途：後台「生產合成設定」使用，管理遠端
--        gamed/config/script/produce_svr.lua。儲存檔案路徑、目前內容
--        快取與初始內容快照（供「還原初始」）。
--        可重複執行、非破壞性。
--  安裝精靈會自動匯入（符合 *_schema.sql 命名）。
--  原始內容以 base64 儲存，支援非 UTF-8（如 GBK）位元組。
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `game_server_produce_config` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `path` varchar(255) NOT NULL DEFAULT '/root/xa274/gamed/config/script/produce_svr.lua' COMMENT '遠端檔案絕對路徑',
  `raw_cache` mediumtext NULL COMMENT '最近讀取內容（base64）',
  `initial_raw` mediumtext NULL COMMENT '初始內容快照（base64）',
  `updated_by` varchar(50) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生產合成設定檔';

INSERT IGNORE INTO `game_server_produce_config` (`id`, `path`)
VALUES (1, '/root/xa274/gamed/config/script/produce_svr.lua');

-- -------------------------------------------------------------
--  獎勵設定檔：管理遠端 gamed/config/script/reward_data.lua
--  （由 reward_interface.lua 以 xdofile 載入）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `game_server_reward_config` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `path` varchar(255) NOT NULL DEFAULT '/root/xa274/gamed/config/script/reward_data.lua' COMMENT '遠端檔案絕對路徑',
  `raw_cache` mediumtext NULL COMMENT '最近讀取內容（base64）',
  `initial_raw` mediumtext NULL COMMENT '初始內容快照（base64）',
  `updated_by` varchar(50) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='獎勵設定檔';

INSERT IGNORE INTO `game_server_reward_config` (`id`, `path`)
VALUES (1, '/root/xa274/gamed/config/script/reward_data.lua');

-- -------------------------------------------------------------
--  角色服務設定檔：管理遠端
--  gamed/config/script/player_sev_config_data.lua
--  （由 player_sev_config_interface.lua 以 xdofile 載入）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `game_server_player_config` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `path` varchar(255) NOT NULL DEFAULT '/root/xa274/gamed/config/script/player_sev_config_data.lua' COMMENT '遠端檔案絕對路徑',
  `raw_cache` mediumtext NULL COMMENT '最近讀取內容（base64）',
  `initial_raw` mediumtext NULL COMMENT '初始內容快照（base64）',
  `updated_by` varchar(50) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色服務設定檔';

INSERT IGNORE INTO `game_server_player_config` (`id`, `path`)
VALUES (1, '/root/xa274/gamed/config/script/player_sev_config_data.lua');

-- -------------------------------------------------------------
--  團體競賽設定檔：管理遠端
--  gamed/config/script/boards_tournamentCfgs.lua
--  （定義 EctypeArgsReg[副本ID] 的階段、事件、控制器、怪物、道具與獎勵）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `game_server_tournament_config` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `path` varchar(255) NOT NULL DEFAULT '/root/xa274/gamed/config/script/boards_tournamentCfgs.lua' COMMENT '遠端檔案絕對路徑',
  `raw_cache` mediumtext NULL COMMENT '最近讀取內容（base64）',
  `initial_raw` mediumtext NULL COMMENT '初始內容快照（base64）',
  `updated_by` varchar(50) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='團體競賽設定檔';

INSERT IGNORE INTO `game_server_tournament_config` (`id`, `path`)
VALUES (1, '/root/xa274/gamed/config/script/boards_tournamentCfgs.lua');

-- -------------------------------------------------------------
--  活動排期設定檔：管理遠端
--  gamed/config/script/campaignlist.lua
--  （定義 CAMPAIGN_LIST[N] 的模板、廣播、時間類型、時段、排行與開服條件）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `game_server_campaignlist_config` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `path` varchar(255) NOT NULL DEFAULT '/root/xa274/gamed/config/script/campaignlist.lua' COMMENT '遠端檔案絕對路徑',
  `raw_cache` mediumtext NULL COMMENT '最近讀取內容（base64）',
  `initial_raw` mediumtext NULL COMMENT '初始內容快照（base64）',
  `updated_by` varchar(50) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='活動排期設定檔';

INSERT IGNORE INTO `game_server_campaignlist_config` (`id`, `path`)
VALUES (1, '/root/xa274/gamed/config/script/campaignlist.lua');

-- -------------------------------------------------------------
--  副本評分設定檔：管理遠端 gamed/config/script/ectype_score.lua
--  （定義 EctypeScore.Cfg[副本ID][模式] 的評價條件與獎勵，
--    以及共用經驗加成表 TeamExpNormal / GradeExpNormal）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `game_server_ectype_config` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `path` varchar(255) NOT NULL DEFAULT '/root/xa274/gamed/config/script/ectype_score.lua' COMMENT '遠端檔案絕對路徑',
  `raw_cache` mediumtext NULL COMMENT '最近讀取內容（base64）',
  `initial_raw` mediumtext NULL COMMENT '初始內容快照（base64）',
  `updated_by` varchar(50) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='副本評分設定檔';

INSERT IGNORE INTO `game_server_ectype_config` (`id`, `path`)
VALUES (1, '/root/xa274/gamed/config/script/ectype_score.lua');

-- -------------------------------------------------------------
--  場景∕世界設定檔：管理遠端 gamed/config/script/instance_gs.lua
--  （定義 InstInfo_GS[場景ID] 的視距/容量/分線/限制，
--    以及 WorldInfo[世界ID] 的類型與場景清單）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `game_server_instgs_config` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `path` varchar(255) NOT NULL DEFAULT '/root/xa274/gamed/config/script/instance_gs.lua' COMMENT '遠端檔案絕對路徑',
  `raw_cache` mediumtext NULL COMMENT '最近讀取內容（base64）',
  `initial_raw` mediumtext NULL COMMENT '初始內容快照（base64）',
  `updated_by` varchar(50) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='場景∕世界設定檔';

INSERT IGNORE INTO `game_server_instgs_config` (`id`, `path`)
VALUES (1, '/root/xa274/gamed/config/script/instance_gs.lua');

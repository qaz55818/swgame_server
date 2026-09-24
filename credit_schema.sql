-- =============================================================
--  踏雪笑傲 · 帳號信用系統資料表（SA 資料庫）
--  前台：home.php（會員中心顯示信用分數 / 明細子視窗）
--  後台：admin/credit_admin.php（子視窗彈出 UI，GM 端操作）
--  工具庫：credit.php
--
--  預設信用分數：100 分
--  資料表尚未建立時，前台會以預設值（100 / 極佳）運作。
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 信用系統參數設定（分數上下限 / 等級門檻 / 開關）
-- ----------------------------
DROP TABLE IF EXISTS `credit_settings`;
CREATE TABLE `credit_settings` (
  `setting_key` varchar(40) NOT NULL,
  `setting_value` varchar(255) NOT NULL DEFAULT '',
  `description` varchar(200) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='帳號信用系統設定';

INSERT INTO `credit_settings` (`setting_key`, `setting_value`, `description`) VALUES
('credit_enabled', '1',   '信用系統總開關（1 開啟 / 0 關閉）'),
('default_score',  '100', '新帳號預設信用分數'),
('min_score',      '0',   '信用分數下限'),
('max_score',      '1000','信用分數上限'),
('tier_excellent', '90',  '等級：極佳（>= 此分數）'),
('tier_good',      '75',  '等級：良好（>= 此分數）'),
('tier_fair',      '60',  '等級：尚可（>= 此分數）'),
('tier_poor',      '40',  '等級：待加強（>= 此分數）'),
('risk_threshold', '60',  '風險帳號門檻（低於此分數列為風險）'),
('show_on_home',   '1',   '會員中心是否顯示信用評分（1 顯示 / 0 隱藏）');

-- ----------------------------
-- 會員信用分數（每位會員一列，預設 100 分）
-- ----------------------------
DROP TABLE IF EXISTS `user_credit`;
CREATE TABLE `user_credit` (
  `uid` int(11) NOT NULL,
  `score` int(11) NOT NULL DEFAULT 100 COMMENT '目前信用分數',
  `total_delta` int(11) NOT NULL DEFAULT 0 COMMENT '累計分數變動',
  `note` varchar(255) NOT NULL DEFAULT '' COMMENT '備註',
  `updated_by` varchar(50) NOT NULL DEFAULT '' COMMENT '最後異動 GM 帳號',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`uid`),
  KEY `idx_credit_score` (`score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會員信用分數';

-- ----------------------------
-- 信用分數異動流水
-- ----------------------------
DROP TABLE IF EXISTS `credit_ledger`;
CREATE TABLE `credit_ledger` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL DEFAULT 0,
  `change_amount` int(11) NOT NULL DEFAULT 0 COMMENT '正為加分、負為扣分',
  `score_before` int(11) NOT NULL DEFAULT 0,
  `score_after` int(11) NOT NULL DEFAULT 0,
  `type` varchar(32) NOT NULL DEFAULT 'admin_set' COMMENT 'admin_set/admin_add/admin_deduct/reset',
  `reason` varchar(255) NOT NULL DEFAULT '',
  `operator` varchar(50) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_credit_ledger_uid` (`uid`),
  KEY `idx_credit_ledger_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='信用分數異動流水';

-- ----------------------------
-- 既有會員回填預設信用分數（100 分）
-- ----------------------------
INSERT INTO `user_credit` (`uid`, `score`, `total_delta`)
SELECT `ID`, 100, 0 FROM `users`
WHERE `ID` NOT IN (SELECT `uid` FROM `user_credit`);

SET FOREIGN_KEY_CHECKS = 1;

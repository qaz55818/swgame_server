-- =============================================================
--  踏雪笑傲 · 儲值貨幣（點數 / 元寶）系統資料表（SA 資料庫）
--  前台：home.php / recharge.php / exchange.php
--  後台：admin/wallet_admin.php
--  入帳：recharge_notify.php / admin/gm_orders_api.php（經 currency.php）
--
--  相容策略：
--    * 舊用戶：以 point(aid=23) 既有餘額回填為元寶，點數初始為 0
--    * 新用戶：首次存取時由 currency_ensure_wallet() 自動建立錢包
-- =============================================================

SET NAMES utf8mb3;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 貨幣設定（匯率 / 名稱 / 開關）
-- ----------------------------
DROP TABLE IF EXISTS `currency_settings`;
CREATE TABLE `currency_settings` (
  `setting_key` varchar(40) NOT NULL,
  `setting_value` varchar(255) NOT NULL DEFAULT '',
  `description` varchar(200) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='點數/元寶系統設定';

INSERT INTO `currency_settings` (`setting_key`, `setting_value`, `description`) VALUES
('points_name',         '點數', '前台點數顯示名稱'),
('yuanbao_name',        '元寶', '前台元寶顯示名稱'),
('exchange_enabled',    '1',    '是否開放點數兌換元寶'),
('exchange_rate',       '1',    '每 1 點數可兌換的元寶數'),
('exchange_min',        '100',  '單次最低兌換點數'),
('exchange_fee_percent','0',    '兌換手續費百分比');

-- ----------------------------
-- 會員錢包（每位會員一列）
-- ----------------------------
DROP TABLE IF EXISTS `user_wallet`;
CREATE TABLE `user_wallet` (
  `uid` int NOT NULL,
  `points` bigint NOT NULL DEFAULT 0 COMMENT '可用點數',
  `yuanbao` bigint NOT NULL DEFAULT 0 COMMENT '元寶餘額',
  `total_points_in` bigint NOT NULL DEFAULT 0 COMMENT '累計獲得點數',
  `total_yuanbao_in` bigint NOT NULL DEFAULT 0 COMMENT '累計獲得元寶',
  `frozen` tinyint(1) NOT NULL DEFAULT 0 COMMENT '凍結旗標',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`uid`),
  KEY `idx_wallet_points` (`points`),
  KEY `idx_wallet_yuanbao` (`yuanbao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='會員點數/元寶錢包';

-- ----------------------------
-- 貨幣交易流水
-- ----------------------------
DROP TABLE IF EXISTS `currency_ledger`;
CREATE TABLE `currency_ledger` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `uid` int NOT NULL DEFAULT 0,
  `currency` varchar(10) NOT NULL DEFAULT 'points' COMMENT 'points / yuanbao',
  `change_amount` bigint NOT NULL DEFAULT 0 COMMENT '正為增加、負為扣減',
  `balance_after` bigint NOT NULL DEFAULT 0,
  `type` varchar(32) NOT NULL DEFAULT '' COMMENT 'recharge/exchange_in/exchange_out/admin_grant/admin_deduct/consume/refund/register_bonus',
  `ref_no` varchar(64) NOT NULL DEFAULT '' COMMENT '關聯單號',
  `description` varchar(255) NOT NULL DEFAULT '',
  `operator` varchar(50) NOT NULL DEFAULT '' COMMENT '操作者（system / GM 帳號 / self）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ledger_uid` (`uid`),
  KEY `idx_ledger_currency` (`currency`),
  KEY `idx_ledger_type` (`type`),
  KEY `idx_ledger_created` (`created_at`),
  KEY `idx_ledger_ref` (`ref_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='點數/元寶交易流水';

-- ----------------------------
-- 點數兌換元寶紀錄
-- ----------------------------
DROP TABLE IF EXISTS `exchange_orders`;
CREATE TABLE `exchange_orders` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `exchange_no` varchar(30) NOT NULL DEFAULT '',
  `uid` int NOT NULL DEFAULT 0,
  `username` varchar(32) NOT NULL DEFAULT '',
  `points_used` bigint NOT NULL DEFAULT 0,
  `rate` decimal(12,4) NOT NULL DEFAULT 1.0000 COMMENT '每點可換元寶',
  `fee_percent` int NOT NULL DEFAULT 0,
  `yuanbao_gained` bigint NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'completed' COMMENT 'completed/failed',
  `note` varchar(200) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exchange_no` (`exchange_no`),
  KEY `idx_exchange_uid` (`uid`),
  KEY `idx_exchange_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='點數兌換元寶紀錄';

-- ----------------------------
-- 舊資料回填：point(aid=23) → user_wallet.yuanbao
-- ----------------------------
INSERT INTO `user_wallet` (`uid`, `points`, `yuanbao`, `total_points_in`, `total_yuanbao_in`)
SELECT `uid`, 0, IFNULL(SUM(`time`), 0), 0, IFNULL(SUM(`time`), 0)
FROM `point` WHERE `aid` = 23 GROUP BY `uid`
ON DUPLICATE KEY UPDATE
  `yuanbao` = VALUES(`yuanbao`),
  `total_yuanbao_in` = GREATEST(`total_yuanbao_in`, VALUES(`total_yuanbao_in`));

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
--  踏雪笑傲 · 會員功能權限資料表（SA 資料庫）
--  後台管理：admin/member_admin.php
--  前台檢查：permissions.php（未建立資料表時預設全部開啟）
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_permissions` (
  `uid` int(11) NOT NULL COMMENT '對應 users.ID',
  `can_recharge` tinyint(1) NOT NULL DEFAULT 1 COMMENT '儲值權限',
  `can_exchange` tinyint(1) NOT NULL DEFAULT 1 COMMENT '兌換權限',
  `can_change_password` tinyint(1) NOT NULL DEFAULT 1 COMMENT '修改密碼權限',
  `can_support` tinyint(1) NOT NULL DEFAULT 1 COMMENT '申訴回報權限',
  `can_view_assets` tinyint(1) NOT NULL DEFAULT 1 COMMENT '檢視資產權限',
  `can_download` tinyint(1) NOT NULL DEFAULT 1 COMMENT '遊戲下載權限',
  `updated_by` varchar(50) NOT NULL DEFAULT '' COMMENT '最後異動 GM 帳號',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會員功能權限設定';

-- 依現有會員建立預設（全開）權限列（可選）
INSERT INTO `user_permissions` (`uid`)
SELECT `ID` FROM `users`
WHERE `ID` NOT IN (SELECT `uid` FROM `user_permissions`);

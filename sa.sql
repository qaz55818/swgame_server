/*
 Navicat Premium Dump SQL

 Source Server         : 192.168.1.62
 Source Server Type    : MySQL
 Source Server Version : 50173 (5.1.73)
 Source Host           : 192.168.1.62:3306
 Source Schema         : sa

 Target Server Type    : MySQL
 Target Server Version : 50173 (5.1.73)
 File Encoding         : 65001

 Date: 05/08/2026 16:16:34
*/

SET NAMES utf8;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for auth
-- ----------------------------
DROP TABLE IF EXISTS `auth`;
CREATE TABLE `auth`  (
  `userid` int(11) NOT NULL DEFAULT 0,
  `zoneid` int(11) NOT NULL DEFAULT 0,
  `rid` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`userid`, `zoneid`, `rid`) USING BTREE
) ENGINE = MyISAM CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = FIXED;

-- ----------------------------
-- Records of auth
-- ----------------------------
INSERT INTO `auth` VALUES (32, 20586, 0);
INSERT INTO `auth` VALUES (32, 20586, 1);
INSERT INTO `auth` VALUES (32, 20586, 2);
INSERT INTO `auth` VALUES (32, 20586, 3);
INSERT INTO `auth` VALUES (32, 20586, 4);
INSERT INTO `auth` VALUES (32, 20586, 5);
INSERT INTO `auth` VALUES (32, 20586, 6);
INSERT INTO `auth` VALUES (32, 20586, 7);
INSERT INTO `auth` VALUES (32, 20586, 8);
INSERT INTO `auth` VALUES (32, 20586, 9);
INSERT INTO `auth` VALUES (32, 20586, 10);
INSERT INTO `auth` VALUES (32, 20586, 11);
INSERT INTO `auth` VALUES (32, 20586, 100);
INSERT INTO `auth` VALUES (32, 20586, 101);
INSERT INTO `auth` VALUES (32, 20586, 102);
INSERT INTO `auth` VALUES (32, 20586, 103);
INSERT INTO `auth` VALUES (32, 20586, 104);
INSERT INTO `auth` VALUES (32, 20586, 105);
INSERT INTO `auth` VALUES (32, 20586, 200);
INSERT INTO `auth` VALUES (32, 20586, 201);
INSERT INTO `auth` VALUES (32, 20586, 202);
INSERT INTO `auth` VALUES (32, 20586, 203);
INSERT INTO `auth` VALUES (32, 20586, 204);
INSERT INTO `auth` VALUES (32, 20586, 205);
INSERT INTO `auth` VALUES (32, 20586, 206);
INSERT INTO `auth` VALUES (32, 20586, 207);
INSERT INTO `auth` VALUES (32, 20586, 208);
INSERT INTO `auth` VALUES (32, 20586, 209);
INSERT INTO `auth` VALUES (32, 20586, 210);
INSERT INTO `auth` VALUES (32, 20586, 211);
INSERT INTO `auth` VALUES (32, 20586, 212);
INSERT INTO `auth` VALUES (32, 20586, 213);
INSERT INTO `auth` VALUES (32, 20586, 214);
INSERT INTO `auth` VALUES (32, 20586, 501);
INSERT INTO `auth` VALUES (32, 20586, 502);
INSERT INTO `auth` VALUES (32, 20586, 503);
INSERT INTO `auth` VALUES (32, 20586, 504);
INSERT INTO `auth` VALUES (32, 20586, 505);
INSERT INTO `auth` VALUES (32, 20586, 506);
INSERT INTO `auth` VALUES (32, 20586, 507);
INSERT INTO `auth` VALUES (32, 20586, 508);
INSERT INTO `auth` VALUES (32, 20586, 509);
INSERT INTO `auth` VALUES (32, 20586, 510);
INSERT INTO `auth` VALUES (32, 20586, 511);
INSERT INTO `auth` VALUES (32, 20586, 512);
INSERT INTO `auth` VALUES (32, 20586, 513);
INSERT INTO `auth` VALUES (32, 20586, 514);
INSERT INTO `auth` VALUES (32, 20586, 515);
INSERT INTO `auth` VALUES (32, 20586, 516);
INSERT INTO `auth` VALUES (32, 20586, 517);
INSERT INTO `auth` VALUES (32, 20586, 518);

-- ----------------------------
-- Table structure for forbid
-- ----------------------------
DROP TABLE IF EXISTS `forbid`;
CREATE TABLE `forbid`  (
  `userid` int(11) NOT NULL DEFAULT 0,
  `type` int(11) NOT NULL DEFAULT 0,
  `ctime` datetime NOT NULL,
  `forbid_time` int(11) NOT NULL DEFAULT 0,
  `reason` blob NOT NULL,
  `gmroleid` int(11) NULL DEFAULT 0,
  PRIMARY KEY (`userid`, `type`) USING BTREE
) ENGINE = MyISAM CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of forbid
-- ----------------------------

-- ----------------------------
-- Table structure for gm_accounts
-- ----------------------------
DROP TABLE IF EXISTS `gm_accounts`;
CREATE TABLE `gm_accounts`  (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `username`(`username`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 2 CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of gm_accounts
-- ----------------------------
INSERT INTO `gm_accounts` VALUES (1, 'virusx', '$2y$10$.FanBxK.AFLjzGsv58dbgOOr7J/Q6AilDzp6oQQf23L7poedEoss2', '2026-08-05 02:44:01');

-- ----------------------------
-- Table structure for iplimit
-- ----------------------------
DROP TABLE IF EXISTS `iplimit`;
CREATE TABLE `iplimit`  (
  `uid` int(11) NOT NULL DEFAULT 0,
  `ipaddr1` int(11) NULL DEFAULT 0,
  `ipmask1` varchar(2) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `ipaddr2` int(11) NULL DEFAULT 0,
  `ipmask2` varchar(2) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `ipaddr3` int(11) NULL DEFAULT 0,
  `ipmask3` varchar(2) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `enable` char(1) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `lockstatus` char(1) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  PRIMARY KEY (`uid`) USING BTREE
) ENGINE = MyISAM CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of iplimit
-- ----------------------------

-- ----------------------------
-- Table structure for online
-- ----------------------------
DROP TABLE IF EXISTS `online`;
CREATE TABLE `online`  (
  `ID` int(11) NULL DEFAULT NULL
) ENGINE = MyISAM CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = FIXED;

-- ----------------------------
-- Records of online
-- ----------------------------
INSERT INTO `online` VALUES (160);

-- ----------------------------
-- Table structure for point
-- ----------------------------
DROP TABLE IF EXISTS `point`;
CREATE TABLE `point`  (
  `uid` int(11) NOT NULL DEFAULT 0,
  `aid` int(11) NOT NULL DEFAULT 0,
  `time` int(11) NOT NULL DEFAULT 0,
  `zoneid` int(11) NULL DEFAULT 0,
  `zonelocalid` int(11) NULL DEFAULT 0,
  `accountstart` datetime NULL DEFAULT NULL,
  `lastlogin` datetime NULL DEFAULT NULL,
  `enddate` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`uid`, `aid`) USING BTREE,
  INDEX `IX_point_aidzoneid`(`aid`, `zoneid`) USING BTREE
) ENGINE = MyISAM CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = FIXED;

-- ----------------------------
-- Records of point
-- ----------------------------
INSERT INTO `point` VALUES (32, 23, 0, NULL, NULL, NULL, '2026-08-05 02:22:26', NULL);
INSERT INTO `point` VALUES (32, 1, 0, 1, 0, NULL, NULL, NULL);
INSERT INTO `point` VALUES (48, 23, 0, NULL, NULL, NULL, '2022-03-18 20:25:05', NULL);
INSERT INTO `point` VALUES (80, 23, 0, NULL, NULL, NULL, '2022-03-18 19:03:44', NULL);
INSERT INTO `point` VALUES (112, 23, 0, NULL, NULL, NULL, '2022-03-18 18:42:41', NULL);
INSERT INTO `point` VALUES (128, 23, 0, NULL, NULL, NULL, '2022-03-18 20:21:54', NULL);
INSERT INTO `point` VALUES (144, 23, 0, NULL, NULL, NULL, '2026-08-05 02:24:48', NULL);
INSERT INTO `point` VALUES (160, 23, 0, 20586, 4, NULL, '2026-08-05 20:46:49', NULL);

-- ----------------------------
-- Table structure for roleinfo
-- ----------------------------
DROP TABLE IF EXISTS `roleinfo`;
CREATE TABLE `roleinfo`  (
  `account_id` int(11) NOT NULL,
  `role_id` bigint(20) NULL DEFAULT NULL,
  `itemtype` int(11) NOT NULL,
  `itemid` int(11) NOT NULL,
  `itempos` int(11) NOT NULL DEFAULT 0,
  `timestamp_low` int(11) NULL DEFAULT 0,
  `itemcount` int(11) NOT NULL DEFAULT 0,
  `timestamp_high` int(11) NULL DEFAULT 0,
  `max_count` int(11) NOT NULL DEFAULT 0,
  `itemdata` varchar(4096) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `proctype` int(11) NOT NULL DEFAULT 0,
  `expire_date` int(11) NOT NULL DEFAULT 0,
  `guid1` int(11) NOT NULL DEFAULT 0,
  `guid2` int(11) NOT NULL DEFAULT 0,
  `emitemid` int(11) NULL DEFAULT 0,
  `itemlevel` int(11) NULL DEFAULT 0,
  `extprop` int(11) NULL DEFAULT 0,
  `remark` varchar(128) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL,
  `itemname` varchar(128) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `version` int(11) NULL DEFAULT 0,
  `reserved1` int(11) NULL DEFAULT 0,
  `reserved2` int(11) NULL DEFAULT 0
) ENGINE = InnoDB CHARACTER SET = latin1 COLLATE = latin1_swedish_ci ROW_FORMAT = Compact;

-- ----------------------------
-- Records of roleinfo
-- ----------------------------

-- ----------------------------
-- Table structure for roles
-- ----------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles`  (
  `account_id` int(11) NOT NULL,
  `role_id` bigint(20) NULL DEFAULT NULL,
  `role_name` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `role_level` int(11) NULL DEFAULT NULL,
  `role_race` int(11) NULL DEFAULT NULL,
  `role_occupation` int(11) NULL DEFAULT NULL,
  `role_gender` int(11) NULL DEFAULT NULL,
  `role_spouse` int(11) NULL DEFAULT NULL,
  `faction_id` int(11) NULL DEFAULT NULL,
  `faction_name` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `faction_level` int(11) NULL DEFAULT NULL,
  `faction_domains` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `role_faction_rank` int(11) NULL DEFAULT NULL,
  `pvp_time` int(11) NULL DEFAULT NULL,
  `pvp_kills` int(11) NULL DEFAULT NULL,
  `pvp_deads` int(11) NULL DEFAULT NULL,
  `unionflag` int(11) NULL DEFAULT 0,
  `OldRoleID` int(20) NULL DEFAULT NULL,
  `OldRoleName` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `NewRoleID` int(20) NULL DEFAULT NULL,
  `NewRoleName` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `UnionDesc` varchar(500) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `FactionNotice` varchar(500) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  `FactionMasterID` int(11) NULL DEFAULT NULL,
  `FactionMemberCount` int(11) NULL DEFAULT NULL,
  `rolexml` longblob NULL,
  `base_id` int(11) NULL DEFAULT 0,
  `base_userid` int(11) NULL DEFAULT 0,
  `status_id` int(11) NULL DEFAULT 0,
  `id_error` int(11) NULL DEFAULT 0,
  `titlelist` varchar(4096) CHARACTER SET gb2312 COLLATE gb2312_chinese_ci NULL DEFAULT NULL
) ENGINE = MyISAM CHARACTER SET = gb2312 COLLATE = gb2312_chinese_ci ROW_FORMAT = Dynamic;

-- ----------------------------
-- Records of roles
-- ----------------------------

-- ----------------------------
-- Table structure for usecashlog
-- ----------------------------
DROP TABLE IF EXISTS `usecashlog`;
CREATE TABLE `usecashlog`  (
  `userid` int(11) NOT NULL DEFAULT 0,
  `zoneid` int(11) NOT NULL DEFAULT 0,
  `sn` int(11) NOT NULL DEFAULT 0,
  `aid` int(11) NOT NULL DEFAULT 0,
  `point` int(11) NOT NULL DEFAULT 0,
  `cash` int(11) NOT NULL DEFAULT 0,
  `status` int(11) NOT NULL DEFAULT 0,
  `creatime` datetime NOT NULL,
  `fintime` datetime NOT NULL,
  INDEX `IX_usecashlog_creatime`(`creatime`) USING BTREE,
  INDEX `IX_usecashlog_uzs`(`userid`, `zoneid`, `sn`) USING BTREE
) ENGINE = MyISAM CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = FIXED;

-- ----------------------------
-- Records of usecashlog
-- ----------------------------

-- ----------------------------
-- Table structure for usecashnow
-- ----------------------------
DROP TABLE IF EXISTS `usecashnow`;
CREATE TABLE `usecashnow`  (
  `userid` int(11) NOT NULL DEFAULT 0,
  `zoneid` int(11) NOT NULL DEFAULT 0,
  `sn` int(11) NOT NULL DEFAULT 0,
  `aid` int(11) NOT NULL DEFAULT 0,
  `point` int(11) NOT NULL DEFAULT 0,
  `cash` int(11) NOT NULL DEFAULT 0,
  `status` int(11) NOT NULL DEFAULT 0,
  `creatime` datetime NOT NULL,
  PRIMARY KEY (`userid`, `zoneid`, `sn`) USING BTREE,
  INDEX `IX_usecashnow_creatime`(`creatime`) USING BTREE,
  INDEX `IX_usecashnow_status`(`status`) USING BTREE
) ENGINE = MyISAM CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = FIXED;

-- ----------------------------
-- Records of usecashnow
-- ----------------------------
INSERT INTO `usecashnow` VALUES (160, 20586, -1, 23, 99999, 99999, 0, '2026-08-05 03:11:53');

-- ----------------------------
-- Table structure for users
-- ----------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users`  (
  `ID` int(11) NOT NULL DEFAULT 0,
  `name` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `passwd` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
  `Prompt` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `answer` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `truename` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `idnumber` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `email` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `mobilenumber` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `province` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `city` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `phonenumber` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `address` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `postalcode` varchar(8) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `gender` int(11) NULL DEFAULT 0,
  `birthday` datetime NULL DEFAULT NULL,
  `creatime` datetime NOT NULL,
  `qq` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `passwd2` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`ID`) USING BTREE,
  UNIQUE INDEX `IX_users_name`(`name`) USING BTREE,
  INDEX `IX_users_creatime`(`creatime`) USING BTREE
) ENGINE = MyISAM CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of users
-- ----------------------------
INSERT INTO `users` VALUES (32, '123456', 'êHWo0¾i—™ÀšÐ\\”', '??', '??', '??', '110', 'zhainan@qq.com', '1', '1', '1', '1', '1', '1', 1, '2022-03-18 11:05:20', '2022-03-18 11:05:19', '88544741', 'êHWo0¾i—™ÀšÐ\\”');
INSERT INTO `users` VALUES (48, '1111', '–ç’–^·,’¥IÝZ3', '0', '0', '0', '0', '1111', '0', '0', '0', '0', '0', '0', 0, '2022-03-18 11:52:39', '2022-03-18 11:52:39', '', '0x1bbd886460827015e5d605ed44252251');
INSERT INTO `users` VALUES (64, '2222', '0xbae5e3208a3c700e3db642b6631e95b9', '0', '0', '0', '0', '1111', '0', '0', '0', '0', '0', '0', 0, '2022-03-18 12:26:00', '2022-03-18 12:26:00', '', '0xbae5e3208a3c700e3db642b6631e95b9');
INSERT INTO `users` VALUES (80, '3333', '·¼*/[¶Õ!æL‰tÁCé ', '0', '0', '0', '0', '1111', '0', '0', '0', '0', '0', '0', 0, '2022-03-18 12:35:12', '2022-03-18 12:35:12', '', '0xd27d320c27c3033b7883347d8beca317');
INSERT INTO `users` VALUES (96, '7758521', '0x4222b8edb62acee897c14d11b269e028', '0', '0', '0', '0', 'retertrrettrettr@163.com', '0', '0', '0', '0', '0', '0', 0, '2022-03-18 12:52:48', '2022-03-18 12:52:48', '', '0x4222b8edb62acee897c14d11b269e028');
INSERT INTO `users` VALUES (112, 'zhengxs', 'X­À_ì£¨ok‚>aG-', '??', '2022-3-18 15:14:40', '??', '842202809', '842202809@qq.com', '1', '1', '1', '1', '1', '1', 1, '2022-03-18 15:14:40', '2022-03-18 15:14:39', '842202809', 'X­À_ì£¨ok‚>aG-');
INSERT INTO `users` VALUES (128, 'ht010429', 'â³0¢ÀÝ>6J\Z1\ZV¤[', '??', '2022-3-18 20:20:15', '??', '842202809', '842202809@qq.com', '1', '1', '1', '1', '1', '1', 1, '2022-03-18 20:20:15', '2022-03-18 20:20:16', '842202809', 'â³0¢ÀÝ>6J\Z1\ZV¤[');
INSERT INTO `users` VALUES (144, 'test', 'Gì-×‘ã.òl¯dí›=', 'code name', 'å”ä¸‰', 'backsword valentine', '110101197601017270', 'backswv@gmail.com', '13570208832', 'beijing', 'beijing', '05620-31368122', 'æ–—ç½—å¤§é™† (Douluo Dalu)', '100010', 0, '1976-01-01 00:00:00', '2026-08-05 02:17:39', '0811377718@qq.com', 'Gì-×‘ã.òl¯dí›=');
INSERT INTO `users` VALUES (160, 'virusx', 'ÿ—øÛ_uGpA:Â˜', 'code name', 'å”ä¸‰', 'backsword valentine', '110101198001013529', 'backswv@gmail.com', '19569031732', 'beijing', 'beijing', '06242-18240455', 'æ–—ç½—å¤§é™† (Douluo Dalu)', '100010', 0, '1980-01-01 00:00:00', '2026-08-05 02:37:41', '0811377718@qq.com', 'ÿ—øÛ_uGpA:Â˜');

-- ----------------------------
-- Table structure for register_log
-- ----------------------------
DROP TABLE IF EXISTS `register_log`;
CREATE TABLE `register_log`  (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL DEFAULT 0,
  `username` varchar(32) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `email` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `ip` varchar(45) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `ip_forwarded` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `user_agent` varchar(512) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `accept_language` varchar(128) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `referer` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `fingerprint_hash` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `session_id` varchar(64) CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `IX_register_log_userid`(`userid`) USING BTREE,
  INDEX `IX_register_log_ip`(`ip`) USING BTREE,
  INDEX `IX_register_log_created`(`created_at`) USING BTREE,
  INDEX `IX_register_log_fp`(`fingerprint_hash`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 1 CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of register_log
-- ----------------------------

-- ----------------------------
-- Table structure for recharge_settings
-- ----------------------------
DROP TABLE IF EXISTS `recharge_settings`;
CREATE TABLE `recharge_settings`  (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_type` enum('method','amount','config') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'method',
  `setting_key` varchar(40) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `display_name` varchar(60) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `icon` varchar(40) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `description` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `amount` int(11) NOT NULL DEFAULT 0,
  `bonus_percent` int(11) NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `IX_recharge_type`(`setting_type`) USING BTREE,
  INDEX `IX_recharge_key`(`setting_key`) USING BTREE
) ENGINE = MyISAM AUTO_INCREMENT = 1 CHARACTER SET = utf8 COLLATE = utf8_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of recharge_settings
-- ----------------------------
INSERT INTO `recharge_settings` (`setting_type`, `setting_key`, `display_name`, `icon`, `description`, `amount`, `bonus_percent`, `enabled`, `sort_order`) VALUES
('method', 'credit_card', '線上刷卡', 'fa-credit-card', 'Visa / MasterCard / JCB 即時入帳', 0, 0, 1, 1),
('method', 'bank_transfer', '銀行轉帳', 'fa-building-columns', 'ATM 轉帳 / 網路銀行匯款', 0, 0, 1, 2),
('method', 'cvs', '超商繳費', 'fa-store', '7-11 / 全家 / 萊爾富 代碼繳費', 0, 0, 1, 3),
('method', 'crypto', '虛擬貨幣', 'fa-bitcoin-sign', 'USDT / BTC / ETH 鏈上支付', 0, 0, 1, 4),
('amount', '', '', '', '', 100, 0, 1, 1),
('amount', '', '', '', '', 300, 5, 1, 2),
('amount', '', '', '', '', 500, 10, 1, 3),
('amount', '', '', '', '', 1000, 15, 1, 4),
('amount', '', '', '', '', 3000, 20, 1, 5),
('amount', '', '', '', '', 5000, 25, 1, 6),
('config', 'min_amount', '最低儲值金額', '', '', 50, 0, 1, 1),
('config', 'max_amount', '最高儲值金額', '', '', 100000, 0, 1, 2);

-- ----------------------------
-- Procedure structure for acquireuserpasswd
-- ----------------------------
DROP PROCEDURE IF EXISTS `acquireuserpasswd`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `acquireuserpasswd`(in name1 VARCHAR(64), out uid1 INTEGER, out passwd1 VARCHAR(64))
BEGIN
  DECLARE passwdtemp VARCHAR(64);
  START TRANSACTION;
    SELECT id, passwd INTO uid1, passwdtemp FROM users WHERE name = name1;
    SELECT fn_varbintohexsubstring(1,passwdtemp,1,0) INTO passwd1;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for addForbid
-- ----------------------------
DROP PROCEDURE IF EXISTS `addForbid`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `addForbid`(in userid1 INTEGER, in type1 INTEGER, in forbid_time1 INTEGER, in reason1 BINARY(255), in gmroleid1 INTEGER)
BEGIN
 DECLARE rowcount INTEGER;
  START TRANSACTION;
    UPDATE forbid SET ctime = now(), forbid_time = forbid_time1, reason = reason1, gmroleid = gmroleid1 WHERE userid = userid1 AND type = type1;
    SET rowcount = ROW_COUNT();
    IF rowcount = 0 THEN
      INSERT INTO forbid VALUES(userid1, type1, now(), forbid_time1, reason1, gmroleid);
    END IF;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for addGM
-- ----------------------------
DROP PROCEDURE IF EXISTS `addGM`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `addGM`(in userid INTEGER, in zoneid INTEGER)
BEGIN
  DECLARE x INTEGER;
  START TRANSACTION;
    SET x = 0;
    WHILE x < 12 DO
      INSERT INTO auth VALUES (userid, zoneid, x);
      SET x = x + 1;
    END WHILE;
    SET x = 100;
    WHILE x < 106 DO
      INSERT INTO auth VALUES (userid, zoneid, x);
      SET x = x + 1;
    END WHILE;
    SET x = 200;
    WHILE x < 215 DO
      INSERT INTO auth VALUES (userid, zoneid, x);
      SET x = x + 1;
    END WHILE;
    SET x = 500;
    WHILE x < 519 DO
      INSERT INTO auth VALUES (userid, zoneid, x);
      SET x = x + 1;
    END WHILE;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for adduser
-- ----------------------------
DROP PROCEDURE IF EXISTS `adduser`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `adduser`(in name1 VARCHAR(64),
  in passwd1 VARCHAR(64),
  in prompt1 VARCHAR(32),
  in answer1 VARCHAR(32),
  in truename1 VARCHAR(32),
  in idnumber1 VARCHAR(32),
  in email1 VARCHAR(32),
  in mobilenumber1 VARCHAR(32),
  in province1 VARCHAR(32),
  in city1 VARCHAR(32),
  in phonenumber1 VARCHAR(32),
  in address1 VARCHAR(64),
  in postalcode1 VARCHAR(8),
  in gender1 INTEGER,
  in birthday1 VARCHAR(32),
  in qq1 VARCHAR(32),
  in passwd21 VARCHAR(64))
BEGIN
  DECLARE idtemp INTEGER;
    SELECT IFNULL(MAX(id), 16) + 16 INTO idtemp FROM users;
    INSERT INTO users (id,name,passwd,prompt,answer,truename,idnumber,email,mobilenumber,province,city,phonenumber,address,postalcode,gender,birthday,creatime,qq,passwd2) VALUES( idtemp, name1, passwd1, prompt1, answer1, truename1, idnumber1, email1, mobilenumber1, province1, city1, phonenumber1, address1, postalcode1, gender1, birthday1, now(), qq1, passwd21 );
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for adduserpoint
-- ----------------------------
DROP PROCEDURE IF EXISTS `adduserpoint`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `adduserpoint`(in uid1 INTEGER, in aid1 INTEGER, in time1 INTEGER)
BEGIN
 DECLARE rowcount INTEGER;
 START TRANSACTION;
    UPDATE point SET time = IFNULL(time,0) + time1 WHERE uid1 = uid AND aid1 = aid;
    SET rowcount = ROW_COUNT();
    IF rowcount = 0 THEN
      INSERT INTO point (uid,aid,time) VALUES (uid1,aid1,time1);
    END IF;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for addUserPriv
-- ----------------------------
DROP PROCEDURE IF EXISTS `addUserPriv`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `addUserPriv`(in userid INTEGER, in zoneid INTEGER, in rid INTEGER)
BEGIN
  START TRANSACTION;
    INSERT INTO auth VALUES(userid, zoneid, rid);
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for changePasswd
-- ----------------------------
DROP PROCEDURE IF EXISTS `changePasswd`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `changePasswd`(in name1 VARCHAR(64), in passwd1 VARCHAR(64))
BEGIN
  START TRANSACTION;
    UPDATE users SET passwd = passwd1 WHERE name = name1;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for changePasswd2
-- ----------------------------
DROP PROCEDURE IF EXISTS `changePasswd2`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `changePasswd2`(in name1 VARCHAR(64), in passwd21 VARCHAR(64))
BEGIN
  START TRANSACTION;
    UPDATE users SET passwd2 = passwd21 WHERE name = name1;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for clearonlinerecords
-- ----------------------------
DROP PROCEDURE IF EXISTS `clearonlinerecords`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `clearonlinerecords`(in zoneid1 INTEGER, in aid1 INTEGER)
BEGIN
  START TRANSACTION;
    UPDATE point SET zoneid = NULL, zonelocalid = NULL WHERE aid = aid1 AND zoneid = zoneid1;
    DELETE FROM online;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for deleteTimeoutForbid
-- ----------------------------
DROP PROCEDURE IF EXISTS `deleteTimeoutForbid`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `deleteTimeoutForbid`(in userid1 INTEGER)
BEGIN
  START TRANSACTION;
    DELETE FROM forbid WHERE userid = userid1 AND timestampdiff(second, ctime, now()) > forbid_time;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for delUserPriv
-- ----------------------------
DROP PROCEDURE IF EXISTS `delUserPriv`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `delUserPriv`(in userid1 INTEGER, in zoneid1 INTEGER, in rid1 INTEGER, in deltype1 INTEGER)
BEGIN
START TRANSACTION;
  IF deltype1 = 0 THEN
    DELETE FROM auth WHERE userid = userid1 AND zoneid = zoneid1 AND rid = rid1;
  ELSE
    IF deltype1 = 1 THEN
      DELETE FROM auth WHERE userid = userid1 AND zoneid = zoneid1;
    ELSE
      IF deltype1 = 2 THEN
        DELETE FROM auth WHERE userid = userid1;
      END IF;
    END IF;
  END IF;
COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for enableiplimit
-- ----------------------------
DROP PROCEDURE IF EXISTS `enableiplimit`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `enableiplimit`(in uid1 INTEGER, in enable1 CHAR(1))
BEGIN
  DECLARE rowcount INTEGER;
  START TRANSACTION;
  UPDATE iplimit SET enable=enable1 WHERE uid=uid1;
  SET rowcount = ROW_COUNT();
  IF rowcount = 0 THEN
    INSERT INTO iplimit (uid,enable) VALUES (uid1,enable1);
  END IF;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Function structure for fn_varbintohexsubstring
-- ----------------------------
DROP FUNCTION IF EXISTS `fn_varbintohexsubstring`;
delimiter ;;
CREATE DEFINER=`root`@`%` FUNCTION `fn_varbintohexsubstring`(fsetprefix bit,pbinin varbinary(8000),startoffset int,cbytesin int) RETURNS varchar(4000) CHARSET latin1
    READS SQL DATA
BEGIN
  DECLARE pstrout VARCHAR(4000);
  DECLARE i int;
  DECLARE firstnibble int;
  DECLARE secondnibble int;
  DECLARE tempint int;
  DECLARE hexstring char( 16);
  BEGIN
    IF( pbinin IS NOT NULL) THEN
      SET i= 0, cbytesin= CASE WHEN( cbytesin> 0) THEN cbytesin ELSE LENGTH( pbinin) END,
         pstrout= CASE WHEN( fsetprefix= 1) THEN '0x'  ELSE ''  END,
         hexstring= '0123456789abcdef';
      IF((( cbytesin * 2) + 2> 4000) or( startoffset< 1)) THEN
        RETURN NULL;
      END IF;
      WHILE( i< cbytesin) DO
        SET tempint= ASCII( substring( pbinin, i + startoffset, 1));
        SET firstnibble= TRUNCATE((tempint / 16),0);
        SET secondnibble= tempint % 16;
        SET pstrout= CONCAT(pstrout ,cast( substring( hexstring,( firstnibble+1), 1) AS CHAR), cast( substring( hexstring,( secondnibble+1), 1) AS CHAR));
        SET i= i + 1;
      END WHILE;
      RETURN pstrout;
    END IF;
    RETURN NULL;
  END;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for lockuser
-- ----------------------------
DROP PROCEDURE IF EXISTS `lockuser`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `lockuser`(in uid1 INTEGER, in lockstatus1 CHAR(1))
BEGIN
  DECLARE rowcount INTEGER;
  START TRANSACTION;
  UPDATE iplimit SET lockstatus=lockstatus1 WHERE uid=uid1;
  SET rowcount = ROW_COUNT();
  IF rowcount = 0 THEN
    INSERT INTO iplimit (uid,lockstatus,enable) VALUES (uid1,lockstatus1,'t');
  END IF;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for recordoffline
-- ----------------------------
DROP PROCEDURE IF EXISTS `recordoffline`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `recordoffline`(in uid1 INTEGER, in aid1 INTEGER, inout zoneid1 INTEGER, inout zonelocalid1 INTEGER, inout overwrite1 INTEGER)
BEGIN
  DECLARE rowcount INTEGER;
  START TRANSACTION;
    UPDATE point SET zoneid = NULL, zonelocalid = NULL WHERE uid = uid1 AND aid = aid1 AND zoneid = zoneid1;
    DELETE FROM online WHERE ID = uid1;
    SET rowcount = ROW_COUNT();
    IF overwrite1 = rowcount THEN
      SELECT zoneid, zonelocalid INTO zoneid1, zonelocalid1 FROM point WHERE uid = uid1 AND aid = aid1;
    END IF;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for recordonline
-- ----------------------------
DROP PROCEDURE IF EXISTS `recordonline`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `recordonline`(in uid1 INTEGER, in aid1 INTEGER, inout zoneid1 INTEGER, inout zonelocalid1 INTEGER, inout overwrite INTEGER)
BEGIN
  DECLARE tmp_zoneid INTEGER;
  DECLARE tmp_zonelocalid INTEGER;
  DECLARE rowcount INTEGER;
  START TRANSACTION;
    SELECT SQL_CALC_FOUND_ROWS zoneid, zonelocalid INTO tmp_zoneid, tmp_zonelocalid FROM point WHERE uid = uid1 and aid = aid1;
    INSERT INTO online (ID) VALUES (uid1);
    SET rowcount = FOUND_ROWS();
    IF rowcount = 0 THEN
      INSERT INTO point (uid, aid, time, zoneid, zonelocalid, lastlogin) VALUES (uid1, aid1, 0, zoneid1, zonelocalid1, now());
    ELSE IF tmp_zoneid IS NULL OR overwrite = 1 THEN
      UPDATE point SET zoneid = zoneid1, zonelocalid = zonelocalid1, lastlogin = now() WHERE uid = uid1 AND aid = aid1;
    END IF;
    END IF;
    IF tmp_zoneid IS NULL THEN
      SET overwrite = 1;
    ELSE
      SET zoneid1 = tmp_zoneid;
      SET zonelocalid1 = tmp_zonelocalid;
    END IF;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for remaintime
-- ----------------------------
DROP PROCEDURE IF EXISTS `remaintime`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `remaintime`(in uid1 INTEGER, in aid1 INTEGER, out remain INTEGER, out freetimeleft INTEGER)
BEGIN
  DECLARE enddate1 DATETIME;
  DECLARE now1 DATETIME;
  DECLARE rowcount INTEGER;
  START TRANSACTION;
  SET now1 = now();
  IF aid1 = 0 THEN
    SET remain = 86313600;
    SET enddate1 = date_add(now1, INTERVAL '30' DAY);
  ELSE
    SELECT time, IFNULL(enddate, now1) INTO remain, enddate1 FROM point WHERE uid = uid1 AND aid = aid1;
    SET rowcount = ROW_COUNT();
    IF rowcount = 0 THEN
      SET remain = 0;
      INSERT INTO point (uid,aid,time) VALUES (uid1, aid1, remain);
    END IF;
  END IF;
  SET freetimeleft = 0;
  IF enddate1 > now1 THEN
    SET freetimeleft = timestampdiff(second, now1, enddate1);
  END IF;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for setiplimit
-- ----------------------------
DROP PROCEDURE IF EXISTS `setiplimit`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `setiplimit`(in uid1 INTEGER, in ipaddr11 INTEGER, in ipmask11 VARCHAR(2), in ipaddr21 INTEGER, in ipmask21 VARCHAR(2), in ipaddr31 INTEGER, in ipmask31 VARCHAR(2), in enable1 CHAR(1))
BEGIN
  DECLARE rowcount INTEGER;
  START TRANSACTION;
    UPDATE iplimit SET ipaddr1 = ipaddr11, ipmask1 = ipmask11, ipaddr2 = ipaddr21, ipmask2 = ipmask21, ipaddr3 = ipaddr31, ipmask3 = ipmask31 WHERE uid = uid1;
    SET rowcount = ROW_COUNT();
    IF rowcount = 0 THEN
      INSERT INTO iplimit (uid, ipaddr1, ipmask1, ipaddr2, ipmask2, ipaddr3, ipmask3, enable1) VALUES (uid1, ipaddr11, ipmask11, ipaddr21, ipmask21, ipaddr31, ipmask31,'t');
    END IF;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for updateUserInfo
-- ----------------------------
DROP PROCEDURE IF EXISTS `updateUserInfo`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `updateUserInfo`(in name1 VARCHAR(32),
  in prompt1 VARCHAR(32),
  in answer1 VARCHAR(32),
  in truename1 VARCHAR(32),
  in idnumber1 VARCHAR(32),
  in email1 VARCHAR(32),
  in mobilenumber1 VARCHAR(32),
  in province1 VARCHAR(32),
  in city1 VARCHAR(32),
  in phonenumber1 VARCHAR(32),
  in address1 VARCHAR(32),
  in postalcode1 VARCHAR(32),
  in gender1 INTEGER,
  in birthday1 VARCHAR(32),
  in qq1 VARCHAR(32))
BEGIN
  START TRANSACTION;
    UPDATE users SET prompt = prompt1, answer = answer1, truename = truename1, idnumber = idnumber1, email = email1, mobilenumber = mobilenumber1, province = province1, city = city1, phonenumber = phonenumber1, address = address1, postalcode = postalcode1, gender = gender1, birthday = birthda1, qq = qq1 WHERE name = name1;
  COMMIT;
END
;;
delimiter ;

-- ----------------------------
-- Procedure structure for usecash
-- ----------------------------
DROP PROCEDURE IF EXISTS `usecash`;
delimiter ;;
CREATE DEFINER=`root`@`%` PROCEDURE `usecash`(in userid1 INTEGER,
  in zoneid1 INTEGER,
  in sn1 INTEGER,
  in aid1 INTEGER,
  in point1 INTEGER,
  in cash1 INTEGER,
  in status1 INTEGER,
  out error INTEGER)
BEGIN
DECLARE sn_old INTEGER;
DECLARE aid_old INTEGER;
DECLARE point_old INTEGER;
DECLARE cash_old INTEGER;
DECLARE status_old INTEGER;
DECLARE createtime_old DATETIME;
DECLARE time_old INTEGER;
DECLARE need_restore INTEGER;
DECLARE exists1 INTEGER;
DECLARE rowcount INTEGER;
START TRANSACTION;
  SET error = 0;
  SET need_restore = 0;
  SELECT SQL_CALC_FOUND_ROWS sn, aid, point, cash, status, creatime INTO sn_old, aid_old, point_old, cash_old, status_old, createtime_old FROM usecashnow WHERE userid = userid1 AND zoneid = zoneid1 AND sn >= 0;
  SET rowcount = FOUND_ROWS();
  IF rowcount = 1 THEN
    SET exists1 = 1;
  ELSE
    SET exists1 = 0;
  END IF;
  IF status1 = 0 THEN
    IF exists1 = 0 THEN
      SELECT aid, point INTO aid1, point1 FROM usecashnow WHERE userid = userid1 AND zoneid = zoneid1 AND sn = sn1;
      SET point1 = IFNULL(point1,0);
      UPDATE point SET time = time-point1 WHERE uid = userid1 AND aid = aid1 AND time >= point1;
      SET rowcount = ROW_COUNT();
      IF rowcount = 1 THEN
        UPDATE usecashnow SET sn = 0, status = 1 WHERE userid = userid1 AND zoneid = zoneid1 AND sn = sn1;
      ELSE
        SET error = -8;
      END IF;
    END IF;
  ELSE
    IF status1 = 1 THEN
      IF exists1 = 0 THEN
        UPDATE point SET time = time-point1 WHERE uid = userid1 AND aid = aid1 AND time >= point1;
        SET rowcount = ROW_COUNT();
        IF rowcount = 1 THEN
          INSERT INTO usecashnow (userid, zoneid, sn, aid, point, cash, status, creatime) VALUES (userid1, zoneid1, sn1, aid1, point1, cash1, status1, now());
        ELSE
          INSERT INTO usecashnow SELECT userid1, zoneid1, IFNULL(min(sn),0)-1, aid1, point1, cash1, 0, now() FROM usecashnow WHERE userid = userid1 AND zoneid = zoneid1 AND 0 >= sn;
          SET error = -8;
        END IF;
      ELSE
        INSERT INTO usecashnow SELECT userid1, zoneid1, IFNULL(min(sn),0)-1, aid1, point1, cash1, 0, now() FROM usecashnow WHERE userid = userid1 AND zoneid = zoneid1 AND 0 >= sn;
        SET error = -7;
      END IF;
    ELSE
      IF status1 = 2 THEN
        IF exists1 = 1 AND status_old = 1 AND sn_old = 0 THEN
          UPDATE usecashnow SET sn = sn1, status = status1 WHERE userid = userid1 AND zoneid = zoneid1 AND sn = sn_old;
        ELSE
          SET error = -9;
        END IF;
      ELSE
        IF status1 = 3 THEN
           IF exists1 = 1 AND status_old = 2 THEN
            UPDATE usecashnow SET status = status1 WHERE userid = userid1 AND zoneid = zoneid1 AND sn = sn_old;
           ELSE
            SET error = -10;
            END IF;
        ELSE
         IF status1 = 4 THEN
          IF exists1 = 1 THEN
            DELETE FROM usecashnow WHERE userid = userid1 AND zoneid = zoneid1 AND sn = sn_old;
            INSERT INTO usecashlog (userid, zoneid, sn, aid, point, cash, status, creatime, fintime) VALUES (userid1, zoneid1, sn_old, aid_old, point_old, cash_old, status1, createtime_old, now());
          END IF;
          IF NOT (exists1 = 1 AND status_old = 3) THEN
            SET error = -11;
          END IF;
        ELSE
          SET error = -12;
        END IF;
      END IF;
    END IF;
  END IF;
  END IF;
  IF need_restore = 1 THEN
    UPDATE point SET time = time+point_old WHERE uid = userid1 AND aid = aid_old;
    DELETE FROM usecashnow WHERE userid = userid1 AND zoneid = zoneid1 AND sn = sn_old;
    INSERT INTO usecashlog (userid, zoneid, sn, aid, point, cash, status, creatime, fintime) VALUES (userid1, zoneid1, sn_old, aid_old, point_old, cash_old, status1, createtime_old, now());
  END IF;
COMMIT;
END
;;
delimiter ;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
--  踏雪笑傲 · 江湖活動（盛典）系統 資料表
--  獨立資料庫：swo_events
--  對應頁面：
--    公開前台  swo/events.php
--    管理後台  admin/events_admin.php
--  執行方式：
--    mysql -u root -p < events_schema.sql
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `swo_events`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `swo_events`;

-- ----------------------------
-- 活動分類（頁籤狀態）
-- ----------------------------
DROP TABLE IF EXISTS `ev_categories`;
CREATE TABLE `ev_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL DEFAULT '' COMMENT '分類代碼',
  `name` varchar(60) NOT NULL DEFAULT '' COMMENT '分類名稱',
  `name_en` varchar(60) NOT NULL DEFAULT '' COMMENT '英文名稱',
  `icon` varchar(40) NOT NULL DEFAULT 'celebration' COMMENT 'Material Symbols 圖示',
  `accent` varchar(20) NOT NULL DEFAULT 'rose' COMMENT '主色系',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ev_category_code` (`code`),
  KEY `idx_ev_category_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活動分類';

-- ----------------------------
-- 活動主表
-- ----------------------------
DROP TABLE IF EXISTS `ev_events`;
CREATE TABLE `ev_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL DEFAULT 0 COMMENT 'ev_categories.id',
  `category_code` varchar(32) NOT NULL DEFAULT '' COMMENT '分類代碼（冗餘）',
  `title` varchar(200) NOT NULL DEFAULT '' COMMENT '活動標題',
  `badge_text` varchar(60) NOT NULL DEFAULT '' COMMENT '左上角標籤文字',
  `badge_class` varchar(120) NOT NULL DEFAULT '' COMMENT '左上角標籤樣式',
  `corner_text` varchar(80) NOT NULL DEFAULT '' COMMENT '右下角提示文字',
  `threshold` varchar(160) NOT NULL DEFAULT '' COMMENT '參與門檻',
  `side_tag` varchar(60) NOT NULL DEFAULT '' COMMENT '右側側標',
  `side_class` varchar(60) NOT NULL DEFAULT '' COMMENT '右側側標顏色',
  `summary` varchar(500) NOT NULL DEFAULT '' COMMENT '活動摘要',
  `description` mediumtext COMMENT '活動內文（HTML）',
  `reward_summary` varchar(300) NOT NULL DEFAULT '' COMMENT '重點獎勵一覽（卡片）',
  `image` varchar(500) NOT NULL DEFAULT '' COMMENT '主視覺圖',
  `accent` varchar(20) NOT NULL DEFAULT 'rose' COMMENT '主色系',
  `period_text` varchar(160) NOT NULL DEFAULT '' COMMENT '活動期間文字',
  `countdown_at` datetime DEFAULT NULL COMMENT '倒數截止時間',
  `is_featured` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否為精選主打',
  `featured_kicker` varchar(80) NOT NULL DEFAULT '' COMMENT '精選活動上方標籤',
  `view_count` int NOT NULL DEFAULT 0 COMMENT '閱覽次數',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ev_event_category` (`category_id`),
  KEY `idx_ev_event_code` (`category_code`),
  KEY `idx_ev_event_featured` (`is_featured`),
  KEY `idx_ev_event_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活動主表';

-- ----------------------------
-- 活動獎勵清單
-- ----------------------------
DROP TABLE IF EXISTS `ev_rewards`;
CREATE TABLE `ev_rewards` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_id` int NOT NULL DEFAULT 0 COMMENT 'ev_events.id',
  `stage_label` varchar(60) NOT NULL DEFAULT '' COMMENT '階段標籤（如 首日登臨）',
  `item` varchar(160) NOT NULL DEFAULT '' COMMENT '獎勵內容',
  `note` varchar(160) NOT NULL DEFAULT '' COMMENT '獎勵說明',
  `highlight` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否為壓軸獎勵',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ev_reward_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活動獎勵清單';

-- ----------------------------
-- 活動規則 / 注意事項說明
-- ----------------------------
DROP TABLE IF EXISTS `ev_notes`;
CREATE TABLE `ev_notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `no_label` varchar(12) NOT NULL DEFAULT '' COMMENT '編號（如 01）',
  `question` varchar(255) NOT NULL DEFAULT '' COMMENT '條目標題',
  `answer` text COMMENT '說明內容（支援 HTML）',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ev_note_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活動規則與注意事項';

-- ----------------------------
-- 活動系統設定
-- ----------------------------
DROP TABLE IF EXISTS `ev_settings`;
CREATE TABLE `ev_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(64) NOT NULL DEFAULT '' COMMENT '設定鍵',
  `setting_value` text COMMENT '設定值',
  `display_name` varchar(80) NOT NULL DEFAULT '' COMMENT '顯示名稱',
  `description` varchar(200) NOT NULL DEFAULT '' COMMENT '說明',
  `setting_type` varchar(20) NOT NULL DEFAULT 'text' COMMENT 'text/toggle/number',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ev_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活動系統設定';

-- =============================================================
-- 初始資料
-- =============================================================

INSERT INTO `ev_categories` (`code`, `name`, `name_en`, `icon`, `accent`, `enabled`, `sort_order`) VALUES
('ongoing',   '進行中活動',   'ONGOING',   'local_fire_department', 'rose',    1, 1),
('permanent', '長期常駐活動', 'PERMANENT', 'all_inclusive',         'emerald', 1, 2),
('upcoming',  '即將開啟',     'UPCOMING',  'schedule',              'sky',     1, 3),
('ended',     '已圓滿結束',   'ENDED',     'history',               'slate',   1, 4);

INSERT INTO `ev_events`
(`category_id`,`category_code`,`title`,`badge_text`,`badge_class`,`corner_text`,`threshold`,`side_tag`,`side_class`,`summary`,`description`,`reward_summary`,`image`,`accent`,`period_text`,`countdown_at`,`is_featured`,`featured_kicker`,`view_count`,`enabled`,`sort_order`) VALUES
(1,'ongoing','冰封雪嶺 · 跨服首殺爭霸賽','全服挑戰','bg-primary text-white','倒數 5 天截止','等級達到 75 級或組隊同盟','首殺爭奪','text-primary','破天玄冰狂嘯，九大伺服器同屏角逐雪域終極巨擘！達成跨服首殺之幫派，將於洛陽王城永久樹立榮耀金漆雕像。','<p>破天玄冰狂嘯，九大伺服器同屏角逐雪域終極巨擘。達成跨服首殺之幫派，將於洛陽王城永久樹立榮耀金漆雕像，並獲得限定稱號「雪嶺戰神」。</p><p>本活動於每週六 20:00 開啟，請組隊同盟後共同挑戰終極首領。</p>','十萬金元寶、全服專屬雕像','https://lh3.googleusercontent.com/aida-public/AB6AXuBfxAxnsj8nVhtWdi4RAzHpo_FoOTu4K3D1PQhkLLyMXJW_1gKA2nL64T0IhIP5GQFAV18zpl258lKxhhttjdRGa6Pb-PCJqyGci2FhhIc_0DUAOUBoe9X1w7XhfdXjXbIYolLsRVUWe6g-u5p4peFOhegYGjZulDU2ucWyGzG8Lf7P8boh5sYZedVay8sxFGSVNvQrVik4z2vLM6iuqk-8YeIhcuuCPjnenYmeVV2u','rose','2025/04/01 ~ 2025/04/15','2025-12-31 20:00:00',0,'',9820,1,1),
(1,'ongoing','千里冰封 · 結緣共闖天涯','俠侶同行','bg-rose-600 text-white','活動至 04/28 止','達成結緣或義結金蘭','情緣限定','text-rose-600','相濡以沫，江湖路遠。雙人組隊通關專屬結緣副本「情寄寒潭」，享歷練修為 50% 增幅，並解鎖雙人比翼雙飛限定輕功！','<p>相濡以沫，江湖路遠。雙人組隊通關專屬結緣副本「情寄寒潭」，享歷練修為 50% 增幅，並解鎖雙人比翼雙飛限定輕功。</p>','專屬雙人坐騎【比翼霜雪】','https://lh3.googleusercontent.com/aida-public/AB6AXuADosW9vywLkHR5XRnJt3ZCWOxSuBYFKtmxswIyGyCoKb4XmzRWLJwj4AV1S1dNF6w_IbW76ODolQCxSwn0Le9UAQzU09KbUrF3AwD3OK5FqGVJqTsJl2DnxOvDNqWEMLEXDqNReIcSSpHryso91U_IEZM-_gSvuoIDO_CK5tqvXNyfCGfglBExhrwAZEAp4rcN9aPWur4NfWUfKuwX5I8NM0SrIQoErBdEAXX1mtH9','rose','2025/04/01 ~ 2025/04/28','2025-12-28 23:59:59',0,'',7640,1,2),
(1,'ongoing','天降祥瑞 · 首儲雙倍重置','儲值反饋','bg-emerald-700 text-white','季春重置','全伺服器所有角色','回饋滿載','text-emerald-700','慶賀版本重大更新，元寶商城全檔位首儲雙倍獎勵全數重置！首次單筆儲值滿額，再贈送動態水墨足跡光環「踏墨生蓮」。','<p>慶賀版本重大更新，元寶商城全檔位首儲雙倍獎勵全數重置。首次單筆儲值滿額，再贈送動態水墨足跡光環「踏墨生蓮」。</p>','100% 元寶返還、光環【踏墨生蓮】','https://lh3.googleusercontent.com/aida-public/AB6AXuBUsd-pc44tP1rRcDN6Azv9cUp-4OadmaQJB3HoBBxHdve3XedbM5rzyNOMIQ2DojozNKthh-gVyo7Epz63sCmNvG7kWVaWxio-8ynJ0oFbdOww2MzYjDEWTS_pFxjnL85ED0sL778DCObxDN7bC_dfDMYDAeg9k87Bl3a0t7VudrZTCOFMvbHfhMKllT1Il1zI5fD3iV4ifqTKyNItoRHCsFPFoO74QaBzcPUP1syQ','emerald','2025/04/01 ~ 2025/05/31','2025-12-31 23:59:59',0,'',6120,1,3),
(1,'ongoing','笑傲江湖 · 短影音與攻略大賽','社群共創','bg-sky-700 text-white','徵集進行中','投稿至官方 Discord / 社群','創作獲賞','text-sky-700','執筆論道，剪影留香。分享你的門派連招秘笈或高光精彩混剪，獲選者即得客製化水墨實體青銅劍雕與三萬點數獎勵！','<p>執筆論道，剪影留香。分享你的門派連招秘笈或高光精彩混剪，獲選者即得客製化水墨實體青銅劍雕與三萬點數獎勵。</p>','實體典藏周邊、30,000 點數','https://lh3.googleusercontent.com/aida-public/AB6AXuDj7y8Zs91xcn5TZa54YdMs6Rh7xCs7qwezHPKT3fWBxiCXH2I5s-twmx2o2UVq_oOmoGjbOpn30jLD8YUnsSPNzfuAupxRYnLjMJfDgS4RFP9PYKngZFRW1_7wvlvHbWHdX_WGdCZbLZGkfPHz_h2GD7t0yxIHxPyerIEH8NbmtWVnFrDwtm-0z4RNv2gTcu4O2jKbki22C1QJIG_STJ3fuRSY0-lKmIoTY-C6vIWV','sky','2025/04/01 ~ 2025/05/15','2025-12-31 23:59:59',0,'',4580,1,4),
(1,'ongoing','寒淵破曉 · 賽季通行證','賽季限定','bg-indigo-600 text-white','剩餘 21 天','等級達到 30 級','獎勵加倍','text-indigo-600','新賽季通行證「寒淵破曉」正式開啟，完成週任務累積歷練值，解鎖絕版坐騎、外觀與海量元寶。','<p>新賽季通行證「寒淵破曉」正式開啟，完成週任務累積歷練值，解鎖絕版坐騎、外觀與海量元寶。</p>','賽季限定坐騎【寒淵玄鯨】','https://lh3.googleusercontent.com/aida-public/AB6AXuCCrtr1xQEzbhCUHKTMyfoBvk43oavXR4Ceqz2samtzrt1Es62FOKjuuAy3PHzQof4m5o2AEy2QYQRMJT9AqvVMivUJeyWufKvjsDkEzsUFJjTPepybeHpjvGvmlk6wWQa1NRWwbeev-qEXkVdkr-kSdikkSViD--ghMz385YQSIzlUuSI2U-0GFo4WQlKCaJOoOO-0jdA4_iZUFGMyQxSL7mN4FbU3MeHJYCf2Ypuq','indigo','2025/04/09 ~ 2025/06/30','2025-12-31 23:59:59',0,'',5310,1,5),
(1,'ongoing','幽玄冰宮 · 深淵探險隊','副本挑戰','bg-sky-600 text-white','每日 3 次','等級達到 60 級','組隊挑戰','text-sky-600','踏入幽玄冰宮深淵，四人小隊挑戰極寒首領，收集冰晶精魄兌換神品裝備與稀有圖譜。','<p>踏入幽玄冰宮深淵，四人小隊挑戰極寒首領，收集冰晶精魄兌換神品裝備與稀有圖譜。</p>','冰晶精魄、神品圖譜殘頁','https://lh3.googleusercontent.com/aida-public/AB6AXuA4bqkH08q0EWlSkCpIbdOMh0V1_EahFDJbBHgNLCNR5Xj_NvAXA3-BtJrRq9sYI-2oCLzB2bns1LcovITUKhxSQso0pdXqaEPyLdsJcLqlyLK4Cf0NpxtcWh4RT-PrM9k12_w8luI76VfMQqauJ0PXQXH65lXUDcQUUflYkqZzVJ3Z0XKBRWUHYwHmLcBzqJwXc2Da_V88iFLst4gjR1-1fCJKpH90v_Al7hO-L8r1','sky','2025/04/09 ~ 2025/05/09','2025-12-31 23:59:59',0,'',3990,1,6),
(2,'permanent','初入江湖 · 衝等豪禮狂送','新手專屬','bg-secondary text-white','常駐開放 · 達標即領','創角 30 天內俠士','全服適用','text-secondary','凡登臨江湖達到指定境界與等級（20/40/60/80級），系統即時奉上神品傳承防具套裝、雙倍歷練修為符與開荒元寶！','<p>凡登臨江湖達到指定境界與等級（20/40/60/80級），系統即時奉上神品傳承防具套裝、雙倍歷練修為符與開荒元寶。</p>','四階神品套裝、雙倍歷練符*10','https://lh3.googleusercontent.com/aida-public/AB6AXuAVmQuN97WRlxKxxJ20F15fhFR5r1YSkR0IKFESDYqL3g1yIiOAPzVvtrg96lzC5WEr2V3SMJ70FWGpPHWqmcvPWGH44mHDMKkniIwOlPQdFcbn6e6RPSixwBf_B5Cl_T-dZov7kC7LueVhEeQ52h4XwRmhDJxAY3VvlXOxW8ZO4V0YjlmOM5kRt8LGpercx7vbIUTVPX2W-dF7Ovc8WqDBccdsOWbt0WGS7O3s4qHs','slate','長期常駐',NULL,0,'',18230,1,1),
(2,'permanent','踏雪尋梅 · 每日福袋搖彩','每日簽到','bg-amber-600 text-white','每日 00:00 重置','無限制，登入遊戲即搖','每日重溫','text-secondary','每日蒞臨江湖簽到即可搖取「寒梅瑞雪福袋」，隨機掉落幸運極限強化石、大量綁定元寶與各門派高級殘卷。','<p>每日蒞臨江湖簽到即可搖取「寒梅瑞雪福袋」，隨機掉落幸運極限強化石、大量綁定元寶與各門派高級殘卷。</p>','每日保底元寶*188、幸運強化石','https://lh3.googleusercontent.com/aida-public/AB6AXuAIGIVqtI3FIczVSoVRcYIGyjRVOu6K1_zcbLR-GkYDkY5vGCgs0Zdl5hmLKOHs7hjFTWUCFeb5LX4-EzEx2etwIrrOWqyo2DVCeeyl7Iiu-mmgt3btJlk0a07_DyhdtHrHrVMCdTyNAyU0KwZtas9gHsRCFZPenEGBCDwC9Ny2PYO9xgBlchiTSyJ7dg7dmxcdvdsPyp_7kWKtvDrihDgNbBbZt_bxxHrUShZsicbe','amber','長期常駐',NULL,0,'',15640,1,2),
(2,'permanent','師徒同門 · 傳功獎勵常駐','師徒系統','bg-teal-700 text-white','常駐開放','拜師或收徒即可','傳功受業','text-teal-700','完成師徒任務即可獲得傳功值，兌換限定頭像框、稱號與大量經驗加成，教學相長、共同成長。','<p>完成師徒任務即可獲得傳功值，兌換限定頭像框、稱號與大量經驗加成。</p>','限定頭像框【桃李滿門】','https://lh3.googleusercontent.com/aida-public/AB6AXuDj7y8Zs91xcn5TZa54YdMs6Rh7xCs7qwezHPKT3fWBxiCXH2I5s-twmx2o2UVq_oOmoGjbOpn30jLD8YUnsSPNzfuAupxRYnLjMJfDgS4RFP9PYKngZFRW1_7wvlvHbWHdX_WGdCZbLZGkfPHz_h2GD7t0yxIHxPyerIEH8NbmtWVnFrDwtm-0z4RNv2gTcu4O2jKbki22C1QJIG_STJ3fuRSY0-lKmIoTY-C6vIWV','teal','長期常駐',NULL,0,'',4210,1,3),
(3,'upcoming','五一勞動節 · 全服雙倍歷練','即將開啟','bg-sky-600 text-white','05/01 盛大開啟','全伺服器所有角色','敬請期待','text-sky-600','向辛勞的俠士致敬！活動期間全服歷練與修為獲取翻倍，加碼贈送限定稱號「勞動最光榮」。','<p>向辛勞的俠士致敬！活動期間全服歷練與修為獲取翻倍，加碼贈送限定稱號「勞動最光榮」。</p>','雙倍歷練、限定稱號','https://lh3.googleusercontent.com/aida-public/AB6AXuAVmQuN97WRlxKxxJ20F15fhFR5r1YSkR0IKFESDYqL3g1yIiOAPzVvtrg96lzC5WEr2V3SMJ70FWGpPHWqmcvPWGH44mHDMKkniIwOlPQdFcbn6e6RPSixwBf_B5Cl_T-dZov7kC7LueVhEeQ52h4XwRmhDJxAY3VvlXOxW8ZO4V0YjlmOM5kRt8LGpercx7vbIUTVPX2W-dF7Ovc8WqDBccdsOWbt0WGS7O3s4qHs','sky','2025/05/01 ~ 2025/05/07',NULL,0,'',0,1,1),
(3,'upcoming','端午龍舟 · 水上競速爭霸','即將開啟','bg-emerald-600 text-white','06/01 隆重登場','等級達到 40 級','敬請期待','text-emerald-600','粽香四溢，龍舟競渡！組隊參與水上競速玩法，爭奪限定坐騎「碧波龍舟」與海量節慶好禮。','<p>粽香四溢，龍舟競渡！組隊參與水上競速玩法，爭奪限定坐騎「碧波龍舟」與海量節慶好禮。</p>','限定坐騎【碧波龍舟】','https://lh3.googleusercontent.com/aida-public/AB6AXuBfxAxnsj8nVhtWdi4RAzHpo_FoOTu4K3D1PQhkLLyMXJW_1gKA2nL64T0IhIP5GQFAV18zpl258lKxhhttjdRGa6Pb-PCJqyGci2FhhIc_0DUAOUBoe9X1w7XhfdXjXbIYolLsRVUWe6g-u5p4peFOhegYGjZulDU2ucWyGzG8Lf7P8boh5sYZedVay8sxFGSVNvQrVik4z2vLM6iuqk-8YeIhcuuCPjnenYmeVV2u','emerald','2025/06/01 ~ 2025/06/10',NULL,0,'',0,1,2),
(4,'ended','元宵燈謎 · 猜燈謎贏豪禮','已結束','bg-slate-500 text-white','活動已圓滿結束','無限制','感謝參與','text-slate-500','元宵佳節，花燈如晝。感謝各位俠士熱情參與猜燈謎活動，得獎名單已於官網公告。','<p>元宵佳節，花燈如晝。感謝各位俠士熱情參與猜燈謎活動，得獎名單已於官網公告。</p>','限定花燈背飾','https://lh3.googleusercontent.com/aida-public/AB6AXuAIGIVqtI3FIczVSoVRcYIGyjRVOu6K1_zcbLR-GkYDkY5vGCgs0Zdl5hmLKOHs7hjFTWUCFeb5LX4-EzEx2etwIrrOWqyo2DVCeeyl7Iiu-mmgt3btJlk0a07_DyhdtHrHrVMCdTyNAyU0KwZtas9gHsRCFZPenEGBCDwC9Ny2PYO9xgBlchiTSyJ7dg7dmxcdvdsPyp_7kWKtvDrihDgNbBbZt_bxxHrUShZsicbe','slate','2025/02/10 ~ 2025/02/18',NULL,0,'',32100,1,1),
(4,'ended','情人節 · 俠侶情緣限定','已結束','bg-rose-400 text-white','活動已圓滿結束','已結緣俠侶','感謝參與','text-rose-400','執子之手，與子偕老。感謝俠侶們踴躍參與情人節限定活動，願有情人終成眷屬。','<p>執子之手，與子偕老。感謝俠侶們踴躍參與情人節限定活動。</p>','雙人稱號【白首同心】','https://lh3.googleusercontent.com/aida-public/AB6AXuADosW9vywLkHR5XRnJt3ZCWOxSuBYFKtmxswIyGyCoKb4XmzRWLJwj4AV1S1dNF6w_IbW76ODolQCxSwn0Le9UAQzU09KbUrF3AwD3OK5FqGVJqTsJl2DnxOvDNqWEMLEXDqNReIcSSpHryso91U_IEZM-_gSvuoIDO_CK5tqvXNyfCGfglBExhrwAZEAp4rcN9aPWur4NfWUfKuwX5I8NM0SrIQoErBdEAXX1mtH9','rose','2025/02/14 ~ 2025/02/20',NULL,0,'',27850,1,2);

-- 精選主打活動（寒淵破曉 · 登入七日贈披風）
INSERT INTO `ev_events`
(`category_id`,`category_code`,`title`,`badge_text`,`badge_class`,`corner_text`,`threshold`,`side_tag`,`side_class`,`summary`,`description`,`reward_summary`,`image`,`accent`,`period_text`,`countdown_at`,`is_featured`,`featured_kicker`,`view_count`,`enabled`,`sort_order`) VALUES
(1,'ongoing','寒淵破曉 · 登入七日贈絕版水墨披風【雪羽驚鴻】','限時盛典 · 萬人登入','bg-primary text-white','','連續登入滿七日','','','江湖初寒，劍魄生輝。凡於活動期間連續登入神州大世界滿七日之俠士，即可免費承領天工坊絕品水墨動態披風「雪羽驚鴻」。身著此羽，踏雪無痕，周身伴隨水墨丹青與落梅飄雪粒子特效！','<p>江湖初寒，劍魄生輝。凡於活動期間連續登入神州大世界滿七日之俠士，即可免費承領天工坊絕品水墨動態披風「雪羽驚鴻」。身著此羽，踏雪無痕，周身伴隨水墨丹青與落梅飄雪粒子特效。</p><p>獎勵將於達成條件後透過遊戲內郵件自動發送，請留意郵件保管期限。</p>','絕版披風【雪羽驚鴻】+ 專屬稱號','https://lh3.googleusercontent.com/aida-public/AB6AXuCCrtr1xQEzbhCUHKTMyfoBvk43oavXR4Ceqz2samtzrt1Es62FOKjuuAy3PHzQof4m5o2AEy2QYQRMJT9AqvVMivUJeyWufKvjsDkEzsUFJjTPepybeHpjvGvmlk6wWQa1NRWwbeev-qEXkVdkr-kSdikkSViD--ghMz385YQSIzlUuSI2U-0GFo4WQlKCaJOoOO-0jdA4_iZUFGMyQxSL7mN4FbU3MeHJYCf2Ypuq','rose','2025/04/01 ~ 2025/04/30','2025-12-31 08:00:00',1,'LIMITED GRAND FESTIVAL',45210,1,0);

-- 精選活動獎勵矩陣
INSERT INTO `ev_rewards` (`event_id`, `stage_label`, `item`, `note`, `highlight`, `sort_order`)
SELECT `id`, '首日登臨', '元寶*1888', '商城自由兌換', 0, 1 FROM `ev_events` WHERE `is_featured` = 1;
INSERT INTO `ev_rewards` (`event_id`, `stage_label`, `item`, `note`, `highlight`, `sort_order`)
SELECT `id`, '三日悟道', '玄鐵精魄*50', '神兵升階必備', 0, 2 FROM `ev_events` WHERE `is_featured` = 1;
INSERT INTO `ev_rewards` (`event_id`, `stage_label`, `item`, `note`, `highlight`, `sort_order`)
SELECT `id`, '五日易筋', '特級洗髓丹*20', '洗鍊天賦根骨', 0, 3 FROM `ev_events` WHERE `is_featured` = 1;
INSERT INTO `ev_rewards` (`event_id`, `stage_label`, `item`, `note`, `highlight`, `sort_order`)
SELECT `id`, '七日圓滿', '雪羽驚鴻', '絕版披風 + 稱號', 1, 4 FROM `ev_events` WHERE `is_featured` = 1;

-- 活動規則 / 注意事項
INSERT INTO `ev_notes` (`no_label`, `question`, `answer`, `enabled`, `sort_order`) VALUES
('01', '活動獎勵的發送方式與領取期限為何？', '<p>大部分活動獎勵（如元寶、道具禮包）將於達成條件後由系統自動發送至遊戲內「郵件」中。請注意郵件有效保管期限通常為 30 天，逾期未領取之信件將自動被系統回收，怒不補發。實體周邊則需於獲獎公告後 14 個工作天內聯繫線上客服登記寄送地址。</p>', 1, 1),
('02', '若在跨服首殺或組隊活動中掉線或發生異常，如何判定資格？', '<p>跨服挑戰依伺服器日誌最終結算為準。若因個人網路不穩定導致首殺擊殺瞬間未處於有效戰鬥團隊內，系統將無法計入成就。若因伺服器主機異常導致中斷，官方營運團隊將另行重啟爭霸時間並奉送全服補償。</p>', 1, 2),
('03', '多個帳號或同一角色能否重複參與新手或首儲雙倍？', '<p>新手專屬與登入簽到獎勵以「角色 UID」為單位結算，每個角色獨立計算。首儲雙倍重置亦綁定於角色。禁止利用外掛程式、惡意多開腳本大量洗刷虛寶，一經查證屬實將永久凍結帳號與沒收非法所得。</p>', 1, 3),
('04', '社群創作徵集比賽版權歸屬與發獎流程？', '<p>參賽者保留作品之署名權，但提交作品即視為授權官方團隊於遊戲宣傳、社群轉發與專題展示無償使用。得獎名單將於每期活動結束後 7 日內於官網公告，點數將直接儲值至報名所留之會員帳戶。</p>', 1, 4);

INSERT INTO `ev_settings` (`setting_key`, `setting_value`, `display_name`, `description`, `setting_type`) VALUES
('hero_kicker',        'SNOW WANDERER GRAND CAMPAIGNS', '主視覺英文標籤', '頁首小標籤文字', 'text'),
('hero_season',        '季春 · 破曉盛會', '主視覺季節標示', '頁首標籤右側文字', 'text'),
('hero_title',         '風雲際會 · 江湖盛典', '主視覺標題', '活動頁 H1 標題', 'text'),
('hero_subtitle',      '踏雪尋芳，好禮相贈。即刻參與最新江湖盛典，擊破玄冰魔陣，爭奪跨服首殺，領取海量元寶、神品玄鐵與絕版坐騎。天下英雄出我輩，笑傲天涯正當時。', '主視覺副標', '活動頁說明文字', 'text'),
('stat1_label',        '全服已發送豪禮', '統計一標題', '頁首統計卡第一項標題', 'text'),
('stat1_value',        '128,450+', '統計一數值', '頁首統計卡第一項數值', 'text'),
('stat1_sub',          '份江湖令簽', '統計一副標', '頁首統計卡第一項副標', 'text'),
('stat2_label',        '當前運行活動', '統計二標題', '頁首統計卡第二項標題', 'text'),
('stat2_sub',          '項進行中', '統計二副標', '頁首統計卡第二項副標', 'text'),
('grid_title',         '精選江湖活動指南', '活動列表標題', '活動矩陣區塊標題', 'text'),
('rules_title',        '江湖活動領賞規則與注意事項', '規則區塊標題', '注意事項區塊標題', 'text'),
('rules_subtitle',     '為維持神州武林公平競爭與各位俠士權益，請於參與活動前詳讀以下規章。', '規則區塊副標', '注意事項區塊說明', 'text'),
('assist_title',       '仍有活動相關疑問？', '客服協助標題', '底部協助區塊標題', 'text'),
('assist_text',        '俠士可隨時聯絡客服小師妹，我們將於 24 小時內為您排憂解難。', '客服協助說明', '底部協助區塊說明', 'text'),
('assist_btn_text',    '聯繫線上客棧', '客服按鈕文字', '底部協助區塊按鈕文字', 'text'),
('assist_btn_url',     'support.php', '客服按鈕連結', '底部協助區塊按鈕連結', 'text'),
('show_stats',         '1', '顯示統計卡', '1=顯示 / 0=隱藏 頁首統計卡', 'toggle'),
('show_rules',         '1', '顯示注意事項', '1=顯示 / 0=隱藏 規則說明區塊', 'toggle'),
('show_assist',        '1', '顯示客服協助', '1=顯示 / 0=隱藏 底部客服協助', 'toggle');

-- 讓倒數時間相對於匯入當下（示範資料保持在未來）
UPDATE `ev_events` SET `countdown_at` = DATE_ADD(NOW(), INTERVAL 14 DAY) WHERE `is_featured` = 1;
UPDATE `ev_events` SET `countdown_at` = DATE_ADD(NOW(), INTERVAL 21 DAY) WHERE `category_code` = 'ongoing' AND `is_featured` = 0;
UPDATE `ev_events` SET `countdown_at` = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE `category_code` = 'upcoming';

SET FOREIGN_KEY_CHECKS = 1;

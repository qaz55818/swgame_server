-- =============================================================
--  踏雪笑傲 · 江湖邸報（官方公告）系統資料表
--  獨立資料庫：swo_news
--  對應頁面：
--    公開前台  swo/news.php
--    管理後台  admin/news_admin.php
--  執行方式：
--    mysql -u root -p < news_schema.sql
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `swo_news`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `swo_news`;

-- ----------------------------
-- 公告分類
-- ----------------------------
DROP TABLE IF EXISTS `news_categories`;
CREATE TABLE `news_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL DEFAULT '' COMMENT '分類代碼',
  `name` varchar(60) NOT NULL DEFAULT '' COMMENT '分類名稱',
  `name_en` varchar(80) NOT NULL DEFAULT '' COMMENT '英文名稱',
  `icon` varchar(40) NOT NULL DEFAULT '' COMMENT 'Material Symbols 圖示',
  `badge_class` varchar(180) NOT NULL DEFAULT '' COMMENT '列表徽章樣式(className)',
  `accent` varchar(20) NOT NULL DEFAULT 'rose' COMMENT '主色系 rose/sky/amber/slate/teal',
  `description` varchar(200) NOT NULL DEFAULT '' COMMENT '分類說明',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_news_category_code` (`code`),
  KEY `idx_news_category_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告分類';

-- ----------------------------
-- 公告主表
-- ----------------------------
DROP TABLE IF EXISTS `news_articles`;
CREATE TABLE `news_articles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL DEFAULT 0 COMMENT 'news_categories.id',
  `category_code` varchar(32) NOT NULL DEFAULT '' COMMENT '分類代碼（冗餘）',
  `title` varchar(200) NOT NULL DEFAULT '' COMMENT '公告標題',
  `slug` varchar(220) NOT NULL DEFAULT '' COMMENT '網址代稱',
  `summary` varchar(500) NOT NULL DEFAULT '' COMMENT '摘要',
  `content` mediumtext COMMENT '公告內文（HTML）',
  `cover_image` varchar(500) NOT NULL DEFAULT '' COMMENT '封面圖網址',
  `cover_caption` varchar(200) NOT NULL DEFAULT '' COMMENT '封面標題',
  `author` varchar(50) NOT NULL DEFAULT '官方團隊' COMMENT '發布者',
  `priority` varchar(16) NOT NULL DEFAULT 'normal' COMMENT 'low/normal/high/urgent',
  `is_sticky` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否置頂',
  `is_featured` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否精選（首頁大卡）',
  `status` varchar(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/published/scheduled/archived',
  `published_at` datetime DEFAULT NULL COMMENT '發布時間',
  `view_count` int NOT NULL DEFAULT 0 COMMENT '閱覽次數',
  `comment_count` int NOT NULL DEFAULT 0 COMMENT '回應數',
  `allow_comments` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否開放回應',
  `seo_title` varchar(200) NOT NULL DEFAULT '' COMMENT 'SEO 標題',
  `seo_keywords` varchar(300) NOT NULL DEFAULT '' COMMENT 'SEO 關鍵字',
  `seo_description` varchar(500) NOT NULL DEFAULT '' COMMENT 'SEO 描述',
  `created_by` varchar(50) NOT NULL DEFAULT '' COMMENT '建立 GM',
  `updated_by` varchar(50) NOT NULL DEFAULT '' COMMENT '最後更新 GM',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_news_article_slug` (`slug`),
  KEY `idx_news_article_category` (`category_id`),
  KEY `idx_news_article_code` (`category_code`),
  KEY `idx_news_article_status` (`status`),
  KEY `idx_news_article_sticky` (`is_sticky`),
  KEY `idx_news_article_published` (`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告主表';

-- ----------------------------
-- 公告附件
-- ----------------------------
DROP TABLE IF EXISTS `news_attachments`;
CREATE TABLE `news_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `article_id` int NOT NULL DEFAULT 0 COMMENT 'news_articles.id',
  `file_name` varchar(180) NOT NULL DEFAULT '' COMMENT '原始檔名',
  `file_path` varchar(255) NOT NULL DEFAULT '' COMMENT '相對儲存路徑或外部網址',
  `file_size` int NOT NULL DEFAULT 0 COMMENT '檔案大小(bytes)',
  `mime_type` varchar(100) NOT NULL DEFAULT '' COMMENT 'MIME 類型',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_news_attachment_article` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告附件';

-- ----------------------------
-- 公告管理操作歷程
-- ----------------------------
DROP TABLE IF EXISTS `news_status_log`;
CREATE TABLE `news_status_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `article_id` int NOT NULL DEFAULT 0 COMMENT 'news_articles.id',
  `action` varchar(40) NOT NULL DEFAULT '' COMMENT 'create/update/publish/unpublish/sticky/delete/restore',
  `operator_type` varchar(10) NOT NULL DEFAULT 'gm' COMMENT 'gm/system',
  `operator_name` varchar(50) NOT NULL DEFAULT '' COMMENT '操作者名稱',
  `old_status` varchar(20) NOT NULL DEFAULT '',
  `new_status` varchar(20) NOT NULL DEFAULT '',
  `note` varchar(255) NOT NULL DEFAULT '' COMMENT '備註',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_news_log_article` (`article_id`),
  KEY `idx_news_log_action` (`action`),
  KEY `idx_news_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告管理操作歷程';

-- ----------------------------
-- 公告系統設定（首頁資訊列）
-- ----------------------------
DROP TABLE IF EXISTS `news_settings`;
CREATE TABLE `news_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(64) NOT NULL DEFAULT '' COMMENT '設定鍵',
  `setting_value` text COMMENT '設定值',
  `display_name` varchar(80) NOT NULL DEFAULT '' COMMENT '顯示名稱',
  `description` varchar(200) NOT NULL DEFAULT '' COMMENT '說明',
  `setting_type` varchar(20) NOT NULL DEFAULT 'text' COMMENT 'text/datetime/number',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_news_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告系統設定';

-- ----------------------------
-- 飛鴿傳書訂閱名單
-- ----------------------------
DROP TABLE IF EXISTS `news_subscribers`;
CREATE TABLE `news_subscribers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(120) NOT NULL DEFAULT '' COMMENT '訂閱信箱',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否有效',
  `source` varchar(40) NOT NULL DEFAULT 'news_page' COMMENT '來源',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unsubscribed_at` datetime DEFAULT NULL COMMENT '退訂時間',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_news_subscriber_email` (`email`),
  KEY `idx_news_subscriber_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告訂閱名單';

-- =============================================================
-- 初始資料
-- =============================================================

INSERT INTO `news_categories` (`code`, `name`, `name_en`, `icon`, `badge_class`, `accent`, `description`, `enabled`, `sort_order`) VALUES
('maintenance', '系統維護', 'Maintenance', 'build',         'bg-rose-100 text-rose-800 border-rose-200',    'rose',  '停機、維護、線路調整與例行作業', 1, 1),
('update',      '重大更新', 'Update',      'auto_awesome',  'bg-sky-100 text-sky-800 border-sky-200',       'sky',   '版本改版、新系統與內容更新',     1, 2),
('event',       '官方活動', 'Event',       'celebration',   'bg-amber-100 text-amber-900 border-amber-200', 'amber', '節慶、限時與商城活動',           1, 3),
('security',    '防詐安全', 'Security',    'shield',        'bg-slate-100 text-slate-800 border-slate-300', 'slate', '防詐、帳號安全與公平競技',       1, 4),
('guide',       '新手指南', 'Guide',       'menu_book',     'bg-teal-100 text-teal-800 border-teal-200',    'teal',  '新手教學與遊戲攻略指引',         1, 5);

INSERT INTO `news_settings` (`setting_key`, `setting_value`, `display_name`, `description`, `setting_type`) VALUES
('site_version',      'v1.8.5',                '目前江湖版本',     '前台資訊列顯示的版本號',           'text'),
('site_codename',     '「寒淵破曉」',          '版本代號',         '版本號旁的副標題',                 'text'),
('maintenance_next',  '4月9日 (三) 08:00',     '下次例行維護',     '前台資訊列顯示的維護時間',         'text'),
('server_status',     'online',                 '全服線路狀態',     'online=暢通 / busy=繁忙 / offline=維護', 'text'),
('hero_title',        '江湖邸報 · 官方公告',   '主視覺標題',       '公告首頁 H1 標題',                 'text'),
('hero_subtitle',     '第一手掌握伺服器動態、版本更新、維護時程與江湖重要通知。劍氣凝霜，事關俠客安危，萬請悉知。', '主視覺副標', '公告首頁說明文字', 'text'),
('subscribe_blurb',   '重大改版與停機維護前 30 分鐘主動推送至您的電子信箱，不錯過任一次江湖大事件。', '訂閱說明', '飛鴿傳書訂閱區塊說明', 'text'),
('list_page_size',    '8',                      '列表每頁筆數',     '前台公告列表每頁顯示筆數',         'number'),
('show_version',      '1',                      '顯示當前版本',     '1=顯示 / 0=隱藏 前台資訊列「當前江湖版本」', 'toggle'),
('show_maintenance',  '1',                      '顯示例行維護',     '1=顯示 / 0=隱藏 前台資訊列「下次例行維護」', 'toggle'),
('show_server_status','1',                      '顯示線路狀態',     '1=顯示 / 0=隱藏 前台資訊列「全服線路」',     'toggle');

SET @cat_maintenance = (SELECT id FROM `news_categories` WHERE `code`='maintenance');
SET @cat_update      = (SELECT id FROM `news_categories` WHERE `code`='update');
SET @cat_event       = (SELECT id FROM `news_categories` WHERE `code`='event');
SET @cat_security    = (SELECT id FROM `news_categories` WHERE `code`='security');
SET @cat_guide       = (SELECT id FROM `news_categories` WHERE `code`='guide');

INSERT INTO `news_articles`
(`category_id`,`category_code`,`title`,`slug`,`summary`,`content`,`cover_image`,`cover_caption`,`author`,`priority`,`is_sticky`,`is_featured`,`status`,`published_at`,`view_count`,`comment_count`,`created_by`,`updated_by`) VALUES
(@cat_update, 'update', 'v1.8.5「寒淵破曉」大型版本更新總覽及維護時程通知', 'v185-hanyuan-poxiao',
 '北境霜崖全新九大主線副本破界開啟，絕世神兵鍛造系統問世，天梯跨服論劍第四賽季正式打響！全服預計於4月9日08:00進行四小時停機維護。',
 '<p>諸位俠士久候了，本次 v1.8.5「寒淵破曉」為本年度規模最大之改版，謹將重點條列如下：</p><h3>一、北境霜崖 · 幽玄冰宮</h3><p>全新九大主線副本破界開啟，「幽玄冰宮·深淵」精英首領將掉落絕世神兵鍛造圖譜殘頁。</p><h3>二、天工開物 · 神兵鍛造</h3><p>全新鍛造系統問世，玩家可淬鍊專屬神兵並銘刻專屬屬性。俠客等級上限提升至 110 級。</p><h3>三、天梯跨服論劍 第四賽季</h3><p>跨服論劍賽季正式打響，賽季獎勵包含絕版外觀與稱號。</p><h3>四、維護時程與補償</h3><p>全服預計於 <strong>4月9日 08:00 - 12:00</strong> 進行四小時停機維護。維護完成後將配發「天工開物匣」及「乾坤補天丹」補償獎勵。</p>',
 'https://lh3.googleusercontent.com/aida-public/AB6AXuA7oohGQCXSwU1TYgL16iWysmFW-CNVVzTUQGDhiQAjWwvz_-vtjuNnZOIOhcDUG0uy7J7U0aE3k6RydMvS7IheFWr4tEdaj12sNIOvv7ia0kZQhMAUrWyJazvdzKWQ0SnCWN9LxiDigDSNSyW3niZ68-yDT7KJhBJTqscCo6AOWu03S4Y9UItItNXfXNTRXgb7N5gdmYmBKIz5ayH7_1eiwLSZasxrSlDr5JWLXQxrPZfpnHO1Vq8l5Q',
 '冰宮現世 · 神兵降臨', '官方團隊', 'urgent', 1, 1, 'published', '2025-04-08 14:00:00', 18920, 234, 'system', 'system'),
(@cat_security, 'security', '防範第三方非法代儲、虛假虛寶交易與帳號安全保護嚴正聲明', 'anti-fraud-statement',
 '近期接獲多名俠士通報，有不肖份子以「低價折扣元寶」、「解綁外掛」為名詐騙帳號及密碼。官方重申：絕不會向玩家索要帳號密碼或二次驗證碼。',
 '<p>近期接獲多名俠士通報，有不肖份子於 LINE 社群、拍賣網站以「低價折扣元寶」、「解綁外掛」為名詐騙帳號及密碼。</p><p><strong>官方嚴正重申：</strong></p><ul><li>絕不會向玩家索要帳號密碼或二次驗證碼。</li><li>查獲非法代儲帳號將面臨永久停權。</li><li>官方唯一認證儲值途徑：遊戲內官方商城、官網會員儲值中心。</li></ul>',
 'https://lh3.googleusercontent.com/aida-public/AB6AXuDnI-4FilHWf0iKpGXiMmahMQi1_W7Hbo_vVy-r3J1toGCZW2R34p9Z9N_2GJPrApd10a-aL0CYY0C7VE1oo2oFndvtbIjpJVHp5jYI34vy9QzDwmoYxG1pt9NMe8Yo_kpPUL833Ky0cBvfVaCrEgNFsZQiX1QimYeEEm7pE0JbVsupNJ1EubTjw6xwujajPppHq3jlAvbDTUo9I14iRgzWcB8njrr5NMrHfwr970Yweko-wTRsVlNgHw',
 '金盾護體 · 官方嚴正聲明', '官方團隊', 'high', 1, 0, 'published', '2025-04-06 10:30:00', 12408, 57, 'system', 'system'),
(@cat_maintenance, 'maintenance', '2025年4月9日（三）伺服器例行維護停機公告 (08:00 - 12:00)', 'routine-maintenance-20250409',
 '為進行伺服器架構升級及資料庫例行備份，全區伺服器將於4月9日上午08:00起進行為期4小時的例行停機維護。',
 '<p>為進行伺服器架構升級及資料庫例行備份，全區伺服器將於 <strong>4月9日上午08:00起進行為期4小時</strong> 的例行停機維護。</p><p>維護期間將無法登入遊戲，造成不便敬請見諒。維護完成後將另行公告並發放補償。</p>',
 '', '', '官方團隊', 'normal', 0, 0, 'published', '2025-04-08 18:00:00', 6430, 0, 'system', 'system'),
(@cat_update, 'update', '全新神兵「寒霜驚鴻劍」打造圖譜與專屬銘文屬性數值全公開', 'frost-weapon-blueprint',
 '天山絕頂千載玄鐵淬鍊而成，攜帶冰心凌波特效。玩家可於幽玄冰宮精英首領取得鍛造圖譜殘頁並進行合成。',
 '<p>天山絕頂千載玄鐵淬鍊而成，攜帶冰心凌波特效。玩家可於幽玄冰宮精英首領取得鍛造圖譜殘頁並進行合成。</p><p>專屬銘文可提升冰屬性傷害與破防數值，詳細屬性數值請參閱附表。</p>',
 '', '', '官方團隊', 'high', 0, 0, 'published', '2025-04-07 15:20:00', 9812, 0, 'system', 'system'),
(@cat_event, 'event', '清明踏青「尋梅採露」全服節慶活動展開：踏雪尋幽採珍釀', 'qingming-event',
 '春雨連綿，煙柳畫橋。活動期間每日前往江南、洛陽採集清明朝露，可兌換限定背部掛件「一簑煙雨」與名劍殘頁。',
 '<p>春雨連綿，煙柳畫橋。活動期間每日前往江南、洛陽採集清明朝露，可兌換限定背部掛件「一簑煙雨」與名劍殘頁。</p><p>活動期間：4月3日維護後至4月17日維護前。</p>',
 '', '', '官方團隊', 'normal', 0, 0, 'published', '2025-04-05 11:00:00', 8140, 0, 'system', 'system'),
(@cat_security, 'security', '全域防外掛機制升級與惡意帳號停權處置名單公告（第十二期）', 'anti-cheat-12',
 '為維護公平武俠競技環境，營運團隊已透過最新「玄武風紀」反作弊模組查核並永久停權 243 個違規使用記憶體修改器之帳號。',
 '<p>為維護公平武俠競技環境，營運團隊已透過最新「玄武風紀」反作弊模組查核並永久停權 243 個違規使用記憶體修改器之帳號。</p><p>本名單同步公布於官網會員中心，如有疑義請於七日內提出申訴。</p>',
 '', '', '官方團隊', 'normal', 0, 0, 'published', '2025-04-04 17:45:00', 5502, 0, 'system', 'system'),
(@cat_event, 'event', '春季水墨時裝「踏雪千尋」與絕版白鹿坐騎「霜角凝輝」限時上架', 'spring-costume-2025',
 '由江南名繡閣特別定製，白綾裁雪、青絲繪墨。活動期間內購買特惠套裝享 85 折首發優惠，附贈專屬水墨運足輕功特效。',
 '<p>由江南名繡閣特別定製，白綾裁雪、青絲繪墨。活動期間內購買特惠套裝享 85 折首發優惠，附贈專屬水墨運足輕功特效。</p>',
 '', '', '官方團隊', 'normal', 0, 0, 'published', '2025-04-03 12:00:00', 11209, 0, 'system', 'system'),
(@cat_maintenance, 'maintenance', '跨服戰場伺服器網路線路與骨幹節點優化作業順利完畢公告', 'crossserver-network-optimize',
 '針對日前跨服「雁門關天險爭奪戰」中部分玩家出現之封包延遲與技能釋放卡頓現象，已完成台灣及港澳骨幹連線專線升級。',
 '<p>針對日前跨服「雁門關天險爭奪戰」中部分玩家出現之封包延遲與技能釋放卡頓現象，已完成台灣及港澳骨幹連線專線升級。</p><p>若仍有延遲狀況，請透過客服中心回報。</p>',
 '', '', '官方團隊', 'normal', 0, 0, 'published', '2025-04-02 21:30:00', 3420, 0, 'system', 'system'),
(@cat_guide, 'guide', '初涉江湖：新手俠客快速升級路徑與各大門派入門心法修煉指引', 'novice-guide-leveling',
 '萬里之行始於足下。本篇詳細拆解 1-60 級主線速升途徑、經脈打通秘訣，以及華山、武當、日月、恆山門派特質剖析。',
 '<p>萬里之行始於足下。本篇詳細拆解 1-60 級主線速升途徑、經脈打通秘訣，以及華山、武當、日月、恆山門派特質剖析。</p>',
 '', '', '官方團隊', 'normal', 0, 0, 'published', '2025-04-01 10:00:00', 14350, 0, 'system', 'system'),
(@cat_security, 'security', '官方在線客服身分驗證機制上線與工單真偽條碼核驗說明', 'support-verify-notice',
 '為杜絕偽冒官員客服行騙情事，即日起所有官方客服回覆函皆隨附動態水墨印記防偽碼，請俠士於回報進度查驗頁面核實。',
 '<p>為杜絕偽冒官員客服行騙情事，即日起所有官方客服回覆函皆隨附動態水墨印記防偽碼，請俠士於回報進度查驗頁面核實。</p>',
 '', '', '官方團隊', 'normal', 0, 0, 'published', '2025-03-29 16:15:00', 4921, 0, 'system', 'system');

-- 管理歷程初始記錄
INSERT INTO `news_status_log` (`article_id`, `action`, `operator_type`, `operator_name`, `old_status`, `new_status`, `note`)
SELECT `id`, 'create', 'system', 'system', 'draft', `status`, '系統初始匯入' FROM `news_articles`;

SET FOREIGN_KEY_CHECKS = 1;

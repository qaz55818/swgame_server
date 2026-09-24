-- =============================================================
--  踏雪笑傲 · 客戶端下載中心 資料表
--  獨立資料庫：swo_download
--  對應頁面：
--    公開前台  swo/download.php
--    管理後台  admin/download_admin.php
--  執行方式：
--    mysql -u root -p < download_schema.sql
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `swo_download`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `swo_download`;

-- ----------------------------
-- 下載平台
-- ----------------------------
DROP TABLE IF EXISTS `dl_platforms`;
CREATE TABLE `dl_platforms` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL DEFAULT '' COMMENT '平台代碼',
  `name` varchar(60) NOT NULL DEFAULT '' COMMENT '平台名稱',
  `name_en` varchar(60) NOT NULL DEFAULT '' COMMENT '英文名稱',
  `icon` varchar(40) NOT NULL DEFAULT '' COMMENT 'Material Symbols 圖示',
  `tagline` varchar(120) NOT NULL DEFAULT '' COMMENT '平台說明',
  `accent` varchar(20) NOT NULL DEFAULT 'sky' COMMENT '主色系 sky/rose/emerald/violet/slate',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dl_platform_code` (`code`),
  KEY `idx_dl_platform_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='下載平台';

-- ----------------------------
-- 版本發行
-- ----------------------------
DROP TABLE IF EXISTS `dl_releases`;
CREATE TABLE `dl_releases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `platform_id` int NOT NULL DEFAULT 0 COMMENT 'dl_platforms.id',
  `platform_code` varchar(32) NOT NULL DEFAULT '' COMMENT '平台代碼（冗餘）',
  `version` varchar(40) NOT NULL DEFAULT '' COMMENT '版本號',
  `build_no` varchar(40) NOT NULL DEFAULT '' COMMENT '組建編號',
  `release_date` date DEFAULT NULL COMMENT '發行日期',
  `size_label` varchar(40) NOT NULL DEFAULT '' COMMENT '檔案大小顯示文字',
  `installer_url` varchar(500) NOT NULL DEFAULT '' COMMENT '主程式下載網址',
  `installer_label` varchar(120) NOT NULL DEFAULT '' COMMENT '下載按鈕文字',
  `cover_image` varchar(500) NOT NULL DEFAULT '' COMMENT '主視覺圖',
  `cover_engine` varchar(80) NOT NULL DEFAULT '' COMMENT '引擎標語（如 DirectX 12 Ultimate）',
  `cover_caption` varchar(120) NOT NULL DEFAULT '' COMMENT '封面標語',
  `sha256` varchar(128) NOT NULL DEFAULT '' COMMENT 'SHA-256 校驗碼',
  `badge` varchar(60) NOT NULL DEFAULT '' COMMENT '版本徽章（如 正式版本）',
  `download_count` int NOT NULL DEFAULT 0 COMMENT '累計下載次數',
  `is_current` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否為該平台當前版本',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `notes` varchar(255) NOT NULL DEFAULT '' COMMENT '備註',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dl_release_platform` (`platform_id`),
  KEY `idx_dl_release_current` (`is_current`),
  KEY `idx_dl_release_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='版本發行';

-- ----------------------------
-- 備用分流載點
-- ----------------------------
DROP TABLE IF EXISTS `dl_mirrors`;
CREATE TABLE `dl_mirrors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `release_id` int NOT NULL DEFAULT 0 COMMENT 'dl_releases.id',
  `label` varchar(80) NOT NULL DEFAULT '' COMMENT '載點名稱',
  `sub_label` varchar(120) NOT NULL DEFAULT '' COMMENT '載點說明',
  `url` varchar(500) NOT NULL DEFAULT '' COMMENT '下載網址',
  `icon` varchar(40) NOT NULL DEFAULT 'open_in_new' COMMENT 'Material Symbols 圖示',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dl_mirror_release` (`release_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='備用分流載點';

-- ----------------------------
-- 電腦配備需求
-- ----------------------------
DROP TABLE IF EXISTS `dl_requirements`;
CREATE TABLE `dl_requirements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `label` varchar(80) NOT NULL DEFAULT '' COMMENT '項目名稱',
  `icon` varchar(40) NOT NULL DEFAULT 'memory' COMMENT 'Material Symbols 圖示',
  `min_value` varchar(200) NOT NULL DEFAULT '' COMMENT '最低配置',
  `rec_value` varchar(200) NOT NULL DEFAULT '' COMMENT '推薦配置',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dl_req_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='電腦配備需求';

-- ----------------------------
-- 下載與安裝 FAQ
-- ----------------------------
DROP TABLE IF EXISTS `dl_faqs`;
CREATE TABLE `dl_faqs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tag` varchar(40) NOT NULL DEFAULT '' COMMENT '標籤（如 [安裝錯誤]）',
  `question` varchar(255) NOT NULL DEFAULT '' COMMENT '問題',
  `note` varchar(200) NOT NULL DEFAULT '' COMMENT '問題補充說明',
  `answer` text COMMENT '解答（支援 HTML）',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
  `sort_order` int NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dl_faq_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='下載與安裝 FAQ';

-- ----------------------------
-- 下載中心系統設定
-- ----------------------------
DROP TABLE IF EXISTS `dl_settings`;
CREATE TABLE `dl_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(64) NOT NULL DEFAULT '' COMMENT '設定鍵',
  `setting_value` text COMMENT '設定值',
  `display_name` varchar(80) NOT NULL DEFAULT '' COMMENT '顯示名稱',
  `description` varchar(200) NOT NULL DEFAULT '' COMMENT '說明',
  `setting_type` varchar(20) NOT NULL DEFAULT 'text' COMMENT 'text/toggle',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dl_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='下載中心系統設定';

-- =============================================================
-- 初始資料
-- =============================================================

INSERT INTO `dl_platforms` (`id`, `code`, `name`, `name_en`, `icon`, `tagline`, `accent`, `enabled`, `sort_order`) VALUES
(1, 'pc',      '電腦端',  'PC / Windows', 'desktop_windows', 'Windows 10 / 11 64-bit',      'sky',    1, 1),
(2, 'android', 'Android', 'Android',      'smartphone',      'Android 8.0 以上',            'emerald', 1, 2),
(3, 'ios',     'iOS',     'iPhone / iPad','phone_iphone',    'iOS 14.0 以上',               'violet', 1, 3),
(4, 'mac',     'macOS',   'macOS',        'laptop_mac',      'Apple Silicon / Intel',       'slate',  0, 4);

INSERT INTO `dl_releases`
(`id`,`platform_id`,`platform_code`,`version`,`build_no`,`release_date`,`size_label`,`installer_url`,`installer_label`,`cover_image`,`cover_engine`,`cover_caption`,`sha256`,`badge`,`download_count`,`is_current`,`enabled`,`sort_order`,`notes`) VALUES
(1, 1, 'pc', 'v1.8.5', '1850', '2025-04-05', '18.6 GB', '#', '立即下載完整客戶端 (.exe)',
 'https://lh3.googleusercontent.com/aida-public/AB6AXuA4bqkH08q0EWlSkCpIbdOMh0V1_EahFDJbBHgNLCNR5Xj_NvAXA3-BtJrRq9sYI-2oCLzB2bns1LcovITUKhxSQso0pdXqaEPyLdsJcLqlyLK4Cf0NpxtcWh4RT-PrM9k12_w8luI76VfMQqauJ0PXQXH65lXUDcQUUflYkqZzVJ3Z0XKBRWUHYwHmLcBzqJwXc2Da_V88iFLst4gjR1-1fCJKpH90v_Al7hO-L8r1',
 'DirectX 12 Ultimate', '墨意風骨 · 寒山踏雪', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', '正式版本', 128934, 1, 1, 1, '主力 PC 客戶端'),
(2, 2, 'android', 'v1.8.5', '1850', '2025-04-05', '1.9 GB', '#', '下載 Android APK', '', '', '', '', '正式版本', 52110, 1, 1, 1, ''),
(3, 3, 'ios', 'v1.8.5', '1850', '2025-04-05', '3.2 GB', '#', '前往 App Store 下載', '', '', '', '', '正式版本', 38760, 1, 1, 1, '');

INSERT INTO `dl_mirrors` (`release_id`, `label`, `sub_label`, `url`, `icon`, `enabled`, `sort_order`) VALUES
(1, '分流載點一', '中華電信直連高速', '#', 'open_in_new',  1, 1),
(1, '分流載點二', 'Google Drive 雲端', '#', 'cloud_download', 1, 2),
(1, '分流載點三', 'MEGA 備用分流',     '#', 'folder_shared',  1, 3);

INSERT INTO `dl_requirements` (`label`, `icon`, `min_value`, `rec_value`, `enabled`, `sort_order`) VALUES
('作業系統 (OS)',       'desktop_windows', 'Windows 10 64-bit (版本 1909 以上)', 'Windows 10 / 11 64-bit 最新版本', 1, 1),
('處理器 (CPU)',        'memory',          'Intel Core i5-8400 或 AMD Ryzen 5 2600', 'Intel Core i7-12700 或 AMD Ryzen 7 5800X 及以上', 1, 2),
('記憶體 (RAM)',        'straighten',      '16 GB DDR4', '32 GB DDR4 3200MHz 或 DDR5', 1, 3),
('顯示卡 (GPU)',        'videogame_asset', 'NVIDIA GeForce GTX 1060 (6GB) / AMD Radeon RX 580', 'NVIDIA GeForce RTX 3070 Ti / 4070 或 AMD Radeon RX 6800 XT', 1, 4),
('硬碟空間 (Storage)',  'hard_drive',      '需預留 50 GB 可用空間 (支援 HDD)', '需預留 80 GB 可用空間 (強烈建議 NVMe M.2 高速 SSD)', 1, 5),
('DirectX 版本',        'developer_board', 'DirectX 11', 'DirectX 12 (支援 DLSS 3.5 光線重構技術)', 1, 6);

INSERT INTO `dl_faqs` (`tag`, `question`, `note`, `answer`, `enabled`, `sort_order`) VALUES
('[安裝錯誤]', '下載過程中頻繁中斷，或提示「封裝檔案校驗失敗 / CRC Checksum Mismatch」？', '適用於網路高壓、校驗封包異常或防毒誤鎖暫存檔',
 '<p>建議依下列步驟排除：</p><ul><li>關閉防毒／防火牆的即時掃描後重新下載，或將安裝目錄加入白名單。</li><li>改用「備用分流載點」下載，避免單一線路壅塞。</li><li>下載完成後以官方提供之 SHA-256 校驗碼核對檔案完整性。</li><li>若仍失敗，請刪除安裝目錄後以管理員權限重新執行安裝器。</li></ul>', 1, 1),
('[啟動異常]', '點擊啟動器後沒有反應，或出現「找不到 d3d12.dll / VCRUNTIME140.dll」？', '常見於缺少 DirectX 或 Visual C++ 執行環境',
 '<p>請先安裝最新版 <strong>DirectX End-User Runtime</strong> 與 <strong>Microsoft Visual C++ Redistributable (x64)</strong>，並更新顯示卡驅動至最新版本後重試。</p>', 1, 2),
('[效能調校]', '遊戲內幀數偏低、貼圖延遲載入，該如何優化？', '適用於 4K 高畫質或較舊顯示卡',
 '<p>可於設定中降低陰影與光線追蹤品質、開啟 DLSS / FSR，並確認遊戲安裝於 NVMe SSD。另請於顯示卡控制台關閉省電模式並開啟 G-Sync / FreeSync。</p>', 1, 3);

INSERT INTO `dl_settings` (`setting_key`, `setting_value`, `display_name`, `description`, `setting_type`) VALUES
('hero_badge',          '官方客戶端發行中心 · CLIENT DISTRIBUTION', '主視覺標籤', '下載頁頁首小標籤', 'text'),
('hero_title',          '踏雪啟程 · 客戶端下載', '主視覺標題', '下載頁 H1 標題', 'text'),
('hero_subtitle',       '沉浸體驗 4K 墨韻江湖，支援 PC 電腦端高速下載與行動雙平台跨端互通。刀光劍影，一指乾坤。', '主視覺副標', '下載頁說明文字', 'text'),
('server_status_label', '全球伺服器狀態', '伺服器狀態標題', '頁首狀態卡標題', 'text'),
('server_status_value', '全線路極速連線中 (99.98%)', '伺服器狀態內容', '頁首狀態卡內容', 'text'),
('installer_kicker',    'RECOMMENDED INSTALLER', '安裝器英文標籤', '主下載卡英文小標', 'text'),
('installer_title',     '官方旗艦微端高速安裝器', '安裝器標題', '主下載卡標題', 'text'),
('installer_desc',      '搭載自研極速 P2SP 多線程分流下載技術，智慧校驗修復受損區塊，免手動配置即可無縫進入江湖。', '安裝器說明', '主下載卡說明文字', 'text'),
('security_badge',      '數位簽章已通過 · 無毒無外掛保證', '安全保證標語', '主下載卡安全徽章文字', 'text'),
('requirements_title',  '電腦配備需求 · 鑑機撫琴', '配備需求標題', '系統需求區塊標題', 'text'),
('requirements_subtitle','為求最佳墨韻光影與千人同屏流暢度，請參考以下系統配置標準。', '配備需求副標', '系統需求區塊說明', 'text'),
('requirements_tip',    '溫馨提醒：若欲體驗 4K 實時水墨光線追蹤與高階粒子特效，請於顯示卡控制台開啟 G-Sync / FreeSync 同步，並確保顯示卡驅動程式更新至最新版本。', '配備需求提醒', '系統需求區塊底部提醒', 'text'),
('faq_title',           '疑難排解 · 鑑機撫琴修復指引', 'FAQ 標題', 'FAQ 區塊標題', 'text'),
('faq_subtitle',        '江湖廣袤，若在客戶端下載、解壓縮、環境運行或畫面渲染中遇阻，請查閱全方位結構化排障方案。', 'FAQ 副標', 'FAQ 區塊說明', 'text'),
('show_server_status',  '1', '顯示伺服器狀態', '1=顯示 / 0=隱藏 頁首伺服器狀態卡', 'toggle'),
('show_mirrors',        '1', '顯示備用分流', '1=顯示 / 0=隱藏 主下載卡備用分流載點', 'toggle'),
('show_requirements',   '1', '顯示配備需求', '1=顯示 / 0=隱藏 電腦配備需求區塊', 'toggle'),
('show_faq',            '1', '顯示 FAQ', '1=顯示 / 0=隱藏 疑難排解區塊', 'toggle');

SET FOREIGN_KEY_CHECKS = 1;

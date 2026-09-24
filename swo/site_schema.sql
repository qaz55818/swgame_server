-- =============================================================
--  踏雪笑傲 · 官方首頁（Landing）設定中心
--  資料庫：sa（主庫）   資料表：swo_site_settings
--  前台：swo/index.php   後台：admin/site_admin.php
--  說明：後台首次進入時會自動建立資料表並寫入預設值；
--        本檔僅供手動匯入或備份參考，重複匯入不會覆蓋既有設定。
-- =============================================================

CREATE TABLE IF NOT EXISTS `swo_site_settings` (
    `setting_key`   VARCHAR(64)  NOT NULL COMMENT '設定鍵',
    `setting_value` TEXT         NULL     COMMENT '設定值',
    `setting_type`  VARCHAR(20)  NOT NULL DEFAULT 'text' COMMENT 'text/textarea/image/url/number/select',
    `group_name`    VARCHAR(40)  NOT NULL DEFAULT 'general' COMMENT '分組：brand/hero/cta/news/featured/social/footer',
    `display_name`  VARCHAR(120) NOT NULL DEFAULT '' COMMENT '欄位顯示名稱',
    `description`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '欄位說明',
    `sort_order`    INT          NOT NULL DEFAULT 0,
    `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 首次安裝：預設值會由 admin/site_admin.php 或 swo/site_config.php 的
-- site_settings_ensure() 自動寫入。以下為主要預設鍵值示例（可選）。

INSERT INTO `swo_site_settings` (`setting_key`,`setting_value`,`setting_type`,`group_name`,`display_name`) VALUES
    ('brand_name',      '踏雪笑傲',   'text',  'brand', '品牌名稱'),
    ('brand_badge',     '正宗武俠',   'text',  'brand', '品牌徽章'),
    ('brand_subtitle',  'SNOW WANDERER ONLINE', 'text', 'brand', '品牌副標'),
    ('status_text',     '全服營運中', 'text',  'brand', '營運狀態文字'),
    ('nav_cta_label',   '加入 DISCORD', 'text',  'brand', '右側主要按鈕文字'),
    ('nav_cta_url',     '#',           'url',   'brand', '右側主要按鈕連結'),
    ('hero_title_1',    '雪落寒山孤劍影', 'text', 'hero', '主標題 第一行'),
    ('hero_title_2',    '天地笑傲任平生', 'text', 'hero', '主標題 第二行'),
    ('cta1_label',      '立即下載遊戲', 'text', 'cta', '主按鈕文字'),
    ('cta1_url',        'download.php', 'url', 'cta', '主按鈕連結'),
    ('cta2_label',      '快速註冊帳號', 'text', 'cta', '註冊按鈕文字'),
    ('cta2_url',        '../index.php', 'url', 'cta', '註冊按鈕連結'),
    ('news_limit',      '8',           'number','news', '公告顯示數量'),
    ('news_more_url',   'news.php',    'url',  'news', '「查看全部」連結')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

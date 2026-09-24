<?php
/**
 * =============================================================
 *  踏雪笑傲 · 官方首頁（Landing）設定中心 — 共用設定 / 資料庫工具
 *  資料庫：sa（主庫，沿用 config.php 連線）  資料表：swo_site_settings
 *  使用頁面：
 *    公開前台  swo/index.php
 *    管理後台  admin/site_admin.php
 *  說明：採 key/value 儲存，未設定時自動回退 site_defaults() 預設值，
 *        因此即使資料表尚未建立，前台仍可正常顯示。
 * =============================================================
 */

require_once __DIR__ . '/../config.php'; // 取得 $DBHost / $DBUser / $DBPassword / $DBName

if (!defined('SITE_SETTINGS_TABLE')) {
    define('SITE_SETTINGS_TABLE', 'swo_site_settings');
}

/** HTML 轉義 */
function site_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * 首頁欄位結構（同時驅動前台預設值與後台表單）。
 * type: text | textarea | image | url | number | select
 */
function site_schema(): array
{
    return [
        /* ---------------- 品牌 / 導覽 ---------------- */
        'brand_logo'      => ['group' => 'brand', 'label' => '網站 Logo', 'type' => 'image', 'default' => 'assets/images/idx-logo.png', 'desc' => '建議透明 PNG，高度約 40px。'],
        'brand_name'      => ['group' => 'brand', 'label' => '品牌名稱', 'type' => 'text', 'default' => '踏雪笑傲'],
        'brand_badge'     => ['group' => 'brand', 'label' => '品牌徽章', 'type' => 'text', 'default' => '正宗武俠'],
        'brand_subtitle'  => ['group' => 'brand', 'label' => '品牌副標', 'type' => 'text', 'default' => 'SNOW WANDERER ONLINE'],
        'status_text'     => ['group' => 'brand', 'label' => '營運狀態文字', 'type' => 'text', 'default' => '全服營運中'],
        'nav_cta_label'   => ['group' => 'brand', 'label' => '右側主要按鈕文字', 'type' => 'text', 'default' => '加入 DISCORD'],
        'nav_cta_url'     => ['group' => 'brand', 'label' => '右側主要按鈕連結', 'type' => 'url',  'default' => '#'],
        'nav_news_url'    => ['group' => 'brand', 'label' => '最新公告連結', 'type' => 'url', 'default' => 'news.php'],
        'nav_events_url'  => ['group' => 'brand', 'label' => '江湖活動連結', 'type' => 'url', 'default' => 'events.php'],
        'nav_download_url'=> ['group' => 'brand', 'label' => '遊戲下載連結', 'type' => 'url', 'default' => 'download.php'],
        'nav_member_url'  => ['group' => 'brand', 'label' => '會員中心連結', 'type' => 'url', 'default' => '../home.php'],

        /* ---------------- Hero 主視覺 ---------------- */
        'hero_image'      => ['group' => 'hero', 'label' => 'Hero 背景圖', 'type' => 'image', 'default' => 'assets/images/idx-hero.jpg', 'desc' => '建議 1920×1080 以上。'],
        'hero_badge'      => ['group' => 'hero', 'label' => '頂部標語徽章', 'type' => 'text', 'default' => '正宗硬派水墨東方仙俠 · 旗艦巨獻'],
        'hero_kicker'     => ['group' => 'hero', 'label' => '主標上方小字', 'type' => 'text', 'default' => '霜刃既出 · 笑傲蒼穹'],
        'hero_title_1'    => ['group' => 'hero', 'label' => '主標題 第一行', 'type' => 'text', 'default' => '雪落寒山孤劍影'],
        'hero_title_2'    => ['group' => 'hero', 'label' => '主標題 第二行', 'type' => 'text', 'default' => '天地笑傲任平生'],
        'hero_seal'       => ['group' => 'hero', 'label' => '印章文字', 'type' => 'text', 'default' => '極境'],
        'hero_subtitle'   => ['group' => 'hero', 'label' => '副標題', 'type' => 'textarea', 'default' => '一柄孤劍踏萬里雪原，一脈清霜引江湖浩蕩。'],
        'hero_subtitle_2' => ['group' => 'hero', 'label' => '副標題（小字）', 'type' => 'text', 'default' => '沉浸式東方寫意武學 · 跨服千人同屏對決 · 今朝試鋒。'],

        /* ---------------- 行動呼籲 CTA ---------------- */
        'cta1_label'      => ['group' => 'cta', 'label' => '主按鈕文字', 'type' => 'text', 'default' => '立即下載遊戲'],
        'cta1_sub'        => ['group' => 'cta', 'label' => '主按鈕副文字', 'type' => 'text', 'default' => 'PC 完整安裝包 · v1.8.5 (28.4 GB)'],
        'cta1_url'        => ['group' => 'cta', 'label' => '主按鈕連結', 'type' => 'url', 'default' => 'download.php'],
        'cta2_label'      => ['group' => 'cta', 'label' => '註冊按鈕文字', 'type' => 'text', 'default' => '快速註冊帳號'],
        'cta2_url'        => ['group' => 'cta', 'label' => '註冊按鈕連結', 'type' => 'url', 'default' => '../index.php'],
        'cta3_label'      => ['group' => 'cta', 'label' => '影片按鈕文字', 'type' => 'text', 'default' => '欣賞宣傳影片'],
        'pv_embed_id'     => ['group' => 'cta', 'label' => '宣傳影片 YouTube ID', 'type' => 'text', 'default' => '', 'desc' => '留空則顯示佔位畫面；例如 dQw4w9WgXcQ。'],

        /* ---------------- 江湖風雲速遞（公告） ---------------- */
        'news_title'      => ['group' => 'news', 'label' => '區塊標題', 'type' => 'text', 'default' => '江湖風雲速遞'],
        'news_subtitle'   => ['group' => 'news', 'label' => '區塊說明', 'type' => 'textarea', 'default' => '掌握天下局勢與版本動態，例行維護、名劍大會與節氣奇遇活動一手掌握。'],
        'news_limit'      => ['group' => 'news', 'label' => '公告顯示數量', 'type' => 'number', 'default' => '8', 'desc' => '首頁右側列表最多顯示幾則公告（1–20）。'],
        'news_more_label' => ['group' => 'news', 'label' => '「查看全部」文字', 'type' => 'text', 'default' => '查看全部歷史公告'],
        'news_more_url'   => ['group' => 'news', 'label' => '「查看全部」連結', 'type' => 'url', 'default' => 'news.php'],

        /* ---------------- 主打資料片卡片 ---------------- */
        'feature_image'   => ['group' => 'featured', 'label' => '主視覺圖片', 'type' => 'image', 'default' => 'assets/images/idx-news-featured.jpg'],
        'feature_badge_a' => ['group' => 'featured', 'label' => '徽章一', 'type' => 'text', 'default' => '年度資料片'],
        'feature_badge_b' => ['group' => 'featured', 'label' => '徽章二', 'type' => 'text', 'default' => '寒淵破曉'],
        'feature_date'    => ['group' => 'featured', 'label' => '發佈日期文字', 'type' => 'text', 'default' => '2025-03-01'],
        'feature_tag'     => ['group' => 'featured', 'label' => '標籤文字', 'type' => 'text', 'default' => '跨服全新宗門戰'],
        'feature_title'   => ['group' => 'featured', 'label' => '標題', 'type' => 'text', 'default' => '全新年度資料片「寒淵破曉」震撼上線，破封冰原秘境'],
        'feature_desc'    => ['group' => 'featured', 'label' => '描述', 'type' => 'textarea', 'default' => '天山冰魄再現，四荒群雄並起。解鎖全新等級上限、極地雪嶺全地圖自由御劍，通關九幽冰淵副本即有機會獲取鎮派絕品飛劍神兵！'],
        'feature_footer'  => ['group' => 'featured', 'label' => '底部文字', 'type' => 'text', 'default' => '參與首通集結活動，領取絕版仙羽稱號'],
        'feature_url'     => ['group' => 'featured', 'label' => '卡片連結', 'type' => 'url', 'default' => 'news.php'],

        /* ---------------- 社群陣地（四張卡片） ---------------- */
        'social1_title'   => ['group' => 'social', 'label' => '社群一 標題', 'type' => 'text', 'default' => '官方 Facebook'],
        'social1_desc'    => ['group' => 'social', 'label' => '社群一 說明', 'type' => 'text', 'default' => '即時追蹤一手爆料活動'],
        'social1_icon'    => ['group' => 'social', 'label' => '社群一 圖示', 'type' => 'select', 'default' => 'public', 'options' => ['public','forum','chat','support_agent','campaign','groups','smart_toy','sports_esports','video_library','mail','phone_in_talk']],
        'social1_tone'    => ['group' => 'social', 'label' => '社群一 色系', 'type' => 'select', 'default' => 'rose', 'options' => ['rose','sky','emerald','slate','amber','violet','cyan']],
        'social1_url'     => ['group' => 'social', 'label' => '社群一 連結', 'type' => 'url', 'default' => '#'],

        'social2_title'   => ['group' => 'social', 'label' => '社群二 標題', 'type' => 'text', 'default' => 'Discord 俠客群'],
        'social2_desc'    => ['group' => 'social', 'label' => '社群二 說明', 'type' => 'text', 'default' => '組隊開荒與即時語音'],
        'social2_icon'    => ['group' => 'social', 'label' => '社群二 圖示', 'type' => 'select', 'default' => 'forum', 'options' => ['public','forum','chat','support_agent','campaign','groups','smart_toy','sports_esports','video_library','mail','phone_in_talk']],
        'social2_tone'    => ['group' => 'social', 'label' => '社群二 色系', 'type' => 'select', 'default' => 'sky', 'options' => ['rose','sky','emerald','slate','amber','violet','cyan']],
        'social2_url'     => ['group' => 'social', 'label' => '社群二 連結', 'type' => 'url', 'default' => '#'],

        'social3_title'   => ['group' => 'social', 'label' => '社群三 標題', 'type' => 'text', 'default' => 'LINE 官方社群'],
        'social3_desc'    => ['group' => 'social', 'label' => '社群三 說明', 'type' => 'text', 'default' => '領取專屬手機壁紙禮包'],
        'social3_icon'    => ['group' => 'social', 'label' => '社群三 圖示', 'type' => 'select', 'default' => 'chat', 'options' => ['public','forum','chat','support_agent','campaign','groups','smart_toy','sports_esports','video_library','mail','phone_in_talk']],
        'social3_tone'    => ['group' => 'social', 'label' => '社群三 色系', 'type' => 'select', 'default' => 'emerald', 'options' => ['rose','sky','emerald','slate','amber','violet','cyan']],
        'social3_url'     => ['group' => 'social', 'label' => '社群三 連結', 'type' => 'url', 'default' => '#'],

        'social4_title'   => ['group' => 'social', 'label' => '社群四 標題', 'type' => 'text', 'default' => '線上客服中心'],
        'social4_desc'    => ['group' => 'social', 'label' => '社群四 說明', 'type' => 'text', 'default' => '7x24 小時問題排解'],
        'social4_icon'    => ['group' => 'social', 'label' => '社群四 圖示', 'type' => 'select', 'default' => 'support_agent', 'options' => ['public','forum','chat','support_agent','campaign','groups','smart_toy','sports_esports','video_library','mail','phone_in_talk']],
        'social4_tone'    => ['group' => 'social', 'label' => '社群四 色系', 'type' => 'select', 'default' => 'slate', 'options' => ['rose','sky','emerald','slate','amber','violet','cyan']],
        'social4_url'     => ['group' => 'social', 'label' => '社群四 連結', 'type' => 'url', 'default' => '../support.php'],

        /* ---------------- Footer ---------------- */
        'footer_poem'     => ['group' => 'footer', 'label' => '品牌詩句', 'type' => 'text', 'default' => '霜刃未曾試，今日把示君。天下風雲出我輩，一入江湖歲月催。'],
        'footer_age_badge'=> ['group' => 'footer', 'label' => '分級標章', 'type' => 'text', 'default' => '輔 15 級'],
        'footer_notice'   => ['group' => 'footer', 'label' => '分級警語', 'type' => 'textarea', 'default' => '本遊戲情節涉及性、暴力、虛擬戀愛或結婚。注意使用時間，避免沉迷於遊戲。遊戲部分內容須另行支付費用。'],
        'footer_copyright'=> ['group' => 'footer', 'label' => '版權宣告', 'type' => 'text', 'default' => 'SNOW WANDERER ONLINE. 踏雪笑傲工作室 版權所有. All Rights Reserved.'],
        'footer_link1_label' => ['group' => 'footer', 'label' => '底部連結一 文字', 'type' => 'text', 'default' => '客服中心'],
        'footer_link1_url'   => ['group' => 'footer', 'label' => '底部連結一 連結', 'type' => 'url', 'default' => '../support.php'],
        'footer_link2_label' => ['group' => 'footer', 'label' => '底部連結二 文字', 'type' => 'text', 'default' => '服務條款'],
        'footer_link2_url'   => ['group' => 'footer', 'label' => '底部連結二 連結', 'type' => 'url', 'default' => '#'],
        'footer_link3_label' => ['group' => 'footer', 'label' => '底部連結三 文字', 'type' => 'text', 'default' => '隱私權政策'],
        'footer_link3_url'   => ['group' => 'footer', 'label' => '底部連結三 連結', 'type' => 'url', 'default' => '#'],
        'footer_link4_label' => ['group' => 'footer', 'label' => '底部連結四 文字', 'type' => 'text', 'default' => '家長監護'],
        'footer_link4_url'   => ['group' => 'footer', 'label' => '底部連結四 連結', 'type' => 'url', 'default' => '#'],
        'footer_link5_label' => ['group' => 'footer', 'label' => '底部連結五 文字', 'type' => 'text', 'default' => '社群交流'],
        'footer_link5_url'   => ['group' => 'footer', 'label' => '底部連結五 連結', 'type' => 'url', 'default' => '../support.php'],
    ];
}

/** 各分組顯示名稱 */
function site_groups(): array
{
    return [
        'brand'    => ['name' => '品牌與導覽', 'icon' => 'fa-crown'],
        'hero'     => ['name' => '主視覺 Hero', 'icon' => 'fa-mountain-sun'],
        'cta'      => ['name' => '行動呼籲與影片', 'icon' => 'fa-hand-pointer'],
        'news'     => ['name' => '江湖風雲速遞', 'icon' => 'fa-bullhorn'],
        'featured' => ['name' => '主打資料片', 'icon' => 'fa-star'],
        'social'   => ['name' => '社群與客服連結', 'icon' => 'fa-share-nodes'],
        'footer'   => ['name' => '頁尾設定', 'icon' => 'fa-shoe-prints'],
    ];
}

/** 預設值（由 schema 推導） */
function site_defaults(): array
{
    $out = [];
    foreach (site_schema() as $key => $meta) {
        $out[$key] = (string)($meta['default'] ?? '');
    }
    return $out;
}

/** 取得資料庫連線（主庫 sa）；失敗時回傳 null，前台自動使用預設值 */
function site_db()
{
    global $DBHost, $DBUser, $DBPassword, $DBName;
    $db = @mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
    if (!$db) { return null; }
    mysqli_set_charset($db, 'utf8mb4');
    return $db;
}

/** 建立資料表（若不存在）並於首次寫入預設值 */
function site_settings_ensure(mysqli $db): void
{
    $table = SITE_SETTINGS_TABLE;
    try {
        mysqli_query($db, "CREATE TABLE IF NOT EXISTS `$table` (
            `setting_key`   VARCHAR(64)  NOT NULL,
            `setting_value` TEXT         NULL,
            `setting_type`  VARCHAR(20)  NOT NULL DEFAULT 'text',
            `group_name`    VARCHAR(40)  NOT NULL DEFAULT 'general',
            `display_name`  VARCHAR(120) NOT NULL DEFAULT '',
            `description`   VARCHAR(255) NOT NULL DEFAULT '',
            `sort_order`    INT          NOT NULL DEFAULT 0,
            `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $res = mysqli_query($db, "SELECT COUNT(*) AS c FROM `$table`");
        $count = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;
        if ($count === 0) {
            $i = 0;
            foreach (site_schema() as $key => $meta) {
                site_setting_put($db, (string)$key, (string)($meta['default'] ?? ''), $meta, $i++);
            }
        }
    } catch (Throwable $e) {
        /* 忽略：無法建立時前台仍會回退預設值 */
    }
}

/**
 * 讀取全部設定（合併預設值）。
 * 若資料表尚未建立會自動建立並寫入預設值（自癒），
 * 且任何資料庫錯誤皆不會中斷前台顯示。
 */
function site_settings_all($db = null): array
{
    $out = site_defaults();
    if (!($db instanceof mysqli)) {
        return $out;
    }
    $table = SITE_SETTINGS_TABLE;
    $res = false;
    try {
        $res = mysqli_query($db, "SELECT `setting_key`, `setting_value` FROM `$table`");
    } catch (Throwable $e) {
        /* 資料表不存在：自動建立後重試一次 */
        site_settings_ensure($db);
        try {
            $res = mysqli_query($db, "SELECT `setting_key`, `setting_value` FROM `$table`");
        } catch (Throwable $e2) {
            $res = false;
        }
    }
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[$row['setting_key']] = (string)$row['setting_value'];
        }
    }
    return $out;
}

/** 讀取單一設定值 */
function site_setting(array $S, string $key, string $default = ''): string
{
    $v = $S[$key] ?? null;
    return ($v === null || $v === '') ? $default : (string)$v;
}

/** 新增或更新設定值 */
function site_setting_put(mysqli $db, string $key, string $value, array $meta = [], int $order = 0): void
{
    $table = SITE_SETTINGS_TABLE;
    $type  = (string)($meta['type'] ?? 'text');
    $group = (string)($meta['group'] ?? 'general');
    $label = (string)($meta['label'] ?? '');
    $desc  = (string)($meta['description'] ?? ($meta['desc'] ?? ''));
    $stmt = $db->prepare(
        "INSERT INTO `$table` (`setting_key`,`setting_value`,`setting_type`,`group_name`,`display_name`,`description`,`sort_order`)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            `setting_value` = VALUES(`setting_value`),
            `setting_type`  = VALUES(`setting_type`),
            `group_name`    = VALUES(`group_name`),
            `display_name`  = VALUES(`display_name`),
            `description`   = VALUES(`description`),
            `sort_order`    = VALUES(`sort_order`)"
    );
    if (!$stmt) { return; }
    $stmt->bind_param('ssssssi', $key, $value, $type, $group, $label, $desc, $order);
    $stmt->execute();
    $stmt->close();
}

/**
 * 處理圖片上傳（後台用）。
 * @return string|null 成功時回傳相對於 swo 根目錄的路徑（assets/uploads/xxx.jpg）
 */
function site_store_upload(string $inputName, string $key): ?string
{
    if (empty($_FILES[$inputName]) || !is_array($_FILES[$inputName])) { return null; }
    $f = $_FILES[$inputName];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { return null; }
    if (($f['size'] ?? 0) <= 0 || $f['size'] > 6 * 1024 * 1024) { return null; }

    $info = @getimagesize($f['tmp_name']);
    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    if (!$info || !isset($allowed[$info[2]])) { return null; }

    $dir = __DIR__ . '/assets/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_dir($dir) || !is_writable($dir)) { return null; }

    $safe = preg_replace('/[^a-z0-9_-]/i', '', $key);
    $name = $safe . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $allowed[$info[2]];
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) { return null; }
    return 'assets/uploads/' . $name;
}

/** 將設定中的圖片路徑轉為可用網址（後台可加 ../swo/ 前綴） */
function site_img_url(string $val, string $prefix = ''): string
{
    $val = trim($val);
    if ($val === '') { return ''; }
    if (preg_match('#^(https?:)?//#i', $val) || strncmp($val, 'data:', 5) === 0) { return $val; }
    return $prefix . ltrim($val, '/');
}

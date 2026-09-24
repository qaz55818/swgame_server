<?php
/**
 * =============================================================
 *  踏雪笑傲 · 本機設定範例（config.sample.php）
 *  -------------------------------------------------------------
 *  安裝程式會自動產生 config.local.php；本檔僅作為手動設定的參考。
 *
 *  手動安裝步驟：
 *    1. 複製本檔為 config.local.php
 *    2. 填入實際的資料庫與站台資訊
 *    3. 匯入資料庫結構（見 install/schema/）
 *    4. 建立管理員帳號（見 admin/gm_setup.php）
 * =============================================================
 */

// --- 資料庫連線 ---
$port       = 3306;
$DBHost     = 'localhost';
$DBUser     = 'root';
$DBPassword = '';
$DBName     = 'sa';

// --- 站台資訊 ---
if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://example.com/sw');
}
if (!defined('APP_SITE_NAME')) {
    define('APP_SITE_NAME', '踏雪笑傲');
}
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Taipei');
}

// --- 安裝狀態（config.local.php 存在即視為已安裝） ---
if (!defined('APP_INSTALLED')) {
    define('APP_INSTALLED', true);
}

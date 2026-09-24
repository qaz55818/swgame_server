<?php
/**
 * =============================================================
 *  踏雪笑傲 · 本機設定（由安裝程式自動產生，請勿任意修改）
 *  產生時間：本機開發環境
 * =============================================================
 */

$port       = 3306;
$DBHost     = 'localhost';
$DBUser     = 'root';
$DBPassword = 'ServBay.dev';
$DBName     = 'sa';

if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://home.dashashop.tw/sw');
}
if (!defined('APP_SITE_NAME')) {
    define('APP_SITE_NAME', '踏雪笑傲');
}
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Taipei');
}
if (!defined('APP_INSTALLED')) {
    define('APP_INSTALLED', true);
}

if (!defined('REMOTE_CRED_KEY')) {
    define('REMOTE_CRED_KEY', 'fe494d32de2353589dabd1ecc0b01565d64df60d2355b672cbfc730d8a38f7a4');
}

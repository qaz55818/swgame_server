<?php
/**
 * =============================================================
 *  踏雪笑傲 · 站台主設定
 *  -------------------------------------------------------------
 *  資料庫與站台資訊由「安裝程式」（install/index.php）寫入
 *  config.local.php（不進版控）。本檔僅保留未安裝時的開發預設值，
 *  因此同一份程式可安裝於不同機器而不需修改任何原始碼。
 * =============================================================
 */

// 載入安裝程式產生的本機設定（若存在，優先採用）
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// 從表單接收加密類型
$encryptionType = 'ascii'; // 預設值  md5 ascii
if (!isset($port))       { $port = 3306; }
if (!isset($DBHost))     { $DBHost = "localhost"; }
if (!isset($DBUser))     { $DBUser = "root"; }
if (!isset($DBPassword)) { $DBPassword = "ServBay.dev"; }
if (!isset($DBName))     { $DBName = "sa"; }

// 時區（由 config.local.php 提供；未設定則沿用 PHP 預設）
if (defined('APP_TIMEZONE') && APP_TIMEZONE !== '') {
    @date_default_timezone_set(APP_TIMEZONE);
}
//mysqldump -u root -p'123456' xo > xo_backup.sql
//mysql -u root -p'123456' xo < xo_backup.sql

// 對外公開網址（ECPay 綠界金流回呼 ReturnURL / OrderResultURL / ClientBackURL 使用）
// 本機開發若無公開網址，可註解此段，ecpay_config.php 會自動依目前網域偵測。
if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://home.dashashop.tw/sw');
}

// -------------------------------------------------------------
//  安裝狀態判定與導向
//  - config.local.php 或 install.lock 存在即視為「已安裝」。
//  - 尚未安裝時，自動將訪客導向安裝精靈（CLI 與安裝程式本身除外）。
// -------------------------------------------------------------
if (!defined('APP_INSTALLED')) {
    define('APP_INSTALLED', is_file(__DIR__ . '/config.local.php') || is_file(__DIR__ . '/install.lock'));
}

if (!APP_INSTALLED && PHP_SAPI !== 'cli' && !defined('APP_INSTALLING')) {
    if (is_file(__DIR__ . '/install/index.php')) {
        $appDir    = str_replace('\\', '/', __DIR__);
        $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? '')));
        $rel = '';
        if ($scriptDir !== '' && strpos($scriptDir, $appDir) === 0) {
            $sub = trim(substr($scriptDir, strlen($appDir)), '/');
            if ($sub !== '') {
                $rel = str_repeat('../', substr_count($sub, '/') + 1);
            }
        }
        header('Location: ' . $rel . 'install/index.php');
        exit;
    }
}

function redeemCard($code, $userId, $db) {
    // 檢查卡片代碼是否存在且有效
    $stmt = $db->prepare("SELECT * FROM cardrecord WHERE code = ? AND status = 1 AND endtime > NOW()");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $card = $stmt->get_result()->fetch_assoc();

    if ($card) {
        // 使用卡片
        $db->query("UPDATE cardrecord SET status = 0, used = 1 WHERE code = '$code'");
        
        // 為使用者增加點數
        $points = $card['pointcard'];
        $db->query("UPDATE users SET points = points + $points WHERE id = $userId");

        return "兌換成功！已新增 $points 點數。";
    } else {
        return "無效或已過期的卡片。";
    }
}


function isValidLandlineNumber($number) {
    return preg_match('/^(0\d{2,4})-?\d{7,8}$/', $number) === 1;
}


function isValidMobileNumber($number) {
    return preg_match('/^1[3-9]\d{9}$/', $number) === 1;
}


function generateIdNumber($regionCode, $randomNumber) {
 
    // 1. 出生年月日
    // 目前年份減去 $randomNumber 以計算出生年份
    $currentYear = (new DateTime())->format('Y');
    $birthYear = $currentYear - $randomNumber;
    $birthday = "$birthYear" . "0101"; // 設定為 1 月 1 日（可依照生日表調整）
    // 2. 個人序號（隨機範圍 001 到 999）
    $sequence = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    
    // 3. 組合代碼以計算校驗碼
    $id17 = $regionCode . $birthday . $sequence;
    
    // 4. 計算校驗碼
    $weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
    $checkDigits = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
    $sum = 0;
    for ($i = 0; $i < 17; $i++) {
        $sum += intval($id17[$i]) * $weights[$i];
    }
    $remainder = $sum % 11;
    $checkDigit = $checkDigits[$remainder];
    
    // 5. 組合完整代碼
    return $id17 . $checkDigit;
}

function generateMobileNumber() {
    // 以 1 開頭，接著是 3-9 之間的數字
    $prefix = '1' . rand(3, 9);
    
    // 再隨機 9 位數字
    $suffix = str_pad(rand(0, 999999999), 9, '0', STR_PAD_LEFT);
    
    // 組合成手機號碼
    return $prefix . $suffix;
}


function generateLandlineNumber() {
    // 隨機區碼（2-4 位）以 0 開頭
    $areaCode = '0' . str_pad(rand(10, 9999), rand(2, 4), '0', STR_PAD_LEFT);
    
    // 隨機本地號碼（7-8 位）
    $localNumber = str_pad(rand(0, 99999999), rand(7, 8), '0', STR_PAD_LEFT);
    
    // 組合成市內電話
    return $areaCode . '-' . $localNumber;
}

// 讀取儲值系統設定（支付方式、金額方案、自訂金額範圍）
function loadRechargeSettings($Link) {
    $result = [
        'methods' => [],
        'tiers'   => [],
        'config'  => ['min_amount' => 50, 'max_amount' => 100000],
        'gateway' => [
            'ecpay_env'         => 'stage',
            'ecpay_merchant_id' => '',
            'ecpay_hash_key'    => '',
            'ecpay_hash_iv'     => '',
            'ecpay_action_url'  => '',
        ],
    ];
    if (!$Link) {
        return $result;
    }
    $res = mysqli_query($Link, "SELECT `id`, `setting_type`, `setting_key`, `display_name`, `icon`, `description`, `amount`, `bonus_percent`, `enabled`, `sort_order` FROM `recharge_settings`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rowEnabled = ((int)$row['enabled'] === 1);
            if ($row['setting_type'] === 'method') {
                $result['methods'][$row['setting_key']] = [
                    'name' => $row['display_name'],
                    'desc' => $row['description'],
                    'icon' => $row['icon'],
                    'enabled' => $rowEnabled,
                    'sort' => (int)$row['sort_order'],
                ];
            } elseif ($row['setting_type'] === 'amount') {
                $result['tiers'][] = [
                    'id' => (int)$row['id'],
                    'amount' => (int)$row['amount'],
                    'bonus_percent' => (int)$row['bonus_percent'],
                    'enabled' => $rowEnabled,
                    'sort' => (int)$row['sort_order'],
                ];
            } elseif ($row['setting_type'] === 'config') {
                if (strpos($row['setting_key'], 'ecpay_') === 0) {
                    // 金流參數以字串儲存於 description 欄位
                    $result['gateway'][$row['setting_key']] = (string)$row['description'];
                } else {
                    $result['config'][$row['setting_key']] = (int)$row['amount'];
                }
            }
        }
    }
    usort($result['tiers'], function ($a, $b) {
        return $a['amount'] <=> $b['amount'];
    });
    return $result;
}
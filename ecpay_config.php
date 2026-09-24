<?php
/**
 * ==============================================================
 *  ECPay 綠界科技 全方位金流 AIO 設定與工具函式
 *  --------------------------------------------------------------
 *  付款方式對應：
 *    credit_card   -> ChoosePayment = Credit（線上刷卡）
 *    bank_transfer -> ChoosePayment = ATM   （銀行轉帳 / 虛擬帳號）
 *    cvs           -> ChoosePayment = CVS   （超商代碼繳費）
 *    crypto        -> 綠界不支援虛擬貨幣，維持人工/示意流程
 *
 *  參考：ECPay AIO 金流（CMV-SHA256 / EncryptType=1）
 *  來源：ECPay API Skill guides/01-payment-aio.md（SNAPSHOT 2026-03）
 * ==============================================================
 */

/**
 * 讀取後台設定的綠界金流參數（recharge_settings.setting_type='config'）
 * 金流參數以字串儲存於 `description` 欄位，key 以 ecpay_ 為前綴。
 */
function ecpay_db_settings()
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    $loaded = [];

    global $DBHost, $DBUser, $DBPassword, $DBName;
    if (!isset($DBHost) || $DBHost === '') {
        return $loaded;
    }

    $link = @mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
    if (!$link) {
        return $loaded;
    }
    @mysqli_set_charset($link, 'utf8');
    $res = @mysqli_query(
        $link,
        "SELECT `setting_key`, `description` FROM `recharge_settings`
          WHERE `setting_type` = 'config' AND `setting_key` LIKE 'ecpay\\_%'"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $loaded[$row['setting_key']] = (string)$row['description'];
        }
    }
    mysqli_close($link);
    return $loaded;
}

// 後台設定值（未設定時採下方預設）
$__ecpayDb = ecpay_db_settings();

if (!defined('ECPAY_MERCHANT_ID')) {
    // 優先採用後台「儲值系統設定」；未設定時使用綠界公開測試帳號
    $__ecpayEnv = (($__ecpayDb['ecpay_env'] ?? '') === 'prod') ? 'prod' : 'stage';

    define('ECPAY_ENV', $__ecpayEnv);
    define('ECPAY_MERCHANT_ID', (($__ecpayDb['ecpay_merchant_id'] ?? '') !== '') ? $__ecpayDb['ecpay_merchant_id'] : '3002607');
    define('ECPAY_HASH_KEY',    (($__ecpayDb['ecpay_hash_key'] ?? '')    !== '') ? $__ecpayDb['ecpay_hash_key']    : 'pwFHCqoQZGmho4w6');
    define('ECPAY_HASH_IV',     (($__ecpayDb['ecpay_hash_iv'] ?? '')     !== '') ? $__ecpayDb['ecpay_hash_iv']     : 'EkRm7iFT261dpevs');
}

// 對外網址（ReturnURL / PaymentInfoURL / OrderResultURL 必須可被綠界從 80/443 存取）
// 可在 config.php 先 define('SITE_URL', 'https://你的網域/子目錄'); 覆寫；否則自動偵測。
// 自動偵測會同時帶入應用程式所在子目錄（例如 /sw），避免回呼網址缺少路徑導致 404。
if (!defined('ECPAY_SITE_URL')) {
    if (defined('SITE_URL') && SITE_URL !== '') {
        $__site = SITE_URL;
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

        // 取得目前程式所在目錄（含子目錄），例如 /sw
        $__dir = '';
        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $__dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        } elseif (!empty($_SERVER['PHP_SELF'])) {
            $__dir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
        }
        if ($__dir === '/' || $__dir === '.') {
            $__dir = '';
        }

        $__site = ($__https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $__dir;
    } else {
        $__site = 'http://localhost';
    }
    define('ECPAY_SITE_URL', rtrim($__site, '/'));
}

if (!defined('ECPAY_ACTION_URL')) {
    // 後台可自訂付款 API 網址；留空則依環境自動選用綠界官方端點
    $__ecpayCustomAction = trim((string)($__ecpayDb['ecpay_action_url'] ?? ''));
    if ($__ecpayCustomAction !== '') {
        define('ECPAY_ACTION_URL', $__ecpayCustomAction);
    } else {
        define('ECPAY_ACTION_URL', ECPAY_ENV === 'prod'
            ? 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5'
            : 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5');
    }
}

// 各儲值方式要使用的綠界付款方式
function ecpay_method_map()
{
    return [
        'credit_card'   => 'Credit',
        'bank_transfer' => 'ATM',
        'cvs'           => 'CVS',
        // 'crypto' 綠界無對應金流，不列入自動跳轉
    ];
}

/**
 * 綠界 ecpayUrlEncode：urlencode -> strtolower -> .NET 字元還原
 * （CMV 專用；不可與 AES 的 aesUrlEncode 混用）
 */
function ecpay_url_encode($source)
{
    $encoded = strtolower(urlencode($source));
    return str_replace(
        ['%2d', '%5f', '%2e', '%21', '%2a', '%28', '%29'],
        ['-', '_', '.', '!', '*', '(', ')'],
        $encoded
    );
}

/**
 * 產生 CheckMacValue（SHA256）
 * 規則：排除 CheckMacValue -> key 不分大小寫排序 -> 串接 HashKey/HashIV -> urlencode -> sha256 -> 大寫
 */
function ecpay_generate_cmv(array $params)
{
    unset($params['CheckMacValue']);
    uksort($params, 'strcasecmp');

    $combined = 'HashKey=' . ECPAY_HASH_KEY;
    foreach ($params as $key => $value) {
        $combined .= '&' . $key . '=' . $value;
    }
    $combined .= '&HashIV=' . ECPAY_HASH_IV;

    return strtoupper(hash('sha256', ecpay_url_encode($combined)));
}

/**
 * 驗證 CheckMacValue（timing-safe，禁止使用 == 比對）
 */
function ecpay_verify_cmv(array $post)
{
    if (empty($post['CheckMacValue'])) {
        return false;
    }
    $received = strtoupper((string)$post['CheckMacValue']);
    $computed = ecpay_generate_cmv($post);
    return hash_equals($computed, $received);
}

/**
 * 產生自動送出表單 HTML（瀏覽器會自動前往綠界付款頁）
 * ⚠️ 禁止使用 iframe 嵌入綠界付款頁
 */
function ecpay_build_form(array $input)
{
    $input['CheckMacValue'] = ecpay_generate_cmv($input);

    $html  = '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="utf-8">';
    $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    $html .= '<title>正在前往綠界安全付款頁…</title></head>';
    $html .= '<body style="font-family:sans-serif;background:#0a0e1a;color:#e2e8f0;text-align:center;padding:60px 20px;">';
    $html .= '<p style="font-size:15px;">正在為您導向綠界安全付款頁面，請稍候…</p>';
    $html .= '<form id="ecpay-form" method="post" action="' . htmlspecialchars(ECPAY_ACTION_URL, ENT_QUOTES) . '">';
    foreach ($input as $key => $value) {
        $html .= '<input type="hidden" name="' . htmlspecialchars($key, ENT_QUOTES) . '" value="' . htmlspecialchars($value, ENT_QUOTES) . '">';
    }
    $html .= '</form>';
    $html .= '<noscript><p>若畫面未自動跳轉，請點擊下方按鈕：</p>';
    $html .= '<button type="submit" form="ecpay-form" style="padding:10px 22px;border:0;border-radius:8px;background:#be123c;color:#fff;font-weight:bold;cursor:pointer;">前往綠界付款</button></noscript>';
    $html .= '<script type="text/javascript">document.getElementById("ecpay-form").submit();</script>';
    $html .= '</body></html>';

    return $html;
}

/**
 * 對外回呼位址（ReturnURL / PaymentInfoURL / OrderResultURL）
 */
function ecpay_callback_url($file)
{
    return ECPAY_SITE_URL . '/' . ltrim($file, '/');
}

/**
 * 建立綠界 AIO 付款參數（依儲值方式產生對應的付款設定）
 *
 * @param string $orderNo   特店交易編號（永久唯一、英數、<=20 字元）
 * @param string $methodKey 儲值方式鍵值（credit_card / bank_transfer / cvs）
 * @param int    $amount    付款金額
 * @return array|null 綠界不支援的方式回傳 null
 */
function ecpay_build_recharge_input($orderNo, $methodKey, $amount)
{
    $map = ecpay_method_map();
    $choosePayment = $map[$methodKey] ?? '';
    if ($choosePayment === '') {
        return null;
    }

    $input = [
        'MerchantID'        => ECPAY_MERCHANT_ID,
        'MerchantTradeNo'   => $orderNo,
        'MerchantTradeDate' => date('Y/m/d H:i:s'), // UTC+8 格式
        'PaymentType'       => 'aio',
        'TotalAmount'       => (int)$amount,
        'TradeDesc'         => '踏雪笑傲元寶儲值',
        'ItemName'          => '踏雪笑傲元寶儲值 x1',
        'ReturnURL'         => ecpay_callback_url('recharge_notify.php'),
        'ChoosePayment'     => $choosePayment,
        'EncryptType'       => 1,
        'NeedExtraPaidInfo' => 'Y',
        'ClientBackURL'     => ecpay_callback_url('recharge.php'),
    ];

    if ($choosePayment === 'Credit') {
        // 連結信用卡：付款完成後導回結果頁
        $input['OrderResultURL'] = ecpay_callback_url('recharge_result.php');
    } elseif ($choosePayment === 'ATM') {
        $input['ExpireDate'] = 7; // 繳費期限（天）
        $input['PaymentInfoURL'] = ecpay_callback_url('recharge_notify.php');
    } elseif ($choosePayment === 'CVS') {
        $input['StoreExpireDate'] = 4320; // 繳費期限（分鐘）= 3 天
        $input['PaymentInfoURL'] = ecpay_callback_url('recharge_notify.php');
    }

    return $input;
}

/**
 * 各儲值方式的訂單有效期限（秒）
 * 需與綠界送出的 ExpireDate / StoreExpireDate 保持一致。
 */
function recharge_expire_seconds($methodKey)
{
    $map = [
        'credit_card'   => 1800,   // 線上刷卡：30 分鐘
        'bank_transfer' => 604800, // 銀行轉帳（ATM）：7 天
        'cvs'           => 259200, // 超商繳費：3 天
        'crypto'        => 1800,   // 虛擬貨幣：30 分鐘
    ];
    return isset($map[$methodKey]) ? (int)$map[$methodKey] : 86400;
}

/**
 * 計算訂單失效時間（Unix timestamp），無法解析時回傳 0
 */
function recharge_order_expires_ts($createdAt, $methodKey)
{
    $ts = strtotime((string)$createdAt);
    if (!$ts) {
        return 0;
    }
    return $ts + recharge_expire_seconds($methodKey);
}

/**
 * 惰性過期：將逾時未付款的 pending 訂單標記為 expired。
 * 由查詢 API 與付款動作呼叫，避免需要額外排程。
 *
 * @param mysqli $Link
 * @param int    $uid 指定會員（0 = 全部會員）
 */
function recharge_expire_overdue_orders($Link, $uid = 0)
{
    if (!$Link) {
        return;
    }
    $sql = "UPDATE `recharge_orders`
               SET `status` = 'expired'
             WHERE `status` = 'pending'
               AND (
                     (`method_key` IN ('credit_card','crypto') AND `created_at` < NOW() - INTERVAL 30 MINUTE)
                  OR (`method_key` = 'bank_transfer'                   AND `created_at` < NOW() - INTERVAL 7 DAY)
                  OR (`method_key` = 'cvs'                             AND `created_at` < NOW() - INTERVAL 3 DAY)
                  OR (`method_key` NOT IN ('credit_card','crypto','bank_transfer','cvs') AND `created_at` < NOW() - INTERVAL 1 DAY)
               )";
    if ($uid > 0) {
        $sql .= " AND `uid` = ?";
        $stmt = $Link->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
    } else {
        $Link->query($sql);
    }
}

/**
 * 將儲值金額入帳為「點數」至會員錢包（user_wallet.points）。
 * 點數可於兌換中心轉換為遊戲元寶；元寶才會同步寫入 point 表 (aid=23)。
 * 重複呼叫時務必先做訂單狀態防護，避免重複入帳。
 *
 * @param mysqli $Link
 * @param int    $uid    使用者 ID
 * @param int    $points 入帳點數
 * @param int    $aid    保留參數（相容舊呼叫）
 * @return bool
 */
function recharge_credit_points($Link, $uid, $points, $aid = 23)
{
    if (!$Link || $uid <= 0 || $points <= 0) {
        return false;
    }
    require_once __DIR__ . '/currency.php';
    $res = currency_add($Link, $uid, 'points', (int)$points, 'recharge', '', '儲值入帳點數', 'system');
    return !empty($res['ok']);
}

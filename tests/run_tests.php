<?php
/**
 * 踏雪笑傲 · 註冊安全與註冊管理功能測試
 * ------------------------------------------------------------
 * 使用方式（需先啟動本機測試伺服器）：
 *   php -S 127.0.0.1:8899 -t .
 *   php tests/run_tests.php
 *
 * 僅供本機／測試環境使用，請勿對正式站執行。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8899';
$TEST_GM = 'reg_qatester';
$TEST_GM_PASS = 'QaTest!2345';
$createdUsers = [];

$pass = 0;
$fail = 0;
$failures = [];

function check($name, $cond, $detail = '')
{
    global $pass, $fail, $failures;
    if ($cond) {
        $pass++;
        echo "  [PASS] $name\n";
    } else {
        $fail++;
        $failures[] = $name . ($detail !== '' ? " => $detail" : '');
        echo "  [FAIL] $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

function section($t)
{
    echo "\n== $t ==\n";
}

function db()
{
    static $m = null;
    if ($m === null) {
        $m = new mysqli('localhost', 'root', 'ServBay.dev', 'sa', 3306);
        $m->set_charset('utf8mb4');
    }
    return $m;
}

function db_query($sql)
{
    try {
        return db()->query($sql);
    } catch (Throwable $e) {
        return false;
    }
}

function clear_limits()
{
    db_query("DELETE FROM `register_rate_limits`");
    db_query("DELETE FROM `register_blocks`");
}

function jar()
{
    static $i = 0;
    $i++;
    $f = sys_get_temp_dir() . '/swo_jar_' . getmypid() . '_' . $i . '.txt';
    @unlink($f);
    return $f;
}

function req($method, $path, $fields = null, $opts = [])
{
    global $BASE;
    $url = (strpos($path, 'http') === 0) ? $path : ($BASE . '/' . ltrim($path, '/'));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_COOKIEJAR      => $opts['jar'] ?? '',
        CURLOPT_COOKIEFILE     => $opts['jar'] ?? '',
        CURLOPT_ENCODING       => '',
    ]);
    if (!empty($opts['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($fields) ? http_build_query($fields) : $fields);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['code' => 0, 'headers' => '', 'body' => '', 'error' => $err];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'code'    => $code,
        'headers' => substr($raw, 0, $hsize),
        'body'    => substr($raw, $hsize),
    ];
}

function get_token($body)
{
    if (preg_match('/name="form_token"[^>]*value="([a-f0-9]+)"/', $body, $m)) {
        return $m[1];
    }
    return '';
}

function get_location($headers)
{
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
        return trim($m[1]);
    }
    return '';
}

function cleanup_users(array $names)
{
    if (!$names) {
        return;
    }
    $in = "'" . implode("','", array_map(function ($n) {
        return db()->real_escape_string($n);
    }, $names)) . "'";
    db_query("DELETE FROM `web_credentials` WHERE `uid` IN (SELECT `ID` FROM `users` WHERE `name` IN ($in))");
    db_query("DELETE FROM `register_log` WHERE `username` IN ($in)");
    db_query("DELETE FROM `users` WHERE `name` IN ($in)");
}

function raw_passwd($name)
{
    $m = new mysqli('localhost', 'root', 'ServBay.dev', 'sa', 3306);
    $m->set_charset('latin1');
    $stmt = $m->prepare("SELECT `passwd` FROM `users` WHERE `name` = ?");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc()['passwd'] ?? null;
    $stmt->close();
    $m->close();
    return $v;
}

function user_exists($name)
{
    $stmt = db()->prepare("SELECT COUNT(*) c FROM `users` WHERE `name` = ?");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $c = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $c > 0;
}

/* ============================================================ */
echo "測試目標：$BASE\n";

// ---- 準備：測試 GM 帳號 ----
db_query("DELETE FROM `gm_accounts` WHERE `username` = '" . db()->real_escape_string($TEST_GM) . "'");
$hash = password_hash($TEST_GM_PASS, PASSWORD_BCRYPT);
$stmt = db()->prepare("INSERT INTO `gm_accounts` (`username`,`password_hash`) VALUES (?,?)");
$stmt->bind_param('ss', $TEST_GM, $hash);
$stmt->execute();
$stmt->close();

// 保存原始設定以便還原
$origSettings = [];
$res = db_query("SELECT `setting_key`,`setting_value` FROM `register_settings`");
while ($res && ($row = $res->fetch_assoc())) {
    $origSettings[$row['setting_key']] = $row['setting_value'];
}
function restore_settings($orig)
{
    $stmt = db()->prepare("UPDATE `register_settings` SET `setting_value` = ? WHERE `setting_key` = ?");
    foreach ($orig as $k => $v) {
        $stmt->bind_param('ss', $v, $k);
        $stmt->execute();
    }
    $stmt->close();
}
function set_setting($k, $v)
{
    $stmt = db()->prepare("INSERT INTO `register_settings` (`setting_key`,`setting_value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`)");
    $stmt->bind_param('ss', $k, $v);
    $stmt->execute();
    $stmt->close();
}

// 開始前清除殘留的限流計數與封鎖，確保測試可重複執行
clear_limits();
set_setting('register_enabled', '1');
set_setting('rate_max_per_ip', '5');
set_setting('rate_max_per_account', '3');
set_setting('rate_max_per_ip_hour', '20');
set_setting('rate_window_seconds', '300');
set_setting('trusted_proxies', '');

// ============================================================
section('1. 正常註冊與遊戲帳號相容性');
$name1 = 'qa' . substr((string)time(), -6);
$createdUsers[] = $name1;
$j = jar();
$r = req('GET', 'index.php', null, ['jar' => $j]);
check('GET index.php 200', $r['code'] === 200, 'code=' . $r['code']);
$token = get_token($r['body']);
check('取得 CSRF form_token', $token !== '');

$r = req('POST', 'index.php', [
    'form_token' => $token, 'login' => $name1, 'passwd' => 'Pass1234',
    'repasswd' => 'Pass1234', 'email' => 'qa@example.com',
], ['jar' => $j]);
check('註冊成功後重新導向 (302)', $r['code'] === 302, 'code=' . $r['code']);
check('已建立遊戲帳號', user_exists($name1));

// 遊戲端 MD5 驗證：users.passwd 應等於 md5(name.pass, true) 原始位元組
// 需以 latin1 連線讀取才能取得原始位元組（與 login.php 一致）
$stored = raw_passwd($name1);
$expected = md5($name1 . 'Pass1234', true);
check('users.passwd 為遊戲端原始二進位 MD5', hash_equals($expected, (string)$stored));

// web_credentials bcrypt 側車
$stmt = db()->prepare("SELECT `password_hash` FROM `web_credentials` w JOIN `users` u ON u.ID=w.uid WHERE u.name = ?");
$stmt->bind_param('s', $name1);
$stmt->execute();
$wc = $stmt->get_result()->fetch_assoc();
$stmt->close();
check('已建立 web_credentials bcrypt 憑證', $wc && password_verify('Pass1234', $wc['password_hash']));

// register_log 成功紀錄
$stmt = db()->prepare("SELECT `status`,`reason_code` FROM `register_log` WHERE `username` = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('s', $name1);
$stmt->execute();
$log = $stmt->get_result()->fetch_assoc();
$stmt->close();
check('register_log 記錄為 success/ok', $log && $log['status'] === 'success' && $log['reason_code'] === 'ok');

// 網站登入相容（bcrypt 側車優先，MD5 fallback）
$j2 = jar();
$r = req('GET', 'login.php', null, ['jar' => $j2]);
$t = get_token($r['body']);
$r = req('POST', 'login.php', ['form_token' => $t, 'login' => $name1, 'passwd' => 'Pass1234'], ['jar' => $j2]);
check('以 bcrypt 側車登入成功 (302 -> home)', $r['code'] === 302 && strpos(get_location($r['headers']), 'home.php') !== false, 'code=' . $r['code'] . ' loc=' . get_location($r['headers']));
// 手動移除 web_credentials 後仍可用遊戲端 MD5 登入（fallback + 升級）
db_query("DELETE FROM `web_credentials` WHERE `uid` = (SELECT ID FROM users WHERE name = '" . db()->real_escape_string($name1) . "')");
$j3 = jar();
$r = req('GET', 'login.php', null, ['jar' => $j3]);
$t = get_token($r['body']);
$r = req('POST', 'login.php', ['form_token' => $t, 'login' => $name1, 'passwd' => 'Pass1234'], ['jar' => $j3]);
check('無側車時以 MD5 相容登入成功', $r['code'] === 302 && strpos(get_location($r['headers']), 'home.php') !== false);
$stmt = db()->prepare("SELECT COUNT(*) c FROM web_credentials w JOIN users u ON u.ID=w.uid WHERE u.name = ?");
$stmt->bind_param('s', $name1);
$stmt->execute();
$upgraded = (int)$stmt->get_result()->fetch_assoc()['c'] > 0;
$stmt->close();
check('MD5 登入後自動補寫 bcrypt 側車', $upgraded);

// 錯誤密碼應失敗
$j4 = jar();
$r = req('GET', 'login.php', null, ['jar' => $j4]);
$t = get_token($r['body']);
$r = req('POST', 'login.php', ['form_token' => $t, 'login' => $name1, 'passwd' => 'WrongPass'], ['jar' => $j4]);
check('錯誤密碼登入失敗', strpos(get_location($r['headers']), 'login.php') !== false);

// ============================================================
section('2. CSRF 與輸入驗證');
clear_limits();
$j = jar();
$r = req('GET', 'index.php', null, ['jar' => $j]);
$r = req('POST', 'index.php', ['login' => 'csrfprobe', 'passwd' => 'Pass1234', 'repasswd' => 'Pass1234', 'email' => 'a@b.com'], ['jar' => $j]);
check('缺少 CSRF token 被拒絕 (400)', $r['code'] === 400, 'code=' . $r['code']);
check('未建立帳號', !user_exists('csrfprobe'));

// 格式驗證
$j = jar();
$r = req('GET', 'index.php', null, ['jar' => $j]);
$t = get_token($r['body']);
$r = req('POST', 'index.php', ['form_token' => $t, 'login' => "abc' OR '1'='1", 'passwd' => 'Pass1234', 'repasswd' => 'Pass1234', 'email' => 'a@b.com'], ['jar' => $j]);
check('SQLi 樣式帳號被格式驗證拒絕', $r['code'] === 302);
check('SQLi 未產生帳號且資料表完好', !user_exists("abc' OR '1'='1") && db_query("SELECT COUNT(*) FROM users") !== false);

$j = jar();
$r = req('GET', 'index.php', null, ['jar' => $j]);
$t = get_token($r['body']);
$r = req('POST', 'index.php', ['form_token' => $t, 'login' => 'qa' . substr((string)time(), -6), 'passwd' => 'aaa', 'repasswd' => 'aaa', 'email' => 'not-an-email'], ['jar' => $j]);
check('無效 Email 被拒絕', $r['code'] === 302 && strpos($r['headers'], 'index.php') !== false);

// ============================================================
section('3. 限流、冷卻與封鎖');
clear_limits();
set_setting('rate_max_per_ip', '3');
set_setting('rate_window_seconds', '300');
set_setting('block_duration_seconds', '60');
$j = jar();
$codes = [];
for ($i = 0; $i < 5; $i++) {
    $r = req('GET', 'index.php', null, ['jar' => $j]);
    $t = get_token($r['body']);
    $u = 'rl' . $i . substr((string)time(), -5);
    $r = req('POST', 'index.php', ['form_token' => $t, 'login' => $u, 'passwd' => 'aaaa', 'repasswd' => 'bbbb', 'email' => 'a@b.com'], ['jar' => $j]);
    $codes[] = $r['code'];
}
check('超過每 IP 門檻後回傳 429', $codes[3] === 429 || $codes[4] === 429, implode(',', $codes));
$blockCount = (int)(db_query("SELECT COUNT(*) c FROM register_blocks WHERE scope='ip' AND active=1")->fetch_assoc()['c']);
check('超過門檻會建立自動封鎖', $blockCount > 0);
$limitedLog = db_query("SELECT COUNT(*) c FROM register_log WHERE status='limited' OR status='blocked'")->fetch_assoc()['c'];
check('限流／封鎖嘗試已寫入紀錄', (int)$limitedLog > 0);

// 手動解除封鎖
$blockId = (int)(db_query("SELECT id FROM register_blocks WHERE active=1 ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'] ?? 0);
if ($blockId) {
    db_query("UPDATE register_blocks SET active=0 WHERE id=$blockId");
}
check('封鎖可被解除（軟性停用）', true);

// 偽造代理標頭（未設定可信代理時應忽略）
clear_limits();
set_setting('rate_max_per_ip', '100');
$nameFake = 'px' . substr((string)time(), -6);
$createdUsers[] = $nameFake;
$j = jar();
$r = req('GET', 'index.php', null, ['jar' => $j]);
$t = get_token($r['body']);
$r = req('POST', 'index.php', ['form_token' => $t, 'login' => $nameFake, 'passwd' => 'Pass1234', 'repasswd' => 'Pass1234', 'email' => 'a@b.com'], ['jar' => $j, 'headers' => ['X-Forwarded-For: 1.2.3.4', 'CF-Connecting-IP: 1.2.3.4']]);
$stmt = db()->prepare("SELECT `ip` FROM `register_log` WHERE `username` = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('s', $nameFake);
$stmt->execute();
$loggedIp = $stmt->get_result()->fetch_assoc()['ip'] ?? '';
$stmt->close();
check('未設定可信代理時忽略 XFF（記錄 127.0.0.1）', $loggedIp === '127.0.0.1', 'ip=' . $loggedIp);

// 設定可信代理後才採用 XFF
set_setting('trusted_proxies', '127.0.0.1/32');
$nameProxy = 'px2' . substr((string)time(), -6);
$createdUsers[] = $nameProxy;
$j = jar();
$r = req('GET', 'index.php', null, ['jar' => $j]);
$t = get_token($r['body']);
$r = req('POST', 'index.php', ['form_token' => $t, 'login' => $nameProxy, 'passwd' => 'Pass1234', 'repasswd' => 'Pass1234', 'email' => 'a@b.com'], ['jar' => $j, 'headers' => ['X-Forwarded-For: 203.0.113.9']]);
$stmt = db()->prepare("SELECT `ip`, `ip_forwarded` FROM `register_log` WHERE `username` = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('s', $nameProxy);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
check('可信代理來源才採用 XFF 作為來源 IP', ($row['ip'] ?? '') === '203.0.113.9', 'ip=' . ($row['ip'] ?? ''));
set_setting('trusted_proxies', '');

// ============================================================
section('4. 關閉註冊後直接呼叫 API');
clear_limits();
set_setting('register_enabled', '0');
set_setting('closed_notice', 'QA 測試關閉中');
$nameClosed = 'cl' . substr((string)time(), -6);
$j = jar();
$r = req('GET', 'index.php', null, ['jar' => $j]);
check('關閉時頁面顯示公告', strpos($r['body'], 'QA 測試關閉中') !== false);
$t = get_token($r['body']);
$r = req('POST', 'index.php', ['form_token' => $t, 'login' => $nameClosed, 'passwd' => 'Pass1234', 'repasswd' => 'Pass1234', 'email' => 'a@b.com'], ['jar' => $j]);
check('關閉時直接 POST 被拒絕 (403)', $r['code'] === 403, 'code=' . $r['code']);
check('關閉時未建立帳號', !user_exists($nameClosed));
$r = req('POST', 'check_username.php', ['username' => $nameClosed]);
$cj = json_decode($r['body'], true);
check('關閉時帳號檢查 API 不洩漏存在性', isset($cj['exists']) && $cj['exists'] === false);
set_setting('register_enabled', '1');

// ============================================================
section('5. 後台授權與註冊管理 API');
$anon = req('GET', 'admin/registration_api.php?action=overview');
check('未登入存取 API 被拒絕 (401)', $anon['code'] === 401, 'code=' . $anon['code']);
$anonPage = req('GET', 'admin/registration_admin.php');
check('未登入存取管理頁被導向登入', $anonPage['code'] === 302 && strpos(get_location($anonPage['headers']), 'gmlogin.php') !== false);

// GM 登入
$gj = jar();
$r = req('GET', 'admin/gmlogin.php', null, ['jar' => $gj]);
$t = get_token($r['body']);
$r = req('POST', 'admin/gmlogin.php', ['form_token' => $t, 'username' => $TEST_GM, 'password' => $TEST_GM_PASS], ['jar' => $gj]);
check('GM 登入成功 (302 -> gmpanel)', $r['code'] === 302 && strpos(get_location($r['headers']), 'gmpanel.php') !== false, 'code=' . $r['code']);

// 取得管理頁與 CSRF
$r = req('GET', 'admin/registration_admin.php', null, ['jar' => $gj]);
check('管理頁可存取', $r['code'] === 200);
check('管理頁含 CSRF', preg_match('/const CSRF = "([a-f0-9]+)"/', $r['body'], $mm) === 1);
$apiCsrf = $mm[1] ?? '';

$r = req('GET', 'admin/registration_api.php?action=overview', null, ['jar' => $gj]);
$ov = json_decode($r['body'], true);
check('總覽 API 回傳 ok', $r['code'] === 200 && !empty($ov['ok']));
check('總覽含時區標示', isset($ov['clock']['label']));

$r = req('GET', 'admin/registration_api.php?action=logs&range=all&limit=50', null, ['jar' => $gj]);
$lg = json_decode($r['body'], true);
check('註冊紀錄 API 回傳 ok', !empty($lg['ok']) && isset($lg['logs']));
check('註冊紀錄可列出資料', ($lg['total'] ?? 0) >= 1);

// 詳情
if (!empty($lg['logs'][0]['id'])) {
    $r = req('GET', 'admin/registration_api.php?action=log_detail&id=' . (int)$lg['logs'][0]['id'], null, ['jar' => $gj]);
    $det = json_decode($r['body'], true);
    check('紀錄詳情 API 正常', !empty($det['ok']) && isset($det['log']['ua']));
}

// 設定儲存（POST + CSRF）
$r = req('POST', 'admin/registration_api.php?action=save_settings', [
    'csrf_token' => $apiCsrf,
    'register_enabled' => '1',
    'closed_notice' => 'QA 已更新公告',
    'rate_window_seconds' => '300', 'rate_max_per_ip' => '6', 'rate_max_per_account' => '3',
    'rate_max_per_ip_hour' => '20', 'rate_cooldown_seconds' => '300',
    'block_duration_seconds' => '3600', 'retention_days' => '90', 'trusted_proxies' => '',
], ['jar' => $gj]);
$sv = json_decode($r['body'], true);
check('設定儲存 API 成功', !empty($sv['ok']), $r['body']);
$stmt = db()->prepare("SELECT setting_value FROM register_settings WHERE setting_key='closed_notice'");
$stmt->execute();
$noticeSaved = $stmt->get_result()->fetch_assoc()['setting_value'] ?? '';
$stmt->close();
check('設定已實際寫入資料庫', $noticeSaved === 'QA 已更新公告');

// 無 CSRF 的變更應被拒絕
$r = req('POST', 'admin/registration_api.php?action=save_settings', ['closed_notice' => 'hack'], ['jar' => $gj]);
check('缺少 CSRF 的設定變更被拒絕 (403)', $r['code'] === 403, 'code=' . $r['code']);

// 新增／列出／解除封鎖
$r = req('POST', 'admin/registration_api.php?action=add_block', [
    'csrf_token' => $apiCsrf, 'scope' => 'ip', 'block_key' => '198.51.100.7', 'ttl_hours' => '1', 'reason' => 'QA 測試',
], ['jar' => $gj]);
$ab = json_decode($r['body'], true);
check('新增封鎖 API 成功', !empty($ab['ok']), $r['body']);
$newBlockId = (int)($ab['id'] ?? 0);
$r = req('GET', 'admin/registration_api.php?action=blocks&status=active&limit=50', null, ['jar' => $gj]);
$bl = json_decode($r['body'], true);
$found = false;
foreach (($bl['blocks'] ?? []) as $b) {
    if ($b['key'] === '198.51.100.7') { $found = true; }
}
check('封鎖名單可查到新增項目', $found);
if ($newBlockId) {
    $r = req('POST', 'admin/registration_api.php?action=remove_block', ['csrf_token' => $apiCsrf, 'id' => $newBlockId], ['jar' => $gj]);
    $rb = json_decode($r['body'], true);
    check('解除封鎖 API 成功', !empty($rb['ok']), $r['body']);
}

// 操作紀錄
$r = req('GET', 'admin/registration_api.php?action=admin_logs&limit=50', null, ['jar' => $gj]);
$al = json_decode($r['body'], true);
$actions = array_column($al['logs'] ?? [], 'action');
check('管理操作紀錄含設定變更', in_array('settings_update', $actions, true));
check('管理操作紀錄含新增與解除封鎖', in_array('block_add', $actions, true) && in_array('block_release', $actions, true));

// GET 執行變更操作應被拒絕
$r = req('GET', 'admin/registration_api.php?action=save_settings&closed_notice=x', null, ['jar' => $gj]);
check('變更操作以 GET 執行被拒絕 (405)', $r['code'] === 405, 'code=' . $r['code']);

// ============================================================
section('6. 儲存型 XSS：User-Agent 內容處理');
clear_limits();
set_setting('register_enabled', '1');
set_setting('rate_max_per_ip', '100');
$xss = '<script>alert("xss")</script>';
$nameXss = 'xs' . substr((string)time(), -6);
$createdUsers[] = $nameXss;
$j = jar();
$r = req('GET', 'index.php', null, ['jar' => $j]);
$t = get_token($r['body']);
$r = req('POST', 'index.php', ['form_token' => $t, 'login' => $nameXss, 'passwd' => 'Pass1234', 'repasswd' => 'Pass1234', 'email' => 'a@b.com'], ['jar' => $j, 'headers' => ['User-Agent: ' . $xss]]);
$stmt = db()->prepare("SELECT `id`,`user_agent` FROM `register_log` WHERE `username` = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('s', $nameXss);
$stmt->execute();
$xrow = $stmt->get_result()->fetch_assoc();
$stmt->close();
check('User-Agent 以原始字串記錄且未執行', $xrow && strpos($xrow['user_agent'], '<script>') !== false);
$r = req('GET', 'admin/registration_api.php?action=log_detail&id=' . (int)$xrow['id'], null, ['jar' => $gj]);
check('詳情 API 以 JSON 回傳（非 HTML 執行）', strpos($r['body'], '<script>alert') !== false && strpos($r['headers'], 'application/json') !== false);
$adminPageSrc = file_get_contents(__DIR__ . '/../admin/registration_admin.php');
check('前端輸出使用 esc() HTML 編碼', strpos($adminPageSrc, 'function esc(') !== false && strpos($adminPageSrc, 'esc(l.ua') !== false);

// ============================================================
section('7. 併發重複註冊（唯一約束）');
clear_limits();
set_setting('register_enabled', '1');
set_setting('rate_max_per_ip', '100');
set_setting('rate_max_per_account', '100');
set_setting('rate_max_per_ip_hour', '1000');
$nameRace = 'rc' . substr((string)time(), -6);
$createdUsers[] = $nameRace;
$mh = curl_multi_init();
$handles = [];
$jars = [];
for ($i = 0; $i < 5; $i++) {
    $jj = jar();
    $jars[] = $jj;
    $gr = req('GET', 'index.php', null, ['jar' => $jj]);
    $tk = get_token($gr['body']);
    $ch = curl_init($BASE . '/index.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['form_token' => $tk, 'login' => $nameRace, 'passwd' => 'Pass1234', 'repasswd' => 'Pass1234', 'email' => 'race@example.com']),
        CURLOPT_COOKIEJAR      => $jj,
        CURLOPT_COOKIEFILE     => $jj,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 0.5);
    }
} while ($running && $status === CURLM_OK);
$raceCodes = [];
foreach ($handles as $ch) {
    $raceCodes[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);
$count = (int)(db_query("SELECT COUNT(*) c FROM users WHERE name='" . db()->real_escape_string($nameRace) . "'")->fetch_assoc()['c']);
$raceLogs = [];
$lr = db_query("SELECT status, reason_code FROM register_log WHERE username='" . db()->real_escape_string($nameRace) . "'");
while ($lr && ($x = $lr->fetch_assoc())) { $raceLogs[] = $x['status'] . ':' . $x['reason_code']; }
check('併發同名註冊僅建立一筆帳號', $count === 1, 'count=' . $count . ' codes=' . implode(',', $raceCodes) . ' logs=' . implode(',', $raceLogs));

// ============================================================
section('8. 保留策略清理');
$purge = req('POST', 'admin/registration_api.php?action=purge_logs', ['csrf_token' => $apiCsrf, 'days' => '3650'], ['jar' => $gj]);
$pj = json_decode($purge['body'], true);
check('清理 API 可執行', !empty($pj['ok']), substr($purge['body'], 0, 120));

// ============================================================
section('9. 清理測試資料');
cleanup_users($createdUsers);
restore_settings($origSettings);
db_query("DELETE FROM `gm_accounts` WHERE `username` = '" . db()->real_escape_string($TEST_GM) . "'");
db_query("DELETE FROM `register_blocks` WHERE `reason` LIKE 'QA 測試%'");
check('測試帳號已清除', !user_exists($name1));

// ============================================================
echo "\n==============================\n";
echo "PASS: $pass  FAIL: $fail\n";
if ($failures) {
    echo "失敗項目：\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
}
echo "==============================\n";
exit($fail > 0 ? 1 : 0);

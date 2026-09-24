<?php
/**
 * =============================================================
 *  踏雪笑傲 · 安裝精靈（install/index.php）
 *  -------------------------------------------------------------
 *  透過瀏覽器逐步完成：環境檢查 → 資料庫設定 → 站台設定 →
 *  匯入資料庫 → 建立管理員 → 完成。
 *
 *  安全：
 *    - 安裝完成後會產生 install.lock；一旦存在即拒絕再次安裝。
 *    - 建議安裝完成後直接刪除整個 install/ 目錄。
 * =============================================================
 */

define('APP_INSTALLING', 1);
require_once __DIR__ . '/lib/installer.php';

install_session_start();

if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['install_csrf'];

$S = &$_SESSION['install'];
if (!is_array($S)) {
    $S = [];
}

$flash = &$_SESSION['install_flash'];
if (!is_array($flash)) {
    $flash = [];
}

function install_flash($type, $msg)
{
    $_SESSION['install_flash'][] = ['type' => $type, 'msg' => $msg];
}

function install_redirect($step)
{
    header('Location: index.php?step=' . (int)$step);
    exit;
}

$locked = install_is_locked();

/* -------------------------------------------------------------
 *  POST 處理
 * ------------------------------------------------------------- */
if (!$locked && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    $action = (string)($_POST['action'] ?? '');

    if ($postedCsrf === '' || !hash_equals($CSRF, $postedCsrf)) {
        install_flash('error', '工作階段已過期，請重新操作。');
        install_redirect(1);
    }

    if ($action === 'restart') {
        unset($_SESSION['install'], $_SESSION['install_flash']);
        install_redirect(1);
    }

    if ($action === 'env') {
        $checks = install_env_checks();
        if (!install_env_pass($checks)) {
            install_flash('error', '環境檢查未通過，請先修正標示為必要的項目。');
            install_redirect(1);
        }
        $S['env'] = true;
        install_redirect(2);
    }

    if ($action === 'db') {
        $host = trim((string)($_POST['db_host'] ?? 'localhost'));
        $port = (int)($_POST['db_port'] ?? 3306);
        $user = (string)($_POST['db_user'] ?? '');
        $pass = (string)($_POST['db_pass'] ?? '');
        $name = trim((string)($_POST['db_name'] ?? 'sa'));

        if ($host === '' || $user === '' || $name === '') {
            install_flash('error', '請完整填寫主機、帳號與資料庫名稱。');
            install_redirect(2);
        }

        $conn = install_db_connect($host, $port, $user, $pass);
        if (!$conn['ok']) {
            install_flash('error', '資料庫連線失敗：' . $conn['error']);
            install_redirect(2);
        }
        $link = $conn['link'];

        if (!install_db_exists($link, $name)) {
            $created = install_db_create($link, $name);
            if (!$created['ok']) {
                install_flash('error', '建立資料庫失敗：' . $created['error']);
                mysqli_close($link);
                install_redirect(2);
            }
        }

        // 確認可切換至該資料庫
        if (!@mysqli_select_db($link, $name)) {
            install_flash('error', '無法使用資料庫「' . $name . '」：' . mysqli_error($link));
            mysqli_close($link);
            install_redirect(2);
        }
        mysqli_close($link);

        $S['db'] = ['host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass, 'name' => $name];
        install_flash('success', '資料庫連線成功。');
        install_redirect(3);
    }

    if ($action === 'site') {
        $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
        $siteName = trim((string)($_POST['site_name'] ?? '踏雪傲笑'));
        $tz = trim((string)($_POST['timezone'] ?? 'Asia/Taipei'));

        if ($siteUrl === '' || !preg_match('#^https?://#i', $siteUrl)) {
            install_flash('error', '請輸入完整的站台網址（含 http:// 或 https://）。');
            install_redirect(3);
        }
        $S['site'] = ['site_url' => $siteUrl, 'site_name' => $siteName, 'timezone' => $tz];
        install_redirect(4);
    }

    if ($action === 'import' || $action === 'reimport') {
        if (empty($S['db'])) {
            install_flash('error', '請先完成資料庫設定。');
            install_redirect(2);
        }
        if ($action === 'reimport') {
            unset($S['import']);
        }
        $db = $S['db'];
        $conn = install_db_connect($db['host'], $db['port'], $db['user'], $db['pass'], $db['name']);
        if (!$conn['ok']) {
            install_flash('error', '資料庫連線失敗：' . $conn['error']);
            install_redirect(2);
        }
        $result = install_run_all_schemas($conn['link'], $db['name']);
        mysqli_close($conn['link']);

        $S['import'] = $result;
        if (!$result['ok']) {
            install_flash('error', '部分結構匯入發生錯誤，請檢視下方結果。');
        } else {
            install_flash('success', '資料庫結構匯入完成。');
        }
        install_redirect(4);
    }

    if ($action === 'admin') {
        if (empty($S['db']) || empty($S['site']) || empty($S['import'])) {
            install_flash('error', '安裝流程不完整，請重新操作。');
            install_redirect(1);
        }
        $username = (string)($_POST['gm_user'] ?? '');
        $password = (string)($_POST['gm_pass'] ?? '');
        $confirm  = (string)($_POST['gm_pass2'] ?? '');
        $clear    = !empty($_POST['clear_sample']);

        if ($password !== $confirm) {
            install_flash('error', '兩次輸入的管理員密碼不一致。');
            install_redirect(5);
        }

        $db = $S['db'];
        $conn = install_db_connect($db['host'], $db['port'], $db['user'], $db['pass'], $db['name']);
        if (!$conn['ok']) {
            install_flash('error', '資料庫連線失敗：' . $conn['error']);
            install_redirect(2);
        }
        $link = $conn['link'];

        $cleared = 0;
        if ($clear) {
            $r = install_clear_sample_gm($link);
            if (!$r['ok']) {
                install_flash('error', '清除範例管理員失敗：' . $r['error']);
                mysqli_close($link);
                install_redirect(5);
            }
            $cleared = $r['deleted'];
        }

        $gm = install_create_gm($link, $username, $password);
        mysqli_close($link);
        if (!$gm['ok']) {
            install_flash('error', '建立管理員失敗：' . $gm['error']);
            install_redirect(5);
        }

        $site = $S['site'];
        $cfg = install_write_config([
            'host'      => $db['host'],
            'port'      => $db['port'],
            'user'      => $db['user'],
            'pass'      => $db['pass'],
            'name'      => $db['name'],
            'site_url'  => $site['site_url'],
            'site_name' => $site['site_name'],
            'timezone'  => $site['timezone'],
        ]);
        if (!$cfg['ok']) {
            install_flash('error', '寫入設定檔失敗：' . $cfg['error']);
            install_redirect(5);
        }

        $lock = install_create_lock(['site_url' => $site['site_url'], 'name' => $db['name']]);
        if (!$lock['ok']) {
            install_flash('error', '建立安裝鎖定檔失敗：' . $lock['error']);
            install_redirect(5);
        }

        $S['admin'] = ['username' => strtolower(trim($username)), 'cleared' => $cleared];
        $S['done'] = true;
        install_flash('success', '安裝完成！');
        install_redirect(6);
    }
}

/* -------------------------------------------------------------
 *  步驟閘門
 * ------------------------------------------------------------- */
$allowed = 1;
if (!empty($S['env']))    { $allowed = 2; }
if (!empty($S['db']))     { $allowed = 3; }
if (!empty($S['site']))   { $allowed = 4; }
if (!empty($S['import'])) { $allowed = 5; }
if (!empty($S['done']))   { $allowed = 6; }

$step = (int)($_GET['step'] ?? $allowed);
if ($step < 1 || $step > $allowed) {
    $step = $allowed;
}

$envChecks = install_env_checks();
$envPass   = install_env_pass($envChecks);
$flashNow  = $flash;
$flash     = [];

$steps = [
    1 => '環境檢查',
    2 => '資料庫設定',
    3 => '站台設定',
    4 => '匯入資料庫',
    5 => '建立管理員',
    6 => '完成',
];

$defaultSiteUrl = install_detect_site_url();
$tzList = ['Asia/Taipei', 'Asia/Hong_Kong', 'Asia/Shanghai', 'Asia/Tokyo', 'UTC'];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>踏雪笑傲 · 系統安裝精靈</title>
<style>
    :root { --crimson:#9f1239; --frost:#0284c7; --ink:#0c0d10; --panel:#16181d; }
    * { box-sizing: border-box; }
    body { margin:0; font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif; background:var(--ink);
        color:#e8e6df; line-height:1.6;
        background-image: radial-gradient(ellipse at 50% -10%, rgba(30,41,59,.75), rgba(7,10,18,.98) 75%),
            radial-gradient(circle at 85% 15%, rgba(2,132,199,.12), transparent 45%),
            radial-gradient(circle at 10% 70%, rgba(159,18,57,.10), transparent 50%);
        min-height:100vh; }
    .wrap { max-width:820px; margin:0 auto; padding:36px 18px 64px; }
    .brand { display:flex; align-items:center; gap:12px; margin-bottom:8px; }
    .brand .seal { width:40px; height:40px; border-radius:10px; background:var(--crimson); color:#fff;
        display:flex; align-items:center; justify-content:center; font-weight:900; font-size:20px; }
    .brand h1 { font-size:20px; margin:0; letter-spacing:2px; }
    .brand small { color:#8b93a1; font-size:11px; letter-spacing:3px; }
    .steps { display:flex; flex-wrap:wrap; gap:8px; margin:22px 0; }
    .steps .s { flex:1; min-width:110px; padding:8px 10px; border-radius:8px; background:#14161b;
        border:1px solid #262a33; font-size:12px; color:#9aa2af; text-align:center; }
    .steps .s.active { border-color:var(--crimson); color:#fff; background:#1e1218; }
    .steps .s.done { border-color:#1f6f4a; color:#7ee2b0; background:#101c16; }
    .card { background:var(--panel); border:1px solid #2a2e38; border-radius:12px; padding:26px 24px;
        box-shadow:0 20px 45px -18px rgba(0,0,0,.6); }
    .card h2 { margin:0 0 4px; font-size:18px; }
    .card p.sub { color:#8b93a1; font-size:13px; margin:0 0 20px; }
    .flash { padding:11px 14px; border-radius:8px; font-size:13px; margin-bottom:14px; }
    .flash.error { background:#2a1216; border:1px solid #a3172c; color:#ffb4c0; }
    .flash.success { background:#101c16; border:1px solid #1f6f4a; color:#7ee2b0; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    th,td { text-align:left; padding:9px 10px; border-bottom:1px solid #262a33; vertical-align:top; }
    th { color:#8b93a1; font-weight:600; font-size:12px; }
    .ok { color:#7ee2b0; } .bad { color:#ff8fa3; }
    .field { margin-bottom:15px; }
    .field label { display:block; font-size:12px; color:#aab2bf; margin-bottom:5px; }
    .field input, .field select { width:100%; padding:10px 12px; background:#0f1116; color:#e8e6df;
        border:1px solid #343946; border-radius:8px; font-size:14px; }
    .field input:focus, .field select:focus { outline:none; border-color:var(--crimson); }
    .row { display:flex; gap:14px; flex-wrap:wrap; }
    .row .field { flex:1; min-width:180px; }
    .hint { font-size:11px; color:#8b93a1; margin-top:4px; }
    .btn { display:inline-flex; align-items:center; gap:8px; padding:11px 22px; border-radius:9px;
        border:1px solid var(--crimson); background:linear-gradient(135deg,#9f1239,#881337); color:#fff;
        font-size:14px; font-weight:700; cursor:pointer; text-decoration:none; }
    .btn:hover { background:linear-gradient(135deg,#be123c,#9f1239); }
    .btn.ghost { background:transparent; border-color:#3a3f4c; color:#c3c9d4; }
    .btn.ghost:hover { border-color:#5a6070; }
    .actions { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:22px; flex-wrap:wrap; }
    .checkline { display:flex; align-items:center; gap:8px; font-size:13px; color:#c3c9d4; }
    .kv { font-size:13px; } .kv div { display:flex; justify-content:space-between; gap:12px; padding:6px 0; border-bottom:1px dashed #262a33; }
    .kv span:first-child { color:#8b93a1; }
    code { background:#0f1116; padding:2px 6px; border-radius:5px; font-size:12px; color:#ffd9a0; }
    .locked { text-align:center; padding:20px 0; }
    .locked h2 { color:#7ee2b0; }
    ul.tips { margin:8px 0 0; padding-left:18px; color:#aab2bf; font-size:13px; }
    ul.tips li { margin:5px 0; }
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <div class="seal">雪</div>
        <div>
            <h1>踏雪笑傲 · 系統安裝精靈</h1>
            <small>SNOW WANDERER ONLINE · INSTALLER</small>
        </div>
    </div>

<?php if ($locked): ?>
    <div class="card locked">
        <h2>系統已完成安裝</h2>
        <p class="sub">偵測到 <code>install.lock</code>，為避免覆蓋現有資料，安裝程式已停用。</p>
        <p>若需重新安裝，請先手動刪除站台根目錄的 <code>install.lock</code>（必要時一併刪除 <code>config.local.php</code>）後再重新整理本頁。</p>
        <p style="color:#ffb84d;font-size:13px;">基於安全考量，安裝完成後建議直接刪除整個 <code>install/</code> 目錄。</p>
        <div class="actions" style="justify-content:center;">
            <a class="btn ghost" href="../index.php">前往前台首頁</a>
            <a class="btn ghost" href="../admin/gmlogin.php">前往管理後台</a>
        </div>
    </div>
<?php else: ?>

    <div class="steps">
        <?php foreach ($steps as $n => $label): ?>
            <div class="s <?php echo $n === $step ? 'active' : ($n < $step ? 'done' : ''); ?>">
                <?php echo $n; ?>. <?php echo install_h($label); ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <?php foreach ($flashNow as $f): ?>
            <div class="flash <?php echo $f['type'] === 'error' ? 'error' : 'success'; ?>"><?php echo install_h($f['msg']); ?></div>
        <?php endforeach; ?>

        <?php /* ---------------- Step 1：環境檢查 ---------------- */ ?>
        <?php if ($step === 1): ?>
            <h2>步驟 1 · 環境檢查</h2>
            <p class="sub">確認伺服器環境符合系統需求。</p>
            <table>
                <thead><tr><th>項目</th><th>狀態</th><th>說明</th></tr></thead>
                <tbody>
                <?php foreach ($envChecks as $c): ?>
                    <tr>
                        <td><?php echo install_h($c['label']); ?><?php if (!empty($c['required'])): ?> <span class="bad">*</span><?php endif; ?></td>
                        <td class="<?php echo $c['ok'] ? 'ok' : 'bad'; ?>"><?php echo $c['ok'] ? '通過' : '未通過'; ?></td>
                        <td><?php echo install_h($c['detail']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="hint">標示 <span class="bad">*</span> 者為必要項目。</p>
            <div class="actions">
                <span></span>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo install_h($CSRF); ?>">
                    <input type="hidden" name="action" value="env">
                    <button class="btn" type="submit" <?php echo $envPass ? '' : 'disabled style="opacity:.5;cursor:not-allowed;"'; ?>>下一步：資料庫設定</button>
                </form>
            </div>

        <?php /* ---------------- Step 2：資料庫設定 ---------------- */ ?>
        <?php elseif ($step === 2): ?>
            <h2>步驟 2 · 資料庫設定</h2>
            <p class="sub">填入 MySQL / MariaDB 連線資訊，資料庫不存在時會自動建立。</p>
            <?php $db = $S['db'] ?? []; ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo install_h($CSRF); ?>">
                <input type="hidden" name="action" value="db">
                <div class="row">
                    <div class="field" style="flex:2;">
                        <label>資料庫主機</label>
                        <input type="text" name="db_host" value="<?php echo install_h($db['host'] ?? 'localhost'); ?>" required>
                    </div>
                    <div class="field">
                        <label>連接埠</label>
                        <input type="number" name="db_port" value="<?php echo install_h($db['port'] ?? 3306); ?>" required>
                    </div>
                </div>
                <div class="row">
                    <div class="field">
                        <label>資料庫帳號</label>
                        <input type="text" name="db_user" value="<?php echo install_h($db['user'] ?? 'root'); ?>" required>
                    </div>
                    <div class="field">
                        <label>資料庫密碼</label>
                        <input type="password" name="db_pass" value="<?php echo install_h($db['pass'] ?? ''); ?>" autocomplete="new-password">
                    </div>
                </div>
                <div class="field">
                    <label>資料庫名稱</label>
                    <input type="text" name="db_name" value="<?php echo install_h($db['name'] ?? 'sa'); ?>" required>
                    <div class="hint">遊戲資料庫名稱，預設為 <code>sa</code>。</div>
                </div>
                <div class="actions">
                    <a class="btn ghost" href="?step=1">上一步</a>
                    <button class="btn" type="submit">測試連線並繼續</button>
                </div>
            </form>

        <?php /* ---------------- Step 3：站台設定 ---------------- */ ?>
        <?php elseif ($step === 3): ?>
            <h2>步驟 3 · 站台設定</h2>
            <p class="sub">設定對外網址與站台資訊（金流回呼與連結會使用此網址）。</p>
            <?php $site = $S['site'] ?? []; ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo install_h($CSRF); ?>">
                <input type="hidden" name="action" value="site">
                <div class="field">
                    <label>站台網址（SITE_URL）</label>
                    <input type="text" name="site_url" value="<?php echo install_h($site['site_url'] ?? $defaultSiteUrl); ?>" required>
                    <div class="hint">例如 <code>https://game.example.com/sw</code>，請勿加上結尾斜線。</div>
                </div>
                <div class="row">
                    <div class="field">
                        <label>站台名稱</label>
                        <input type="text" name="site_name" value="<?php echo install_h($site['site_name'] ?? '踏雪笑傲'); ?>">
                    </div>
                    <div class="field">
                        <label>時區</label>
                        <select name="timezone">
                            <?php foreach ($tzList as $tz): ?>
                                <option value="<?php echo install_h($tz); ?>" <?php echo (($site['timezone'] ?? 'Asia/Taipei') === $tz) ? 'selected' : ''; ?>><?php echo install_h($tz); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="actions">
                    <a class="btn ghost" href="?step=2">上一步</a>
                    <button class="btn" type="submit">下一步：匯入資料庫</button>
                </div>
            </form>

        <?php /* ---------------- Step 4：匯入資料庫 ---------------- */ ?>
        <?php elseif ($step === 4): ?>
            <h2>步驟 4 · 匯入資料庫</h2>
            <p class="sub">依序匯入遊戲基底 <code>sa.sql</code>、各前台模組結構與補齊資料表。</p>
            <?php if (empty($S['import'])): ?>
                <p>此步驟會建立所有資料表並匯入 <code>sa.sql</code> 的初始資料，可能需要數秒至數十秒，請勿關閉視窗。</p>
                <p class="hint">除主資料庫外，系統會另建立模組專用資料庫：<code>swo_news</code>、<code>swo_events</code>、<code>swo_download</code>。資料庫帳號需具備 <code>CREATE DATABASE</code> 權限。</p>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo install_h($CSRF); ?>">
                    <input type="hidden" name="action" value="import">
                    <div class="actions">
                        <a class="btn ghost" href="?step=3">上一步</a>
                        <button class="btn" type="submit">開始匯入資料庫</button>
                    </div>
                </form>
            <?php else: ?>
                <?php $imp = $S['import']; ?>
                <table>
                    <thead><tr><th>檔案</th><th>結果</th></tr></thead>
                    <tbody>
                    <?php foreach ($imp['results'] as $r): ?>
                        <tr>
                            <td><?php echo install_h(basename($r['file'])); ?></td>
                            <td class="<?php echo $r['ok'] ? 'ok' : 'bad'; ?>"><?php echo install_h(install_sql_summary($r)); ?></td>
                        </tr>
                        <?php if (!empty($r['errors'])): ?>
                            <tr><td colspan="2" style="color:#ff8fa3;font-size:12px;">
                                <?php foreach (array_slice($r['errors'], 0, 5) as $e): ?>
                                    <div>• <?php echo install_h($e); ?></div>
                                <?php endforeach; ?>
                            </td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="actions">
                    <a class="btn ghost" href="?step=3">上一步</a>
                    <div style="display:flex;gap:10px;">
                        <form method="post" onsubmit="return confirm('重新匯入會先 DROP 既有資料表，既有資料將遺失，確定要繼續嗎？');">
                            <input type="hidden" name="csrf" value="<?php echo install_h($CSRF); ?>">
                            <input type="hidden" name="action" value="reimport">
                            <button class="btn ghost" type="submit">重新匯入</button>
                        </form>
                        <a class="btn" href="?step=5">下一步：建立管理員</a>
                    </div>
                </div>
            <?php endif; ?>

        <?php /* ---------------- Step 5：建立管理員 ---------------- */ ?>
        <?php elseif ($step === 5): ?>
            <h2>步驟 5 · 建立管理員</h2>
            <p class="sub">建立第一組 GM 管理後台帳號，並可選擇清除 <code>sa.sql</code> 內建的範例管理員。</p>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo install_h($CSRF); ?>">
                <input type="hidden" name="action" value="admin">
                <div class="row">
                    <div class="field">
                        <label>管理員帳號</label>
                        <input type="text" name="gm_user" required placeholder="僅小寫英數字、底線、減號">
                    </div>
                    <div class="field">
                        <label>管理員密碼</label>
                        <input type="password" name="gm_pass" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="field">
                        <label>確認密碼</label>
                        <input type="password" name="gm_pass2" required minlength="8" autocomplete="new-password">
                    </div>
                </div>
                <label class="checkline">
                    <input type="checkbox" name="clear_sample" value="1" checked>
                    清除 <code>sa.sql</code> 內建的範例管理員帳號（建議勾選，避免預設憑證外流）
                </label>
                <div class="actions">
                    <a class="btn ghost" href="?step=4">上一步</a>
                    <button class="btn" type="submit">完成安裝</button>
                </div>
            </form>

        <?php /* ---------------- Step 6：完成 ---------------- */ ?>
        <?php elseif ($step === 6): ?>
            <h2>安裝完成</h2>
            <p class="sub">系統已可使用，以下為本次安裝摘要。</p>
            <?php $db = $S['db'] ?? []; $site = $S['site'] ?? []; $admin = $S['admin'] ?? []; ?>
            <div class="kv">
                <div><span>站台網址</span><span><?php echo install_h($site['site_url'] ?? ''); ?></span></div>
                <div><span>資料庫</span><span><?php echo install_h($db['name'] ?? ''); ?> @ <?php echo install_h($db['host'] ?? ''); ?></span></div>
                <div><span>管理員帳號</span><span><?php echo install_h($admin['username'] ?? ''); ?></span></div>
                <div><span>清除範例管理員</span><span><?php echo !empty($admin['cleared']) ? ('已刪除 ' . (int)$admin['cleared'] . ' 筆') : '未清除'; ?></span></div>
                <div><span>設定檔</span><span>config.local.php（已產生）</span></div>
            </div>
            <p style="margin-top:18px;color:#ffb84d;font-size:13px;">安全提醒：請立即刪除整個 <code>install/</code> 目錄，並確認 <code>config.local.php</code> 權限為 <code>640</code> 或不可公開讀取。</p>
            <ul class="tips">
                <li>前台首頁：<a href="../index.php" style="color:#7ec8ff;">../index.php</a></li>
                <li>會員登入：<a href="../login.php" style="color:#7ec8ff;">../login.php</a></li>
                <li>管理後台：<a href="../admin/gmlogin.php" style="color:#7ec8ff;">../admin/gmlogin.php</a></li>
            </ul>
            <div class="actions">
                <span></span>
                <a class="btn" href="../index.php">前往前台首頁</a>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

    <p style="text-align:center;color:#5b6270;font-size:11px;margin-top:22px;">
        © <?php echo date('Y'); ?> SNOW WANDERER ONLINE · INSTALLER
    </p>
</div>
</body>
</html>

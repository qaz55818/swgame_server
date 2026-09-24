<?php
require_once __DIR__ . '/session_bootstrap.php';
app_session_start();
include_once "config.php";
require_once __DIR__ . '/register_security.php';
require_once __DIR__ . '/web_auth.php';

// 取得一次性提示訊息（採用 PRG：POST 後重新導向，提示框於頁面載入後才顯示）
$flash = $_SESSION['flash'] ?? null;
if ($flash !== null) {
    unset($_SESSION['flash']);
}

// 資料庫連線（任何失敗都不對外顯示內部細節）
$Link = null;
try {
    $Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName, $port ?? 3306);
} catch (Throwable $e) {
    $Link = null;
}

// 註冊設定（資料表尚未遷移時沿用安全預設值）
$regSettings = $Link ? register_settings_load($Link) : register_setting_defaults();
$registrationEnabled = register_setting_bool($regSettings, 'register_enabled', true);
$closedNotice = (string)($regSettings['closed_notice'] ?? '');
if ($Link) {
    mysqli_set_charset($Link, "utf8");
}

// 註冊規則（後台「註冊設定」可調整；資料表未遷移時沿用安全預設值）
$emailMaxAccounts       = max(1, register_setting_int($regSettings, 'email_max_accounts', 1));
$accountMinLength       = register_setting_int($regSettings, 'account_min_length', 4);
$accountMaxLength       = register_setting_int($regSettings, 'account_max_length', 10);
$passwordMinLength      = register_setting_int($regSettings, 'password_min_length', 4);
$passwordMaxLength      = register_setting_int($regSettings, 'password_max_length', 10);
$forbiddenWords         = register_setting_list($regSettings, 'forbidden_words');
$forbiddenSymbols       = register_setting_symbols($regSettings, 'forbidden_symbols');
$forbiddenEmailPatterns = register_setting_list($regSettings, 'forbidden_email_patterns');
// 保證下限不大於上限，避免設定異常導致無法註冊
if ($accountMinLength > $accountMaxLength) { $accountMinLength = $accountMaxLength; }
if ($passwordMinLength > $passwordMaxLength) { $passwordMinLength = $passwordMaxLength; }

// 若已登入且沒有待顯示提示，跳過註冊並直接前往主控台
if ((isset($_SESSION['username']) && $_SESSION['username'] !== '') && $flash === null) {
    header("Location: home.php");
    exit();
}

// 設定提示訊息並重新導向（讓提示框在跳轉後才顯示）
function flashAndRedirect($type, $title, $message) {
    $_SESSION['flash'] = ['type' => $type, 'title' => $title, 'message' => $message];
    header("Location: index.php");
    exit();
}

$HALT = false;
$httpStatus = 200;
// 需要正確 HTTP 狀態碼的情境（限流／封鎖／關閉註冊）在同一頁顯示提示，不重新導向
function haltWithFlash($code, $type, $title, $message) {
    global $HALT, $httpStatus, $flash;
    $httpStatus = (int)$code;
    $flash = ['type' => $type, 'title' => $title, 'message' => $message];
    $HALT = true;
}

// 為表單產生 token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$formToken = $_SESSION['form_token'];

/** 建立註冊嘗試紀錄資料（絕不含密碼、雜湊、驗證碼、Session ID 或 Token） */
function regAttemptData($Link, $Login, $Email) {
    $meta = register_client_meta();
    return [
        'userid'           => 0,
        'username'         => mb_substr((string)$Login, 0, 32),
        'email'            => mb_substr((string)$Email, 0, 64),
        'ip'               => $Link ? register_client_ip($Link) : (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'ip_forwarded'     => $meta['ip_forwarded'],
        'user_agent'       => $meta['user_agent'],
        'accept_language'  => $meta['accept_language'],
        'referer'          => $meta['referer'],
        'fingerprint_hash' => hash('sha256', $meta['user_agent'] . '|' . $meta['accept_language']),
    ];
}

/** 寫入一筆註冊嘗試紀錄 */
function regRecord($Link, $Login, $Email, $status, $code, $text, $userId = 0) {
    if (!$Link) {
        return 0;
    }
    $labels = register_reason_labels();
    $data = regAttemptData($Link, $Login, $Email);
    $data['status']      = $status;
    $data['reason_code'] = $code;
    $data['reason_text'] = ($text !== '' ? $text : ($labels[$code] ?? ''));
    $data['userid']      = (int)$userId;
    return register_record_attempt($Link, $data);
}

// 檢查是否為 POST 提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $labels = register_reason_labels();
    // 帳號強制轉為小寫（禁止大寫註冊）；密碼保留大小寫，不做 trim
    $Login  = strtolower(trim((string)($_POST['login'] ?? '')));
    $Pass   = (string)($_POST['passwd'] ?? '');
    $Repass = (string)($_POST['repasswd'] ?? '');
    $Email  = trim((string)($_POST['email'] ?? ''));

    // 0) 限制請求大小（伺服器端，不依賴前端）
    if (register_request_too_large(16384)) {
        regRecord($Link, $Login, $Email, 'failed', 'request_too_large', $labels['request_too_large']);
        haltWithFlash(413, 'danger', '輸入內容過大', '送出的內容超過允許大小，請精簡後再試。');
    }

    // 1) 蜜罐欄位：正常使用者看不到也不會填寫，機器人才會填入內容
    if (!$HALT && !empty($_POST['website'])) {
        regRecord($Link, $Login, $Email, 'failed', 'honeypot', $labels['honeypot']);
        header("Location: login.php");
        exit();
    }

    // 2) 註冊開關：伺服器端強制，即使直接呼叫 API（帶或不帶 token）也必須被拒絕
    if (!$HALT && !$registrationEnabled) {
        regRecord($Link, $Login, $Email, 'disabled', 'registration_disabled', $labels['registration_disabled']);
        haltWithFlash(403, 'warning', '註冊暫停開放', $closedNotice !== '' ? $closedNotice : '註冊功能目前暫停開放，請稍後再試。');
    }

    // 3) CSRF：所有變更操作都必須帶正確 token，且不以 GET 執行
    if (!$HALT) {
        $postedToken = (isset($_POST['form_token']) && is_string($_POST['form_token'])) ? $_POST['form_token'] : '';
        if ($postedToken === '' || empty($_SESSION['form_token']) || !hash_equals((string)$_SESSION['form_token'], $postedToken)) {
            regRecord($Link, $Login, $Email, 'failed', 'csrf_invalid', $labels['csrf_invalid']);
            $_SESSION['form_token'] = bin2hex(random_bytes(32));
            haltWithFlash(400, 'danger', '表單已失效', '你的工作階段已過期，請重新整理後再試。');
        }
    }
    if (!$HALT) {
        // 移除 token 以防止重複使用
        unset($_SESSION['form_token']);
    }

    // 4) 輸入長度上限防禦（防止超長輸入）
    if (!$HALT && (strlen($Login) > 32 || strlen($Pass) > 128 || strlen($Repass) > 128 || strlen($Email) > 254)) {
        regRecord($Link, $Login, $Email, 'failed', 'account_invalid', '輸入內容過長');
        flashAndRedirect('danger', '輸入內容有誤', '輸入內容過長，請縮短後再試。');
    }

    // 5) 限流與封鎖（資料庫原子計數，可承受併發，不依賴前端／Cookie／單一 Session）
    if (!$HALT) {
        $ip = $Link ? register_client_ip($Link, $regSettings) : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $access = register_check_access($Link, $regSettings, $ip, $Login, true);
        if (!$access['allowed']) {
            $status = in_array($access['reason_code'], ['ip_blocked', 'account_blocked'], true) ? 'blocked' : 'limited';
            regRecord($Link, $Login, $Email, $status, $access['reason_code'], $labels[$access['reason_code']] ?? $access['message']);
            if (!empty($access['retry_after'])) {
                header('Retry-After: ' . max(1, (int)$access['retry_after']));
            }
            haltWithFlash($access['http'], 'warning', '操作過於頻繁', $access['message']);
        }
    }

    // 6) 伺服器端格式驗證（不可只依賴前端驗證；長度與禁用清單由後台設定）
    if (!$HALT) {
        $accountPattern = '/^[a-z0-9_-]{' . $accountMinLength . ',' . $accountMaxLength . '}$/';
        if ($Login === '' || !preg_match($accountPattern, $Login)) {
            regRecord($Link, $Login, $Email, 'failed', 'account_invalid', $labels['account_invalid']);
            flashAndRedirect('danger', '帳號格式錯誤', '遊戲帳號只能包含 ' . $accountMinLength . '-' . $accountMaxLength . ' 個英文字母、數字、底線或連字號。');
        } elseif (register_text_has_forbidden_word($Login, $forbiddenWords)
                  || register_text_has_forbidden_symbol($Login, $forbiddenSymbols)) {
            regRecord($Link, $Login, $Email, 'failed', 'account_forbidden', $labels['account_forbidden']);
            flashAndRedirect('danger', '帳號不受允許', '此遊戲帳號包含系統禁止使用的詞彙或符號，請更換其他帳號。');
        } elseif ($Pass !== $Repass) {
            regRecord($Link, $Login, $Email, 'failed', 'password_mismatch', $labels['password_mismatch']);
            flashAndRedirect('danger', '密碼不一致', '兩次輸入的密碼不一致，請重新確認。');
        } elseif (strlen($Pass) < $passwordMinLength || strlen($Pass) > $passwordMaxLength || preg_match('/\s/', $Pass) || strpos($Pass, "\0") !== false) {
            regRecord($Link, $Login, $Email, 'failed', 'password_invalid', $labels['password_invalid']);
            flashAndRedirect('danger', '密碼格式錯誤', '密碼長度必須為 ' . $passwordMinLength . '-' . $passwordMaxLength . ' 個字元，且不可包含空白。');
        } elseif (strcasecmp($Pass, $Login) === 0) {
            regRecord($Link, $Login, $Email, 'failed', 'password_weak', $labels['password_weak']);
            flashAndRedirect('danger', '密碼過於簡單', '密碼不可與遊戲帳號相同。');
        } elseif (register_text_has_forbidden_word($Pass, $forbiddenWords)
                  || register_text_has_forbidden_symbol($Pass, $forbiddenSymbols)) {
            regRecord($Link, $Login, $Email, 'failed', 'password_forbidden', $labels['password_forbidden']);
            flashAndRedirect('danger', '密碼不受允許', '此密碼包含系統禁止使用的詞彙或符號，請更換其他密碼。');
        } elseif (!filter_var($Email, FILTER_VALIDATE_EMAIL) || strpos($Email, "\0") !== false) {
            regRecord($Link, $Login, $Email, 'failed', 'email_invalid', $labels['email_invalid']);
            flashAndRedirect('danger', '電子郵件格式錯誤', '請輸入有效的電子郵件地址。');
        } elseif (register_email_matches_forbidden($Email, $forbiddenEmailPatterns)) {
            regRecord($Link, $Login, $Email, 'failed', 'email_forbidden', $labels['email_forbidden']);
            flashAndRedirect('danger', '電子郵件不受允許', '此電子郵件格式或網域不受允許，請改用其他信箱。');
        }
    }

    // 7) 建立帳號
    if (!$HALT) {
        if (!$Link) {
            flashAndRedirect('danger', '系統連線異常', '資料庫連線失敗，請稍後再試。');
        }

        // 先寫入一筆 pending 紀錄，確保每個到達應用程式的嘗試都被保存
        $attemptLogId = regRecord($Link, $Login, $Email, 'pending', 'ok', '處理中');

        // passwd 欄位在 utf8 varchar 欄位中儲存原始二進位 MD5 位元組。
        // latin1 可原樣傳遞任何位元組；所有讀寫 passwd 的連線皆須一致。
        mysqli_set_charset($Link, "latin1");

        // 帳號唯一性：先查詢，最終仍以資料庫 UNIQUE(name) 作為併發防線
        $isDuplicate = false;
        try {
            $checkQuery = $Link->prepare("SELECT `name` FROM `users` WHERE `name` = ? LIMIT 1");
            $checkQuery->bind_param("s", $Login);
            $checkQuery->execute();
            $isDuplicate = $checkQuery->get_result()->num_rows > 0;
            $checkQuery->close();
        } catch (Throwable $e) {
            mysqli_set_charset($Link, "utf8");
            register_finalize_attempt($Link, $attemptLogId, 'failed', 'db_error', $labels['db_error']);
            flashAndRedirect('danger', '系統發生錯誤', '系統暫時無法處理，請稍後再試。');
        }

        if ($isDuplicate) {
            mysqli_set_charset($Link, "utf8");
            register_finalize_attempt($Link, $attemptLogId, 'failed', 'account_exists', $labels['account_exists']);
            flashAndRedirect('danger', '帳號已被使用', '此遊戲帳號已被使用，請更換其他帳號。');
        }

        // 電子郵件註冊上限：同一組 Email 可註冊的帳號數由後台設定（不分大小寫）
        $emailCount = 0;
        try {
            $emailStmt = $Link->prepare("SELECT COUNT(*) AS c FROM `users` WHERE LOWER(`email`) = ?");
            $emailLower = strtolower($Email);
            $emailStmt->bind_param("s", $emailLower);
            $emailStmt->execute();
            $emailCount = (int)($emailStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $emailStmt->close();
        } catch (Throwable $e) {
            mysqli_set_charset($Link, "utf8");
            register_finalize_attempt($Link, $attemptLogId, 'failed', 'db_error', $labels['db_error']);
            flashAndRedirect('danger', '系統發生錯誤', '系統暫時無法處理，請稍後再試。');
        }

        if ($emailCount >= $emailMaxAccounts) {
            mysqli_set_charset($Link, "utf8");
            register_finalize_attempt($Link, $attemptLogId, 'failed', 'email_exists', $labels['email_exists']);
            if ($emailMaxAccounts <= 1) {
                flashAndRedirect('danger', '電子信箱已被註冊', '此電子信箱已註冊過帳號，請改用其他信箱或直接登入。');
            }
            flashAndRedirect('danger', '電子信箱已達上限', '此電子信箱已註冊 ' . $emailMaxAccounts . ' 組帳號（上限 ' . $emailMaxAccounts . ' 組），請改用其他信箱。');
        }

        // 加密密碼。遊戲伺服器只接受原始二進位（ascii）MD5 格式，
        // 因此仍維持此格式以確保遊戲端登入相容（網站端另以 bcrypt 側車強化）。
        $Salt = md5($Login . $Pass, true);
        // 產生隨機手機號碼
        $mobile = generateMobileNumber();
        // 產生隨機市內電話號碼
        $landline = generateLandlineNumber();
        // 目前年份減去隨機數以計算出生年份
        $randomNumber = random_int(18, 99);
        $currentYear = (new DateTime())->format('Y');
        $birthYear = $currentYear - $randomNumber;
        $birthday = "$birthYear" . "0101";
        // 身分證的出生日期
        $idNumber = generateIdNumber("110101", $randomNumber);
        $gender = 0;

        // 以參數化呼叫 adduser（不再拼接使用者輸入）。對原始二進位 Salt，
        // 參數化可避免引號／反斜線／空字元造成 SQL 字串破壞。
        // 前 13 個為字串、第 14 個 gender 為整數、其後 3 個為字串。
        // mysqli bind_param 需要以「變數」傳遞（不可直接傳字面值）。
        $prompt = 'code name';
        $answer = '唐三';
        $truename = 'backsword valentine';
        $province = 'beijing';
        $city = 'beijing';
        $address = '斗罗大陆 (Douluo Dalu)';
        $postalcode = '100010';
        $qq = '0811377718@qq.com';
        $types = 'sssssssssssss' . 'i' . 'sss';
        $created = false;
        $duplicateName = false;
        for ($try = 0; $try < 3; $try++) {
            try {
                $call = $Link->prepare("CALL adduser(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $call->bind_param(
                    $types,
                    $Login, $Salt, $prompt, $answer, $truename,
                    $idNumber, $Email, $mobile, $province, $city,
                    $landline, $address, $postalcode, $gender, $birthday,
                    $qq, $Salt
                );
                $call->execute();
                $call->close();
                $created = true;
                break;
            } catch (Throwable $e) {
                $msg = (string)$e->getMessage();
                if (stripos($msg, 'IX_users_name') !== false || stripos($msg, "for key 'name'") !== false) {
                    $duplicateName = true;
                    break;
                }
                // MyISAM 下 MAX(id)+16 的併發競態：重試以降低主鍵碰撞
                usleep(80000);
            }
        }

        if (!$created) {
            mysqli_set_charset($Link, "utf8");
            if ($duplicateName) {
                register_finalize_attempt($Link, $attemptLogId, 'failed', 'account_exists', $labels['account_exists']);
                flashAndRedirect('danger', '帳號已被使用', '此遊戲帳號已被使用，請更換其他帳號。');
            }
            register_finalize_attempt($Link, $attemptLogId, 'failed', 'concurrent_duplicate', $labels['concurrent_duplicate']);
            flashAndRedirect('danger', '註冊失敗', '系統忙碌中，請稍後再試。');
        }

        // 取得使用者 ID（參數化）
        $userid1 = 0;
        try {
            $idStmt = $Link->prepare("SELECT `ID` FROM `users` WHERE `name` = ? LIMIT 1");
            $idStmt->bind_param("s", $Login);
            $idStmt->execute();
            $idRow = $idStmt->get_result()->fetch_assoc();
            $idStmt->close();
            $userid1 = (int)($idRow['ID'] ?? 0);
        } catch (Throwable $e) {
            $userid1 = 0;
        }

        // 初始化錢包。所有值先轉為整數才拼接，避免任何輸入進入 SQL 字串。
        if ($userid1 > 0) {
            $zoneid1 = 1; $sn1 = 1; $aid1 = 1; $point1 = 0; $cash1 = 0; $status1 = 1;
            try {
                $Link->query("CALL usecash(" . (int)$userid1 . ", " . (int)$zoneid1 . ", " . (int)$sn1 . ", " . (int)$aid1 . ", " . (int)$point1 . ", " . (int)$cash1 . ", " . (int)$status1 . ", @error)");
            } catch (Throwable $e) {
                // 錢包初始化失敗不影響帳號建立，交由後台追蹤
            }
        }

        // 記錄最終結果，並同步網站端 bcrypt 憑證（遊戲端 MD5 保持不變）
        mysqli_set_charset($Link, "utf8");
        register_finalize_attempt($Link, $attemptLogId, 'success', 'ok', $labels['ok'], $userid1);
        if ($userid1 > 0) {
            web_password_sync($Link, $userid1, $Pass, $Salt);
        }

        mysqli_close($Link);

        // 註冊成功 - 登入玩家，並設定成功提示後重新導向
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userid1;
        $_SESSION['username'] = $Login;
        $_SESSION['is_gm'] = 0;
        $_SESSION['login_time'] = time();
        $_SESSION['login_ip'] = register_client_ip(null, $regSettings);

        $_SESSION['flash'] = [
            'type' => 'success',
            'title' => '註冊成功',
            'message' => '歡迎踏入江湖！俠客帳號「' . $Login . '」已建立，現在就啟程踏雪笑傲。'
        ];
        header("Location: index.php");
        exit();
    }

    // 需要正確 HTTP 狀態碼的情境（限流／封鎖／關閉）維持在同一頁顯示
    if ($HALT) {
        http_response_code($httpStatus);
        if ($registrationEnabled) {
            $_SESSION['form_token'] = bin2hex(random_bytes(32));
            $formToken = $_SESSION['form_token'];
        }
    }
}
?>
<!DOCTYPE html><html lang="zh-Hant"><head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>踏雪笑傲 | 俠客註冊</title>
    <!-- Tailwind CSS CDN -->
    <link rel="stylesheet" href="assets/tailwind.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700;900&amp;family=Ma+Shan+Zheng&amp;family=Noto+Serif+TC:wght@400;600;700;900&amp;family=Noto+Sans+TC:wght@300;400;500;700&amp;display=swap" rel="stylesheet">
    <!-- 樣式由 assets/tailwind.css 提供（編譯後靜態檔，不再使用 Tailwind CDN） -->
    <style>
        /* 自定義雪景背景與毛玻璃光影 */
        body {
            background: linear-gradient(135deg, #0b111e 0%, #152238 35%, #1e293b 70%, #0f172a 100%);
            min-height: 100vh;
            color: #1e293b;
            overflow-x: hidden;
            position: relative;
        }

        /* 遠山水墨積雪剪影與霜光 */
        .mountain-bg {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 55%;
            background: radial-gradient(ellipse at 50% 100%, rgba(224, 242, 254, 0.15) 0%, transparent 70%),
                        linear-gradient(to top, rgba(15, 23, 42, 0.95), transparent);
            pointer-events: none;
            z-index: 0;
        }

        /* 冰雪光芒光暈 */
        .frost-aurora {
            position: fixed;
            top: -20%;
            left: 20%;
            width: 800px;
            height: 600px;
            background: radial-gradient(circle, rgba(186, 230, 253, 0.18) 0%, rgba(56, 189, 248, 0.08) 40%, transparent 70%);
            filter: blur(80px);
            pointer-events: none;
            z-index: 0;
            animation: pulseGlow 10s ease-in-out infinite alternate;
        }

        .crimson-aurora {
            position: fixed;
            bottom: 10%;
            right: 15%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(244, 63, 94, 0.1) 0%, transparent 70%);
            filter: blur(90px);
            pointer-events: none;
            z-index: 0;
        }

        @keyframes pulseGlow {
            0% { transform: scale(0.95) translate(0, 0); opacity: 0.7; }
            100% { transform: scale(1.1) translate(30px, 20px); opacity: 1; }
        }

        /* 飄雪 Canvas 層疊 */
        #snow-canvas {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 1;
        }

        /* 琉璃冰雪卡片質感 */
        .snow-card {
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(28px);
            -webkit-backdrop-filter: blur(28px);
            border: 1px solid rgba(255, 255, 255, 0.95);
            box-shadow: 
                0 25px 60px -15px rgba(0, 0, 0, 0.35),
                0 0 50px rgba(186, 230, 253, 0.35),
                inset 0 0 20px rgba(255, 255, 255, 0.7);
            border-radius: 24px;
            position: relative;
            z-index: 10;
        }

        /* 左側水墨雪景展示板 */
        .snow-hero-panel {
            background: linear-gradient(160deg, #f8fafc 0%, #e2e8f0 50%, #cbd5e1 100%);
            position: relative;
            overflow: hidden;
        }
        .snow-hero-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background: 
                radial-gradient(circle at 80% 20%, rgba(244, 63, 94, 0.12) 0%, transparent 40%),
                radial-gradient(circle at 20% 80%, rgba(56, 189, 248, 0.15) 0%, transparent 50%);
            pointer-events: none;
        }

        /* 古風印章效果 */
        .ancient-seal {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #be123c;
            color: #be123c;
            background: rgba(254, 242, 242, 0.85);
            font-family: "Noto Serif TC", serif;
            font-weight: 900;
            letter-spacing: 2px;
            padding: 2px 8px;
            border-radius: 4px;
            box-shadow: inset 0 0 4px rgba(190, 18, 60, 0.2), 0 2px 6px rgba(190, 18, 60, 0.15);
            transform: rotate(-3deg);
        }

        /* 輸入框冰霜聚焦特效 */
        .frost-input {
            background: rgba(248, 250, 252, 0.85);
            border: 1.5px solid #cbd5e1;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .frost-input:focus {
            background: #ffffff;
            border-color: #0284c7;
            box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.2), 0 4px 12px rgba(2, 132, 199, 0.12);
        }

        /* 武俠主按鈕：踏雪踏浪紅梅漸變 */
        .btn-sword {
            background: linear-gradient(135deg, #e11d48 0%, #be123c 60%, #9f1239 100%);
            box-shadow: 0 8px 24px -4px rgba(190, 18, 60, 0.45), 0 0 12px rgba(244, 63, 94, 0.3);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .btn-sword::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -60%;
            width: 40%;
            height: 200%;
            background: linear-gradient(to right, transparent, rgba(255,255,255,0.4), transparent);
            transform: rotate(25deg);
            transition: 0.75s;
        }
        .btn-sword:hover::after {
            left: 130%;
        }
        .btn-sword:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px -4px rgba(190, 18, 60, 0.55), 0 0 20px rgba(244, 63, 94, 0.45);
        }
        .btn-sword:active {
            transform: translateY(0);
        }

        /* 梅花花瓣飄落裝飾樣式 */
        .plum-petal {
            position: absolute;
            background: radial-gradient(circle at 30% 30%, #fda4af, #f43f5e);
            border-radius: 60% 40% 70% 30% / 60% 30% 70% 40%;
            opacity: 0.85;
            filter: drop-shadow(0 2px 4px rgba(225, 29, 72, 0.25));
            pointer-events: none;
        }

        /* ===== 雪白水墨視窗框體系 (wuxia-frame) ===== */
        .wuxia-frame-base {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.95) 100%);
            box-shadow:
                0 25px 60px -15px rgba(2, 6, 23, 0.35),
                0 0 0 1px rgba(255, 255, 255, 0.9),
                0 0 35px rgba(56, 189, 248, 0.18);
            border-radius: 12px;
            position: relative;
        }
        .corner-ornament {
            position: absolute;
            width: 22px;
            height: 22px;
            pointer-events: none;
            opacity: 0.85;
            z-index: 5;
        }
        .corner-tl { top: -2px; left: -2px; border-top: 3px solid #be123c; border-left: 3px solid #be123c; border-top-left-radius: 6px; }
        .corner-tr { top: -2px; right: -2px; border-top: 3px solid #be123c; border-right: 3px solid #be123c; border-top-right-radius: 6px; }
        .corner-bl { bottom: -2px; left: -2px; border-bottom: 3px solid #0284c7; border-left: 3px solid #0284c7; border-bottom-left-radius: 6px; }
        .corner-br { bottom: -2px; right: -2px; border-bottom: 3px solid #0284c7; border-right: 3px solid #0284c7; border-bottom-right-radius: 6px; }
        .inner-border-line {
            position: absolute;
            inset: 6px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 8px;
            pointer-events: none;
            z-index: 0;
        }
        .ink-header-line {
            background: linear-gradient(90deg, transparent 0%, rgba(15, 23, 42, 0.25) 25%, rgba(190, 18, 60, 0.6) 50%, rgba(15, 23, 42, 0.25) 75%, transparent 100%);
            height: 1px;
            width: 100%;
        }
        .seal-stamp {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #be123c;
            color: #fff;
            border-radius: 3px;
            font-size: 11px;
            padding: 1px 6px;
            letter-spacing: 1px;
            box-shadow: 0 2px 4px rgba(190, 18, 60, 0.25);
            font-family: 'Noto Serif TC', serif;
            white-space: nowrap;
        }
        .wuxia-input {
            background-color: #ffffff;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .wuxia-input:focus {
            outline: none;
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15), 0 2px 8px rgba(2, 132, 199, 0.08);
        }
        .wuxia-input-error {
            border-color: #be123c !important;
            box-shadow: 0 0 0 3px rgba(190, 18, 60, 0.12) !important;
        }
        @keyframes frostPulseSmooth {
            0%, 100% { box-shadow: 0 22px 50px -12px rgba(2, 6, 23, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.92), 0 0 25px rgba(56, 189, 248, 0.16); }
            50% { box-shadow: 0 25px 60px -12px rgba(2, 6, 23, 0.38), 0 0 0 1px rgba(255, 255, 255, 1), 0 0 36px rgba(56, 189, 248, 0.26); }
        }
        .glow-frost { animation: frostPulseSmooth 6s ease-in-out infinite; }
        .action-btn-smooth {
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            transform: translateZ(0);
        }
        .action-btn-smooth:hover { box-shadow: 0 4px 14px rgba(190, 18, 60, 0.35); }

        /* ===== 底部傳送門按鈕（下載遊戲 / 官網首頁） ===== */
        .portal-link-btn {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.5rem 0.95rem;
            border-radius: 0.85rem;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.96) 0%, rgba(241, 245, 249, 0.9) 100%);
            color: #334155;
            overflow: hidden;
            box-shadow: 0 4px 14px -6px rgba(15, 23, 42, 0.25);
            transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.28s ease,
                        border-color 0.28s ease, color 0.28s ease;
        }
        .portal-link-btn::after {
            content: '';
            position: absolute;
            top: 0;
            left: -120%;
            width: 55%;
            height: 100%;
            background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.7), transparent);
            transform: skewX(-20deg);
            transition: left 0.6s ease;
            pointer-events: none;
        }
        .portal-link-btn:hover::after { left: 145%; }
        .portal-link-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 26px -8px rgba(15, 23, 42, 0.35);
            border-color: rgba(190, 18, 60, 0.35);
            color: #0f172a;
        }
        .portal-link-btn:active { transform: translateY(0); }
        .portal-link-btn__icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 0.6rem;
            color: #ffffff;
            flex: none;
            box-shadow: 0 4px 10px -3px rgba(15, 23, 42, 0.4);
            transition: transform 0.28s ease;
        }
        .portal-link-btn:hover .portal-link-btn__icon { transform: scale(1.08) rotate(-4deg); }
        .portal-link-btn--download .portal-link-btn__icon { background: linear-gradient(135deg, #0ea5e9, #0369a1); }
        .portal-link-btn--home .portal-link-btn__icon { background: linear-gradient(135deg, #e11d48, #7f1d3a); }
        .portal-link-btn__text { display: flex; flex-direction: column; line-height: 1.1; text-align: left; }
        .portal-link-btn__title {
            font-family: 'Noto Serif TC', serif;
            font-weight: 700;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
        }
        .portal-link-btn__sub {
            font-size: 0.58rem;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: #94a3b8;
            margin-top: 0.15rem;
            transition: color 0.28s ease;
        }
        .portal-link-btn:hover .portal-link-btn__sub { color: #64748b; }

        /* ===== 使用者條款連結與子視窗 ===== */
        .terms-link {
            background: none;
            border: 0;
            padding: 0;
            margin: 0;
            font: inherit;
            font-weight: 700;
            color: #be123c;
            text-decoration: underline;
            text-underline-offset: 2px;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .terms-link:hover { color: #881337; }

        .terms-modal {
            position: fixed;
            inset: 0;
            z-index: 90;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .terms-modal.is-open { opacity: 1; visibility: visible; }
        .terms-modal__bd {
            position: absolute;
            inset: 0;
            background: rgba(2, 6, 23, 0.62);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }
        .terms-modal__dlg {
            position: relative;
            width: 100%;
            max-width: 46rem;
            max-height: 88vh;
            display: flex;
            flex-direction: column;
            border-radius: 16px;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.99) 0%, rgba(248, 250, 252, 0.97) 100%);
            box-shadow: 0 30px 70px -20px rgba(2, 6, 23, 0.55), 0 0 0 1px rgba(255, 255, 255, 0.9), 0 0 40px rgba(56, 189, 248, 0.18);
            transform: translateY(14px) scale(0.97);
            transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1);
        }
        .terms-modal.is-open .terms-modal__dlg { transform: translateY(0) scale(1); }
        .terms-modal__head {
            display: flex;
            align-items: flex-start;
            gap: 0.9rem;
            padding: 1.1rem 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.92), rgba(241, 245, 249, 0.85));
        }
        .terms-modal__head-icon {
            width: 2.6rem;
            height: 2.6rem;
            flex: none;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.8rem;
            color: #be123c;
            background: linear-gradient(135deg, #fee2e2, #fecdd3);
            border: 1px solid #fecaca;
            box-shadow: 0 6px 14px -6px rgba(190, 18, 60, 0.4);
        }
        .terms-modal__title {
            font-family: 'Noto Serif TC', serif;
            font-weight: 800;
            font-size: 1rem;
            color: #0f172a;
            letter-spacing: 0.02em;
        }
        .terms-modal__sub { font-size: 0.7rem; color: #64748b; margin-top: 0.2rem; }
        .terms-modal__close {
            margin-left: auto;
            flex: none;
            width: 2.1rem;
            height: 2.1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.6rem;
            border: 1px solid transparent;
            background: transparent;
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .terms-modal__close:hover { background: #f1f5f9; color: #334155; border-color: #e2e8f0; }
        .terms-modal__body {
            padding: 1.25rem 1.4rem;
            overflow-y: auto;
            color: #475569;
            font-size: 0.8rem;
            line-height: 1.75;
        }
        .terms-modal__body h4 {
            font-family: 'Noto Serif TC', serif;
            color: #0f172a;
            font-weight: 700;
            font-size: 0.86rem;
            margin: 1.1rem 0 0.4rem;
            padding-left: 0.6rem;
            border-left: 3px solid #be123c;
        }
        .terms-modal__body h4:first-child { margin-top: 0; }
        .terms-modal__body p { margin: 0 0 0.4rem; }
        .terms-modal__body ul { margin: 0.2rem 0 0.6rem; padding-left: 1.15rem; list-style: disc; }
        .terms-modal__body li { margin: 0.15rem 0; }
        .terms-modal__foot {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.7rem;
            padding: 0.9rem 1.25rem;
            border-top: 1px solid #e2e8f0;
            background: rgba(248, 250, 252, 0.9);
        }
        .terms-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.55rem 1.15rem;
            border-radius: 0.75rem;
            font-size: 0.78rem;
            font-weight: 700;
            font-family: 'Noto Serif TC', serif;
            letter-spacing: 0.04em;
            cursor: pointer;
            transition: all 0.24s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid transparent;
        }
        .terms-btn--ghost {
            background: #ffffff;
            color: #64748b;
            border-color: #e2e8f0;
        }
        .terms-btn--ghost:hover { color: #334155; border-color: #cbd5e1; background: #f8fafc; }
        .terms-btn--agree {
            color: #ffffff;
            background: linear-gradient(135deg, #e11d48 0%, #be123c 60%, #9f1239 100%);
            box-shadow: 0 8px 20px -6px rgba(190, 18, 60, 0.5);
        }
        .terms-btn--agree:hover { transform: translateY(-1px); box-shadow: 0 12px 26px -6px rgba(190, 18, 60, 0.6); }
    </style>
</head>
<body class="flex items-center justify-center p-4 sm:p-6 md:p-10 font-sansTC selection:bg-rose-200 selection:text-rose-900">

<?php if ($flash !== null):
    $flashType = $flash['type'] ?? 'info';
    $flashTitle = $flash['title'] ?? '提示';
    $flashMessage = $flash['message'] ?? '';
    $flashStyles = [
        'danger'  => ['wrap' => 'border-rose-200/90',  'badge' => 'bg-rose-50 border-rose-200 text-rose-600',   'btn' => 'bg-rose-700 hover:bg-rose-600'],
        'warning' => ['wrap' => 'border-amber-200/90', 'badge' => 'bg-amber-50 border-amber-200 text-amber-600', 'btn' => 'bg-amber-600 hover:bg-amber-500'],
        'success' => ['wrap' => 'border-sky-200/90',   'badge' => 'bg-sky-50 border-sky-200 text-sky-600',       'btn' => 'bg-gradient-to-r from-sky-700 to-sky-600 hover:from-sky-600 hover:to-sky-500'],
        'info'    => ['wrap' => 'border-sky-200/90',   'badge' => 'bg-sky-50 border-sky-200 text-sky-600',       'btn' => 'bg-gradient-to-r from-sky-700 to-sky-600 hover:from-sky-600 hover:to-sky-500'],
    ];
    $st = $flashStyles[$flashType] ?? $flashStyles['info'];
?>
    <!-- 提示框（雪峰警示與確認小視窗，依 PRG 於頁面載入後才顯示） -->
    <div id="flash-overlay" class="fixed inset-0 z-[60] flex items-center justify-center p-4" style="background: rgba(2, 6, 23, 0.55); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);">
        <div class="wuxia-frame-base glow-frost relative p-6 sm:p-7 w-full max-w-md border <?php echo $st['wrap']; ?>">
            <div class="corner-ornament corner-tl"></div>
            <div class="corner-ornament corner-tr"></div>
            <div class="corner-ornament corner-bl"></div>
            <div class="corner-ornament corner-br"></div>
            <div class="inner-border-line"></div>
            <div class="relative z-10 text-center space-y-3">
                <div class="w-12 h-12 mx-auto rounded-full border flex items-center justify-center shadow-inner <?php echo $st['badge']; ?>">
                    <?php if ($flashType === 'success'): ?>
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                    <?php elseif ($flashType === 'info'): ?>
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                    <?php else: ?>
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                    <?php endif; ?>
                </div>
                <h3 class="text-lg font-bold font-serif text-slate-900"><?php echo htmlspecialchars($flashTitle); ?></h3>
                <p class="text-xs text-slate-600 leading-relaxed px-4"><?php echo htmlspecialchars($flashMessage); ?></p>
                <div class="pt-3 flex items-center justify-center gap-3">
                    <?php if ($flashType === 'success'): ?>
                        <a href="home.php" class="px-6 py-1.5 text-xs font-medium text-white rounded-md shadow transition-colors <?php echo $st['btn']; ?>">進入遊戲 · 踏雪啟程</a>
                    <?php else: ?>
                        <button type="button" onclick="closeFlash()" class="px-6 py-1.5 text-xs font-medium text-white rounded-md shadow transition-colors <?php echo $st['btn']; ?>">知道了，重新填寫</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

    <!-- 動態飄雪與背景特效 -->
    <canvas id="snow-canvas" width="1280" height="830"></canvas>
    <div class="mountain-bg"></div>
    <div class="frost-aurora"></div>
    <div class="crimson-aurora"></div>

    <!-- 主外框容器 (Snow Glass Card) -->
    <main class="w-full max-w-5xl snow-card overflow-hidden my-auto grid grid-cols-1 lg:grid-cols-12">
        
        <!-- 左側：踏雪尋梅主題古風視覺 Banner (佔 5 欄) -->
        <div class="lg:col-span-5 snow-hero-panel p-8 sm:p-10 flex flex-col justify-between border-b lg:border-b-0 lg:border-r border-slate-200/80"><!-- 極簡空靈留白水墨視覺區 -->
<div class="relative z-10 flex flex-col items-center text-center my-auto py-8 px-4">
    <!-- 水墨徽標 -->
    <div class="relative mb-6 group">
        <div class="absolute inset-0 rounded-full bg-sky-200/30 blur-xl"></div>
        <div class="relative w-28 h-28 mx-auto flex items-center justify-center p-1 rounded-full bg-white/40 border border-white/60 shadow-sm backdrop-blur-sm">
            <img src="assets/logo.svg" alt="踏雪笑傲 標誌" class="w-full h-full object-contain">
        </div>
    </div>

    <!-- 標題與拼音/英文 -->
    <div class="space-y-3">
        <h1 class="font-calligraphy text-5xl sm:text-6xl text-slate-800 tracking-wider flex items-center justify-center gap-2">
            <span class="">踏雪笑傲</span>
        </h1>
        <p class="font-cinzel tracking-[0.35em] text-xs text-slate-500 uppercase">
            Snow Wanderer
        </p>
    </div>

    <!-- 水墨留白詩詞對聯 -->
    <div class="mt-10 mb-8 max-w-xs mx-auto border-t border-b border-slate-300/60 py-5">
        <p class="font-serifTC text-sm sm:text-base text-slate-700 tracking-[0.25em] leading-loose font-medium">
            雪落寒山孤劍影<br>
            天地笑傲任平生
        </p>
    </div>

    <!-- 簡約印章裝飾 -->
    <div class="flex items-center justify-center gap-3 text-slate-400 text-xs tracking-widest">
        <span class="h-px w-8 bg-slate-300"></span>
        <span class="font-serifTC text-rose-800 text-[11px] border border-rose-800/40 px-2 py-0.5 rounded bg-rose-50/50">獨孤一劍</span>
        <span class="h-px w-8 bg-slate-300"></span>
    </div>
</div>

<!-- 底部優雅版權與狀態 -->
<div class="relative z-10 pt-6 border-t border-slate-200/60 flex items-center justify-between text-[11px] text-slate-400 tracking-wider font-serifTC">
    <span class="flex items-center gap-1.5">
        <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500/80"></span>
        伺服器連線狀態：良好
    </span>
    <span class="">© 踏雪笑傲 · 官方正版授權</span>
</div></div>

        <!-- 右側：修飾美化後的俠客註冊表單 (佔 7 欄) -->
        <div class="lg:col-span-7 p-5 sm:p-8 flex flex-col justify-center">
            <div class="wuxia-frame-base glow-frost relative p-6 sm:p-8">
                <div class="corner-ornament corner-tl"></div>
                <div class="corner-ornament corner-tr"></div>
                <div class="corner-ornament corner-bl"></div>
                <div class="corner-ornament corner-br"></div>
                <div class="inner-border-line"></div>
                <div class="relative z-10">
                    <!-- 表單標題區域 -->
                    <div class="mb-6">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-200">
                            <div>
                                <h2 class="font-serifTC text-xl sm:text-2xl font-bold text-slate-900 tracking-wide flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 bg-rose-600 rotate-45 shadow-sm"></span>
                                    <span class="">踏雪入江湖</span>
                                    <span class="text-rose-600 text-base font-normal bg-rose-50 border border-rose-200 px-2 py-0.5 rounded-md">註冊帳號</span>
                                </h2>
                                <p class="text-slate-500 text-xs sm:text-sm mt-1.5">填寫以下帳號資料，即可完成遊戲帳號註冊</p>
                            </div>
                            <span class="seal-stamp">踏雪留名</span>
                        </div>
                        <div class="ink-header-line mt-3"></div>
                    </div>

            <?php if (!$registrationEnabled): ?>
            <!-- 註冊已關閉：伺服器端同時拒絕任何直接 POST／API 呼叫 -->
            <div class="rounded-2xl border border-amber-200 bg-amber-50/90 p-6 text-center space-y-3">
                <div class="w-14 h-14 mx-auto rounded-full bg-white border border-amber-200 text-amber-600 flex items-center justify-center shadow-sm">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.7-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                </div>
                <h3 class="font-serifTC text-lg font-bold text-slate-900">註冊暫停開放</h3>
                <p class="text-sm text-slate-600 leading-relaxed"><?php echo htmlspecialchars($closedNotice !== '' ? $closedNotice : '註冊功能目前暫停開放，造成不便敬請見諒。', ENT_QUOTES, 'UTF-8'); ?></p>
                <a href="login.php" class="inline-flex items-center gap-1.5 mt-2 px-5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-xs font-semibold transition-colors">已有帳號？前往登入 →</a>
            </div>
            <?php else: ?>
            <!-- 核心提交表單 (保留原本的所有 name 欄位與邏輯) -->
            <form id="registerForm" action="" method="POST" novalidate="" class="space-y-4">
                <!-- 原 PHP 表單 Token 隱藏欄位 -->
                <input type="hidden" name="form_token" value="<?php echo $formToken; ?>">
                <!-- 蜜罐欄位（對使用者隱藏，供反機器人偵測，正常人類不會填寫） -->
                <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;opacity:0;height:0;width:0;">

                <!-- 1. 遊戲帳號 (login) -->
                <div>
                    <label for="username" class="flex items-center justify-between text-xs font-semibold text-slate-700 mb-1.5"><span class="flex items-center gap-1.5"><span class="text-rose-600">*</span><span class="">遊戲帳號</span></span><span class="text-[11px] font-normal text-slate-400"><?php echo $accountMinLength; ?>-<?php echo $accountMaxLength; ?> 個英數字、底線或連字號（自動轉為小寫）</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        </div>
                        <input type="text" id="username" name="login" class="frost-input w-full pl-10 pr-4 py-2.5 rounded-xl text-slate-800 text-sm placeholder:text-slate-400 focus:outline-none" placeholder="請輸入 <?php echo $accountMinLength; ?>-<?php echo $accountMaxLength; ?> 位英數字或連字符" required="">
                        <div id="username-spinner" class="hidden absolute inset-y-0 right-0 pr-3.5 flex items-center">
                            <svg class="animate-spin h-4 w-4 text-sky-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                        </div>
                    </div>
                    <p class="text-[11px] text-rose-500 mt-1 min-h-[16px] transition-all" id="username-error"></p>
                </div>

                <!-- 2. 密碼 (passwd) -->
                <div>
                    <label for="password" class="flex items-center justify-between text-xs font-semibold text-slate-700 mb-1.5"><span class="flex items-center gap-1.5"><span class="text-rose-600">*</span><span class="">登入密碼</span></span><span class="text-[11px] font-normal text-slate-400"><?php echo $passwordMinLength; ?>-<?php echo $passwordMaxLength; ?> 個字元</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        </div>
                        <input type="password" id="password" name="passwd" class="frost-input w-full pl-10 pr-11 py-2.5 rounded-xl text-slate-800 text-sm placeholder:text-slate-400 focus:outline-none" placeholder="請輸入 <?php echo $passwordMinLength; ?>-<?php echo $passwordMaxLength; ?> 位密碼" required="">
                        <button type="button" class="toggle-eye absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none" data-target="password" aria-label="顯示密碼">
                            <svg class="eye-open w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            <svg class="eye-closed w-4 h-4 hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                        </button>
                    </div>
                    <!-- 密碼強度指示器 (保留並以雪地琉璃色系翻新) -->
                    <div class="flex items-center justify-between mt-1.5">
                        <div class="flex-1 flex gap-1.5 h-1.5 bg-slate-200/80 rounded-full overflow-hidden p-0.5 strength">
                            <span class="flex-1 bg-slate-300 rounded-full transition-all duration-300"></span>
                            <span class="flex-1 bg-slate-300 rounded-full transition-all duration-300"></span>
                            <span class="flex-1 bg-slate-300 rounded-full transition-all duration-300"></span>
                            <span class="flex-1 bg-slate-300 rounded-full transition-all duration-300"></span>
                        </div>
                        <span class="strength-label text-[11px] text-slate-500 ml-3 min-w-[32px] text-right">未輸入</span>
                    </div>
                    <p class="text-[11px] text-rose-500 mt-1 min-h-[16px]" id="password-error"></p>
                </div>

                <!-- 3. 確認密碼 (repasswd) -->
                <div>
                    <label for="repassword" class="flex items-center justify-between text-xs font-semibold text-slate-700 mb-1.5">
                        <span class="flex items-center gap-1.5">
                            <span class="text-rose-600">*</span>
                            <span class="">確認密碼</span>
                        </span>
                        <span id="match-indicator" class="text-[11px] font-medium"></span>
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        </div>
                        <input type="password" id="repassword" name="repasswd" class="frost-input w-full pl-10 pr-11 py-2.5 rounded-xl text-slate-800 text-sm placeholder:text-slate-400 focus:outline-none" placeholder="請再次輸入相同密碼" required="">
                        <button type="button" class="toggle-eye absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none" data-target="repassword" aria-label="顯示密碼">
                            <svg class="eye-open w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            <svg class="eye-closed w-4 h-4 hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                        </button>
                    </div>
                    <p class="text-[11px] text-rose-500 mt-1 min-h-[16px]" id="repassword-error"></p>
                </div>

                <!-- 4. 電子郵件 (email) -->
                <div>
                    <label for="email" class="flex items-center justify-between text-xs font-semibold text-slate-700 mb-1.5"><span class="flex items-center gap-1.5"><span class="text-rose-600">*</span><span class="">電子信箱</span></span><span class="text-[11px] font-normal text-slate-400">用於帳號驗證與密碼找回</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m22 7-10 5L2 7"></path></svg>
                        </div>
                        <input type="email" id="email" name="email" class="frost-input w-full pl-10 pr-11 py-2.5 rounded-xl text-slate-800 text-sm placeholder:text-slate-400 focus:outline-none" placeholder="請輸入電子郵件信箱 (例如 user@example.com)" required="">
                        <div id="email-spinner" class="hidden absolute inset-y-0 right-0 pr-3.5 flex items-center">
                            <svg class="animate-spin h-4 w-4 text-sky-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                        </div>
                    </div>
                    <p class="text-[11px] text-rose-500 mt-1 min-h-[16px]" id="email-error"></p>
                </div>

                <!-- 守則與條款協議 -->
                <div class="flex flex-wrap items-center gap-1.5 text-xs text-slate-600 pt-1">
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" id="agreeTerms" checked="" class="w-4 h-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500 accent-rose-600">
                        <span class="">我已閱讀並同意</span>
                    </label>
                    <button type="button" class="terms-link" onclick="openTermsModal()">《踏雪笑傲》</button>
                    <span class="">使用者條款與隱私權規範</span>
                </div>

                <!-- 提交主按鈕 -->
                <div class="pt-3">
                    <button type="submit" class="btn-sword w-full py-3 px-6 rounded-xl text-white font-serifTC font-bold text-base tracking-widest flex items-center justify-center gap-2 group">
                        <span class="">踏雪啟程 · 立即註冊</span>
                        <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <!-- 輔助導航連結與客服 -->
            <div class="mt-6 pt-5 border-t border-slate-200/80 flex flex-col sm:flex-row items-center justify-between text-xs text-slate-500 gap-3">
                <div class="">已有帳號？ <a href="login.php" class="text-rose-700 font-semibold hover:text-rose-800 hover:underline">前往登入 →</a></div>
                <div class="flex flex-wrap items-center justify-center gap-2.5">
                    <a href="swo/download.php" class="portal-link-btn portal-link-btn--download" aria-label="下載遊戲">
                        <span class="portal-link-btn__icon">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"></path><path d="m7 10 5 5 5-5"></path><path d="M5 21h14"></path></svg>
                        </span>
                        <span class="portal-link-btn__text">
                            <span class="portal-link-btn__title">下載遊戲</span>
                            <span class="portal-link-btn__sub">Download</span>
                        </span>
                    </a>
                    <a href="swo/index.php" class="portal-link-btn portal-link-btn--home" aria-label="官網首頁">
                        <span class="portal-link-btn__icon">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 9-8 9 8"></path><path d="M5 10v11h14V10"></path><path d="M10 21v-6h4v6"></path></svg>
                        </span>
                        <span class="portal-link-btn__text">
                            <span class="portal-link-btn__title">官網首頁</span>
                            <span class="portal-link-btn__sub">Official Site</span>
                        </span>
                    </a>
                </div>
            </div>
                </div>
            </div>
        </div>
    </main>

    <!-- 原程式邏輯完全相容之 JavaScript (含雪花粒子系統渲染) -->
    <script>
    // 註冊規則（由後台「註冊設定」即時提供，前端僅作提示；後端仍會重新驗證）
    const REG_RULES = <?php echo json_encode([
        'accountMin' => $accountMinLength,
        'accountMax' => $accountMaxLength,
        'passwordMin' => $passwordMinLength,
        'passwordMax' => $passwordMaxLength,
    ], JSON_UNESCAPED_UNICODE); ?>;
    function closeFlash() {
        var el = document.getElementById('flash-overlay');
        if (el) el.style.display = 'none';
    }

    document.addEventListener('DOMContentLoaded', function() {
        // 點擊提示框背景亦可關閉
        var flashOverlay = document.getElementById('flash-overlay');
        if (flashOverlay) {
            flashOverlay.addEventListener('click', function(e) {
                if (e.target === flashOverlay) closeFlash();
            });
        }

        const usernameInput = document.getElementById('username');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        const repasswordInput = document.getElementById('repassword');
        
        const usernameError = document.getElementById('username-error');
        const emailError = document.getElementById('email-error');
        const passwordError = document.getElementById('password-error');
        const repasswordError = document.getElementById('repassword-error');
        
        const strengthSegments = document.querySelectorAll('.strength span');
        const strengthLabel = document.querySelector('.strength-label');
        const matchIndicator = document.getElementById('match-indicator');
        const usernameSpinner = document.getElementById('username-spinner');
        const emailSpinner = document.getElementById('email-spinner');

        // 錯誤提示與硃砂紅框同步切換
        function setFieldError(input, errorEl, message) {
            errorEl.textContent = message;
            if (message) {
                input.classList.add('wuxia-input-error');
            } else {
                input.classList.remove('wuxia-input-error');
            }
        }

        // 強制帳號輸入轉為小寫（禁止大寫註冊）
        usernameInput.addEventListener('input', function() {
            const start = usernameInput.selectionStart;
            const end = usernameInput.selectionEnd;
            usernameInput.value = usernameInput.value.toLowerCase();
            usernameInput.setSelectionRange(start, end);
        });

        // 即時檢查帳號格式與重複性
        usernameInput.addEventListener('blur', function() {
            const username = usernameInput.value.trim();
            if (!username) {
                setFieldError(usernameInput, usernameError, '');
                return;
            }
            if (username.length < REG_RULES.accountMin || username.length > REG_RULES.accountMax) {
                setFieldError(usernameInput, usernameError, `⚠ 遊戲帳號長度必須為 ${REG_RULES.accountMin}-${REG_RULES.accountMax} 個字元。`);
                return;
            }
            if (!/^[a-zA-Z0-9_-]+$/.test(username)) {
                setFieldError(usernameInput, usernameError, '⚠ 遊戲帳號只能包含英文字母、數字、底線或連字號。');
                return;
            }

            // 非同步重複檢查
            if (usernameSpinner) usernameSpinner.classList.remove('hidden');
            fetch('check_username.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `username=${encodeURIComponent(username)}`
            })
            .then(response => response.json())
            .then(data => {
                if (usernameSpinner) usernameSpinner.classList.add('hidden');
                if (data && data.forbidden) {
                    setFieldError(usernameInput, usernameError, '⚠ ' + (data.message || '此遊戲帳號不受允許，請更換其他帳號。'));
                    return;
                }
                setFieldError(usernameInput, usernameError, data.exists ? '⚠ 此遊戲帳號已被其他俠客使用。' : '');
            })
            .catch(() => {
                if (usernameSpinner) usernameSpinner.classList.add('hidden');
                setFieldError(usernameInput, usernameError, '');
            });
        });

        // 電子郵件格式即時檢核 + 是否已被註冊
        emailInput.addEventListener('blur', function() {
            const email = emailInput.value.trim();
            if (!email) {
                setFieldError(emailInput, emailError, '');
                return;
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                setFieldError(emailInput, emailError, '⚠ 請輸入有效的飛鴿傳書電子郵件地址。');
                return;
            }
            if (emailSpinner) emailSpinner.classList.remove('hidden');
            fetch('check_username.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `email=${encodeURIComponent(email)}`
            })
            .then(response => response.json())
            .then(data => {
                if (emailSpinner) emailSpinner.classList.add('hidden');
                if (data && data.forbidden) {
                    setFieldError(emailInput, emailError, '⚠ ' + (data.message || '此電子信箱格式不受允許，請改用其他信箱。'));
                    return;
                }
                setFieldError(emailInput, emailError, (data && data.exists) ? '⚠ 此電子信箱已達註冊上限，請改用其他信箱。' : '');
            })
            .catch(() => {
                if (emailSpinner) emailSpinner.classList.add('hidden');
                setFieldError(emailInput, emailError, '');
            });
        });

        // 密碼強度演算法 (美化色系：微弱/普通/良好/強勁)
        function updateStrength(pw) {
            if (!pw) {
                strengthSegments.forEach(seg => {
                    seg.style.background = '#cbd5e1';
                    seg.style.boxShadow = 'none';
                });
                strengthLabel.textContent = '未輸入';
                strengthLabel.className = 'strength-label text-[11px] text-slate-400 ml-3 min-w-[32px] text-right';
                return;
            }
            let score = 0;
            if (pw.length >= 4) score++;
            if (pw.length >= 7) score++;
            if (pw.length >= 10) score++;
            if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
            if (/\d/.test(pw)) score++;
            if (/[^A-Za-z0-9]/.test(pw)) score++;
            score = Math.min(score, 4);

            const levels = ['太弱', '初學', '普通', '深厚', '極高'];
            const colors = ['#f43f5e', '#f97316', '#eab308', '#0ea5e9', '#10b981'];
            const textClasses = ['text-rose-500', 'text-amber-600', 'text-yellow-600', 'text-sky-600', 'text-emerald-600'];

            strengthSegments.forEach((seg, i) => {
                if (i < score) {
                    seg.style.background = colors[score];
                    seg.style.boxShadow = `0 0 6px ${colors[score]}88`;
                } else {
                    seg.style.background = '#cbd5e1';
                    seg.style.boxShadow = 'none';
                }
            });
            strengthLabel.textContent = levels[score];
            strengthLabel.className = `strength-label text-[11px] font-semibold ml-3 min-w-[32px] text-right ${textClasses[score]}`;
        }

        // 密碼一致性即時提示
        function updateMatch() {
            if (!repasswordInput.value) {
                matchIndicator.textContent = '';
                matchIndicator.className = 'text-[11px] font-medium';
            } else if (passwordInput.value === repasswordInput.value) {
                matchIndicator.textContent = '✓ 兩次密碼相符';
                matchIndicator.className = 'text-[11px] font-medium text-emerald-600';
            } else {
                matchIndicator.textContent = '✗ 密碼不相符';
                matchIndicator.className = 'text-[11px] font-medium text-rose-600';
            }
        }

        passwordInput.addEventListener('input', function() {
            const password = passwordInput.value.trim();
            if (password.length > 0 && (password.length < REG_RULES.passwordMin || password.length > REG_RULES.passwordMax)) {
                setFieldError(passwordInput, passwordError, `⚠ 密碼長度必須為 ${REG_RULES.passwordMin}-${REG_RULES.passwordMax} 個字元。`);
            } else {
                setFieldError(passwordInput, passwordError, '');
            }
            updateStrength(password);
            updateMatch();
        });

        repasswordInput.addEventListener('input', function() {
            if (passwordInput.value && repasswordInput.value && passwordInput.value !== repasswordInput.value) {
                setFieldError(repasswordInput, repasswordError, '⚠ 兩次輸入的密碼不一致。');
            } else {
                setFieldError(repasswordInput, repasswordError, '');
            }
            updateMatch();
        });

        // 眼睛切換明文/暗文
        document.querySelectorAll('.toggle-eye').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const targetId = btn.getAttribute('data-target');
                const input = document.getElementById(targetId);
                const eyeOpen = btn.querySelector('.eye-open');
                const eyeClosed = btn.querySelector('.eye-closed');
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                if (isPassword) {
                    eyeOpen.classList.add('hidden');
                    eyeClosed.classList.remove('hidden');
                } else {
                    eyeOpen.classList.remove('hidden');
                    eyeClosed.classList.add('hidden');
                }
            });
        });

        // Canvas 高效飄雪系統 (含雪花晶體與飄拂物理)
        const canvas = document.getElementById('snow-canvas');
        const ctx = canvas.getContext('2d');
        let width = canvas.width = window.innerWidth;
        let height = canvas.height = window.innerHeight;

        window.addEventListener('resize', () => {
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
        });

        const flakes = [];
        const flakeCount = Math.floor(Math.min(width, 1600) / 14); // 根據螢幕自適應雪花數量

        for (let i = 0; i < flakeCount; i++) {
            flakes.push({
                x: Math.random() * width,
                y: Math.random() * height,
                radius: Math.random() * 2.8 + 1,
                density: Math.random() * flakeCount,
                speedY: Math.random() * 1.2 + 0.6,
                speedX: Math.random() * 0.8 - 0.4,
                alpha: Math.random() * 0.75 + 0.25
            });
        }

        function drawSnow() {
            ctx.clearRect(0, 0, width, height);
            ctx.fillStyle = 'rgba(255, 255, 255, 0.85)';
            
            for (let i = 0; i < flakes.length; i++) {
                const f = flakes[i];
                ctx.beginPath();
                ctx.arc(f.x, f.y, f.radius, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(235, 245, 255, ${f.alpha})`;
                ctx.shadowColor = 'rgba(255, 255, 255, 0.7)';
                ctx.shadowBlur = f.radius * 2;
                ctx.fill();

                // 更新位置
                f.y += f.speedY;
                f.x += f.speedX + Math.sin(f.y * 0.01) * 0.3;

                // 邊界重置
                if (f.y > height) {
                    f.y = -10;
                    f.x = Math.random() * width;
                }
                if (f.x > width) f.x = 0;
                else if (f.x < 0) f.x = width;
            }
            requestAnimationFrame(drawSnow);
        }
        drawSnow();
    });
    </script>



    <!-- 使用者條款與隱私權規範 子視窗 -->
    <div class="terms-modal" id="terms-modal" role="dialog" aria-modal="true" aria-labelledby="terms-modal-title">
        <div class="terms-modal__bd" data-terms-close></div>
        <div class="terms-modal__dlg">
            <div class="terms-modal__head">
                <div class="terms-modal__head-icon">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M8 13h8"></path><path d="M8 17h8"></path></svg>
                </div>
                <div>
                    <h3 class="terms-modal__title" id="terms-modal-title">踏雪笑傲 · 使用者條款與隱私權規範</h3>
                    <p class="terms-modal__sub">最後更新：2026 年 1 月 · 請詳閱以下條款後再行註冊</p>
                </div>
                <button type="button" class="terms-modal__close" onclick="closeTermsModal()" aria-label="關閉">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="terms-modal__body">
                <h4>一、帳號註冊與保管</h4>
                <p>申請《踏雪笑傲》（以下稱「本遊戲」）帳號時，您應提供真實且有效之電子郵件，並保證未冒用他人身分。每位玩家之電子郵件僅能註冊一組帳號，重複註冊將不予受理。</p>
                <p>帳號與密碼由您自行妥善保管，不得轉讓、出租或出借。凡以您的帳號登入所為之一切行為，均視為您本人之行為，如有遺失或被盜用，請立即透過客服管道反映。</p>

                <h4>二、遊戲行為規範</h4>
                <p>您同意於遊戲中遵守法令與善良風俗，並不得從事下列行為：</p>
                <ul>
                    <li>使用外掛、腳本、自動程式或其他非官方工具影響遊戲公平。</li>
                    <li>利用程式漏洞、複製道具或進行任何形式之不正當獲利。</li>
                    <li>散布不實訊息、進行詐騙、騷擾、謾罵或侵害他人權益。</li>
                    <li>販售、仲介或以任何方式交易遊戲帳號與虛擬寶物。</li>
                </ul>

                <h4>三、虛擬寶物與付費</h4>
                <p>遊戲內之點數、元寶、道具等虛擬寶物，僅供本遊戲服務範圍內使用，不得要求兌換現金或移轉至其他帳號。已完成之儲值交易，除法令另有規定或本公司另有公告外，恕不提供退款。</p>

                <h4>四、個人資料與隱私保護</h4>
                <p>本公司依個人資料保護相關法令，於提供遊戲服務、帳號驗證、交易處理與客服支援之必要範圍內，蒐集、處理及利用您所提供之電子郵件等個人資料。</p>
                <p>我們不會任意將您的個人資料提供給第三人，並採取合理之安全措施防止資料被竊取、竄改、毀損或洩漏。您得依法請求查詢、更正或刪除您的個人資料。</p>

                <h4>五、服務變更、暫停與終止</h4>
                <p>本公司得視營運需要，隨時新增、修改或終止部分或全部遊戲服務，並於官方網站公告。若您違反本條款，本公司有權視情節輕重為警告、限制功能、暫時停權或永久終止帳號，且不負任何補償責任。</p>

                <h4>六、智慧財產權</h4>
                <p>本遊戲之程式、美術、音樂、文字、商標及其他內容，均受智慧財產權法令保護，未經授權不得重製、散布、修改或為其他利用。</p>

                <h4>七、免責聲明</h4>
                <p>本遊戲服務係依「現況」提供，本公司不保證服務絕不中斷或全無錯誤。對於因不可抗力、網路異常、系統維護或第三人行為所致之損失，本公司於法令允許範圍內不負賠償責任。</p>

                <h4>八、條款同意與修正</h4>
                <p>當您勾選同意並完成註冊，即表示您已閱讀、理解並同意遵守本條款之全部內容。本條款如有修正，將公告於官方網站，您於修正後繼續使用本遊戲，即視為同意修正後之內容。</p>
            </div>
            <div class="terms-modal__foot">
                <button type="button" class="terms-btn terms-btn--ghost" onclick="closeTermsModal()">稍後再看</button>
                <button type="button" class="terms-btn terms-btn--agree" onclick="agreeTerms()">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
                    我已閱讀並同意
                </button>
            </div>
        </div>
    </div>

    <script>
        // ===== 使用者條款子視窗 =====
        (function () {
            var modal = document.getElementById('terms-modal');
            if (!modal) return;

            window.openTermsModal = function () {
                modal.classList.add('is-open');
                document.body.style.overflow = 'hidden';
                var body = modal.querySelector('.terms-modal__body');
                if (body) body.scrollTop = 0;
            };
            window.closeTermsModal = function () {
                modal.classList.remove('is-open');
                document.body.style.overflow = '';
            };
            window.agreeTerms = function () {
                var cb = document.getElementById('agreeTerms');
                if (cb) {
                    cb.checked = true;
                    cb.dispatchEvent(new Event('change', { bubbles: true }));
                }
                window.closeTermsModal();
            };

            modal.addEventListener('click', function (e) {
                if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-terms-close')) {
                    window.closeTermsModal();
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) window.closeTermsModal();
            });
        })();
    </script>

</body></html>
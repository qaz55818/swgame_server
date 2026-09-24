<?php
// 安全性：此工具會將「所有」玩家密碼重設為固定值，屬高風險操作。
// 僅允許已登入的 GM 透過後台工作階段執行，或由命令列管理員執行。
require_once __DIR__ . '/session_bootstrap.php';
app_session_start();
if (PHP_SAPI !== 'cli' && (empty($_SESSION['gm_username']))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("此工具僅限已登入的 GM 使用。\n");
}

// 不對外顯示 PHP 錯誤細節
error_reporting(E_ALL);
ini_set('display_errors', 0);

include_once "config.php";
try {
    $conn = new mysqli($DBHost, $DBUser, $DBPassword, $DBName, $port);
} catch (Throwable $e) {
    http_response_code(500);
    exit("資料庫連線失敗。\n");
}
if ($conn->connect_error) {
    http_response_code(500);
    exit("資料庫連線失敗。\n");
}

// 產生 MD5 hash 的函式
function encode($salt, $type = 'md5') {
    if ($type == 'ascii') {
        return md5($salt, true); // ASCII (raw bytes)
    }
    return "0x" . md5($salt); // MD5 (hexadecimal with 0x prefix)
}


if (isset($_POST['encryption'])) {
    $encryptionType = $_POST['encryption'];
}

// 從資料庫取得 name
$sql = "SELECT name FROM users";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    // 準備呼叫預存程序
    $stmt = $conn->prepare("CALL changePasswd(?, ?)");

    if (!$stmt) {
        http_response_code(500);
        exit("系統暫時無法處理，請稍後再試。\n");
    }

    // 更新每個使用者名稱的 passwd
    while ($row = $result->fetch_assoc()) {
        $name = $row['name'];
        $Pass = '123456';
        $hashedPassword = encode($name . $Pass, $encryptionType);

        // bind_param "ss" 用於 string 與 string
        $stmt->bind_param("ss", $name, $hashedPassword);

        // 檢查執行時的錯誤
        if (!$stmt->execute()) {
            echo "更新使用者 " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . " 的密碼時發生錯誤。<br>";
        } else {
            echo "已成功更新使用者 " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . " 的密碼。<br>";
        }
    }

    $stmt->close();
} else {
    echo "找不到任何使用者。";
}

// 所有工作完成後關閉連線
$conn->close();
?>
<?php
/**
 * =============================================================
 *  踏雪笑傲 · 會員訊息即時推送（Server-Sent Events）
 *  -------------------------------------------------------------
 *  以 EventSource 連線，於有新訊息時即時推送未讀數與最新訊息 ID。
 *  連線存活上限約 25 秒後主動結束，瀏覽器會自動重新連線。
 *  僅限已登入會員；不佔用 Session 鎖（讀取後即釋放）。
 * =============================================================
 */
require_once __DIR__ . '/session_bootstrap.php';
app_session_start(['remember' => true]);
@include_once __DIR__ . '/config.php';
require_once __DIR__ . '/messages.php';

// 授權檢查（送出 SSE 標頭前）
if (!isset($_SESSION['username']) || $_SESSION['username'] === '') {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'unauthorized';
    exit();
}
$username = (string)$_SESSION['username'];
$userId = $_SESSION['user_id'] ?? '';

// 釋放 Session 鎖，避免阻塞其他請求
session_write_close();

// 允許長時間輸出（連線有上限，不會無限佔用）
@set_time_limit(35);
@ignore_user_abort(false);

// SSE 標頭
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // 告知 nginx 不要緩衝
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
@ob_implicit_flush(true);

function sse_emit($event, $data)
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . $data . "\n\n";
    @flush();
}

$Link = null;
try {
    $Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName, $port ?? 3306);
} catch (Throwable $e) {
    $Link = null;
}
if (!$Link) {
    sse_emit('fatal', json_encode(['message' => 'db']));
    exit();
}
mysqli_set_charset($Link, 'utf8mb4');

$uid = member_message_resolve_uid($Link, $username, $userId);
if ($uid <= 0) {
    sse_emit('fatal', json_encode(['message' => 'nouser']));
    mysqli_close($Link);
    exit();
}

echo "retry: 3000\n\n";
@flush();

$end = time() + 25;
$lastUnread = -1;
$lastLatest = -1;
while (time() < $end && !connection_aborted()) {
    $unread = member_message_unread_count($Link, $uid);
    $latest = member_message_latest_id($Link, $uid);
    if ($unread !== $lastUnread || $latest !== $lastLatest) {
        sse_emit('update', json_encode(['unread' => $unread, 'latest' => $latest]));
        $lastUnread = $unread;
        $lastLatest = $latest;
    } else {
        // keep-alive 註解
        echo ": ping\n\n";
        @flush();
    }
    sleep(2);
}

mysqli_close($Link);

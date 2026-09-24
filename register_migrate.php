<?php
/**
 * =============================================================
 *  踏雪笑傲 · 註冊管理資料庫遷移（命令列專用）
 *  -------------------------------------------------------------
 *  執行：
 *    php register_migrate.php
 *    php register_migrate.php --purge-days=90
 *
 *  特性：
 *    - 僅能在 CLI 執行，避免被網站訪客觸發。
 *    - 具冪等性（可重複執行）；只新增資料表／欄位，不刪除既有資料。
 *    - 正式環境執行前請先備份：mysqldump -u root -p sa > sa_backup.sql
 * =============================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "此遷移工具僅允許透過命令列執行。\n";
    exit(1);
}

include_once __DIR__ . '/config.php';
require_once __DIR__ . '/register_security.php';

$purgeDays = null;
foreach ($argv as $arg) {
    if (preg_match('/^--purge-days=(\d+)$/', $arg, $m)) {
        $purgeDays = (int)$m[1];
    }
}

try {
    $Link = mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName, $port ?? 3306);
} catch (Throwable $e) {
    fwrite(STDERR, "資料庫連線失敗：" . $e->getMessage() . "\n");
    exit(1);
}
if (!$Link) {
    fwrite(STDERR, "資料庫連線失敗。\n");
    exit(1);
}

mysqli_set_charset($Link, 'utf8mb4');

echo "開始遷移註冊管理資料表…\n";
register_ensure_core_tables($Link);

// 補齊預設設定值（不覆蓋既有值）
$defaults = register_setting_defaults();
$inserted = 0;
try {
    $stmt = $Link->prepare("INSERT IGNORE INTO `register_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
    foreach ($defaults as $key => $value) {
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $inserted += $stmt->affected_rows;
    }
    $stmt->close();
} catch (Throwable $e) {
    fwrite(STDERR, "寫入預設設定失敗：" . $e->getMessage() . "\n");
}

// web_credentials 側車表
try {
    $Link->query("CREATE TABLE IF NOT EXISTS `web_credentials` (
        `uid` int NOT NULL,
        `password_hash` varchar(255) NOT NULL DEFAULT '',
        `legacy_fingerprint` char(64) NOT NULL DEFAULT '',
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`uid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $res = $Link->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_credentials' AND COLUMN_NAME = 'legacy_fingerprint'");
    if ($res && ((int)($res->fetch_assoc()['c'] ?? 0) === 0)) {
        $Link->query("ALTER TABLE `web_credentials` ADD COLUMN `legacy_fingerprint` char(64) NOT NULL DEFAULT '' AFTER `password_hash`");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "建立 web_credentials 失敗：" . $e->getMessage() . "\n");
}

echo "已建立／確認資料表：register_settings、register_blocks、register_rate_limits、register_admin_logs、web_credentials\n";
echo "已確認 register_log.status / reason_code / reason_text / idx_register_status\n";
echo "新增預設設定列：{$inserted}\n";

if ($purgeDays !== null) {
    $purged = register_purge($Link, $purgeDays, 5000);
    echo "清理保留天數 {$purgeDays} 天：註冊紀錄 {$purged['logs']} 筆、限流計數 {$purged['rate']} 筆\n";
}

mysqli_close($Link);
echo "遷移完成。\n";

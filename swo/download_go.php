<?php
/**
 * =============================================================
 *  踏雪笑傲 · 下載中心 — 下載導向 / 計數
 *  點擊後累計 download_count，再導向實際載點。
 * =============================================================
 */
require_once __DIR__ . '/../download_config.php';

$db = download_db();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$fallback = 'download.php';

if ($id > 0) {
    $stmt = $db->prepare("SELECT `installer_url` FROM `dl_releases` WHERE `id` = ? AND `enabled` = 1 LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $db->query("UPDATE `dl_releases` SET `download_count` = `download_count` + 1 WHERE `id` = " . $id);
            $url = (string)$row['installer_url'];
            // 避免 header injection
            $url = str_replace(["\r", "\n"], '', $url);
            if ($url !== '' && $url !== '#') {
                header('Location: ' . $url);
                exit();
            }
            header('Location: download.php?started=1');
            exit();
        }
    }
}

header('Location: ' . $fallback);
exit();

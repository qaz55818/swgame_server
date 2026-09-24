<?php
/**
 * =============================================================
 *  踏雪笑傲 · 可散佈安裝包打包工具（build.php）
 *  -------------------------------------------------------------
 *  用法（命令列）：
 *    php build.php
 *    php build.php --output=dist/game-installer.zip
 *
 *  產出：dist/sw-installer-YYYYmmdd-HHMMSS.zip
 *  內容：完整程式 + 安裝精靈（install/），排除本機設定、備份、
 *        測試、開發工具與既有壓縮檔。
 * =============================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "此打包工具僅允許透過命令列執行。\n";
    exit(1);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "PHP 未載入 zip 擴充，無法打包。\n");
    exit(1);
}

$root = __DIR__;
$outDir = $root . '/dist';
if (!is_dir($outDir) && !@mkdir($outDir, 0775, true)) {
    fwrite(STDERR, "無法建立輸出目錄：{$outDir}\n");
    exit(1);
}

$output = null;
foreach ($argv as $arg) {
    if (preg_match('/^--output=(.+)$/', $arg, $m)) {
        $output = $m[1];
    }
}
if ($output === null) {
    $output = $outDir . '/sw-installer-' . date('Ymd-His') . '.zip';
} elseif ($output[0] !== '/' && !preg_match('#^[A-Za-z]:#', $output)) {
    $output = $root . '/' . ltrim($output, '/\\');
}

/** 需排除的相對路徑片段（不分大小寫） */
$excludeParts = [
    '.git',
    '.opencode',
    'tests',
    'dist',
    'node_modules',
    'admin/backups',
];

/** 需排除的檔名（不分大小寫） */
$excludeNames = [
    'config.local.php',
    'config.local.php.tmp',
    'install.lock',
    'screen.png',
    'build.php',
];

/** 需排除的副檔名 */
$excludeExts = ['zip', 'sql.gz', 'tmp', 'log'];

function build_excluded($rel, $excludeParts, $excludeNames, $excludeExts)
{
    $relNorm = str_replace('\\', '/', $rel);
    $lower = strtolower($relNorm);

    foreach ($excludeParts as $part) {
        $part = strtolower($part);
        if ($lower === $part || strpos($lower, $part . '/') === 0 || strpos($lower, '/' . $part . '/') !== false) {
            return true;
        }
    }
    if (in_array(strtolower(basename($relNorm)), $excludeNames, true)) {
        return true;
    }
    foreach ($excludeExts as $ext) {
        if (substr($lower, -(strlen($ext) + 1)) === '.' . $ext) {
            return true;
        }
    }
    return false;
}

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "無法建立壓縮檔：{$output}\n");
    exit(1);
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$added = 0;
foreach ($it as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    $rel = ltrim(str_replace($root, '', $path), '/\\');

    if (build_excluded($rel, $excludeParts, $excludeNames, $excludeExts)) {
        continue;
    }
    if ($path === $output) {
        continue;
    }
    if ($zip->addFile($path, 'sw/' . str_replace('\\', '/', $rel))) {
        $added++;
    }
}

$zip->close();

printf("已打包 %d 個檔案\n輸出：%s\n大小：%s bytes\n", $added, $output, number_format(filesize($output)));

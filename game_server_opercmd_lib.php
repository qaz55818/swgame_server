<?php
/**
 * =============================================================
 *  踏雪笑傲 · 熱更新指令設定工具庫（game_server_opercmd_lib.php）
 *  -------------------------------------------------------------
 *  管理遠端 opercmd.xml（XML 熱更新指令）。檔案結構：
 *    <vector name="templates">              指令模板清單
 *      <object name="templates" type="GHotRepair">
 *        zoneid / serial_id / mask            分區、序號、遮罩
 *        <object name="setconnection">        連線設定
 *        <vector name="forbidactivity">       禁用活動清單（多筆）
 *        <vector name="openactivity">         開放活動清單
 *        <vector name="setinstance">          副本設定
 *        <object name="setac">                防作弊
 *        <object name="setdoubleexp">         雙倍經驗
 *        <object name="setmemorycheck">       記憶體檢查
 *        <object name="setcashtrade">         現金交易
 *        <object name="setengamequeue">       排隊系統
 *        reserve1..4                          保留欄位
 *
 *  每個變數皆附中文說明與英文原文（XML variable name），並支援：
 *    - 透過既有檔案框架讀取／寫回（base64 快取，保留 BOM 與原格式）
 *    - 僅修改對應行的變數值，完整保留縮排、屬性與其他內容
 *    - 初始版本快照與還原
 *
 *  目標檔案代碼：gdeliveryd_opercmd（見 game_server_files_lib registry）
 *  依賴：game_server_files_lib.php（gameserver_file_read / cache / restore）
 * =============================================================
 */

if (defined('GAME_SERVER_OPERCMD_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_OPERCMD_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_files_lib.php';

if (!defined('GAME_SERVER_OPERCMD_KEY')) {
    define('GAME_SERVER_OPERCMD_KEY', 'gdeliveryd_opercmd');
}

/* ============================================================
 *  一、欄位對照表（中文說明 + 英文原文）
 * ============================================================ */
/** 物件名稱 → 中文標籤 */
function gameserver_opercmd_object_labels(): array
{
    return [
        'templates'      => '熱更新模板',
        'setconnection'  => '連線設定',
        'forbidactivity' => '禁用活動',
        'openactivity'   => '開放活動',
        'setinstance'    => '副本設定',
        'setac'          => '防作弊設定',
        'setdoubleexp'   => '雙倍經驗',
        'setmemorycheck' => '記憶體檢查',
        'setcashtrade'   => '現金交易',
        'setengamequeue' => '排隊系統',
        'hotmails'       => '熱更新郵件',
    ];
}

/**
 * 變數中文標籤與說明。
 * 先查 (物件.變數) 專屬覆寫，再查通用變數名。
 * @return array [中文標籤, 中文說明]
 */
function gameserver_opercmd_field_meta(string $obj, string $key): array
{
    $overrides = [
        'setconnection.enable'  => ['允許連線',   '是否允許玩家連線（1 允許／0 禁止）。'],
        'setac.enable'          => ['啟用防作弊', '是否啟用防作弊（1 開啟／0 關閉）。'],
        'setmemorycheck.enable' => ['記憶體檢查', '是否啟用客戶端記憶體檢查（1 開啟／0 關閉）。'],
        'setcashtrade.enable'   => ['現金交易',   '是否允許玩家間現金交易（1 允許／0 禁止）。'],
        'setengamequeue.enable' => ['排隊系統',   '是否啟用登入排隊（1 開啟／0 關閉）。'],
        'forbidactivity.index'  => ['活動索引',   '要禁用的活動類型索引。'],
        'forbidactivity.enable' => ['禁用開關',   '該活動是否禁用（1 禁用／0 不禁用）。'],
        'openactivity.index'    => ['活動索引',   '要開放的活動類型索引。'],
        'openactivity.enable'   => ['開放開關',   '該活動是否開放（1 開放／0 不開放）。'],
    ];
    if (isset($overrides[$obj . '.' . $key])) {
        return $overrides[$obj . '.' . $key];
    }

    $common = [
        'zoneid'     => ['分區編號',   '此熱更新指令目標的遊戲分區（zone）編號，0 表示不限制。'],
        'serial_id'  => ['指令序號',   '此熱更新指令的序號。'],
        'mask'       => ['遮罩',       '熱更新指令的位元遮罩值。'],
        'index'      => ['索引',       '活動／項目的類型索引。'],
        'enable'     => ['開關',       '1 = 開啟／0 = 關閉。'],
        'start_time' => ['開始時間',   '雙倍經驗活動的開始時間（Unix 時間戳，0 表示立即）。'],
        'last_time'  => ['持續時間',   '雙倍經驗活動的持續秒數（0 表示不限）。'],
        'reserve1'   => ['保留欄位 1', '系統保留欄位，未使用時為 0。'],
        'reserve2'   => ['保留欄位 2', '系統保留欄位，未使用時為 0。'],
        'reserve3'   => ['保留欄位 3', '系統保留欄位，未使用時為 0。'],
        'reserve4'   => ['保留欄位 4', '系統保留欄位，未使用時為 0。'],
    ];
    return $common[$key] ?? [$key, ''];
}

/** 由父層路徑（如 templates/setconnection）產生中文群組標題 */
function gameserver_opercmd_group_label(string $parentPath): string
{
    if ($parentPath === '') {
        return '根節點';
    }
    $labels = gameserver_opercmd_object_labels();
    // 合併連續同名節點（例如 vector/object 皆名為 forbidactivity），取最大出現序號
    $merged = [];
    foreach (explode('/', $parentPath) as $seg) {
        $base = $seg;
        $idx  = 0;
        if (preg_match('/^(.*)#(\d+)$/', $seg, $m)) {
            $base = $m[1];
            $idx  = (int)$m[2];
        }
        $last = count($merged) - 1;
        if ($last >= 0 && $merged[$last]['base'] === $base) {
            $merged[$last]['idx'] = max($merged[$last]['idx'], $idx);
        } else {
            $merged[] = ['base' => $base, 'idx' => $idx];
        }
    }
    $out = [];
    foreach ($merged as $m) {
        $lab = $labels[$m['base']] ?? $m['base'];
        $out[] = $m['idx'] > 0 ? ($lab . ' #' . ($m['idx'] + 1)) : $lab;
    }
    return implode(' · ', $out);
}

/* ============================================================
 *  二、解析 / 修改
 * ============================================================ */
/**
 * 解析 opercmd.xml。
 * @return array {
 *   nodes: [path => ['path','key','type','value','line','obj','group']],
 *   lines: array, eol: string, bom: bool
 * }
 */
function gameserver_opercmd_parse($content): array
{
    $bom = (substr((string)$content, 0, 3) === "\xEF\xBB\xBF");
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);

    $nodes = [];
    $stack = [];        // 每個元素：['name'=>物件名, 'seg'=>路徑片段]
    $counters = [];     // 同層同名物件的出現次數

    foreach ($lines as $i => $line) {
        // 開啟 object / vector（非自我閉合）
        if (preg_match('/<(object|vector)\s+name="([^"]+)"[^>]*?(\/?)>/', $line, $m)) {
            if ($m[3] === '/') {
                continue; // 自我閉合，無子節點
            }
            $name   = $m[2];
            if (empty($stack)) {
                // 文件根容器：不納入路徑、不佔用同層計數
                $stack[] = ['name' => $name, 'seg' => ''];
                continue;
            }
            $parent = implode('/', array_filter(array_column($stack, 'seg'), function ($s) { return $s !== ''; }));
            $ckey   = $parent . '|' . $name;
            $idx    = $counters[$ckey] ?? 0;
            $counters[$ckey] = $idx + 1;
            $seg = ($idx === 0) ? $name : ($name . '#' . $idx);
            $stack[] = ['name' => $name, 'seg' => $seg];
            continue;
        }

        // 關閉 object / vector
        if (preg_match('/<\/(object|vector)>/', $line)) {
            array_pop($stack);
            continue;
        }

        // 變數
        if (preg_match('/<variable\s+name="([^"]+)"\s+type="([^"]+)"\s*>(.*?)<\/variable>/', $line, $m)) {
            $parent = implode('/', array_filter(array_column($stack, 'seg'), function ($s) { return $s !== ''; }));
            $path   = ($parent === '' ? '' : $parent . '/') . $m[1];
            $top    = !empty($stack) ? $stack[count($stack) - 1] : null;
            $nodes[$path] = [
                'path'  => $path,
                'key'   => $m[1],
                'type'  => $m[2],
                'value' => $m[3],
                'line'  => $i,
                'obj'   => $top['name'] ?? '',
                'group' => $parent,
            ];
            continue;
        }
    }

    return ['nodes' => $nodes, 'lines' => $lines, 'eol' => $eol, 'bom' => $bom];
}

/**
 * 套用更新並回傳新內容。
 * $updates = [path => 值]
 * 僅在值有變動時才修改對應行，完整保留縮排、屬性、註解與 BOM。
 */
function gameserver_opercmd_apply_updates($content, array $updates): string
{
    $parsed = gameserver_opercmd_parse($content);
    $lines  = $parsed['lines'];

    foreach ($updates as $path => $val) {
        if (!isset($parsed['nodes'][$path])) {
            continue;
        }
        $node = $parsed['nodes'][$path];
        $val  = trim((string)$val);
        if ($val === '' || !preg_match('/^-?\d{1,10}$/', $val)) {
            continue;
        }
        if ((string)$val === (string)$node['value']) {
            continue;
        }
        $idx = $node['line'];
        $key = preg_quote($node['key'], '/');
        $lines[$idx] = preg_replace(
            '/(<variable\s+name="' . $key . '"\s+type="[^"]*"\s*>)[^<]*(<\/variable>)/',
            '${1}' . (int)$val . '${2}',
            $lines[$idx],
            1
        );
    }

    $out = implode($parsed['eol'], $lines);
    return $parsed['bom'] ? "\xEF\xBB\xBF" . $out : $out;
}

/* ============================================================
 *  三、遠端操作
 * ============================================================ */
/** 讀取遠端並更新快取 */
function gameserver_opercmd_refresh($link, $by = ''): array
{
    return gameserver_file_refresh($link, GAME_SERVER_OPERCMD_KEY, $by);
}

/** 還原初始版本 */
function gameserver_opercmd_restore($link, $by = ''): array
{
    return gameserver_file_restore($link, GAME_SERVER_OPERCMD_KEY, $by);
}

/** 取得目前快取內容（已解 base64 的原始位元組） */
function gameserver_opercmd_content($link): string
{
    $row = gameserver_file_get($link, GAME_SERVER_OPERCMD_KEY);
    return (string)($row['raw_cache'] ?? '');
}

/**
 * 套用更新並寫回遠端。
 * @param array $updates 見 gameserver_opercmd_apply_updates()
 */
function gameserver_opercmd_apply($link, array $updates, $by = ''): array
{
    $read = gameserver_file_read($link, GAME_SERVER_OPERCMD_KEY);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = gameserver_opercmd_apply_updates($read['content'], $updates);
    try {
        $sftp = remote_sftp($read['server'], 30);
        if (!$sftp->put($read['path'], $content)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $read['path']];
        }
        $cached = gameserver_file_cache($link, GAME_SERVER_OPERCMD_KEY, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $read['server'], 'save_opercmd', $read['path'], 'items=' . count($updates));
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

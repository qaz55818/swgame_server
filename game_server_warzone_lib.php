<?php
/**
 * =============================================================
 *  踏雪笑傲 · 戰場地圖設定工具庫（game_server_warzone_lib.php）
 *  -------------------------------------------------------------
 *  管理遠端 warzonemap.lua（Lua 表格）：
 *    - WarZoneOpenOffset[ID] = 戰場開放時間偏移（分鐘）
 *    - WarZoneMap[GROUP]     = { 地圖 ID, ... }（戰場 → 地圖對應）
 *    - MatchZoneMap[GROUP]   = { 地圖 ID, ... }（匹配 → 地圖對應）
 *    - 中文說明 + 英文原文（符號名稱／區段名）
 *    - 透過既有檔案框架讀取／寫回（base64 快取，支援 GBK/ANSI）
 *    - 初始版本快照與還原
 *
 *  目標檔案代碼：gdeliveryd_warzone（見 game_server_files_lib registry）
 *  依賴：game_server_files_lib.php（gameserver_file_read / cache / restore）
 * =============================================================
 */

if (defined('GAME_SERVER_WARZONE_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_WARZONE_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_files_lib.php';

if (!defined('GAME_SERVER_WARZONE_KEY')) {
    define('GAME_SERVER_WARZONE_KEY', 'gdeliveryd_warzone');
}

/* ============================================================
 *  一、區段說明（中文 + 英文原文）
 * ============================================================ */
/** 三個區段的中文說明（英文原文為 Lua 表名） */
function gameserver_warzone_section_map(): array
{
    return [
        'WarZoneOpenOffset' => [
            'label' => '戰場開放時間偏移',
            'desc'  => '設定各戰場開放時間的偏移量（單位：分鐘）；未設定者預設為 0。',
        ],
        'WarZoneMap' => [
            'label' => '戰場地圖對應',
            'desc'  => '將戰場編號（WarZoneMap 索引）對應到一組地圖 ID；跨服戰場依此選擇 hub。',
        ],
        'MatchZoneMap' => [
            'label' => '匹配地圖對應',
            'desc'  => '將匹配組編號（MatchZoneMap 索引，從 1 開始）對應到一組地圖 ID。',
        ],
    ];
}

/* ============================================================
 *  二、編碼轉換（檔案為 ANSI/GBK）
 * ============================================================ */
/** GBK/ANSI 位元組 → UTF-8（供顯示） */
function gameserver_warzone_to_utf8($s): string
{
    $s = (string)$s;
    if ($s === '' || !preg_match('/[\x80-\xFF]/', $s)) {
        return $s;
    }
    if (function_exists('mb_convert_encoding')) {
        $u = @mb_convert_encoding($s, 'UTF-8', 'GBK');
        if (is_string($u) && $u !== '') {
            return $u;
        }
    }
    if (function_exists('iconv')) {
        $u = @iconv('GBK', 'UTF-8//IGNORE', $s);
        if ($u !== false) {
            return $u;
        }
    }
    return $s;
}

/** UTF-8 → GBK/ANSI 位元組（供寫回） */
function gameserver_warzone_from_utf8($s): string
{
    $s = (string)$s;
    if ($s === '' || !preg_match('/[\x{0080}-\x{FFFF}]/u', $s)) {
        return $s;
    }
    if (function_exists('mb_convert_encoding')) {
        $g = @mb_convert_encoding($s, 'GBK', 'UTF-8');
        if (is_string($g) && $g !== '') {
            return $g;
        }
    }
    if (function_exists('iconv')) {
        $g = @iconv('UTF-8', 'GBK//IGNORE', $s);
        if ($g !== false) {
            return $g;
        }
    }
    return $s;
}

/* ============================================================
 *  三、解析 / 修改
 * ============================================================ */
/**
 * 解析 warzonemap.lua。
 * @return array {
 *   offset:    [id => ['value'=>int,'line'=>int]],
 *   warzone:   [group => ['line'=>int,'items'=>[ ['value'=>int,'comment'=>utf8,'has_comment'=>bool,'line'=>int], ... ]]],
 *   matchzone: [group => 同上],
 *   lines: array, eol: string
 * }
 */
function gameserver_warzone_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);

    $offset    = [];
    $warzone   = [];
    $matchzone = [];
    $block     = '';
    $curGroup  = null;

    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*WarZoneOpenOffset\s*=\s*\{\s*$/', $line)) {
            $block = 'offset';
            continue;
        }
        if (preg_match('/^\s*WarZoneMap\[(\d+)\]\s*=\s*\{\s*$/', $line, $m)) {
            $curGroup = (int)$m[1];
            $warzone[$curGroup] = ['line' => $i, 'items' => []];
            $block = 'wargroup';
            continue;
        }
        if (preg_match('/^\s*MatchZoneMap\[(\d+)\]\s*=\s*\{\s*$/', $line, $m)) {
            $curGroup = (int)$m[1];
            $matchzone[$curGroup] = ['line' => $i, 'items' => []];
            $block = 'matchgroup';
            continue;
        }
        if ($block !== '' && preg_match('/^\s*\}\s*$/', $line)) {
            $block = '';
            $curGroup = null;
            continue;
        }

        if ($block === 'offset') {
            if (preg_match('/^\s*\[(\d+)\]\s*=\s*(-?\d+)\s*,?/', $line, $m)) {
                $offset[(int)$m[1]] = ['value' => (int)$m[2], 'line' => $i];
            }
        } elseif ($block === 'wargroup' || $block === 'matchgroup') {
            if ($curGroup !== null && preg_match('/^\s*(\d+)\s*,?\s*(?:--\s?(.*?))?\s*$/', $line, $m)) {
                $item = [
                    'value'       => (int)$m[1],
                    'comment'     => gameserver_warzone_to_utf8(isset($m[2]) ? $m[2] : ''),
                    'has_comment' => isset($m[2]),
                    'line'        => $i,
                ];
                if ($block === 'wargroup') {
                    $warzone[$curGroup]['items'][] = $item;
                } else {
                    $matchzone[$curGroup]['items'][] = $item;
                }
            }
        }
    }

    return [
        'offset'    => $offset,
        'warzone'   => $warzone,
        'matchzone' => $matchzone,
        'lines'     => $lines,
        'eol'       => $eol,
    ];
}

/**
 * 套用更新並回傳新內容。
 * $updates = [
 *   'offset'    => [id => 值],
 *   'warzone'   => [group => [idx => ['value'=>, 'comment'=>]]],
 *   'matchzone' => [group => [idx => ['value'=>, 'comment'=>]]],
 * ]
 * 僅在值有變動時才修改對應行，完整保留縮排、註解與其他內容。
 */
function gameserver_warzone_apply_updates($content, array $updates): string
{
    $parsed = gameserver_warzone_parse($content);
    $lines  = $parsed['lines'];

    // 一、開放時間偏移
    if (!empty($updates['offset']) && is_array($updates['offset'])) {
        foreach ($updates['offset'] as $id => $val) {
            $id = (int)$id;
            if (!isset($parsed['offset'][$id])) {
                continue;
            }
            $val = trim((string)$val);
            if ($val === '' || !preg_match('/^-?\d{1,9}$/', $val)) {
                continue;
            }
            if ((int)$val === $parsed['offset'][$id]['value']) {
                continue;
            }
            $idx = $parsed['offset'][$id]['line'];
            $lines[$idx] = preg_replace(
                '/^(\s*\[\s*' . $id . '\s*\]\s*=\s*)-?\d+/',
                '${1}' . (int)$val,
                $lines[$idx],
                1
            );
        }
    }

    // 二、地圖群組（WarZoneMap / MatchZoneMap）
    foreach (['warzone' => 'WarZoneMap', 'matchzone' => 'MatchZoneMap'] as $key => $tbl) {
        if (empty($updates[$key]) || !is_array($updates[$key])) {
            continue;
        }
        foreach ($updates[$key] as $gid => $items) {
            $gid = (int)$gid;
            if (!isset($parsed[$key][$gid]) || !is_array($items)) {
                continue;
            }
            $orig = $parsed[$key][$gid]['items'];
            foreach ($items as $idx => $u) {
                $idx = (int)$idx;
                if (!isset($orig[$idx]) || !is_array($u)) {
                    continue;
                }
                $lineNo = $orig[$idx]['line'];

                // 地圖 ID
                if (isset($u['value'])) {
                    $val = trim((string)$u['value']);
                    if ($val !== '' && preg_match('/^-?\d{1,9}$/', $val) && (int)$val !== $orig[$idx]['value']) {
                        $lines[$lineNo] = preg_replace('/^(\s*)-?\d+/', '${1}' . (int)$val, $lines[$lineNo], 1);
                    }
                }

                // 地圖名稱（-- 註解）；以字串切割避免 GBK 反斜線問題
                if (array_key_exists('comment', $u)) {
                    $new = (string)$u['comment'];
                    if ($new !== $orig[$idx]['comment']) {
                        $gbk = gameserver_warzone_from_utf8($new);
                        $pos = strpos($lines[$lineNo], '--');
                        if ($pos !== false) {
                            $lines[$lineNo] = substr($lines[$lineNo], 0, $pos) . '--' . $gbk;
                        } else {
                            $lines[$lineNo] = rtrim($lines[$lineNo]) . '--' . $gbk;
                        }
                    }
                }
            }
        }
    }

    return implode($parsed['eol'], $lines);
}

/* ============================================================
 *  四、遠端操作
 * ============================================================ */
/** 讀取遠端並更新快取 */
function gameserver_warzone_refresh($link, $by = ''): array
{
    return gameserver_file_refresh($link, GAME_SERVER_WARZONE_KEY, $by);
}

/** 還原初始版本 */
function gameserver_warzone_restore($link, $by = ''): array
{
    return gameserver_file_restore($link, GAME_SERVER_WARZONE_KEY, $by);
}

/** 取得目前快取內容（已解 base64 的原始位元組） */
function gameserver_warzone_content($link): string
{
    $row = gameserver_file_get($link, GAME_SERVER_WARZONE_KEY);
    return (string)($row['raw_cache'] ?? '');
}

/**
 * 套用更新並寫回遠端。
 * @param array $updates 見 gameserver_warzone_apply_updates()
 */
function gameserver_warzone_apply($link, array $updates, $by = ''): array
{
    $read = gameserver_file_read($link, GAME_SERVER_WARZONE_KEY);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = gameserver_warzone_apply_updates($read['content'], $updates);
    try {
        $sftp = remote_sftp($read['server'], 30);
        if (!$sftp->put($read['path'], $content)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $read['path']];
        }
        $cached = gameserver_file_cache($link, GAME_SERVER_WARZONE_KEY, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $read['server'], 'save_warzone', $read['path'], '');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

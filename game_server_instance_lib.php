<?php
/**
 * =============================================================
 *  踏雪笑傲 · 副本設定工具庫（game_server_instance_lib.php）
 *  -------------------------------------------------------------
 *  管理遠端 gdeliveryd/instance_common.lua（Lua 表格 InstInfo_Common）。
 *    - 解析每張地圖（InstInfo_Common[N]）的詳細參數
 *    - 中文說明 + 英文原文欄位對照
 *    - 透過既有檔案框架讀取／寫回（base64 快取，支援 GBK/ANSI 編碼）
 *    - 初始版本快照與還原
 *
 *  目標檔案代碼：gdeliveryd_instance（見 game_server_files_lib registry）
 *  依賴：game_server_files_lib.php（gameserver_file_read / cache / restore）
 * =============================================================
 */

if (defined('GAME_SERVER_INSTANCE_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_INSTANCE_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_files_lib.php';

if (!defined('GAME_SERVER_INSTANCE_KEY')) {
    define('GAME_SERVER_INSTANCE_KEY', 'gdeliveryd_instance');
}

/* ============================================================
 *  一、欄位對照表（中文說明 + 英文原文）
 * ============================================================ */
function gameserver_instance_field_map(): array
{
    return [
        'base_path'              => ['label' => '基礎路徑',            'type' => 'text'],
        'battlefields'           => ['label' => '戰場 ID 列表',        'type' => 'text'],
        'sub_path'               => ['label' => '子路徑',              'type' => 'text'],
        'inst_type'              => ['label' => '副本類型',            'type' => 'int'],
        'map_name'               => ['label' => '地圖名稱',            'type' => 'text'],
        'map_type'               => ['label' => '地圖類型',            'type' => 'int'],
        'pk_type'                => ['label' => 'PK 模式',             'type' => 'int'],
        'map_level'              => ['label' => '等級需求',            'type' => 'int'],
        'pk_level_min'           => ['label' => 'PK 最低等級',         'type' => 'int'],
        'pk_level_max'           => ['label' => 'PK 最高等級',         'type' => 'int'],
        'team_kickout_need_vote' => ['label' => '踢出需投票',          'type' => 'int'],
        'can_autofight'          => ['label' => '允許自動戰鬥',        'type' => 'bool'],
        'special_scene_type'     => ['label' => '特殊場景類型',        'type' => 'int'],
        'special_scene_param'    => ['label' => '特殊場景參數 1',      'type' => 'int'],
        'special_scene_param2'   => ['label' => '特殊場景參數 2',      'type' => 'int'],
        'special_scene_param3'   => ['label' => '特殊場景參數 3（座標 X,Y,Z）', 'type' => 'vec3'],
    ];
}

/* ============================================================
 *  二、編碼轉換（檔案為 ANSI/GBK）
 * ============================================================ */
/** GBK/ANSI 位元組 → UTF-8（供顯示） */
function gameserver_instance_to_utf8($s): string
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
function gameserver_instance_from_utf8($s): string
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
 * 解析 Lua 內容為地圖清單。
 * @return array { entries: array, lines: array, eol: string }
 *   entries[id] = ['id'=>, 'name'=>, 'fields'=>[key=>['value'=>,'prefix'=>,'suffix'=>,'raw'=>]]]
 */
function gameserver_instance_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);
    $entries = [];
    $cur = null;

    foreach ($lines as $i => $line) {
        if (preg_match('/^InstInfo_Common\[(\d+)\]\s*=\s*\{\s*$/', $line, $m)) {
            $cur = (int)$m[1];
            $entries[$cur] = ['id' => $cur, 'name' => '', 'fields' => []];
            continue;
        }
        if ($cur !== null && preg_match('/^\}\s*$/', $line)) {
            $cur = null;
            continue;
        }
        if ($cur === null) {
            continue;
        }
        if (preg_match('/^(\s*)([A-Za-z0-9_]+)(\s*=\s*)(.*?)(,?)(\s*)$/', $line, $m)) {
            $key   = $m[2];
            $raw   = $m[4];
            $quoted = (strlen($raw) >= 2 && $raw[0] === '"' && substr($raw, -1) === '"');
            $display = $quoted ? substr($raw, 1, -1) : $raw;
            $display = gameserver_instance_to_utf8($display);
            $entries[$cur]['fields'][$key] = [
                'value'  => $display,
                'raw'    => $raw,
                'quoted' => $quoted,
                'line'   => $i,
                'prefix' => $m[1] . $m[2] . $m[3],
                'suffix' => $m[5] . $m[6],
            ];
            if ($key === 'map_name') {
                $entries[$cur]['name'] = $display;
            }
        }
    }
    return ['entries' => $entries, 'lines' => $lines, 'eol' => $eol];
}

/** 正規化座標向量 {a,b,c} */
function gameserver_instance_normalize_vec3($val): string
{
    $val = trim((string)$val);
    $val = trim($val, '{}');
    $parts = preg_split('/\s*,\s*/', $val);
    $nums = [];
    foreach (array_slice($parts, 0, 3) as $p) {
        if ($p === '') {
            continue;
        }
        $nums[] = (strpos($p, '.') !== false) ? (string)(float)$p : (string)(int)$p;
    }
    while (count($nums) < 3) {
        $nums[] = '0';
    }
    return '{' . implode(',', array_slice($nums, 0, 3)) . '}';
}

/**
 * 套用更新：$updates[id][key] = 新值（UTF-8 顯示值）。
 * 僅修改對應行，保留縮排、逗號與其他內容。
 */
function gameserver_instance_apply_updates($content, array $updates): string
{
    $parsed = gameserver_instance_parse($content);
    $lines  = $parsed['lines'];
    $map    = gameserver_instance_field_map();

    foreach ($parsed['entries'] as $id => $entry) {
        if (!isset($updates[$id]) || !is_array($updates[$id])) {
            continue;
        }
        foreach ($entry['fields'] as $key => $info) {
            if (!array_key_exists($key, $updates[$id])) {
                continue;
            }
            $val  = (string)$updates[$id][$key];
            $type = $map[$key]['type'] ?? 'text';
            if ($type === 'bool') {
                $newVal = (strtolower(trim($val)) === 'true' || trim($val) === '1') ? 'true' : 'false';
            } elseif ($type === 'int') {
                $newVal = (string)(int)$val;
            } elseif ($type === 'vec3') {
                $newVal = gameserver_instance_normalize_vec3($val);
            } else {
                $newVal = '"' . str_replace('"', '', gameserver_instance_from_utf8($val)) . '"';
            }
            $lines[$info['line'] ?? -1] = $info['prefix'] . $newVal . $info['suffix'];
        }
    }
    return implode($parsed['eol'], $lines);
}

/* ============================================================
 *  四、遠端操作
 * ============================================================ */
/** 讀取遠端並更新快取 */
function gameserver_instance_refresh($link, $by = ''): array
{
    return gameserver_file_refresh($link, GAME_SERVER_INSTANCE_KEY, $by);
}

/** 還原初始版本 */
function gameserver_instance_restore($link, $by = ''): array
{
    return gameserver_file_restore($link, GAME_SERVER_INSTANCE_KEY, $by);
}

/** 取得目前快取內容（已解 base64 的原始位元組） */
function gameserver_instance_content($link): string
{
    $row = gameserver_file_get($link, GAME_SERVER_INSTANCE_KEY);
    return (string)($row['raw_cache'] ?? '');
}

/**
 * 套用更新並寫回遠端。
 * @param array $updates id => [ key => 新值(UTF-8) ]
 */
function gameserver_instance_apply($link, array $updates, $by = ''): array
{
    $read = gameserver_file_read($link, GAME_SERVER_INSTANCE_KEY);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = gameserver_instance_apply_updates($read['content'], $updates);
    try {
        $sftp = remote_sftp($read['server'], 30);
        if (!$sftp->put($read['path'], $content)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $read['path']];
        }
        $cached = gameserver_file_cache($link, GAME_SERVER_INSTANCE_KEY, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $read['server'], 'save_instance', $read['path'], 'maps=' . count($updates));
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

<?php
/**
 * =============================================================
 *  踏雪笑傲 · 傳送資訊設定工具庫（game_server_transinfo_lib.php）
 *  -------------------------------------------------------------
 *  管理遠端 transinfo.lua（Lua 表格）：
 *    - TransNPC[map]            = { [1] = { ID=, x=, y=, z= } }   場景傳送點
 *    - FactionAdministrator[map]= { [1] = { ID=, x=, y=, z= } }   幫派管理員
 *    - FactionTransNPC[map]     = { [1] = { ID=, x=, y=, z= } }   幫派傳送 NPC
 *    - RaidDungeons[id]         = { ID=, x=, y=, z= }             副本尋徑
 *    - AdjInfo[from][to]        = { TransBox=, Name=, x=, y=, z= } 相鄰傳送盒
 *    - TransmitInfo[from][to]   = { to }                          可傳送關係
 *    - GuideInfo[from][to]      = { 途經序列 }                    引導路徑
 *    - 中文說明 + 英文原文（欄位名稱）
 *    - 透過既有檔案框架讀取／寫回（base64 快取）
 *    - 初始版本快照與還原
 *
 *  目標檔案代碼：gdeliveryd_transinfo（見 game_server_files_lib registry）
 *  依賴：game_server_files_lib.php（gameserver_file_read / cache / restore）
 * =============================================================
 */

if (defined('GAME_SERVER_TRANSINFO_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_TRANSINFO_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_files_lib.php';

if (!defined('GAME_SERVER_TRANSINFO_KEY')) {
    define('GAME_SERVER_TRANSINFO_KEY', 'gdeliveryd_transinfo');
}

/* ============================================================
 *  一、區段與欄位對照（中文說明 + 英文原文）
 * ============================================================ */
function gameserver_transinfo_section_map(): array
{
    return [
        'transnpc'     => ['label' => '場景傳送 NPC', 'table' => 'TransNPC',
            'desc' => '記錄各地圖（場景）中傳送 NPC 的 ID 與座標；下標為地圖編號。'],
        'factionadmin' => ['label' => '幫派管理員', 'table' => 'FactionAdministrator',
            'desc' => '幫派地圖中的管理員 NPC（ID 與座標）。'],
        'factiontrans' => ['label' => '幫派傳送 NPC', 'table' => 'FactionTransNPC',
            'desc' => '大地圖上可尋徑至幫派的傳送 NPC（ID 與座標）。'],
        'raiddungeons' => ['label' => '副本尋徑', 'table' => 'RaidDungeons',
            'desc' => '副本與大地圖之間的尋徑目標（ID 與座標）。'],
        'adjinfo'      => ['label' => '相鄰地圖傳送盒', 'table' => 'AdjInfo',
            'desc' => '相鄰地圖之間透過傳送盒子的連接關係（出發圖 → 目的圖）。'],
        'transmitinfo' => ['label' => '地圖傳送關係', 'table' => 'TransmitInfo',
            'desc' => '地圖之間是否可傳送（出發圖 → 可到達圖）。'],
        'guideinfo'    => ['label' => '引導路徑', 'table' => 'GuideInfo',
            'desc' => '自動尋路時的引導資訊（出發圖 → 目的圖的途經地圖序列）。'],
    ];
}

/** NPC 型（單一 NPC）欄位對照：key => [中文, 型別] */
function gameserver_transinfo_npc_fields(): array
{
    return [
        'ID' => ['NPC 編號', 'int'],
        'x'  => ['X 座標', 'float'],
        'y'  => ['Y 座標', 'float'],
        'z'  => ['Z 座標', 'float'],
    ];
}

/** 相鄰傳送盒欄位對照 */
function gameserver_transinfo_adj_fields(): array
{
    return [
        'TransBox' => ['傳送盒 ID', 'int'],
        'x'        => ['X 座標', 'float'],
        'y'        => ['Y 座標', 'float'],
        'z'        => ['Z 座標', 'float'],
    ];
}

/* ============================================================
 *  二、編碼（本檔一般為 UTF-8；非 UTF-8 時退回 GBK）
 * ============================================================ */
function gameserver_transinfo_to_utf8($s): string
{
    $s = (string)$s;
    if ($s === '' || !preg_match('/[\x80-\xFF]/', $s)) {
        return $s;
    }
    if (function_exists('mb_check_encoding') && mb_check_encoding($s, 'UTF-8')) {
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

/* ============================================================
 *  三、解析
 * ============================================================ */
/**
 * 解析 transinfo.lua。
 * @return array 含 transnpc / factionadmin / factiontrans / raiddungeons /
 *                adjinfo / transmitinfo / guideinfo / lines / eol
 */
function gameserver_transinfo_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);

    $npcKeyOf = [
        'TransNPC'             => 'transnpc',
        'FactionAdministrator' => 'factionadmin',
        'FactionTransNPC'      => 'factiontrans',
        'RaidDungeons'         => 'raiddungeons',
    ];

    $out = [
        'transnpc' => [], 'factionadmin' => [], 'factiontrans' => [], 'raiddungeons' => [],
        'adjinfo' => [], 'transmitinfo' => [], 'guideinfo' => [],
    ];

    $block = '';      // 'adj' | 'transmit' | 'guide'
    $curFrom = null;

    // 相鄰傳送盒單列解析（可在一行中找到多個）
    $adjRe = '/\[(\d+)\]\s*=\s*\{\s*TransBox\s*=\s*(-?\d+)\s*,\s*Name\s*=\s*(.*?)\s*,\s*x\s*=\s*(-?[\d.]+)\s*,\s*y\s*=\s*(-?[\d.]+)\s*,\s*z\s*=\s*(-?[\d.]+)\s*\}/';

    foreach ($lines as $i => $line) {
        $t = ltrim($line);
        if ($t === '' || substr($t, 0, 2) === '--') {
            continue;
        }

        // NPC 型單行表
        if (preg_match('/^\s*(TransNPC|FactionAdministrator|FactionTransNPC|RaidDungeons)\[(\d+)\]\s*=\s*\{\s*\[(\d+)\]\s*=\s*\{\s*ID\s*=\s*(-?\d+)\s*,\s*x\s*=\s*(-?[\d.]+)\s*,\s*y\s*=\s*(-?[\d.]+)\s*,\s*z\s*=\s*(-?[\d.]+)\s*\}\s*\}\s*$/', $line, $m)) {
            $key = $npcKeyOf[$m[1]];
            $out[$key][(int)$m[2]] = [
                'line' => $i,
                'ID'   => (int)$m[4],
                'x'    => $m[5],
                'y'    => $m[6],
                'z'    => $m[7],
            ];
            continue;
        }

        // 區段起點
        if (preg_match('/^\s*AdjInfo\[(\d+)\]\s*=\s*\{\s*(.*)$/', $line, $m)) {
            $block = 'adj';
            $curFrom = (int)$m[1];
            $out['adjinfo'][$curFrom] = ['line' => $i, 'dests' => []];
            if (trim($m[2]) !== '' && preg_match_all($adjRe, $m[2], $mm, PREG_SET_ORDER)) {
                foreach ($mm as $d) {
                    $out['adjinfo'][$curFrom]['dests'][] = [
                        'to'       => (int)$d[1],
                        'TransBox' => (int)$d[2],
                        'name'     => gameserver_transinfo_to_utf8($d[3]),
                        'x'        => $d[4],
                        'y'        => $d[5],
                        'z'        => $d[6],
                        'line'     => $i,
                    ];
                }
            }
            continue;
        }
        if (preg_match('/^\s*TransmitInfo\[(\d+)\]\s*=\s*\{\s*$/', $line, $m)) {
            $block = 'transmit';
            $curFrom = (int)$m[1];
            $out['transmitinfo'][$curFrom] = ['line' => $i, 'dests' => []];
            continue;
        }
        if (preg_match('/^\s*GuideInfo\[(\d+)\]\s*=\s*\{\s*$/', $line, $m)) {
            $block = 'guide';
            $curFrom = (int)$m[1];
            $out['guideinfo'][$curFrom] = ['line' => $i, 'dests' => []];
            continue;
        }

        if ($block !== '') {
            if (preg_match('/^\s*\}\s*$/', $line)) {
                $block = '';
                $curFrom = null;
                continue;
            }
            if ($block === 'adj') {
                if (preg_match_all($adjRe, $line, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $d) {
                        $out['adjinfo'][$curFrom]['dests'][] = [
                            'to'       => (int)$d[1],
                            'TransBox' => (int)$d[2],
                            'name'     => gameserver_transinfo_to_utf8($d[3]),
                            'x'        => $d[4],
                            'y'        => $d[5],
                            'z'        => $d[6],
                            'line'     => $i,
                        ];
                    }
                }
            } elseif ($block === 'transmit') {
                if (preg_match('/^\s*\[(\d+)\]\s*=\s*\{\s*(-?\d+)\s*\}\s*,?\s*$/', $line, $m)) {
                    $out['transmitinfo'][$curFrom]['dests'][] = [
                        'to'    => (int)$m[1],
                        'value' => (int)$m[2],
                        'line'  => $i,
                    ];
                }
            } elseif ($block === 'guide') {
                if (preg_match('/^\s*\[(\d+)\]\s*=\s*\{\s*([\d,\s]*)\}\s*,?\s*$/', $line, $m)) {
                    $out['guideinfo'][$curFrom]['dests'][] = [
                        'to'    => (int)$m[1],
                        'path'  => trim($m[2]),
                        'line'  => $i,
                    ];
                }
            }
        }
    }

    $out['lines'] = $lines;
    $out['eol']   = $eol;
    return $out;
}

/* ============================================================
 *  四、套用更新
 * ============================================================ */
/**
 * $updates = [
 *   'transnpc'|'factionadmin'|'factiontrans'|'raiddungeons' => [map => ['ID'=>,'x'=>,'y'=>,'z'=>]],
 *   'adjinfo'      => [from => [to => ['TransBox'=>,'x'=>,'y'=>,'z']]],
 *   'transmitinfo' => [from => [to => ['value'=>]]],
 *   'guideinfo'    => [from => [to => ['path'=>]]],
 * ]
 * 僅在值有變動時才修改對應行，完整保留其他內容。
 */
function gameserver_transinfo_apply_updates($content, array $updates): string
{
    $parsed = gameserver_transinfo_parse($content);
    $lines  = $parsed['lines'];
    $isInt  = function ($v) { return preg_match('/^-?\d{1,12}$/', $v); };
    $isNum  = function ($v) { return preg_match('/^-?\d{1,12}(\.\d+)?$/', $v); };

    // 一、NPC 型表
    foreach (['transnpc', 'factionadmin', 'factiontrans', 'raiddungeons'] as $key) {
        if (empty($updates[$key]) || !is_array($updates[$key])) {
            continue;
        }
        foreach ($updates[$key] as $map => $f) {
            $map = (int)$map;
            if (!isset($parsed[$key][$map]) || !is_array($f)) {
                continue;
            }
            $idx = $parsed[$key][$map]['line'];
            $src = $parsed[$key][$map];
            if (isset($f['ID']) && $isInt(trim((string)$f['ID'])) && (int)$f['ID'] !== $src['ID']) {
                $lines[$idx] = preg_replace('/(\bID\s*=\s*)-?\d+/', '${1}' . (int)$f['ID'], $lines[$idx], 1);
            }
            foreach (['x', 'y', 'z'] as $axis) {
                if (isset($f[$axis]) && $isNum(trim((string)$f[$axis])) && (string)$f[$axis] !== $src[$axis]) {
                    $lines[$idx] = preg_replace('/(\b' . $axis . '\s*=\s*)-?[\d.]+/', '${1}' . $f[$axis], $lines[$idx], 1);
                }
            }
        }
    }

    // 二、相鄰傳送盒
    if (!empty($updates['adjinfo']) && is_array($updates['adjinfo'])) {
        foreach ($updates['adjinfo'] as $from => $dests) {
            $from = (int)$from;
            if (!isset($parsed['adjinfo'][$from]) || !is_array($dests)) {
                continue;
            }
            $byTo = [];
            foreach ($parsed['adjinfo'][$from]['dests'] as $d) {
                $byTo[$d['to']] = $d;
            }
            foreach ($dests as $to => $f) {
                $to = (int)$to;
                if (!isset($byTo[$to]) || !is_array($f)) {
                    continue;
                }
                $idx = $byTo[$to]['line'];
                $src = $byTo[$to];
                if (isset($f['TransBox']) && $isInt(trim((string)$f['TransBox'])) && (int)$f['TransBox'] !== $src['TransBox']) {
                    $lines[$idx] = preg_replace('/(\[\s*' . $to . '\s*\]\s*=\s*\{\s*TransBox\s*=\s*)-?\d+/', '${1}' . (int)$f['TransBox'], $lines[$idx], 1);
                }
                foreach (['x', 'y', 'z'] as $axis) {
                    if (isset($f[$axis]) && $isNum(trim((string)$f[$axis])) && (string)$f[$axis] !== $src[$axis]) {
                        $lines[$idx] = preg_replace('/(\b' . $axis . '\s*=\s*)-?[\d.]+/', '${1}' . $f[$axis], $lines[$idx], 1);
                    }
                }
            }
        }
    }

    // 三、可傳送關係
    if (!empty($updates['transmitinfo']) && is_array($updates['transmitinfo'])) {
        foreach ($updates['transmitinfo'] as $from => $dests) {
            $from = (int)$from;
            if (!isset($parsed['transmitinfo'][$from]) || !is_array($dests)) {
                continue;
            }
            $byTo = [];
            foreach ($parsed['transmitinfo'][$from]['dests'] as $d) {
                $byTo[$d['to']] = $d;
            }
            foreach ($dests as $to => $f) {
                $to = (int)$to;
                if (!isset($byTo[$to]) || !is_array($f) || !isset($f['value'])) {
                    continue;
                }
                $val = trim((string)$f['value']);
                if (!$isInt($val) || (int)$val === $byTo[$to]['value']) {
                    continue;
                }
                $idx = $byTo[$to]['line'];
                $lines[$idx] = preg_replace('/(\[\s*' . $to . '\s*\]\s*=\s*\{\s*)-?\d+/', '${1}' . (int)$val, $lines[$idx], 1);
            }
        }
    }

    // 四、引導路徑
    if (!empty($updates['guideinfo']) && is_array($updates['guideinfo'])) {
        foreach ($updates['guideinfo'] as $from => $dests) {
            $from = (int)$from;
            if (!isset($parsed['guideinfo'][$from]) || !is_array($dests)) {
                continue;
            }
            $byTo = [];
            foreach ($parsed['guideinfo'][$from]['dests'] as $d) {
                $byTo[$d['to']] = $d;
            }
            foreach ($dests as $to => $f) {
                $to = (int)$to;
                if (!isset($byTo[$to]) || !is_array($f) || !isset($f['path'])) {
                    continue;
                }
                $raw = trim((string)$f['path']);
                if ($raw === '' || !preg_match('/^[\d,\s]+$/', $raw)) {
                    continue;
                }
                $parts = array_filter(array_map('trim', explode(',', $raw)), function ($p) { return $p !== '' && ctype_digit($p); });
                $new = implode(',', $parts);
                if ($new === '' || $new === $byTo[$to]['path']) {
                    continue;
                }
                $idx = $byTo[$to]['line'];
                $lines[$idx] = preg_replace('/(\[\s*' . $to . '\s*\]\s*=\s*\{)[^}]*(\})/', '${1}' . $new . '${2}', $lines[$idx], 1);
            }
        }
    }

    return implode($parsed['eol'], $lines);
}

/* ============================================================
 *  五、遠端操作
 * ============================================================ */
function gameserver_transinfo_refresh($link, $by = ''): array
{
    return gameserver_file_refresh($link, GAME_SERVER_TRANSINFO_KEY, $by);
}

function gameserver_transinfo_restore($link, $by = ''): array
{
    return gameserver_file_restore($link, GAME_SERVER_TRANSINFO_KEY, $by);
}

function gameserver_transinfo_content($link): string
{
    $row = gameserver_file_get($link, GAME_SERVER_TRANSINFO_KEY);
    return (string)($row['raw_cache'] ?? '');
}

function gameserver_transinfo_apply($link, array $updates, $by = ''): array
{
    $read = gameserver_file_read($link, GAME_SERVER_TRANSINFO_KEY);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = gameserver_transinfo_apply_updates($read['content'], $updates);
    try {
        $sftp = remote_sftp($read['server'], 30);
        if (!$sftp->put($read['path'], $content)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $read['path']];
        }
        $cached = gameserver_file_cache($link, GAME_SERVER_TRANSINFO_KEY, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $read['server'], 'save_transinfo', $read['path'], '');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

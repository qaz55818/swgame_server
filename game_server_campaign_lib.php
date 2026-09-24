<?php
/**
 * =============================================================
 *  踏雪笑傲 · 活動設定工具庫（game_server_campaign_lib.php）
 *  -------------------------------------------------------------
 *  管理遠端 gdeliveryd/campaignlist_cn.lua（Lua 表格 CAMPAIGN_LIST）。
 *    - 解析每個活動（CAMPAIGN_LIST[N]）的詳細參數（含巢狀清單）
 *    - 中文說明 + 英文原文欄位對照
 *    - 透過既有檔案框架讀取／寫回（base64 快取，支援 GBK/ANSI）
 *    - 初始版本快照與還原
 *
 *  目標檔案代碼：gdeliveryd_campaign（見 game_server_files_lib registry）
 * =============================================================
 */

if (defined('GAME_SERVER_CAMPAIGN_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_CAMPAIGN_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_files_lib.php';

if (!defined('GAME_SERVER_CAMPAIGN_KEY')) {
    define('GAME_SERVER_CAMPAIGN_KEY', 'gdeliveryd_campaign');
}

/* ============================================================
 *  一、欄位對照表（中文說明 + 英文原文）
 * ============================================================ */
/** 活動頂層純量欄位 */
function gameserver_campaign_scalar_map(): array
{
    return [
        'time_type'      => ['label' => '時間類型',   'type' => 'enum'],
        'open_count'     => ['label' => '可開啟次數', 'type' => 'int'],
        'forbid_zoneids' => ['label' => '禁止分區',   'type' => 'int'],
    ];
}

/** 清單欄位（每個清單內各列的欄位） */
function gameserver_campaign_list_map(): array
{
    return [
        'tids'                 => ['label' => '活動模板 ID',   'fields' => ['TID' => '模板 ID', 'RATE' => '機率']],
        'speakids'             => ['label' => '預告廣播',      'fields' => ['PRE_MIN' => '提前分鐘']],
        'time_sect'            => ['label' => '時段設定',      'fields' => [
            'YEAR' => '年', 'MONTH' => '月', 'DAY' => '日', 'MONTHWEEK' => '月第幾週',
            'WEEK' => '星期', 'HOUR' => '時', 'MIN' => '分', 'SEC' => '秒', 'LAST_TIME' => '持續秒數',
        ]],
        'toplist_conditions'   => ['label' => '排行榜條件',    'fields' => [
            'TOPLIST_ID' => '排行榜 ID', 'RANK' => '名次', 'VALUE' => '數值', 'RETCODE' => '返回碼',
        ]],
        'open_server_condition' => ['label' => '開服條件',     'fields' => ['AFTER_HOUR' => '開服後小時', 'RETCODE' => '返回碼']],
        'zoneids'              => ['label' => '分區 ID',       'fields' => ['ZONEID' => '分區 ID']],
    ];
}

/** 時間類型列舉（CTT.*） */
function gameserver_campaign_time_types(): array
{
    return [
        'CTT.CTT_PER_HOUR'     => '每小時',
        'CTT.CTT_PER_DAY'      => '每日',
        'CTT.CTT_PER_WEEK'     => '每週',
        'CTT.CTT_PER_MONTHWEEK' => '每月第幾週',
        'CTT.CTT_PER_YEAR'     => '每年',
        'CTT.CTT_PER_YEAR2'    => '每年（指定年份）',
        'CTT.CTT_ALL_TIME_OPEN'  => '全時開放',
        'CTT.CTT_ALL_TIME_CLOSE' => '全時關閉',
    ];
}

/* ============================================================
 *  二、編碼轉換（檔案為 ANSI/GBK）
 * ============================================================ */
function gameserver_campaign_to_utf8($s): string
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

function gameserver_campaign_from_utf8($s): string
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
 *  三、解析
 * ============================================================ */
/**
 * 解析 campaignlist_cn.lua。
 * @return array { campaigns: array, lines: array, eol: string }
 */
function gameserver_campaign_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);

    $scalarMap = gameserver_campaign_scalar_map();
    $listMap   = gameserver_campaign_list_map();
    $listKeys  = array_keys($listMap);

    $campaigns = [];
    $cur = null;          // 目前活動 ID
    $curList = null;      // 目前清單 key
    $listDepth = 0;       // 清單內大括號深度
    $awaitBrace = false;  // 已見 `key =`，等待 `{`
    $lastComment = '';

    $rowFields = function ($key, $line) use ($listMap) {
        $out = [];
        foreach ($listMap[$key]['fields'] as $fk => $fl) {
            if (preg_match('/\b' . preg_quote($fk, '/') . '\s*=\s*(-?[\d.]+)/', $line, $m)) {
                $out[$fk] = $m[1];
            }
        }
        return $out;
    };

    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = $lines[$i];
        $t = trim($line);

        if ($cur === null) {
            if (preg_match('/^--(.*)$/', $t, $m)) {
                $lastComment = trim($m[1]);
                continue;
            }
            if (preg_match('/^CAMPAIGN_LIST\[(\d+)\]\s*=\s*$/', $t, $m)) {
                $cur = (int)$m[1];
                $campaigns[$cur] = [
                    'id' => $cur,
                    'name' => gameserver_campaign_to_utf8($lastComment),
                    'scalars' => [],
                    'lists' => [],
                    'open_server' => [],
                    'open_server_line' => -1,
                ];
                $lastComment = '';
                continue;
            }
            continue;
        }

        // 已在清單區塊內
        if ($curList !== null) {
            if ($awaitBrace) {
                $awaitBrace = false;
                $listDepth = 1;
                // 開服條件的內容行（緊接於 { 之後）
                if ($curList === 'open_server_condition') {
                    $campaigns[$cur]['open_server_line'] = $i + 1;
                }
                continue;
            }
            $open = substr_count($t, '{');
            $close = substr_count($t, '}');
            $net = $open - $close;

            if ($listDepth === 1 && $net === 0 && $t !== '' && $t[0] !== ';' && strpos($t, '--') !== 0) {
                // 一列資料
                if ($curList === 'open_server_condition') {
                    $campaigns[$cur]['open_server'] = ['line' => $i, 'fields' => $rowFields($curList, $line)];
                } else {
                    $campaigns[$cur]['lists'][$curList][] = ['line' => $i, 'fields' => $rowFields($curList, $line)];
                }
            }
            $listDepth += $net;
            if ($listDepth <= 0) {
                $curList = null;
                $listDepth = 0;
            }
            continue;
        }

        // 活動頂層
        if ($t === '}' || $t === '},') {
            $cur = null;
            continue;
        }
        if ($t === '{') {
            continue;
        }
        if (preg_match('/^(' . implode('|', array_map('preg_quote', $listKeys)) . ')\s*=\s*$/', $t, $m)) {
            $curList = $m[1];
            $awaitBrace = true;
            if (!isset($campaigns[$cur]['lists'][$curList])) {
                $campaigns[$cur]['lists'][$curList] = [];
            }
            continue;
        }
        if (preg_match('/^(time_type)\s*=\s*(.*?),?\s*$/', $t, $m)) {
            $campaigns[$cur]['scalars']['time_type'] = ['line' => $i, 'value' => trim($m[2])];
            continue;
        }
        if (preg_match('/^(open_count|forbid_zoneids)\s*=\s*(.*?),?\s*$/', $t, $m)) {
            $campaigns[$cur]['scalars'][$m[1]] = ['line' => $i, 'value' => trim($m[2])];
            continue;
        }
    }

    return ['campaigns' => $campaigns, 'lines' => $lines, 'eol' => $eol];
}

/* ============================================================
 *  四、套用更新
 * ============================================================ */
/**
 * 套用更新並回傳新內容。
 * $updates: [ id => [
 *     'scalars' => ['time_type'=>..,'open_count'=>..,'forbid_zoneids'=>..],
 *     'open_server' => ['AFTER_HOUR'=>..,'RETCODE'=>..],
 *     'lists' => ['tids'=>[ [TID=>,RATE=>], ... ], ...]
 * ] ]
 */
function gameserver_campaign_apply_updates($content, array $updates): string
{
    $parsed = gameserver_campaign_parse($content);
    $lines = $parsed['lines'];
    $campaigns = $parsed['campaigns'];
    $listMap = gameserver_campaign_list_map();
    $validTimeTypes = array_keys(gameserver_campaign_time_types());

    foreach ($updates as $id => $data) {
        $id = (int)$id;
        if (!isset($campaigns[$id]) || !is_array($data)) {
            continue;
        }
        $c = $campaigns[$id];

        // 純量
        if (!empty($data['scalars']) && is_array($data['scalars'])) {
            foreach ($data['scalars'] as $key => $val) {
                if (!isset($c['scalars'][$key])) {
                    continue;
                }
                $line = $c['scalars'][$key]['line'];
                if ($key === 'time_type') {
                    $val = trim((string)$val);
                    if (!in_array($val, $validTimeTypes, true)) {
                        continue;
                    }
                    $lines[$line] = preg_replace('/^(\s*time_type\s*=\s*).*?(\s*,?\s*)$/', '${1}' . $val . '${2}', $lines[$line], 1);
                } else {
                    $num = (int)$val;
                    $lines[$line] = preg_replace('/^(\s*' . preg_quote($key, '/') . '\s*=\s*)-?\d+(\s*,?\s*)$/', '${1}' . $num . '${2}', $lines[$line], 1);
                }
            }
        }

        // 開服條件
        if (!empty($data['open_server']) && is_array($data['open_server']) && $c['open_server_line'] >= 0) {
            $lineIdx = $c['open_server_line'];
            $curLine = $lines[$lineIdx] ?? '';
            $have = isset($c['open_server']['fields']) && !empty($c['open_server']['fields']);
            $afterHour = isset($data['open_server']['AFTER_HOUR']) ? (int)$data['open_server']['AFTER_HOUR'] : null;
            $retcode   = isset($data['open_server']['RETCODE']) ? (int)$data['open_server']['RETCODE'] : null;
            if ($have) {
                if ($afterHour !== null && preg_match('/AFTER_HOUR\s*=\s*-?\d+/', $curLine)) {
                    $curLine = preg_replace('/(AFTER_HOUR\s*=\s*)-?\d+/', '${1}' . $afterHour, $curLine, 1);
                }
                if ($retcode !== null && preg_match('/RETCODE\s*=\s*-?\d+/', $curLine)) {
                    $curLine = preg_replace('/(RETCODE\s*=\s*)-?\d+/', '${1}' . $retcode, $curLine, 1);
                }
                $lines[$lineIdx] = $curLine;
            } elseif ($afterHour !== null && $retcode !== null) {
                $indent = preg_match('/^(\s*)/', $curLine, $mm) ? $mm[1] : "\t\t";
                $lines[$lineIdx] = $indent . 'AFTER_HOUR = ' . $afterHour . ', RETCODE = ' . $retcode;
            }
        }

        // 清單
        if (!empty($data['lists']) && is_array($data['lists'])) {
            foreach ($data['lists'] as $listKey => $rows) {
                if (!isset($listMap[$listKey]) || !is_array($rows)) {
                    continue;
                }
                $origRows = $c['lists'][$listKey] ?? [];
                foreach ($rows as $ri => $rowFields) {
                    if (!isset($origRows[$ri]) || !is_array($rowFields)) {
                        continue;
                    }
                    $lineIdx = $origRows[$ri]['line'];
                    foreach ($rowFields as $fk => $fv) {
                        if (!array_key_exists($fk, $origRows[$ri]['fields'])) {
                            continue;
                        }
                        $fv = trim((string)$fv);
                        if (!preg_match('/^-?\d+(\.\d+)?$/', $fv)) {
                            continue;
                        }
                        $pat = '/(\b' . preg_quote($fk, '/') . '\s*=\s*)-?\d+(\.\d+)?/';
                        if (preg_match($pat, $lines[$lineIdx])) {
                            $lines[$lineIdx] = preg_replace($pat, '${1}' . $fv, $lines[$lineIdx], 1);
                        }
                    }
                }
            }
        }
    }

    return implode($parsed['eol'], $lines);
}

/* ============================================================
 *  五、遠端操作
 * ============================================================ */
function gameserver_campaign_refresh($link, $by = ''): array
{
    return gameserver_file_refresh($link, GAME_SERVER_CAMPAIGN_KEY, $by);
}

function gameserver_campaign_restore($link, $by = ''): array
{
    return gameserver_file_restore($link, GAME_SERVER_CAMPAIGN_KEY, $by);
}

function gameserver_campaign_content($link): string
{
    $row = gameserver_file_get($link, GAME_SERVER_CAMPAIGN_KEY);
    return (string)($row['raw_cache'] ?? '');
}

/** 取得單一活動的解析結果（供編輯視窗） */
function gameserver_campaign_get_one($link, $id): ?array
{
    $parsed = gameserver_campaign_parse(gameserver_campaign_content($link));
    return $parsed['campaigns'][(int)$id] ?? null;
}

function gameserver_campaign_apply($link, array $updates, $by = ''): array
{
    $read = gameserver_file_read($link, GAME_SERVER_CAMPAIGN_KEY);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = gameserver_campaign_apply_updates($read['content'], $updates);
    try {
        $sftp = remote_sftp($read['server'], 30);
        if (!$sftp->put($read['path'], $content)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $read['path']];
        }
        $cached = gameserver_file_cache($link, GAME_SERVER_CAMPAIGN_KEY, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $read['server'], 'save_campaign', $read['path'], 'campaigns=' . count($updates));
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

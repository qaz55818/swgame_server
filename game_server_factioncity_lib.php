<?php
/**
 * =============================================================
 *  踏雪笑傲 · 勢力城市設定工具庫（game_server_factioncity_lib.php）
 *  -------------------------------------------------------------
 *  管理遠端 factioncity.lua（Lua 表格）。檔案結構：
 *    - AuctionTime = { weekday, hour, min }    勢力城拍賣時間
 *    - Subs2Cons   = { [分舵等級] = 建造消耗 }  分舵等級 → 建造消耗對應
 *    - FactionCity = { [城市ID] = { 16 個欄位 } } 各城市詳細參數
 *
 *  每個欄位皆附中文說明與英文原文（原始鍵名），並支援：
 *    - 透過既有檔案框架讀取／寫回（base64 快取，支援 GBK/ANSI）
 *    - 僅修改對應行的數值，完整保留縮排、註解與其他內容
 *    - 初始版本快照與還原
 *    - 城市名稱取自檔案中 `[城市ID]` 前一行的 `--` 註解（GBK → UTF-8）
 *
 *  目標檔案代碼：gdeliveryd_factioncity（見 game_server_files_lib registry）
 *  依賴：game_server_files_lib.php（gameserver_file_read / cache / restore）
 * =============================================================
 */

if (defined('GAME_SERVER_FACTIONCITY_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_FACTIONCITY_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_files_lib.php';

if (!defined('GAME_SERVER_FACTIONCITY_KEY')) {
    define('GAME_SERVER_FACTIONCITY_KEY', 'gdeliveryd_factioncity');
}

/* ============================================================
 *  一、欄位對照表（中文說明 + 英文原文）
 * ============================================================ */
/**
 * FactionCity 每個城市的欄位定義。
 * key => [中文標籤, 型別(int|switch|text), 中文說明]
 */
function gameserver_factioncity_field_map(): array
{
    return [
        'citylevel'        => ['地圖等級',     'int',    '該勢力城市所屬的地圖等級。'],
        'economy_init'     => ['初始經濟值',   'int',    '城市初始的經濟數值。'],
        'economy_upper'    => ['經濟值上限',   'int',    '城市經濟數值可成長到的上限。'],
        'security_init'    => ['治安初始值',   'int',    '城市初始的治安數值。'],
        'security_upper'   => ['治安值上限',   'int',    '城市治安數值可成長到的上限。'],
        'industry_init'    => ['工業初始值',   'int',    '城市初始的工業數值。'],
        'industry_upper'   => ['工業值上限',   'int',    '城市工業數值可成長到的上限。'],
        'sub_power_lower'  => ['分舵戰力下限', 'int',    '加入分舵所需的最小戰鬥力。'],
        'sub_limit'        => ['分舵名額',     'int',    '分舵可容納的幫派／成員數量上限。'],
        'main_limit'       => ['總舵名額',     'int',    '總舵可容納的幫派／成員數量上限。'],
        'main_power_lower' => ['總舵戰力下限', 'int',    '加入總舵所需的最小戰鬥力。'],
        'init_king'        => ['初始龍頭',     'int',    '城市初始佔領幫派（龍頭）編號。'],
        'can_player_king'  => ['允許玩家稱王', 'switch', '是否允許玩家幫派成為該城龍頭（1 允許／0 不允許）。'],
        'isopen'           => ['是否開放',     'switch', '該城市是否開放（1 開放／0 關閉）。'],
        'icon'             => ['城市地圖',     'text',   '城市小地圖圖檔路徑（例如 worldmaps\\x57.dds）。'],
        'base_task'        => ['基礎任務數量', 'int',    '該城每輪基礎金錢任務的基準數量。'],
    ];
}

/** AuctionTime 欄位定義。key => [中文標籤, 中文說明] */
function gameserver_factioncity_auction_map(): array
{
    return [
        'weekday' => ['拍賣星期', '勢力城拍賣開放的星期（1 = 週一 … 7 = 週日）。'],
        'hour'    => ['拍賣小時', '拍賣開放的整點（0 - 23）。'],
        'min'     => ['拍賣分鐘', '拍賣開放的分鐘（0 - 59）。'],
    ];
}

/** Subs2Cons 區段說明 */
function gameserver_factioncity_subs_label(): array
{
    return [
        'label' => '分舵等級建造消耗',
        'desc'  => '以分舵等級為索引，對應升級／建造所需的資源消耗值（Subs2Cons）。',
    ];
}

/* ============================================================
 *  二、編碼轉換（檔案為 ANSI/GBK）
 * ============================================================ */
/** GBK/ANSI 位元組 → UTF-8（供顯示） */
function gameserver_factioncity_to_utf8($s): string
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

/* ============================================================
 *  三、解析 / 修改
 * ============================================================ */
/**
 * 解析 factioncity.lua。
 * @return array {
 *   auction: [key => ['value'=>int,'line'=>int]],
 *   subs:    [level => ['value'=>int,'line'=>int]],
 *   cities:  [id => ['line'=>int,'name'=>utf8,'fields'=>[key => ['value'=>string,'quoted'=>bool,'line'=>int]]]],
 *   lines:   array, eol: string
 * }
 */
function gameserver_factioncity_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);

    $auction = [];
    $subs    = [];
    $cities  = [];
    $block   = '';
    $curCity = null;
    $pendingName = '';

    foreach ($lines as $i => $line) {
        // 進入區段
        if (preg_match('/^\s*AuctionTime\s*=\s*\{\s*$/', $line)) {
            $block = 'auction';
            continue;
        }
        if (preg_match('/^\s*Subs2Cons\s*=\s*\{\s*$/', $line)) {
            $block = 'subs';
            continue;
        }
        if (preg_match('/^\s*FactionCity\s*=\s*\{\s*$/', $line)) {
            $block = 'cities';
            continue;
        }

        // 城市名稱：`[id] = {` 前一行的 `--` 註解
        if ($block === 'cities' && $curCity === null && preg_match('/^\s*--\s*(.*?)\s*$/', $line, $cm)) {
            $pendingName = gameserver_factioncity_to_utf8($cm[1]);
            continue;
        }

        // 進入單一城市
        if ($block === 'cities' && $curCity === null && preg_match('/^\s*\[(\d+)\]\s*=\s*\{\s*$/', $line, $m)) {
            $curCity = (int)$m[1];
            $cities[$curCity] = ['line' => $i, 'name' => $pendingName, 'fields' => []];
            $pendingName = '';
            continue;
        }

        // 結束單一城市
        if ($block === 'cities' && $curCity !== null && preg_match('/^\s*\}\s*,?\s*$/', $line)) {
            $curCity = null;
            continue;
        }

        // 城市欄位
        if ($block === 'cities' && $curCity !== null
            && preg_match('/^\s*\[\s*"([A-Za-z0-9_]+)"\s*\]\s*=\s*("(?:[^"\\\\]|\\\\.)*"|-?\d+)/', $line, $m)) {
            $token  = $m[2];
            $quoted = (strlen($token) > 0 && $token[0] === '"');
            $value  = $quoted ? substr($token, 1, -1) : $token;
            $cities[$curCity]['fields'][$m[1]] = ['value' => $value, 'quoted' => $quoted, 'line' => $i];
            continue;
        }

        // AuctionTime 欄位
        if ($block === 'auction' && preg_match('/^\s*\[\s*"([A-Za-z0-9_]+)"\s*\]\s*=\s*(-?\d+)/', $line, $m)) {
            $auction[$m[1]] = ['value' => (int)$m[2], 'line' => $i];
            continue;
        }

        // Subs2Cons 欄位
        if ($block === 'subs' && preg_match('/^\s*\[(\d+)\]\s*=\s*(-?\d+)/', $line, $m)) {
            $subs[(int)$m[1]] = ['value' => (int)$m[2], 'line' => $i];
            continue;
        }

        // 結束區段
        if ($block !== '' && preg_match('/^\s*\}\s*$/', $line)) {
            $block = '';
            $curCity = null;
            continue;
        }
    }

    return [
        'auction' => $auction,
        'subs'    => $subs,
        'cities'  => $cities,
        'lines'   => $lines,
        'eol'     => $eol,
    ];
}

/**
 * 套用更新並回傳新內容。
 * $updates = [
 *   'auction' => [key => 值],
 *   'subs'    => [level => 值],
 *   'cities'  => [id => [key => 值]],
 * ]
 * 僅在值有變動時才修改對應行，完整保留縮排、註解與其他內容。
 */
function gameserver_factioncity_apply_updates($content, array $updates): string
{
    $parsed   = gameserver_factioncity_parse($content);
    $lines    = $parsed['lines'];
    $fieldMap = gameserver_factioncity_field_map();

    // 一、AuctionTime
    if (!empty($updates['auction']) && is_array($updates['auction'])) {
        foreach ($updates['auction'] as $key => $val) {
            if (!isset($parsed['auction'][$key])) {
                continue;
            }
            $val = trim((string)$val);
            if ($val === '' || !preg_match('/^-?\d{1,9}$/', $val)) {
                continue;
            }
            if ((int)$val === $parsed['auction'][$key]['value']) {
                continue;
            }
            $idx = $parsed['auction'][$key]['line'];
            $lines[$idx] = preg_replace(
                '/^(\s*\[\s*"' . preg_quote($key, '/') . '"\s*\]\s*=\s*)-?\d+/',
                '${1}' . (int)$val,
                $lines[$idx],
                1
            );
        }
    }

    // 二、Subs2Cons
    if (!empty($updates['subs']) && is_array($updates['subs'])) {
        foreach ($updates['subs'] as $level => $val) {
            $level = (int)$level;
            if (!isset($parsed['subs'][$level])) {
                continue;
            }
            $val = trim((string)$val);
            if ($val === '' || !preg_match('/^-?\d{1,12}$/', $val)) {
                continue;
            }
            if ((int)$val === $parsed['subs'][$level]['value']) {
                continue;
            }
            $idx = $parsed['subs'][$level]['line'];
            $lines[$idx] = preg_replace(
                '/^(\s*\[\s*' . $level . '\s*\]\s*=\s*)-?\d+/',
                '${1}' . (int)$val,
                $lines[$idx],
                1
            );
        }
    }

    // 三、FactionCity
    if (!empty($updates['cities']) && is_array($updates['cities'])) {
        foreach ($updates['cities'] as $cityId => $fields) {
            $cityId = (int)$cityId;
            if (!isset($parsed['cities'][$cityId]) || !is_array($fields)) {
                continue;
            }
            $orig = $parsed['cities'][$cityId]['fields'];
            foreach ($fields as $key => $val) {
                if (!isset($orig[$key])) {
                    continue;
                }
                $type  = $fieldMap[$key][1] ?? 'text';
                $lineNo = $orig[$key]['line'];
                $val = (string)$val;

                if ($type === 'text') {
                    if ($val === $orig[$key]['value']) {
                        continue;
                    }
                    // 只替換引號內的內容，保留其餘格式
                    $lines[$lineNo] = preg_replace(
                        '/^(\s*\[\s*"' . preg_quote($key, '/') . '"\s*\]\s*=\s*")[^"]*(")/',
                        '${1}' . str_replace(['\\', '$'], ['\\\\', '\\$'], $val) . '${2}',
                        $lines[$lineNo],
                        1
                    );
                } else {
                    $val = trim($val);
                    if ($val === '' || !preg_match('/^-?\d{1,12}$/', $val)) {
                        continue;
                    }
                    if ((int)$val === (int)$orig[$key]['value']) {
                        continue;
                    }
                    $lines[$lineNo] = preg_replace(
                        '/^(\s*\[\s*"' . preg_quote($key, '/') . '"\s*\]\s*=\s*)-?\d+/',
                        '${1}' . (int)$val,
                        $lines[$lineNo],
                        1
                    );
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
function gameserver_factioncity_refresh($link, $by = ''): array
{
    return gameserver_file_refresh($link, GAME_SERVER_FACTIONCITY_KEY, $by);
}

/** 還原初始版本 */
function gameserver_factioncity_restore($link, $by = ''): array
{
    return gameserver_file_restore($link, GAME_SERVER_FACTIONCITY_KEY, $by);
}

/** 取得目前快取內容（已解 base64 的原始位元組） */
function gameserver_factioncity_content($link): string
{
    $row = gameserver_file_get($link, GAME_SERVER_FACTIONCITY_KEY);
    return (string)($row['raw_cache'] ?? '');
}

/**
 * 套用更新並寫回遠端。
 * @param array $updates 見 gameserver_factioncity_apply_updates()
 */
function gameserver_factioncity_apply($link, array $updates, $by = ''): array
{
    $read = gameserver_file_read($link, GAME_SERVER_FACTIONCITY_KEY);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = gameserver_factioncity_apply_updates($read['content'], $updates);
    try {
        $sftp = remote_sftp($read['server'], 30);
        if (!$sftp->put($read['path'], $content)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $read['path']];
        }
        $cached = gameserver_file_cache($link, GAME_SERVER_FACTIONCITY_KEY, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $read['server'], 'save_factioncity', $read['path'], '');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

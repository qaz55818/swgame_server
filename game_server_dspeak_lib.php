<?php
/**
 * =============================================================
 *  踏雪笑傲 · 喊話設定工具庫（game_server_dspeak_lib.php）
 *  -------------------------------------------------------------
 *  管理遠端 ds_speak.lua（Lua 表格 DS_SPEAK_TYPE / DS_SPEAK_IDS）。
 *    - 解析每個喊話項目的兩個數值：
 *        DS_SPEAK_TYPE[NAME] = 類型值（內部事件代碼）
 *        DS_SPEAK_IDS[DST.NAME] = 喊話／提示 ID
 *    - 中文說明 + 英文原文（符號名稱）欄位對照
 *    - 透過既有檔案框架讀取／寫回（base64 快取，支援 GBK/ANSI）
 *    - 初始版本快照與還原
 *
 *  目標檔案代碼：gdeliveryd_dspeak（見 game_server_files_lib registry）
 *  依賴：game_server_files_lib.php（gameserver_file_read / cache / restore）
 * =============================================================
 */

if (defined('GAME_SERVER_DSPEAK_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_DSPEAK_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_files_lib.php';

if (!defined('GAME_SERVER_DSPEAK_KEY')) {
    define('GAME_SERVER_DSPEAK_KEY', 'gdeliveryd_dspeak');
}

/* ============================================================
 *  一、欄位對照表（中文說明 + 英文原文符號名稱）
 * ============================================================ */
/**
 * 符號名稱 => [中文標籤, 中文說明]。
 * 依原始檔 DS_SPEAK_TYPE 定義順序排列。
 */
function gameserver_dspeak_name_map(): array
{
    return [
        'DST_FACTION_RCVAUCPOINT'          => ['幫派拍賣得標',     '幫派成員獲得拍賣物品時的提示。'],
        'DST_FACTION_CLASH_SRC_ACCPT'      => ['幫戰·發起·接受',   '發起幫戰挑戰，對方接受。'],
        'DST_FACTION_CLASH_SRC_REFUS'      => ['幫戰·發起·拒絕',   '發起幫戰挑戰，對方拒絕。'],
        'DST_FACTION_CLASH_SRC_OFFLINE'    => ['幫戰·發起·離線',   '發起幫戰挑戰，對方離線。'],
        'DST_FACTION_CLASH_SRC_ALREADY'    => ['幫戰·發起·已開戰', '發起幫戰挑戰，己方已在幫戰中。'],
        'DST_FACTION_CLASH_SRC_COOLDOWN'   => ['幫戰·發起·冷卻',   '發起幫戰挑戰，己方仍在冷卻。'],
        'DST_FACTION_CLASH_SRC_COOLDOWN_D' => ['幫戰·發起·對方冷卻', '發起幫戰挑戰，對方仍在冷卻。'],
        'DST_FACTION_CLASH_SRC_ALREADY_D'  => ['幫戰·發起·對方已開戰', '發起幫戰挑戰，對方已在幫戰中。'],
        'DST_FACTION_CLASH_SRC_ARENA'      => ['幫戰·發起·競技場', '發起幫戰挑戰，處於競技場。'],
        'DST_FACTION_CLASH_DST_ACCPT'      => ['幫戰·接收·接受',   '接收幫戰挑戰，接受。'],
        'DST_FACTION_CLASH_DST_REFUS'      => ['幫戰·接收·拒絕',   '接收幫戰挑戰，拒絕。'],
        'DST_FACTION_CLASH_DST_ARENA'      => ['幫戰·接收·競技場', '接收幫戰挑戰，處於競技場。'],
        'DST_FACTION_CLASH_SRC_NOANSWER'   => ['幫戰·發起·無回應', '發起幫戰挑戰，對方無回應。'],
        'DST_FACTION_CLASH_RESULT_DRAW'    => ['幫戰結果·平局',   '幫戰結算為平局。'],
        'DST_FACTION_CLASH_RESULT_WIN'     => ['幫戰結果·勝負',   '幫戰結算分出勝負。'],
        'DST_FCITY_SUBEXPEL'               => ['勢力城·被逐出',   '勢力城成員被逐出。'],
        'DST_FCITY_CLEARUNLINK'            => ['勢力城·清除連通', '清除勢力城連通關係。'],
        'DST_FCITY_AUCREADY'               => ['勢力城·拍賣預備', '勢力城拍賣開始前的預備提示。'],
        'DST_FCITY_UNLINKNOTICE'           => ['勢力城·連通通知', '勢力城連通狀態通知。'],
        'DST_FCITY_AUCEND'                 => ['勢力城·拍賣結束', '一輪勢力城拍賣結束。'],
        'DST_FCITY_AUCPRICEEXCEED'         => ['勢力城·競價超越', '拍賣競價被超越的廣播。'],
        'DST_TIZI_ADD'                     => ['題字·新增',       '題字新增提示。'],
        'DST_TIZI_DEL'                     => ['題字·刪除',       '題字刪除提示。'],
        'DST_FACTION_TIZI_ADD'             => ['幫派題字·新增',   '幫派題字新增。'],
        'DST_FACTION_TIZI_ADDED'           => ['幫派題字·已新增', '幫派題字已新增完成。'],
        'DST_TIZI_ADD_AN'                  => ['題字·匿名新增',   '匿名題字新增。'],
        'DST_TIGUAN_END1'                  => ['踢館·結束 1',     '踢館結束提示（第 1 種）。'],
        'DST_TIGUAN_END2'                  => ['踢館·結束 2',     '踢館結束提示（第 2 種）。'],
        'DST_TIGUAN_END3'                  => ['踢館·結束 3',     '踢館結束提示（第 3 種）。'],
        'DST_TIGUAN_END4'                  => ['踢館·結束 4',     '踢館結束提示（第 4 種）。'],
        'DST_TIGUAN_BOOK_SUCCESS'          => ['踢館·預約成功',   '踢館預約成功。'],
        'DST_TIGUAN_BOOK_EXCEED'           => ['踢館·預約超限',   '踢館預約超出上限。'],
        'DST_TIGUAN_FOCUS_BEGIN'           => ['踢館·關注開始',   '踢館關注階段開始。'],
        'DST_TIGUAN_FOCUS_END'             => ['踢館·關注結束',   '踢館關注階段結束。'],
        'DST_TIGUAN_BOOK_FAILED'           => ['踢館·預約失敗',   '踢館預約失敗。'],
        'DST_TIGUAN_BOOK_OUTDATE'          => ['踢館·預約過期',   '踢館預約已過期。'],
        'DST_TEAM_VOTE_COMPLETE'           => ['隊伍投票·完成',   '隊伍投票完成。'],
        'DST_TEAM_VOTE_FAILED'             => ['隊伍投票·失敗',   '隊伍投票失敗。'],
        'DST_TEAM_VOTE_TIMEOUT'            => ['隊伍投票·逾時',   '隊伍投票逾時。'],
        'DST_HEAD_UNIONMEMBER'             => ['頭銜·聯盟成員',   '聯盟成員頭銜提示。'],
        'DST_HEAD_FACTIONMEMBER'           => ['頭銜·幫派成員',   '幫派成員頭銜提示。'],
        'DST_UNION_COOLDOWN'               => ['聯盟·冷卻',       '聯盟操作冷卻中。'],
        'DST_PLUGIN_REPORT_RE'             => ['插件檢舉·回覆',   '插件檢舉的回覆提示。'],
        'DST_APPOINT_OFFICER'              => ['任命·官員',       '任命官員提示。'],
        'DST_DISMISS_OFFICER'              => ['罷免·官員',       '罷免官員提示。'],
        'DST_NEW_KING'                     => ['君主·登基',       '新君主登基。'],
        'DST_REMOVE_KING'                  => ['君主·移除',       '移除君主。'],
        'DST_FORBID_CHAT'                  => ['禁言',             '玩家被禁言提示。'],
        'DST_DISMISS_LOW_OFFICER'          => ['罷免·低階官員',   '罷免低階官員提示。'],
        'DST_BEKING_AGAIN'                 => ['君主·再次稱王',   '再次稱王提示。'],
        'DST_DECLARE_WAR'                  => ['宣戰',             '宣戰廣播。'],
        'DST_BE_DECLARE_WAR'               => ['被宣戰',           '被宣戰廣播。'],
        'DST_SNOW_WAR_WIN'                 => ['雪戰·勝利',       '雪戰勝利廣播。'],
        'DST_DELATE_KING'                  => ['君主·延遲',       '延遲稱王／任期延後。'],
    ];
}

/* ============================================================
 *  二、編碼轉換（檔案為 ANSI/GBK）
 * ============================================================ */
/** GBK/ANSI 位元組 → UTF-8（供顯示） */
function gameserver_dspeak_to_utf8($s): string
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
 * 解析 ds_speak.lua。
 * @return array {
 *   types: [name => ['value'=>int,'line'=>int]],
 *   ids:   [name => ['value'=>int,'line'=>int]],
 *   lines: array, eol: string
 * }
 */
function gameserver_dspeak_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);

    $types = [];
    $ids   = [];
    $block = '';

    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*DS_SPEAK_TYPE\s*=\s*\{\s*$/', $line)) {
            $block = 'type';
            continue;
        }
        if (preg_match('/^\s*DS_SPEAK_IDS\s*=\s*\{\s*$/', $line)) {
            $block = 'ids';
            continue;
        }
        if ($block !== '' && preg_match('/^\s*\}\s*$/', $line)) {
            $block = '';
            continue;
        }
        if ($block === 'type') {
            if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=\s*(-?\d+)\s*(?:,|--|$)/', $line, $m)) {
                $types[$m[1]] = ['value' => (int)$m[2], 'line' => $i];
            }
        } elseif ($block === 'ids') {
            if (preg_match('/^\s*\[\s*DST\.([A-Za-z0-9_]+)\s*\]\s*=\s*(-?\d+)\s*(?:,|--|$)/', $line, $m)) {
                $ids[$m[1]] = ['value' => (int)$m[2], 'line' => $i];
            }
        }
    }

    return ['types' => $types, 'ids' => $ids, 'lines' => $lines, 'eol' => $eol];
}

/**
 * 套用更新並回傳新內容。
 * $updates[name] = ['type' => int|string|null, 'id' => int|string|null]
 * 僅修改對應行的數值，完整保留縮排、註解、逗號與其他內容。
 */
function gameserver_dspeak_apply_updates($content, array $updates): string
{
    $parsed = gameserver_dspeak_parse($content);
    $lines  = $parsed['lines'];

    foreach ($updates as $name => $u) {
        if (!is_array($u)) {
            continue;
        }
        if (isset($parsed['types'][$name]) && isset($u['type']) && $u['type'] !== '' && $u['type'] !== null) {
            $idx = $parsed['types'][$name]['line'];
            $val = (string)(int)$u['type'];
            $pat = '/^(\s*' . preg_quote($name, '/') . '\s*=\s*)-?\d+/';
            if (isset($lines[$idx]) && preg_match($pat, $lines[$idx])) {
                $lines[$idx] = preg_replace($pat, '${1}' . $val, $lines[$idx], 1);
            }
        }
        if (isset($parsed['ids'][$name]) && isset($u['id']) && $u['id'] !== '' && $u['id'] !== null) {
            $idx = $parsed['ids'][$name]['line'];
            $val = (string)(int)$u['id'];
            $pat = '/^(\s*\[\s*DST\.' . preg_quote($name, '/') . '\s*\]\s*=\s*)-?\d+/';
            if (isset($lines[$idx]) && preg_match($pat, $lines[$idx])) {
                $lines[$idx] = preg_replace($pat, '${1}' . $val, $lines[$idx], 1);
            }
        }
    }

    return implode($parsed['eol'], $lines);
}

/* ============================================================
 *  四、遠端操作
 * ============================================================ */
/** 讀取遠端並更新快取 */
function gameserver_dspeak_refresh($link, $by = ''): array
{
    return gameserver_file_refresh($link, GAME_SERVER_DSPEAK_KEY, $by);
}

/** 還原初始版本 */
function gameserver_dspeak_restore($link, $by = ''): array
{
    return gameserver_file_restore($link, GAME_SERVER_DSPEAK_KEY, $by);
}

/** 取得目前快取內容（已解 base64 的原始位元組） */
function gameserver_dspeak_content($link): string
{
    $row = gameserver_file_get($link, GAME_SERVER_DSPEAK_KEY);
    return (string)($row['raw_cache'] ?? '');
}

/**
 * 套用更新並寫回遠端。
 * @param array $updates name => ['type'=>.., 'id'=>..]
 */
function gameserver_dspeak_apply($link, array $updates, $by = ''): array
{
    $read = gameserver_file_read($link, GAME_SERVER_DSPEAK_KEY);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = gameserver_dspeak_apply_updates($read['content'], $updates);
    try {
        $sftp = remote_sftp($read['server'], 30);
        if (!$sftp->put($read['path'], $content)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $read['path']];
        }
        $cached = gameserver_file_cache($link, GAME_SERVER_DSPEAK_KEY, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $read['server'], 'save_dspeak', $read['path'], 'items=' . count($updates));
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

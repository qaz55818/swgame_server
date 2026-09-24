<?php
/**
 * =============================================================
 *  踏雪笑傲 · 團體競賽設定工具庫（game_server_tournament_lib.php）
 *  -------------------------------------------------------------
 *  專用於遠端 boards_tournamentCfgs.lua（gamed/config/script/）：
 *    - 設定遠端檔案路徑（game_server_tournament_config）
 *    - 透過 SSH/SFTP 讀取與寫回
 *    - 內容快取與初始快照（供「還原初始」）
 *    - 沿用通用 Lua 解析器（game_server_reward_lib 的詞法／語法），
 *      以位元組位移就地替換「數值」與「索引鍵」，保留註解、函式與縮排
 *    - 結構化模型：EctypeArgsReg[副本ID] 的
 *        playerMaxReviveNum / Client / TeamArgs / Events / EventConfigs
 *      EventConfigs 再依「階段類型 → 子階段 → 事件 → 參數」展開
 *    - 中文說明 + 英文原文（gameserver_tournament_*_doc）
 *    - 寫入操作稽核（沿用 admin/remote_lib.php）
 *
 *  目標伺服器沿用 game_server_config.server_id（與「遊戲版本設定」共用）。
 *  資料表：game_server_tournament_config（見 game_server_script_schema.sql）
 *  依賴：game_server_reward_lib.php（通用解析器／編碼）、admin/remote_lib.php
 * =============================================================
 */

if (defined('GAME_SERVER_TOURNAMENT_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_TOURNAMENT_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_reward_lib.php';

if (!defined('GAME_SERVER_TOURNAMENT_TABLE')) {
    define('GAME_SERVER_TOURNAMENT_TABLE', 'game_server_tournament_config');
}
if (!defined('GAME_SERVER_TOURNAMENT_DEFAULT_PATH')) {
    define('GAME_SERVER_TOURNAMENT_DEFAULT_PATH', '/root/xa274/gamed/config/script/boards_tournamentCfgs.lua');
}

/* ============================================================
 *  一、資料表與設定存取（與其他設定檔同構）
 * ============================================================ */
/** 建立資料表並補上預設列（可重複執行、非破壞性） */
function gameserver_tournament_ensure($link): void
{
    if (!$link) {
        return;
    }
    $t = GAME_SERVER_TOURNAMENT_TABLE;
    try {
        $link->query("CREATE TABLE IF NOT EXISTS `$t` (
            `id` tinyint(1) NOT NULL DEFAULT 1,
            `path` varchar(255) NOT NULL DEFAULT '" . GAME_SERVER_TOURNAMENT_DEFAULT_PATH . "',
            `raw_cache` mediumtext NULL,
            `initial_raw` mediumtext NULL,
            `updated_by` varchar(50) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $link->query("INSERT IGNORE INTO `$t` (`id`,`path`) VALUES (1,'" . GAME_SERVER_TOURNAMENT_DEFAULT_PATH . "')");
    } catch (Throwable $e) {
        // 權限不足時略過
    }
}

/** 讀取設定列（資料表不存在時回傳預設值） */
function gameserver_tournament_config_get($link): array
{
    $default = [
        'id' => 1, 'path' => GAME_SERVER_TOURNAMENT_DEFAULT_PATH,
        'raw_cache' => '', 'initial_raw' => '', 'updated_by' => '', 'updated_at' => '',
    ];
    if (!$link) {
        return $default;
    }
    try {
        $res = @$link->query("SELECT * FROM `" . GAME_SERVER_TOURNAMENT_TABLE . "` WHERE `id` = 1 LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            $row = array_merge($default, $row);
            $row['raw_cache']   = gameserver_decode_raw($row['raw_cache'] ?? '');
            $row['initial_raw'] = gameserver_decode_raw($row['initial_raw'] ?? '');
            return $row;
        }
    } catch (Throwable $e) {
        // 忽略
    }
    return $default;
}

/** 設定遠端檔案路徑 */
function gameserver_tournament_set_path($link, $path, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $path = trim((string)$path);
    if ($path === '') {
        $path = GAME_SERVER_TOURNAMENT_DEFAULT_PATH;
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    try {
        $stmt = $link->prepare("INSERT INTO `" . GAME_SERVER_TOURNAMENT_TABLE . "` (`id`,`path`,`updated_by`)
            VALUES (1,?,?) ON DUPLICATE KEY UPDATE `path`=VALUES(`path`), `updated_by`=VALUES(`updated_by`)");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $path, $by);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

/** 由內容更新快取與初始快照；成功回傳 true */
function gameserver_tournament_cache($link, $content, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $cfg = gameserver_tournament_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        $initial = (string)$content;
    }
    $enc  = gameserver_encode_raw($content);
    $ienc = gameserver_encode_raw($initial);
    try {
        $stmt = $link->prepare("UPDATE `" . GAME_SERVER_TOURNAMENT_TABLE . "`
            SET `raw_cache`=?, `initial_raw`=?, `updated_by`=? WHERE `id`=1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sss', $enc, $ienc, $by);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

/* ============================================================
 *  二、編碼轉換（委派通用實作）
 * ============================================================ */
function gameserver_tournament_is_utf8($s): bool { return gameserver_produce_is_utf8($s); }
function gameserver_tournament_to_utf8($s): string { return gameserver_produce_to_utf8($s); }
function gameserver_tournament_from_utf8($s): string { return gameserver_produce_from_utf8($s); }

/* ============================================================
 *  三、遠端讀寫（SSH / SFTP）
 * ============================================================ */
/**
 * 讀取遠端 boards_tournamentCfgs.lua。
 * @return array { ok, content?, error?, server?, path? }
 */
function gameserver_tournament_read($link): array
{
    $cfg = gameserver_tournament_config_get($link);
    $server = gameserver_file_target_server($link);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器（請先於「遊戲版本設定」選擇）。'];
    }
    $path = (string)$cfg['path'];
    try {
        $sftp = remote_sftp($server, 20);
        $content = $sftp->get($path);
        if ($content === false) {
            return ['ok' => false, 'error' => '無法讀取遠端檔案：' . $path];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'content' => $content, 'server' => $server, 'path' => $path];
}

/** 將內容寫回遠端並更新快取 */
function gameserver_tournament_write($link, $content, $by = '', $action = 'save_tournament'): array
{
    $cfg = gameserver_tournament_config_get($link);
    $server = gameserver_file_target_server($link);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器。'];
    }
    $path = (string)$cfg['path'];
    try {
        $sftp = remote_sftp($server, 30);
        if (!$sftp->put($path, (string)$content)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $path];
        }
        $cached = gameserver_tournament_cache($link, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, $action, $path, '');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

/** 讀取遠端並更新快取 */
function gameserver_tournament_refresh($link, $by = ''): array
{
    $read = gameserver_tournament_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    if (!gameserver_tournament_cache($link, $read['content'], $by)) {
        return ['ok' => false, 'error' => '讀取成功，但本機快取寫入失敗（請檢查資料庫）。'];
    }
    if (!empty($read['server']) && function_exists('remote_audit')) {
        remote_audit($link, $read['server'], 'refresh_tournament', $read['path'], '');
    }
    return ['ok' => true, 'error' => ''];
}

/** 還原初始內容快照 */
function gameserver_tournament_restore($link, $by = ''): array
{
    $cfg = gameserver_tournament_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        return ['ok' => false, 'error' => '尚無初始快照，請先執行「讀取最新」。'];
    }
    return gameserver_tournament_write($link, $initial, $by, 'restore_tournament');
}

/* ============================================================
 *  四、解析 / 套用（沿用通用 Lua 解析器）
 * ============================================================ */
/** 受管理的頂層設定名稱（本檔以 EctypeArgsReg[副本ID] 註冊） */
function gameserver_tournament_known_bases(): array
{
    return ['EctypeArgsReg'];
}

/**
 * 解析 boards_tournamentCfgs.lua。
 * 回傳 entries（頂層指派）、nodes（純量節點，附 id）、
 *      keys（索引鍵欄位，附 kid）、eol、content（去除 BOM）。
 * @return array { entries, nodes, keys, eol, content }
 */
function gameserver_tournament_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $parser = new GameserverLuaParser((string)$content);
    $entries = $parser->parseAll(gameserver_tournament_known_bases());

    $nodes = [];
    $keys = [];
    $counter = 0;
    $kcounter = 0;
    $walk = function ($node) use (&$walk, &$nodes, &$keys, &$counter, &$kcounter) {
        if ($node === null) {
            return;
        }
        if ($node->kind === 'scalar' || $node->kind === 'ref') {
            $node->id = $counter;
            $nodes[$counter] = $node;
            $counter++;
            return;
        }
        if ($node->kind === 'table') {
            foreach ($node->fields as $f) {
                if ($f->kind === 'index') {
                    $f->kid = $kcounter;
                    $keys[$kcounter] = $f;
                    $kcounter++;
                }
                $walk($f->value);
            }
        }
    };
    foreach ($entries as $e) {
        $walk($e->node);
    }
    return ['entries' => $entries, 'nodes' => $nodes, 'keys' => $keys, 'eol' => $eol, 'content' => $content];
}

/** 取出索引鍵欄位「[」與「]」之間的位元組範圍（$keyStart 指向 '['） */
function gameserver_tournament_key_span(string $content, int $keyStart): array
{
    $n = strlen($content);
    $depth = 0;
    for ($i = $keyStart; $i < $n; $i++) {
        $c = $content[$i];
        if ($c === '[' || $c === '{') {
            $depth++;
        } elseif ($c === ']' || $c === '}') {
            $depth--;
            if ($depth === 0) {
                return [$keyStart + 1, $i];
            }
        }
    }
    return [0, 0];
}

/**
 * 依節點 id / 鍵 id 就地套用新值，回傳新內容。
 * @param array $valueEdits id  => 新值（字串）
 * @param array $keyEdits   kid => 新索引鍵（整數或識別字）
 */
function gameserver_tournament_apply_content($content, array $valueEdits, array $keyEdits = []): string
{
    $content = gameserver_strip_bom((string)$content);
    $parsed = gameserver_tournament_parse($content);
    $nodes = $parsed['nodes'];
    $keys  = $parsed['keys'];
    $repls = [];

    foreach ($valueEdits as $id => $new) {
        $id = (int)$id;
        if (!isset($nodes[$id])) {
            continue;
        }
        $node = $nodes[$id];
        $new = trim((string)$new);
        if ($node->kind === 'ref') {
            // 識別字參照（如 TOURNAMENT_STAGE_FINISH）：僅允許識別字或整數
            if (isset($node->start, $node->end) && preg_match('/^-?\d+$|^[A-Za-z_]\w*$/', $new)) {
                $repls[] = [$node->start, $node->end, $new];
            }
            continue;
        }
        if ($node->kind !== 'scalar') {
            continue;
        }
        if ($node->vtype === 'number') {
            if (!preg_match('/^-?(\d+(\.\d+)?|\.\d+)$/', $new)) {
                continue;
            }
            $repls[] = [$node->start, $node->end, $new];
        } elseif ($node->vtype === 'string') {
            $esc = str_replace(['\\', '"'], ['\\\\', '\\"'], $new);
            $repls[] = [$node->start, $node->end, '"' . $esc . '"'];
        } elseif ($node->vtype === 'bool') {
            if ($new === 'true' || $new === 'false') {
                $repls[] = [$node->start, $node->end, $new];
            }
        }
    }

    foreach ($keyEdits as $kid => $newKey) {
        $kid = (int)$kid;
        if (!isset($keys[$kid])) {
            continue;
        }
        $newKey = trim((string)$newKey);
        if ($newKey === '' || !preg_match('/^-?\d+$|^[A-Za-z_]\w*$/', $newKey)) {
            continue;
        }
        list($s, $e) = gameserver_tournament_key_span($content, (int)$keys[$kid]->keyStart);
        if ($s === 0 && $e === 0) {
            continue;
        }
        $repls[] = [$s, $e, $newKey];
    }

    usort($repls, function ($a, $b) { return $b[0] <=> $a[0]; });
    foreach ($repls as $r) {
        $content = substr($content, 0, $r[0]) . $r[2] . substr($content, $r[1]);
    }
    return $content;
}

/** 套用結構化更新並寫回遠端 */
function gameserver_tournament_apply($link, array $valueEdits, array $keyEdits, $by = ''): array
{
    $read = gameserver_tournament_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = gameserver_tournament_apply_content($read['content'], $valueEdits, $keyEdits);
    return gameserver_tournament_write($link, $content, $by, 'save_tournament');
}

/**
 * 將解析結果轉為「副本（board）」模型清單。
 * @return array [ [id, name, path, node, fields, stats], ... ]
 */
function gameserver_tournament_boards(array $parsed): array
{
    $names = gameserver_tournament_board_names();
    $boards = [];
    foreach ($parsed['entries'] as $e) {
        if (($e->base ?? '') !== 'EctypeArgsReg' || $e->node->kind !== 'table') {
            continue;
        }
        $id = 0;
        if (preg_match('/\[(\d+)\]/', (string)$e->path, $m)) {
            $id = (int)$m[1];
        }
        $fields = [];
        foreach ($e->node->fields as $f) {
            if ($f->kind === 'name') {
                $fields[$f->key] = $f->value;
            }
        }
        $stats = ['stages' => 0, 'configs' => 0, 'events' => 0];
        if (isset($fields['EventConfigs']) && $fields['EventConfigs']->kind === 'table') {
            foreach ($fields['EventConfigs']->fields as $st) {
                if ($st->value->kind !== 'table') {
                    continue;
                }
                $stats['stages']++;
                foreach ($st->value->fields as $sid) {
                    if ($sid->value->kind !== 'table') {
                        continue;
                    }
                    foreach ($sid->value->fields as $ev) {
                        $stats['configs']++;
                    }
                }
            }
        }
        if (isset($fields['Events']) && $fields['Events']->kind === 'table') {
            $stats['events'] = count($fields['Events']->fields);
        }
        $boards[] = [
            'id'     => $id,
            'name'   => $names[$id] ?? ('副本 #' . $id),
            'path'   => (string)$e->path,
            'node'   => $e->node,
            'fields' => $fields,
            'stats'  => $stats,
        ];
    }
    return $boards;
}

/* ============================================================
 *  五、參數說明（中文 + 英文原文 + 詳細中文註釋）
 * ============================================================ */
/** 階段類型常數說明（TOURNAMENT_STAGE_*） */
function gameserver_tournament_stage_doc(): array
{
    return [
        0 => ['無效階段', 'TOURNAMENT_STAGE_INVALID', '預留的無效值，正常設定中不應出現。'],
        1 => ['初始階段', 'TOURNAMENT_STAGE_INIT', '副本剛建立、尚未開始流程的階段，通常不需設定事件。'],
        2 => ['入場階段', 'TOURNAMENT_STAGE_ENTERING', '玩家陸續進入場地、分隊與準備開始競賽的階段。'],
        3 => ['競技階段', 'TOURNAMENT_STAGE_COMPETITION', '競賽正式進行中，依「子階段（stageid）」逐步推進。'],
        4 => ['結束階段', 'TOURNAMENT_STAGE_FINISH', '競賽結束，結算優勝隊伍並發放獎勵。'],
    ];
}

/** 解析階段鍵（可為數字 "2" 或常數名 "TOURNAMENT_STAGE_ENTERING"）→ [數字|null, 說明] */
function gameserver_tournament_stage_lookup($key): array
{
    $doc = gameserver_tournament_stage_doc();
    $key = (string)$key;
    if (ctype_digit($key)) {
        $n = (int)$key;
        return [$n, $doc[$n] ?? [$key, $key, '']];
    }
    foreach ($doc as $n => $info) {
        if ($info[1] === $key) {
            return [$n, $info];
        }
    }
    return [null, [$key, $key, '']];
}

/** 事件類型常數說明（EVENT_*） */
function gameserver_tournament_event_doc(): array
{
    return [
        'EVENT_NPC_BORN'                => ['NPC 出生',   'EVENT_NPC_BORN',                '怪物 / NPC 出生時觸發，用於累加場景計數。'],
        'EVENT_NPC_DEATH'               => ['NPC 死亡',   'EVENT_NPC_DEATH',               '怪物 / NPC 死亡時觸發，可作為通關條件。'],
        'EVENT_PLAYER_ENTER'            => ['玩家入場',   'EVENT_PLAYER_ENTER',            '玩家進入副本時觸發，用於登記隊伍與顏色。'],
        'EVENT_PLAYER_DEATH'            => ['玩家死亡',   'EVENT_PLAYER_DEATH',            '玩家死亡時觸發，可累加隊伍擊殺數。'],
        'EVENT_PLAYER_PICKUP_ITEM'      => ['玩家拾取道具', 'EVENT_PLAYER_PICKUP_ITEM',    '玩家拾取道具時觸發，用於收集計數與通關判斷。'],
        'EVENT_PLAYER_DROP_ITEM'        => ['玩家丟棄道具', 'EVENT_PLAYER_DROP_ITEM',      '玩家丟棄道具時觸發，用於扣減收集計數。'],
        'EVENT_PLAYER_REVIVE'           => ['玩家復活',   'EVENT_PLAYER_REVIVE',           '玩家復活時觸發，可判斷復活次數上限並踢出。'],
        'EVENT_TOURNAMENT_STAGE_START'  => ['階段開始',   'EVENT_TOURNAMENT_STAGE_START',  '競賽階段開始時觸發，用於開啟控制器或發放獎勵。'],
        'EVENT_TOURNAMENT_STAGE_FINISH' => ['階段結束',   'EVENT_TOURNAMENT_STAGE_FINISH', '競賽階段結束時觸發，用於關閉控制器。'],
    ];
}

/** 設定鍵說明（board 欄位 / EventConfigs 內參數 / gotoStage 欄位） */
function gameserver_tournament_key_doc(): array
{
    return [
        // 副本（board）層級
        'playerMaxReviveNum'     => ['最大復活次數',   'playerMaxReviveNum',     '玩家在本副本內可復活的最大次數；復活次數達此值即被踢出場。'],
        'Client'                 => ['客戶端隊伍標記', 'Client',                 '下發給客戶端的隊伍顏色 / 位置參數編號清單（如 201~205、301~305、401~405）。'],
        'TeamArgs'               => ['隊伍參數編號',   'TeamArgs',               '與 Client 對應、供伺服器記錄各隊伍狀態的場景參數編號清單。'],
        'Events'                 => ['註冊事件',       'Events',                 '本副本要監聽的事件類型清單（對應 EVENT_* 常數）。'],
        'Eventfunc'              => ['事件分派函式',   'Eventfunc',              '事件回呼函式（FuncList）；本工具不修改，保留原樣。'],
        'EventConfigs'           => ['事件設定',       'EventConfigs',           '依「階段類型 → 子階段 → 事件」定義的控制器、怪物、道具與獎勵設定。'],

        // EventConfigs 內的參數
        'startCtrlList'          => ['開啟控制器',     'startCtrlList',          '觸發時要「開啟」的控制器編號清單（如開啟大門、寶箱）。'],
        'closeCtrlList'          => ['關閉控制器',     'closeCtrlList',          '觸發時要「關閉」的控制器編號清單（如關閉大門與透明怪）。'],
        'ConditioncloseCtrlList' => ['條件關閉控制器', 'ConditioncloseCtrlList', '滿足條件（例如怪物被清空）後才「關閉」的控制器編號清單。'],
        'monsterList'            => ['怪物清單',       'monsterList',            '{怪物ID = 場景參數}；用於統計場上怪物數量以判斷通關。'],
        'itemList'               => ['道具清單',       'itemList',               '{道具ID = 條件數量}；拾取 / 丟棄道具的計數與達標條件。'],
        'gotoStage'              => ['跳轉階段',       'gotoStage',              '達到條件後要跳轉到的目標階段（含 stageType 與 stageid）。'],
        'awardItem'              => ['獎勵物品',       'awardItem',              '{物品ID = 數量}；發放給優勝隊伍的獎勵。'],
        'stageType'              => ['目標階段類型',   'stageType',              '跳轉目標的階段類型，對應 TOURNAMENT_STAGE_* 常數。'],
        'stageid'                => ['目標子階段',     'stageid',                '跳轉目標的子階段編號（同一階段類型下的第幾階段）。'],
    ];
}

/** 已知副本名稱（依原始註解；未列出者以「副本 #ID」顯示） */
function gameserver_tournament_board_names(): array
{
    return [
        2966 => '測試組',
        3059 => '玄武',
        3060 => '白虎',
    ];
}

/** 檔案結構總覽（供「參數說明」頁呈現） */
function gameserver_tournament_structure_doc(): array
{
    return [
        [
            'title' => '檔案用途',
            'en'    => 'boards_tournamentCfgs.lua',
            'desc'  => '團體競賽（Tournament）副本設定檔。以 EctypeArgsReg[副本ID] 註冊每個競賽副本的階段流程、事件、控制器、怪物、道具與獎勵。',
        ],
        [
            'title' => '註冊結構',
            'en'    => 'EctypeArgsReg[ID] = { ... }',
            'desc'  => '每一個副本是一個鍵為數字的表：包含 playerMaxReviveNum（復活上限）、Client / TeamArgs（隊伍參數）、Events（監聽事件）與 EventConfigs（事件設定）。',
        ],
        [
            'title' => '事件設定結構',
            'en'    => 'EventConfigs[階段類型][子階段][事件] = { ... }',
            'desc'  => '三層索引：第一層為階段類型（1 初始 / 2 入場 / 3 競技 / 4 結束），第二層為子階段（stageid），第三層為事件類型（EVENT_*）。值為該事件對應的參數表。',
        ],
        [
            'title' => '事件處理流程',
            'en'    => 'FuncList(...)',
            'desc'  => '引擎觸發事件時呼叫 Eventfunc → FuncList，依 currentStageType / currentStageid 取出對應設定，再依序執行發獎、NPC 出生 / 死亡、拾取 / 丟棄、復活、開關控制器等處理。',
        ],
        [
            'title' => '就地編輯原則',
            'en'    => 'in-place byte replacement',
            'desc'  => '本工具以位元組位移就地替換數值與索引鍵，其餘內容、中文註解、函式與縮排皆維持原樣，寫回後不破壞原檔格式。',
        ],
    ];
}

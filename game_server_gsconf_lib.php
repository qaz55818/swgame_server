<?php
/**
 * =============================================================
 *  踏雪笑傲 · gs.conf 核心設定工具庫（game_server_gsconf_lib.php）
 *  -------------------------------------------------------------
 *  專用於遠端 gamed/gs.conf（INI 風格）：
 *    - 解析為「有序區段」（可含同名重複區段，如 InstanceMode_4、
 *      MirrorScene_NNN），並記錄每個鍵值所在行，寫入時就地替換、
 *      保留註解與排版。
 *    - 遠端讀取／寫回、內容快取與初始快照（沿用 game_server_files）。
 *    - 開關型參數（on/off）對照表，供前台以「開關元件」呈現。
 *    - 每個參數的中文說明 + 英文原文（gameserver_gsconf_*_doc）。
 *
 *  目標伺服器沿用 game_server_config.server_id（與「遊戲版本設定」共用）。
 *  檔案登錄沿用 game_server_files（file_key = gamed_gs）。
 *  依賴：game_server_script_lib.php（編碼／目標伺服器）、admin/remote_lib.php
 * =============================================================
 */

if (defined('GAME_SERVER_GSCONF_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_GSCONF_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_script_lib.php';

if (!defined('GAME_SERVER_GSCONF_KEY')) {
    define('GAME_SERVER_GSCONF_KEY', 'gamed_gs');
}

/* ============================================================
 *  一、解析
 * ============================================================ */
/**
 * 解析 gs.conf。
 * @return array {
 *   sections: [ ['name','occ','line','keys'=>[['key','value','line'], ...]], ... ],  // 依檔案順序（位置索引）
 *   lines: array, eol: string
 * }
 * 註：同名區段以 occ（出現序，0 起）區分；前台編輯以「位置索引」為鍵，
 *     可精確處理重複區段（如兩個 [InstanceMode_4]）。
 */
function gameserver_gsconf_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);

    $sections = [];
    $seen = [];
    $cur = -1;

    foreach ($lines as $i => $line) {
        $t = trim($line);
        if ($t === '') {
            continue;
        }
        // 註解行（; 開頭）一律略過，包含被註解的區段標頭與鍵值
        if ($t[0] === ';') {
            continue;
        }
        if (preg_match('/^\[([^\]]+)\]\s*$/', $t, $m)) {
            $name = trim($m[1]);
            $occ = $seen[$name] ?? 0;
            $seen[$name] = $occ + 1;
            $sections[] = ['name' => $name, 'occ' => $occ, 'line' => $i, 'keys' => []];
            $cur = count($sections) - 1;
            continue;
        }
        if ($cur >= 0) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*?)\s*$/', $t, $m)) {
                $sections[$cur]['keys'][] = ['key' => $m[1], 'value' => $m[2], 'line' => $i];
            }
        }
    }

    return ['sections' => $sections, 'lines' => $lines, 'eol' => $eol];
}

/** 依區段名稱彙整（name => [occ0, occ1, ...]），供前台分組顯示 */
function gameserver_gsconf_group(array $sections): array
{
    $out = [];
    foreach ($sections as $pos => $sec) {
        $out[$sec['name']][] = ['pos' => $pos] + $sec;
    }
    return $out;
}

/** 就地設定某行的值（保留 key= 與尾端空白） */
function gameserver_gsconf_set_line($line, $newVal): string
{
    if (preg_match('/^(\s*[A-Za-z_][A-Za-z0-9_.]*\s*=\s*)(.*?)(\s*)$/', (string)$line, $m)) {
        return $m[1] . $newVal . $m[3];
    }
    return (string)$line;
}

/**
 * 套用編輯並回傳新內容。
 * $edits = [ 區段位置索引 => [ 鍵名 => 新值, ... ], ... ]
 */
function gameserver_gsconf_apply_content($content, array $edits): string
{
    $p = gameserver_gsconf_parse($content);
    $lines = $p['lines'];

    foreach ($edits as $pos => $kv) {
        $pos = (int)$pos;
        if (!isset($p['sections'][$pos]) || !is_array($kv)) {
            continue;
        }
        foreach ($kv as $key => $newVal) {
            $key = (string)$key;
            foreach ($p['sections'][$pos]['keys'] as $k) {
                if ($k['key'] === $key) {
                    $lines[$k['line']] = gameserver_gsconf_set_line($lines[$k['line']], (string)$newVal);
                    break;
                }
            }
        }
    }

    return implode($p['eol'], $lines);
}

/* ============================================================
 *  二、遠端套用
 * ============================================================ */
/**
 * 讀取遠端 gs.conf → 套用 → 寫回 → 更新快取（含稽核）。
 * @param array $edits 區段位置索引 => [鍵 => 值]
 */
function gameserver_gsconf_apply($link, array $edits, $by = ''): array
{
    $read = gameserver_file_read($link, GAME_SERVER_GSCONF_KEY);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = $read['content'];
    // gs.conf 多為 GBK/ANSI；表單為 UTF-8，寫回前轉回。
    if (!gameserver_produce_is_utf8($content)) {
        $converted = [];
        foreach ($edits as $pos => $kv) {
            if (!is_array($kv)) { continue; }
            foreach ($kv as $k => $v) {
                $converted[$pos][$k] = gameserver_produce_from_utf8((string)$v);
            }
        }
        $edits = $converted;
    }
    $newContent = gameserver_gsconf_apply_content($content, $edits);

    try {
        $sftp = remote_sftp($read['server'], 30);
        if (!$sftp->put($read['path'], $newContent)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $read['path']];
        }
        $cached = gameserver_file_cache($link, GAME_SERVER_GSCONF_KEY, $newContent, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $read['server'], 'save_' . GAME_SERVER_GSCONF_KEY, $read['path'], '');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

/* ============================================================
 *  三、開關型參數對照（前台以開關元件呈現）
 * ============================================================ */
/**
 * 開關型參數的開／關寫入值。鍵為參數名。
 * on/off 為實際寫入檔案的完整字串（含原檔可能存在的尾端分號）。
 */
function gameserver_gsconf_switch_meta(): array
{
    return [
        'anti_wallow'        => ['on' => 'true', 'off' => 'false'],
        'debug_command_mode' => ['on' => '1', 'off' => '0'],
        'CheckPlayerMove'    => ['on' => '1', 'off' => '0'],
        'allow_root'         => ['on' => '1', 'off' => '0'],
        'no_output'          => ['on' => '1', 'off' => '0'],
        'no_skoutput'        => ['on' => '1', 'off' => '0'],
        'screen_out'         => ['on' => '1;', 'off' => '0;'],
        'forbid_trade'       => ['on' => '1', 'off' => '0'],
    ];
}

/** 是否為開關型參數 */
function gameserver_gsconf_is_switch(string $key): bool
{
    return isset(gameserver_gsconf_switch_meta()[$key]);
}

/** 開關型參數的開／關值（找不到時回傳 1/0 預設） */
function gameserver_gsconf_switch(string $key): array
{
    $m = gameserver_gsconf_switch_meta();
    return $m[$key] ?? ['on' => '1', 'off' => '0'];
}

/** 判斷目前值是否為「開啟」 */
function gameserver_gsconf_switch_is_on(string $key, $value): bool
{
    $sw = gameserver_gsconf_switch($key);
    $norm = function ($v) { return strtolower(rtrim(trim((string)$v), ";\t ")); };
    $v = $norm($value);
    if ($v === '') {
        return false;
    }
    return $v === $norm($sw['on']) || $v === 'true' || $v === '1';
}

/* ============================================================
 *  四、參數說明（中文 + 英文原文）
 * ============================================================ */
/** 區段資訊：回傳 ['base','label','icon','desc'] */
function gameserver_gsconf_section_info(string $name): array
{
    $map = [
        'Identify'         => ['身分識別',   'fa-id-card',       '伺服器識別設定。'],
        'SpawnController'  => ['生成控制器', 'fa-diagram-project', '控制場景生成（Spawn）行為。'],
        'General'          => ['一般設定',   'fa-sliders',       '伺服器一般運作參數。'],
        'WallowLight'      => ['輕度防沉迷', 'fa-hourglass-half', '防沉迷「輕度」階段的收益倍率。'],
        'WallowHeavy'      => ['重度防沉迷', 'fa-hourglass-end',  '防沉迷「重度」階段的收益倍率。'],
        'WallowTime'       => ['防沉迷時間', 'fa-clock',         '防沉迷的累計與重置時間設定。'],
        'Param'            => ['其他參數',   'fa-gear',          '雜項運作參數。'],
        'MoveMap'          => ['尋路地圖',   'fa-route',         '各類尋路（移動）檔名設定。'],
        'Script'           => ['腳本',       'fa-scroll',         '全域腳本設定。'],
        'Template'         => ['資料模板',   'fa-folder-tree',   '各類遊戲資料檔路徑設定。'],
        'InstanceRegister' => ['副本註冊',   'fa-book',          '副本模式的註冊與動態分線設定。'],
        'InstanceMode'     => ['副本模式',   'fa-layer-group',   '副本模式所需 gs 數量與對應副本模板。'],
        'SceneMirror'      => ['場景分線',   'fa-clone',         '需要分線（mirror）的場景清單。'],
        'MirrorScene'      => ['分線場景',   'fa-map-pin',       '個別場景的分線容量與支援伺服器。'],
    ];
    if (preg_match('/^(InstanceMode|MirrorScene)_(\d+)$/', $name, $m)) {
        $info = $map[$m[1]];
        return ['base' => $m[1], 'label' => $info[0] . ' ' . $m[2], 'icon' => $info[1], 'desc' => $info[2]];
    }
    if (isset($map[$name])) {
        return ['base' => $name, 'label' => $map[$name][0], 'icon' => $map[$name][1], 'desc' => $map[$name][2]];
    }
    return ['base' => $name, 'label' => $name, 'icon' => 'fa-circle-dot', 'desc' => ''];
}

/**
 * 欄位說明：回傳 [中文標籤, 英文原文, 型別, 說明]。
 * 型別：switch（開關） / int / uint / rate / text / list。
 */
function gameserver_gsconf_field_doc(string $base, string $key): array
{
    $wallow = function ($stage) {
        return [
            'exp'        => ['經驗倍率',       'exp',        'rate', $stage . '經驗獲得倍率（如 0.5f 表 0.5 倍）。'],
            'sp'         => ['技能點倍率',     'sp',         'rate', $stage . '技能點獲得倍率。'],
            'item'       => ['物品倍率',       'item',       'rate', $stage . '物品掉落倍率。'],
            'money'      => ['金錢倍率',       'money',      'rate', $stage . '金錢獲得倍率。'],
            'task_exp'   => ['任務經驗倍率',   'task_exp',   'rate', $stage . '任務經驗倍率。'],
            'task_sp'    => ['任務技能點倍率', 'task_sp',    'rate', $stage . '任務技能點倍率。'],
            'task_money' => ['任務金錢倍率',   'task_money', 'rate', $stage . '任務金錢倍率。'],
        ];
    };

    $m = [
        'Identify' => [
            'ServerID' => ['伺服器識別碼', 'ServerID', 'int', '本 gs 的唯一編號，須與 gsalias.conf 中的設定一致。'],
        ],
        'SpawnController' => [
            'disable' => ['停用生成', 'disable', 'list', '停用的 Spawn（生成）設定清單（原檔中以 ; 註解，未啟用）。'],
        ],
        'General' => [
            'all_scenes'           => ['啟用場景清單',   'all_scenes',           'list',   '本伺服器支援的所有場景 ID（分號分隔）。'],
            'all_worlds'           => ['啟用世界清單',   'all_worlds',           'list',   '本伺服器支援的所有世界（副本）ID（分號分隔）。'],
            'worlds'               => ['額外世界',       'worlds',               'list',   '額外開啟的世界 ID。'],
            'height_check_scenes'  => ['高度檢查場景',   'height_check_scenes',  'list',   '需要進行高度（Z 軸）檢查的場景 ID。'],
            'anti_wallow'          => ['防沉迷',         'anti_wallow',          'switch', '是否啟用防沉迷限制（true 開／false 關）。'],
            'debug_command_mode'   => ['除錯指令模式',   'debug_command_mode',   'switch', '是否開放除錯 GM 指令（1 開／0 關）。'],
            'CheckPlayerMove'      => ['檢查玩家移動',   'CheckPlayerMove',      'switch', '偵測玩家異常移動（反外掛，1 開／0 關）。'],
            'logic_level_limit'    => ['邏輯等級上限',   'logic_level_limit',    'int',    '伺服器邏輯處理的最高等級。'],
            'allow_login_prof_mask'=> ['允許登入職業遮罩','allow_login_prof_mask','uint',   '以位元遮罩限制可登入的職業；0xffffffff（4294967295）表示全部允許。'],
            'no_output'            => ['關閉輸出',       'no_output',            'switch', '關閉伺服器的一般輸出（1 關／0 開）。'],
            'no_skoutput'          => ['關閉技能輸出',   'no_skoutput',          'switch', '關閉技能相關輸出（1 關／0 開）。'],
            'screen_out'           => ['螢幕輸出',       'screen_out',           'switch', '輸出螢幕（畫面）資訊（1 開／0 關）。'],
        ],
        'WallowLight' => $wallow('輕度防沉迷時'),
        'WallowHeavy' => $wallow('重度防沉迷時'),
        'WallowTime' => [
            'TimeLight' => ['輕度防沉迷時數', 'TimeLight', 'int',  '累計上線達此（時）數後進入「輕度」防沉迷。'],
            'TimeHeavy' => ['重度防沉迷時數', 'TimeHeavy', 'int',  '累計上線達此（時）數後進入「重度」防沉迷。'],
            'TimeClear' => ['重置時數',       'TimeClear', 'int',  '離線累計達此（時）數後重置防沉迷計時。'],
            'ClearMode' => ['重置模式',       'ClearMode', 'text', '重置判定方式（例如 RestTime）。'],
        ],
        'Param' => [
            'allow_root'   => ['允許 root 指令', 'allow_root',   'switch', '是否允許 root 權限的管理指令（1 開／0 關）。'],
            'forbid_trade' => ['禁止交易',       'forbid_trade', 'switch', '是否禁止玩家交易（原檔中以 ; 註解，未啟用）。'],
        ],
        'MoveMap' => [
            'Path'      => ['陸地尋路檔', 'Path',      'text', '陸地移動尋路檔名。'],
            'WaterPath' => ['水域尋路檔', 'WaterPath', 'text', '水域移動尋路檔名。'],
            'AirPath'   => ['空中尋路檔', 'AirPath',   'text', '空中移動尋路檔名。'],
        ],
        'Script' => [
            'GlobalScript' => ['全域腳本', 'GlobalScript', 'text', '全域腳本檔路徑（建議使用絕對路徑）。'],
        ],
        'Template' => [
            'Root'              => ['根目錄',       'Root',              'text', '資料檔根目錄（目錄分隔請用「/」）。'],
            'item_data_file'    => ['物品資料檔',   'item_data_file',    'text', '物品資料檔名。'],
            'task_data_file'    => ['任務資料檔',   'task_data_file',    'text', '任務資料檔名。'],
            'mall_data_file'    => ['商城資料檔',   'mall_data_file',    'text', '商城資料檔名。'],
            'policy_data_file'  => ['政策資料檔',   'policy_data_file',  'text', '政策資料檔名。'],
            'LuaData'           => ['Lua 介面檔',   'LuaData',           'text', 'Lua 介面資料檔名。'],
            'prof_lvup'         => ['職業升級表',   'prof_lvup',         'text', '職業升級經驗表檔名。'],
            'prof_adds'         => ['職業加成表',   'prof_adds',         'text', '職業屬性加成表檔名。'],
            'prof_lvup_ex'      => ['進階職業升級表','prof_lvup_ex',     'text', '進階職業升級經驗表檔名。'],
            'prof_adds_ex'      => ['進階職業加成表','prof_adds_ex',     'text', '進階職業屬性加成表檔名。'],
            'atkdefmodi'        => ['攻防修正表',   'atkdefmodi',        'text', '攻擊／防禦修正表檔名。'],
            'map_conf_file'     => ['地圖設定檔',   'map_conf_file',     'text', '地形設定檔名。'],
            'force_data'        => ['力道換算表',   'force_data',        'text', '經驗換算力道表檔名。'],
            'skills_data'       => ['技能資料檔',   'skills_data',       'text', '技能資料檔名。'],
            'loot_group'        => ['掉落群組檔',   'loot_group',        'text', '掉落群組 XML 檔名。'],
            'npc_group_refresh' => ['NPC 群組刷新檔','npc_group_refresh','text', 'NPC 群組刷新檔名。'],
            'RestartShell'      => ['重啟腳本',     'RestartShell',      'text', '伺服器重啟腳本路徑。'],
            'RegionFile'        => ['區域檔 1',     'RegionFile',        'text', '區域（region）檔名。'],
            'RegionFile2'       => ['區域檔 2',     'RegionFile2',       'text', '區域（precinct）檔名。'],
            'RegionFile3'       => ['區域檔 3',     'RegionFile3',       'text', '區域（bufregion）檔名。'],
            'NPCGenFile'        => ['NPC 生成檔',   'NPCGenFile',        'text', 'NPC 生成資料檔名。'],
            'PathFile'          => ['路徑檔',       'PathFile',          'text', '路徑資料（path.sev）檔名。'],
            'DiscovermapDef'    => ['探索地圖設定', 'DiscovermapDef',    'text', '探索地圖定義檔名。'],
            'board_data'        => ['棋盤資料檔',   'board_data',        'text', '棋盤（board）資料檔名。'],
        ],
        'InstanceRegister' => [
            'dyn_instance_line' => ['動態分線編號', 'dyn_instance_line', 'list', '可動態分配的 gs 分線編號清單（分號分隔）。'],
            'mode'              => ['啟用副本模式', 'mode',              'list', '啟用的 InstanceMode 編號清單（分號分隔）。'],
        ],
        'InstanceMode' => [
            'need_gs_count' => ['所需 GS 數量',   'need_gs_count', 'int',  '該副本模式需要佔用的 gs（分線）數量。'],
            'instance_tid'  => ['副本模板 ID 清單','instance_tid',  'list', '屬於該模式的副本（世界）模板 ID（分號分隔）。'],
        ],
        'SceneMirror' => [
            'mirror_scenes' => ['分線場景清單', 'mirror_scenes', 'list', '需要開啟分線（mirror）的場景 ID（分號分隔）。'],
        ],
        'MirrorScene' => [
            'min_players_count' => ['最小開啟人數', 'min_players_count', 'int',  '開啟新分線所需的最少玩家人數。'],
            'player_capacity'   => ['玩家容量',     'player_capacity',   'int',  '該場景每條分線可容納的玩家人數。'],
            'support_server'    => ['支援伺服器',   'support_server',    'int',  '支援的伺服器 ID（或支援數量）。'],
        ],
    ];

    if (isset($m[$base][$key])) {
        return $m[$base][$key];
    }
    return [$key, $key, 'text', ''];
}

/** 取得區段內所有鍵的說明（依檔案出現順序） */
function gameserver_gsconf_field_docs_for(array $section): array
{
    $info = gameserver_gsconf_section_info($section['name']);
    $out = [];
    foreach ($section['keys'] as $k) {
        $doc = gameserver_gsconf_field_doc($info['base'], $k['key']);
        $out[] = [
            'key'   => $k['key'],
            'value' => $k['value'],
            'zh'    => $doc[0],
            'en'    => $doc[1],
            'type'  => $doc[2],
            'desc'  => $doc[3],
        ];
    }
    return $out;
}

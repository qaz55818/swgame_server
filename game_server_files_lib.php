<?php
/**
 * =============================================================
 *  踏雪笑傲 · 遊戲核心設定檔工具庫（game_server_files_lib.php）
 *  -------------------------------------------------------------
 *  可管理多個遠端設定檔（目前註冊：gamed/gs.conf）。每個檔案：
 *    - 於 registry 定義可瀏覽／編輯的具名參數（含中文標籤）
 *    - 透過 SSH/SFTP 讀取與寫回
 *    - 內容快取與初始快照（供「還原初始」）
 *    - 寫入操作稽核
 *
 *  目標伺服器沿用 game_server_config.server_id（與「遊戲版本設定」共用）。
 *  資料表：game_server_files（見 game_server_files_schema.sql）
 *  依賴：game_server_lib.php（解析函式）、admin/remote_lib.php（連線）
 * =============================================================
 */

if (defined('GAME_SERVER_FILES_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_FILES_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_lib.php';

if (!defined('GAME_SERVER_FILES_TABLE')) {
    define('GAME_SERVER_FILES_TABLE', 'game_server_files');
}

/**
 * gdeliveryd/gamesys.conf 可編輯欄位定義。
 * 每筆：section / key / en（英文原文鍵名）/ label（中文）/ type(bool|int|switch|text) / desc（中文說明）。
 * 註：原始檔中以 ';' 註解、未啟用的參數（so_broadcast / inform_* / domainname 等）不列入，
 *     避免儲存時將註解行意外啟用。
 */
function gameserver_gdeliveryd_fields(): array
{
    $common = [
        'type'           => ['連線類型', 'text',   '通訊協定類型（tcp / udp）。'],
        'port'           => ['連接埠', 'int',    '服務監聽或連線的 TCP/UDP 連接埠。'],
        'address'        => ['綁定位址', 'text',   '監聽或連線的 IP 位址。'],
        'so_sndbuf'      => ['傳送緩衝區', 'int',    'Socket 傳送緩衝區大小（位元組）。'],
        'so_rcvbuf'      => ['接收緩衝區', 'int',    'Socket 接收緩衝區大小（位元組）。'],
        'ibuffermax'     => ['輸入緩衝上限', 'int',    '單次輸入緩衝區上限（位元組）。'],
        'obuffermax'     => ['輸出緩衝上限', 'int',    '單次輸出緩衝區上限（位元組）。'],
        'accumulate'     => ['累積緩衝', 'int',    '封包累積緩衝大小（位元組）。'],
        'tcp_nodelay'    => ['TCP 無延遲', 'switch', '啟用 TCP_NODELAY 以降低延遲（1 開啟／0 關閉）。'],
        'listen_backlog' => ['監聽佇列', 'int',    '連線等待佇列（listen backlog）長度。'],
    ];
    $mk = function (string $section, array $keys, array $overrides = []) use ($common): array {
        $out = [];
        foreach ($keys as $k) {
            $meta = $overrides[$k] ?? ($common[$k] ?? [$k, 'text', '']);
            $out[] = [
                'section' => $section,
                'key'     => $k,
                'en'      => $k,
                'label'   => $meta[0],
                'type'    => $meta[1],
                'desc'    => $meta[2],
            ];
        }
        return $out;
    };

    $fields = [];
    // 日誌服務（UDP / TCP）
    $fields = array_merge($fields, $mk('LogUDPClient', ['type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf', 'ibuffermax', 'obuffermax', 'accumulate']));
    $fields = array_merge($fields, $mk('LogTCPClient', ['type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf', 'ibuffermax', 'obuffermax', 'accumulate']));
    // 網頁數據服務
    $fields = array_merge($fields, $mk('IWebDSServer', ['type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf', 'tcp_nodelay', 'accumulate', 'ibuffermax', 'obuffermax']));
    // 基礎信息服務（gdeliveryd 主體）
    $fields = array_merge($fields, $mk('GDeliveryServer', [
        'zoneid', 'aid', 'zondname', 'debug_mode', 'type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf',
        'ibuffermax', 'obuffermax', 'tcp_nodelay', 'listen_backlog', 'accumulate', 'battlefield',
        'siege_enable', 'siege_update_enable', 'contest_file', 'contest_prise', 'createrole_forbid',
        'grade_border', 'table_charset', 'name_charset', 'max_name_len', 'whitelist_enable',
        'account_whitelist', 'max_roam_num', 'instance_common_path', 'fbase_count_per_domain', 'green_server',
    ], [
        'zoneid'                 => ['分區編號', 'int',    '所屬遊戲分區（zone）編號。'],
        'aid'                    => ['區服編號', 'int',    '本伺服器於該分區的區服編號。'],
        'zondname'               => ['分區名稱', 'text',   '分區名稱（原始鍵名即為 zondname）。'],
        'debug_mode'             => ['除錯模式', 'switch', '啟用除錯模式（1 開啟／0 關閉）。'],
        'battlefield'            => ['戰場功能', 'switch', '啟用戰場系統（1 開啟／0 關閉）。'],
        'siege_enable'           => ['攻城戰功能', 'switch', '啟用攻城戰（1 開啟／0 關閉）。'],
        'siege_update_enable'    => ['攻城戰更新', 'switch', '啟用攻城戰狀態更新（1 開啟／0 關閉）。'],
        'contest_file'           => ['競賽資料檔', 'text',   '問答競賽題庫檔名。'],
        'contest_prise'          => ['競賽獎勵', 'text',   '競賽獎勵物品編號清單（逗號分隔）。'],
        'createrole_forbid'      => ['禁止建立角色', 'switch', '是否禁止建立新角色（1 禁止／0 允許）。'],
        'grade_border'           => ['等級分界', 'text',   '等級區間分界點清單（逗號分隔）。'],
        'table_charset'          => ['資料表字元集', 'text',   '資料表使用的字元編碼（如 GBK）。'],
        'name_charset'           => ['名稱字元集', 'text',   '角色名稱使用的字元編碼（如 GBK）。'],
        'max_name_len'           => ['名稱長度上限', 'int',    '角色名稱最大長度。'],
        'whitelist_enable'       => ['白名單開關', 'switch', '啟用帳號白名單（1 開啟／0 關閉）。'],
        'account_whitelist'      => ['帳號白名單', 'text',   '允許登入的帳號白名單（逗號分隔）。'],
        'max_roam_num'           => ['最大漫遊數', 'int',    '同時允許的最大漫遊數量。'],
        'instance_common_path'   => ['副本公用腳本', 'text',   '副本公用設定腳本路徑。'],
        'fbase_count_per_domain' => ['每區基地數', 'int',    '每個 domain 的基地（fbase）數量。'],
        'green_server'           => ['綠色伺服器', 'switch', '是否為綠色（無商城）伺服器（1 是／0 否）。'],
    ]));
    // 帳號驗證客戶端
    $fields = array_merge($fields, $mk('GAuthClient', [
        'type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf', 'tcp_nodelay', 'accumulate',
        'isec', 'iseckey', 'osec', 'oseckey', 'shared_key', 'ibuffermax', 'obuffermax',
    ], [
        'isec'       => ['輸入加密方式', 'int',  '輸入串流加密方式（0 不加密）。'],
        'iseckey'    => ['輸入加密金鑰', 'text', '輸入串流加密金鑰。'],
        'osec'       => ['輸出加密方式', 'int',  '輸出串流加密方式（0 不加密）。'],
        'oseckey'    => ['輸出加密金鑰', 'text', '輸出串流加密金鑰。'],
        'shared_key' => ['共用金鑰', 'text',   '與驗證服務共用的金鑰。'],
    ]));
    // 供應商伺服器
    $fields = array_merge($fields, $mk('GProviderServer', [
        'id', 'type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf', 'ibuffermax', 'obuffermax', 'tcp_nodelay', 'accumulate',
    ], [
        'id' => ['伺服器編號', 'int', '供應商伺服器識別編號。'],
    ]));
    // 角色資料庫客戶端
    $fields = array_merge($fields, $mk('GameDBClient', ['type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf', 'tcp_nodelay', 'accumulate', 'ibuffermax', 'obuffermax']));
    // 角色名稱客戶端
    $fields = array_merge($fields, $mk('UniqueNameClient', ['type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf', 'ibuffermax', 'obuffermax', 'tcp_nodelay', 'accumulate']));
    // 防作弊客戶端
    $fields = array_merge($fields, $mk('GAntiCheatClient', ['type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf', 'ibuffermax', 'obuffermax', 'tcp_nodelay', 'accumulate', 'ac_enable'], [
        'ac_enable' => ['啟用防作弊', 'switch', '啟用防作弊連線（1 開啟／0 關閉）。'],
    ]));
    // 3D 防作弊客戶端
    $fields = array_merge($fields, $mk('G3DAntiCheatClient', ['type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf', 'ibuffermax', 'obuffermax', 'tcp_nodelay', 'accumulate']));
    // 天氣系統
    $weather = ['count', 'interval'];
    $wOver = [
        'count'    => ['天氣種類數', 'int', '天氣種類總數。'],
        'interval' => ['天氣間隔', 'int', '天氣切換間隔（秒）。'],
    ];
    for ($i = 0; $i < 9; $i++) {
        $weather[] = 'city' . $i;
        $wOver['city' . $i] = ['城市 ' . $i . ' 天氣', 'text', '城市 ' . $i . ' 的天氣權重清單（逗號分隔）。'];
    }
    $fields = array_merge($fields, $mk('Weather', $weather, $wOver));
    // 路徑與開關類區段
    $fields = array_merge($fields, $mk('CampaignPath', ['path'], ['path' => ['活動清單路徑', 'text', '活動清單 Lua 腳本路徑。']]));
    $fields = array_merge($fields, $mk('Home', ['enabled'], ['enabled' => ['家園系統', 'switch', '啟用家園系統（1 開啟／0 關閉）。']]));
    $fields = array_merge($fields, $mk('FactionCity', ['path'], ['path' => ['勢力城市路徑', 'text', '勢力城市 Lua 腳本路徑。']]));
    $fields = array_merge($fields, $mk('SpeakPath', ['path'], ['path' => ['喊話設定路徑', 'text', '喊話（speak）設定 Lua 腳本路徑。']]));
    $fields = array_merge($fields, $mk('Escort', ['enabled', 'adj_info_script'], [
        'enabled'         => ['護送系統', 'switch', '啟用護送系統（1 開啟／0 關閉）。'],
        'adj_info_script' => ['護送設定腳本', 'text', '護送相關設定 Lua 腳本路徑。'],
    ]));
    $fields = array_merge($fields, $mk('HotXmlPath', ['path'], ['path' => ['熱更新設定路徑', 'text', '熱更新（opercmd）XML 路徑。']]));
    $fields = array_merge($fields, $mk('HubServers', ['count'], ['count' => ['Hub 數量', 'int', 'Hub 伺服器連線數量。']]));
    for ($i = 0; $i <= 5; $i++) {
        $fields = array_merge($fields, $mk('GGameHubClient' . $i, ['type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf', 'ibuffermax', 'obuffermax', 'accumulate', 'tcp_nodelay']));
    }
    return $fields;
}

/** gamesys.conf 各區段的中文名稱（依原始出現順序） */
function gameserver_gdeliveryd_section_labels(): array
{
    $labels = [
        'LogUDPClient'       => '日誌 UDP 客戶端',
        'LogTCPClient'       => '日誌 TCP 客戶端',
        'IWebDSServer'       => '網頁數據服務',
        'GDeliveryServer'    => '基礎信息服務（主體）',
        'GAuthClient'        => '帳號驗證客戶端',
        'GProviderServer'    => '供應商伺服器',
        'GameDBClient'       => '角色資料庫客戶端',
        'UniqueNameClient'   => '角色名稱客戶端',
        'GAntiCheatClient'   => '防作弊客戶端',
        'G3DAntiCheatClient' => '3D 防作弊客戶端',
        'Weather'            => '天氣系統',
        'CampaignPath'       => '活動路徑',
        'Home'               => '家園系統',
        'FactionCity'        => '勢力城市',
        'SpeakPath'          => '喊話設定',
        'Escort'             => '護送系統',
        'HotXmlPath'         => '熱更新路徑',
        'HubServers'         => 'Hub 伺服器數量',
    ];
    for ($i = 0; $i <= 5; $i++) {
        $labels['GGameHubClient' . $i] = 'Hub 客戶端 ' . $i;
    }
    return $labels;
}

/**
 * gamedbd/gamesys.conf（角色資料庫核心設定）可編輯欄位定義。
 * 每筆：section / key / en（英文原文鍵名）/ label（中文）/ type(bool|int|switch|text|list) / desc（中文說明）。
 *
 * 結構說明：
 *   [GameDBServer] 角色資料庫服務主體（連線、緩衝、監聽）
 *   [LogUDPClient] / [LogTCPClient] 日誌服務連線（UDP / TCP）
 *   [storagewdb]   世界資料庫儲存（路徑、檢查點、快取、備份）
 *   [ThreadPool]   執行緒池（執行緒數、佇列、優先級）
 *   [gamedbd]      進階選項（除錯、職業設定匯入、角色刪除緩衝）
 *
 * 註：原始檔中以 ';' 註解、未啟用的各資料表專屬快取水位參數
 *     （base_cache_high/low、status_cache_high/low、inventory_cache_high/low、
 *      task_cache_high/low）不列入可編輯欄位，避免儲存時將註解行意外啟用；
 *      其說明另見「角色DB核心設置」頁面的「進階註解參數」區塊。
 */
function gameserver_gamedbd_fields(): array
{
    // 連線／緩衝類參數在各區段共用（中文標籤＋型別＋說明）
    $common = [
        'type'           => ['連線類型',     'text',   '通訊協定類型（tcp / udp）。'],
        'port'           => ['連接埠',       'int',    '服務監聽或連線的 TCP/UDP 連接埠。'],
        'address'        => ['綁定位址',     'text',   '監聽或連線的 IP 位址（伺服器端常為 127.0.0.1 或 0.0.0.0）。'],
        'so_sndbuf'      => ['傳送緩衝區',   'int',    'Socket 傳送緩衝區大小（位元組）。'],
        'so_rcvbuf'      => ['接收緩衝區',   'int',    'Socket 接收緩衝區大小（位元組）。'],
        'ibuffermax'     => ['輸入緩衝上限', 'int',    '單次輸入緩衝區上限（位元組）。'],
        'obuffermax'     => ['輸出緩衝上限', 'int',    '單次輸出緩衝區上限（位元組）。'],
        'accumulate'     => ['累積緩衝',     'int',    '封包累積緩衝大小（位元組）。'],
        'tcp_nodelay'    => ['TCP 無延遲',   'switch', '啟用 TCP_NODELAY 以降低延遲（1 開啟／0 關閉）。'],
        'listen_backlog' => ['監聽佇列',     'int',    '連線等待佇列（listen backlog）長度。'],
    ];
    // 依區段與鍵清單產生欄位定義（overrides 可覆寫中文標籤／型別／說明）
    $mk = function (string $section, array $keys, array $overrides = []) use ($common): array {
        $out = [];
        foreach ($keys as $k) {
            $meta = $overrides[$k] ?? ($common[$k] ?? [$k, 'text', '']);
            $out[] = [
                'section' => $section,
                'key'     => $k,
                'en'      => $k,
                'label'   => $meta[0],
                'type'    => $meta[1],
                'desc'    => $meta[2],
            ];
        }
        return $out;
    };

    $fields = [];

    // ── 角色資料庫服務主體 ────────────────────────────────
    $fields = array_merge($fields, $mk('GameDBServer', [
        'zoneid', 'aid', 'type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf',
        'ibuffermax', 'obuffermax', 'tcp_nodelay', 'listen_backlog', 'accumulate',
    ], [
        'zoneid' => ['分區編號', 'int', '所屬遊戲分區（zone）編號，須與其他服務一致。'],
        'aid'    => ['區服編號', 'int', '本伺服器於該分區的區服編號。'],
    ]));

    // ── 日誌服務（UDP / TCP 客戶端）───────────────────────
    $fields = array_merge($fields, $mk('LogUDPClient', ['type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf', 'ibuffermax', 'obuffermax', 'accumulate']));
    $fields = array_merge($fields, $mk('LogTCPClient', ['type', 'port', 'address', 'so_sndbuf', 'so_rcvbuf', 'ibuffermax', 'obuffermax', 'accumulate']));

    // ── 世界資料庫儲存（wdb）──────────────────────────────
    $fields = array_merge($fields, $mk('storagewdb', [
        'homedir', 'datadir', 'logdir', 'backupdir', 'checkpoint_interval',
        'times_incbackup', 'tables', 'cache_high_default', 'cache_low_default',
        'backup_lockfile', 'quit_lockfile',
    ], [
        'homedir'             => ['資料根目錄',    'text', '世界資料庫主目錄（dbhomewdb）的絕對路徑。'],
        'datadir'             => ['資料子目錄',    'text', '相對於 homedir 的實際資料目錄名稱。'],
        'logdir'              => ['日誌目錄',      'text', '相對於 homedir 的日誌目錄名稱。'],
        'backupdir'           => ['備份目錄',      'text', '備份檔存放的絕對路徑。'],
        'checkpoint_interval' => ['檢查點間隔',    'int',  '檢查點（checkpoint）寫入間隔，單位秒。'],
        'times_incbackup'     => ['增量備份次數',  'int',  '累積達此次數的增量備份後觸發一次完整備份。'],
        'tables'              => ['資料表清單',    'list', '納入資料庫管理的世界資料表名稱清單（逗號分隔）。'],
        'cache_high_default'  => ['預設快取上限',  'int',  '一般資料表的快取高水位（筆數）。'],
        'cache_low_default'   => ['預設快取下限',  'int',  '一般資料表的快取低水位（筆數）。'],
        'backup_lockfile'     => ['備份鎖定檔',    'text', '備份程序使用的鎖定檔路徑。'],
        'quit_lockfile'       => ['結束鎖定檔',    'text', '伺服器結束程序使用的鎖定檔路徑。'],
    ]));

    // ── 執行緒池 ──────────────────────────────────────────
    $fields = array_merge($fields, $mk('ThreadPool', ['threads', 'max_queuesize', 'prior_strict'], [
        'threads'       => ['執行緒配置', 'text',   '執行緒池配置，格式為 (優先級,數量) 串接，例如 (1,15)(0,1)(100,1)(101,1)。'],
        'max_queuesize' => ['佇列上限',   'int',    '任務佇列最大長度。'],
        'prior_strict'  => ['嚴格優先級', 'switch', '是否嚴格依優先級處理任務（1 開啟／0 關閉）。'],
    ]));

    // ── gamedbd 進階選項 ──────────────────────────────────
    $fields = array_merge($fields, $mk('gamedbd', ['debug_mode', 'import_clsconfig', 'role_delete_timeout'], [
        'debug_mode'          => ['除錯模式',      'switch', '啟用除錯模式（1 開啟／0 關閉）。'],
        'import_clsconfig'    => ['匯入職業設定',  'switch', '啟動時是否匯入職業（class）設定（1 開啟／0 關閉）。'],
        'role_delete_timeout' => ['角色刪除緩衝期', 'int',   '角色刪除後仍可還原的保留秒數（604800 秒＝7 天）。'],
    ]));

    return $fields;
}

/** gamedbd/gamesys.conf 各區段的中文名稱（依原始出現順序） */
function gameserver_gamedbd_section_labels(): array
{
    return [
        'GameDBServer' => '角色資料庫服務（主體）',
        'LogUDPClient' => '日誌 UDP 客戶端',
        'LogTCPClient' => '日誌 TCP 客戶端',
        'storagewdb'   => '世界資料庫儲存',
        'ThreadPool'   => '執行緒池',
        'gamedbd'      => 'gamedbd 進階選項',
    ];
}

/**
 * 受管理的設定檔註冊表。
 * fields 定義每個可編輯參數：section / key / label / type(bool|int|switch|text)；
 * 另可含 en（英文原文）與 desc（中文說明）。
 * 新增其他檔案時，於此加入一筆即可，前台會自動產生對應卡片。
 */
function gameserver_file_registry(): array
{
    return [
        'gamed_gs' => [
            'label'        => 'gamed 核心設定',
            'sub'          => 'gs.conf',
            'default_path' => '/root/xa274/gamed/gs.conf',
            'fields'       => [
                ['section' => 'General', 'key' => 'anti_wallow',        'label' => '是否防沉迷',      'type' => 'switch', 'toggle_on' => '1', 'toggle_off' => '0'],
                ['section' => 'General', 'key' => 'debug_command_mode', 'label' => '调试命令',        'type' => 'switch', 'toggle_on' => '1', 'toggle_off' => '0'],
                ['section' => 'General', 'key' => 'CheckPlayerMove',    'label' => 'CheckPlayerMove', 'type' => 'int'],
                ['section' => 'General', 'key' => 'logic_level_limit',  'label' => '等級上限',        'type' => 'int'],
            ],
        ],
        // 角色資料庫核心設定（gamedbd/gamesys.conf，由「角色DB核心設置」專用頁面管理）
        'gamedbd_gamesys' => [
            'label'          => '角色DB核心設定',
            'sub'            => 'gamesys.conf',
            'default_path'   => '/root/xa274/gamedbd/gamesys.conf',
            'hidden'         => true,
            'fields'         => gameserver_gamedbd_fields(),
            'section_labels' => gameserver_gamedbd_section_labels(),
        ],
        // 基礎信息服務設定（gdeliveryd/gamesys.conf，由「基礎信息設定」專用頁面管理）
        'gdeliveryd_gamesys' => [
            'label'          => '基礎信息服務設定',
            'sub'            => 'gamesys.conf',
            'default_path'   => '/root/xa274/gdeliveryd/gamesys.conf',
            'hidden'         => true,
            'fields'         => gameserver_gdeliveryd_fields(),
            'section_labels' => gameserver_gdeliveryd_section_labels(),
        ],
        // 喊話設定檔（ds_speak.lua，Lua 表格，由「喊話設定」專用頁面管理）
        'gdeliveryd_dspeak' => [
            'label'        => '喊話設定',
            'sub'          => 'ds_speak.lua',
            'default_path' => '/root/xa274/gdeliveryd/ds_speak.lua',
            'format'       => 'lua_dspeak',
            'hidden'       => true,
            'fields'       => [],
        ],
        // 戰場地圖設定檔（warzonemap.lua，Lua 表格，由「戰場地圖設定」專用頁面管理）
        'gdeliveryd_warzone' => [
            'label'        => '戰場地圖設定',
            'sub'          => 'warzonemap.lua',
            'default_path' => '/root/xa274/gdeliveryd/warzonemap.lua',
            'format'       => 'lua_warzone',
            'hidden'       => true,
            'fields'       => [],
        ],
        // 傳送資訊設定檔（transinfo.lua，Lua 表格，由「傳送資訊設定」專用頁面管理）
        'gdeliveryd_transinfo' => [
            'label'        => '傳送資訊設定',
            'sub'          => 'transinfo.lua',
            'default_path' => '/root/xa274/gdeliveryd/transinfo.lua',
            'format'       => 'lua_transinfo',
            'hidden'       => true,
            'fields'       => [],
        ],
        // 副本設定檔（Lua 表格，由「副本設定」專用頁面管理）
        'gdeliveryd_instance' => [
            'label'        => '副本設定',
            'sub'          => 'instance_common.lua',
            'default_path' => '/root/xa274/gdeliveryd/instance_common.lua',
            'format'       => 'lua_instance',
            'hidden'       => true,
            'fields'       => [],
        ],
        // 勢力城市設定檔（factioncity.lua，Lua 表格，由「勢力城市設定」專用頁面管理）
        'gdeliveryd_factioncity' => [
            'label'        => '勢力城市設定',
            'sub'          => 'factioncity.lua',
            'default_path' => '/root/xa274/gdeliveryd/factioncity.lua',
            'format'       => 'lua_factioncity',
            'hidden'       => true,
            'fields'       => [],
        ],
        // 熱更新指令設定檔（opercmd.xml，XML，由「熱更新指令設定」專用頁面管理）
        'gdeliveryd_opercmd' => [
            'label'        => '熱更新指令設定',
            'sub'          => 'opercmd.xml',
            'default_path' => '/root/xa274/gdeliveryd/opercmd.xml',
            'format'       => 'xml_opercmd',
            'hidden'       => true,
            'fields'       => [],
        ],
        // 活動設定檔（Lua 表格，由「活動設定」專用頁面管理）
        'gdeliveryd_campaign' => [
            'label'        => '活動設定',
            'sub'          => 'campaignlist_cn.lua',
            'default_path' => '/root/xa274/gdeliveryd/campaignlist_cn.lua',
            'format'       => 'lua_campaign',
            'hidden'       => true,
            'fields'       => [],
        ],
        // 帳號驗證資料庫結構（authd/build/table.xml，由「帳號驗證資料庫結構」專用頁面管理）
        'authd_table' => [
            'label'        => '帳號驗證資料庫結構',
            'sub'          => 'table.xml',
            'default_path' => '/root/xa274/authd/build/table.xml',
            'format'       => 'xml_table',
            'hidden'       => true,
            'fields'       => [],
        ],
    ];
}

/**
 * 開關型欄位的開／關寫入值（預設 1 / 0）。
 * @return array { on, off }
 */
function gameserver_field_switch(array $f): array
{
    return [
        'on'  => isset($f['toggle_on']) ? (string)$f['toggle_on'] : '1',
        'off' => isset($f['toggle_off']) ? (string)$f['toggle_off'] : '0',
    ];
}

/** 判斷開關型欄位目前是否為「開啟」狀態（相容 1 / true） */
function gameserver_switch_is_on(array $f, $value): bool
{
    $v = strtolower(trim((string)$value));
    $sw = gameserver_field_switch($f);
    return ($v === strtolower($sw['on']) || $v === 'true' || $v === '1');
}

/** 由欄位代碼尋找 registry 定義（附加 file_key / file_label） */
function gameserver_field_by_key($key): ?array
{
    foreach (gameserver_file_registry() as $fileKey => $def) {
        foreach (($def['fields'] ?? []) as $f) {
            if (($f['key'] ?? '') === $key) {
                $f['file_key']   = $fileKey;
                $f['file_label'] = (string)($def['label'] ?? '');
                return $f;
            }
        }
    }
    return null;
}

/* ============================================================
 *  一、資料表與檔案列存取
 * ============================================================ */
/** 建立資料表並為每個註冊檔補上預設列（可重複執行、非破壞性） */
function gameserver_files_ensure($link): void
{
    if (!$link) {
        return;
    }
    $t = GAME_SERVER_FILES_TABLE;
    try {
        $link->query("CREATE TABLE IF NOT EXISTS `$t` (
            `file_key` varchar(64) NOT NULL,
            `label` varchar(120) NOT NULL DEFAULT '',
            `path` varchar(255) NOT NULL DEFAULT '',
            `raw_cache` mediumtext NULL,
            `initial_raw` mediumtext NULL,
            `updated_by` varchar(50) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`file_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $link->prepare("INSERT IGNORE INTO `$t` (`file_key`,`label`,`path`) VALUES (?,?,?)");
        if ($stmt) {
            foreach (gameserver_file_registry() as $key => $def) {
                $label = (string)$def['label'];
                $path  = (string)$def['default_path'];
                $stmt->bind_param('sss', $key, $label, $path);
                $stmt->execute();
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        // 權限不足時略過
    }
}

/** 取得單一檔案設定（合併 registry 預設值；未知代碼回傳 null） */
function gameserver_file_get($link, $key): ?array
{
    $reg = gameserver_file_registry();
    if (!isset($reg[$key])) {
        return null;
    }
    $def = $reg[$key];
    $row = [
        'file_key'    => (string)$key,
        'label'       => (string)$def['label'],
        'path'        => (string)$def['default_path'],
        'raw_cache'   => '',
        'initial_raw' => '',
        'updated_by'  => '',
        'updated_at'  => '',
    ];
    if ($link) {
        try {
            $stmt = $link->prepare("SELECT * FROM `" . GAME_SERVER_FILES_TABLE . "` WHERE `file_key` = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $key);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($r) {
                    $row = array_merge($row, $r);
                }
            }
        } catch (Throwable $e) {
            // 忽略
        }
    }
    $row['raw_cache']   = gameserver_decode_raw($row['raw_cache'] ?? '');
    $row['initial_raw'] = gameserver_decode_raw($row['initial_raw'] ?? '');
    return $row;
}

/** 取得所有註冊檔案的設定列（依 registry 順序） */
function gameserver_files_all($link): array
{
    $out = [];
    foreach (array_keys(gameserver_file_registry()) as $key) {
        $row = gameserver_file_get($link, $key);
        if ($row !== null) {
            $out[$key] = $row;
        }
    }
    return $out;
}

/** 設定檔案路徑 */
function gameserver_file_set_path($link, $key, $path, $by = ''): bool
{
    $reg = gameserver_file_registry();
    if (!$link || !isset($reg[$key])) {
        return false;
    }
    $path = trim((string)$path);
    if ($path === '') {
        $path = (string)$reg[$key]['default_path'];
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    try {
        $stmt = $link->prepare("UPDATE `" . GAME_SERVER_FILES_TABLE . "` SET `path`=?, `updated_by`=? WHERE `file_key`=?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sss', $path, $by, $key);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

/** 由內容更新快取與初始快照；成功回傳 true */
function gameserver_file_cache($link, $key, $content, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $row = gameserver_file_get($link, $key);
    if ($row === null) {
        return false;
    }
    $initial = (string)($row['initial_raw'] ?? '');
    if ($initial === '') {
        $initial = (string)$content;
    }
    $enc  = gameserver_encode_raw($content);
    $ienc = gameserver_encode_raw($initial);
    try {
        $stmt = $link->prepare("UPDATE `" . GAME_SERVER_FILES_TABLE . "` SET `raw_cache`=?, `initial_raw`=?, `updated_by`=? WHERE `file_key`=?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssss', $enc, $ienc, $by, $key);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

/* ============================================================
 *  二、遠端讀寫（SSH / SFTP）
 * ============================================================ */
/** 取得共用目標伺服器（game_server_config.server_id） */
function gameserver_file_target_server($link)
{
    require_once __DIR__ . '/admin/remote_lib.php';
    $cfg = gameserver_config_get($link);
    return gameserver_target_server($link, $cfg);
}

/**
 * 讀取指定檔案的遠端內容。
 * @return array { ok, content?, error?, server?, path? }
 */
function gameserver_file_read($link, $key): array
{
    $row = gameserver_file_get($link, $key);
    if ($row === null) {
        return ['ok' => false, 'error' => '未知的設定檔。'];
    }
    $server = gameserver_file_target_server($link);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器（請先於「遊戲版本設定」選擇）。'];
    }
    $path = (string)$row['path'];
    if ($path === '') {
        return ['ok' => false, 'error' => '尚未設定檔案路徑。'];
    }
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

/* ============================================================
 *  三、高階操作
 * ============================================================ */
/** 讀取遠端並更新快取 */
function gameserver_file_refresh($link, $key, $by = ''): array
{
    $read = gameserver_file_read($link, $key);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    if (!gameserver_file_cache($link, $key, $read['content'], $by)) {
        return ['ok' => false, 'error' => '讀取成功，但本機快取寫入失敗（請檢查資料庫）。'];
    }
    if (!empty($read['server']) && function_exists('remote_audit')) {
        remote_audit($link, $read['server'], 'refresh_file_' . $key, $read['path'], '');
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * 套用區段參數更新：讀取 → 逐區段修改 → 寫回 → 更新快取。
 * @param array $updatesBySection 例如 ['General'=>['logic_level_limit'=>'120']]
 */
function gameserver_file_apply($link, $key, array $updatesBySection, $by = ''): array
{
    $read = gameserver_file_read($link, $key);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = $read['content'];
    foreach ($updatesBySection as $section => $updates) {
        if (!is_array($updates) || empty($updates)) {
            continue;
        }
        $content = gameserver_update_section($content, (string)$section, $updates);
    }
    try {
        $sftp = remote_sftp($read['server'], 30);
        if (!$sftp->put($read['path'], $content)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $read['path']];
        }
        $cached = gameserver_file_cache($link, $key, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $read['server'], 'save_file_' . $key, $read['path'], '');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

/** 還原初始內容快照 */
function gameserver_file_restore($link, $key, $by = ''): array
{
    $row = gameserver_file_get($link, $key);
    if ($row === null) {
        return ['ok' => false, 'error' => '未知的設定檔。'];
    }
    $initial = (string)$row['initial_raw'];
    if ($initial === '') {
        return ['ok' => false, 'error' => '尚無初始快照，請先執行「讀取最新」。'];
    }
    $server = gameserver_file_target_server($link);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器。'];
    }
    try {
        $sftp = remote_sftp($server, 30);
        if (!$sftp->put((string)$row['path'], $initial)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限。'];
        }
        gameserver_file_cache($link, $key, $initial, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, 'restore_file_' . $key, (string)$row['path'], '');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => ''];
}

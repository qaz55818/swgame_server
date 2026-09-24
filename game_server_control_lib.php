<?php
/**
 * =============================================================
 *  踏雪笑傲 · 遊戲伺服器啟停控制工具庫（game_server_control_lib.php）
 *  -------------------------------------------------------------
 *  功能：
 *    - 設定啟動／關閉腳本路徑、遊戲目錄與啟動參數
 *    - 啟動（含防重複啟動防護）／關閉整體伺服器
 *    - 即時偵測各服務程序狀態（pgrep 等效，改用 ps 解析）
 *    - 個別服務的啟動／關閉
 *    - 操作稽核
 *
 *  目標伺服器沿用 game_server_config.server_id。
 *  資料表：game_server_control（見 game_server_control_schema.sql）
 * =============================================================
 */

if (defined('GAME_SERVER_CONTROL_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_CONTROL_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_lib.php';

if (!defined('GAME_SERVER_CONTROL_TABLE')) {
    define('GAME_SERVER_CONTROL_TABLE', 'game_server_control');
}
if (!defined('GAME_SERVER_START_GUARD_SECONDS')) {
    define('GAME_SERVER_START_GUARD_SECONDS', 120);
}

/* ============================================================
 *  一、設定存取
 * ============================================================ */
function gameserver_control_ensure($link): void
{
    if (!$link) {
        return;
    }
    $t = GAME_SERVER_CONTROL_TABLE;
    try {
        $link->query("CREATE TABLE IF NOT EXISTS `$t` (
            `id` tinyint(1) NOT NULL DEFAULT 1,
            `start_script` varchar(255) NOT NULL DEFAULT '/root/xa274/myqd',
            `stop_script` varchar(255) NOT NULL DEFAULT '/root/xa274/stop',
            `pw_path` varchar(255) NOT NULL DEFAULT '/root/xa274',
            `signup_port` int(11) NOT NULL DEFAULT 8888,
            `small` varchar(8) NOT NULL DEFAULT 'yes',
            `gangs` varchar(16) NOT NULL DEFAULT 'allow',
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `last_start_at` datetime NULL,
            `updated_by` varchar(50) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $link->query("INSERT IGNORE INTO `$t` (`id`) VALUES (1)");
    } catch (Throwable $e) {
        // 忽略
    }
}

/** 讀取設定（資料表不存在時回傳預設值） */
function gameserver_control_get($link): array
{
    $default = [
        'id'            => 1,
        'start_script'  => '/root/xa274/myqd',
        'stop_script'   => '/root/xa274/stop',
        'pw_path'       => '/root/xa274',
        'signup_port'   => 8888,
        'small'         => 'yes',
        'gangs'         => 'allow',
        'enabled'       => 1,
        'last_start_at' => '',
        'updated_by'    => '',
        'updated_at'    => '',
    ];
    if (!$link) {
        return $default;
    }
    try {
        $res = @$link->query("SELECT * FROM `" . GAME_SERVER_CONTROL_TABLE . "` WHERE `id` = 1 LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return array_merge($default, $row);
        }
    } catch (Throwable $e) {
        // 忽略
    }
    return $default;
}

/** 儲存設定 */
function gameserver_control_save($link, array $data, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $startScript = trim((string)($data['start_script'] ?? ''));
    $stopScript  = trim((string)($data['stop_script'] ?? ''));
    $pwPath      = trim((string)($data['pw_path'] ?? ''));
    $signupPort  = (int)($data['signup_port'] ?? 8888);
    $small       = ((string)($data['small'] ?? 'yes') === 'yes') ? 'yes' : 'no';
    $gangs       = ((string)($data['gangs'] ?? 'allow') === 'notallow') ? 'notallow' : 'allow';
    $enabled     = !empty($data['enabled']) ? 1 : 0;
    if ($pwPath !== '' && $pwPath[0] !== '/') {
        $pwPath = '/' . $pwPath;
    }
    if ($signupPort < 1 || $signupPort > 65535) {
        $signupPort = 8888;
    }
    try {
        $stmt = $link->prepare("UPDATE `" . GAME_SERVER_CONTROL_TABLE . "`
            SET `start_script`=?, `stop_script`=?, `pw_path`=?, `signup_port`=?, `small`=?, `gangs`=?, `enabled`=?, `updated_by`=?
            WHERE `id`=1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sssissss', $startScript, $stopScript, $pwPath, $signupPort, $small, $gangs, $enabled, $by);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

/** 記錄啟動時間（防重複啟動防護） */
function gameserver_control_mark_start($link): void
{
    if (!$link) {
        return;
    }
    try {
        $link->query("UPDATE `" . GAME_SERVER_CONTROL_TABLE . "` SET `last_start_at`=NOW() WHERE `id`=1");
    } catch (Throwable $e) {
        // 忽略
    }
}

/* ============================================================
 *  二、服務清單
 * ============================================================ */
/**
 * 依設定產生服務註冊表。
 * @return array key => [label, match, start, kill]
 */
function gameserver_service_registry(array $cfg): array
{
    $pw   = rtrim((string)($cfg['pw_path'] ?? '/root/xa274'), '/');
    if ($pw === '') {
        $pw = '/root/xa274';
    }
    $logs = $pw . '/logs';
    $port = (int)($cfg['signup_port'] ?? 8888);
    $small = ((string)($cfg['small'] ?? 'yes') === 'yes');
    $gangs = ((string)($cfg['gangs'] ?? 'allow') !== 'notallow');

    $mk = function ($label, $match, $start) {
        return [
            'label' => $label,
            'match' => $match,
            'start' => $start,
            'kill'  => preg_replace('/^(.)/', '[$1]', $match),
        ];
    };

    $svc = [];
    $svc['signupserv']  = $mk('帳號註冊服務', 'signupserv', "cd {$pw}/signupservice/ && nohup ./signupserv 0.0.0.0 {$port} >{$logs}/signupservice.log 2>&1 &");
    $svc['authd']       = $mk('帳號數據服務', 'authd', "cd {$pw}/authd/build/ && nohup ./authd >{$logs}/authd.log 2>&1 &");
    $svc['gamedbd']     = $mk('角色數據服務', 'gamedbd', "cd {$pw}/gamedbd/ && nohup ./gamedbd gamesys.conf >{$logs}/gamedbd.log 2>&1 &");
    $svc['gacd']        = $mk('防沉迷服務', 'gacd', "cd {$pw}/gacd && nohup ./gacd gamesys.conf >{$logs}/gacd.log 2>&1 &");
    $svc['uniquenamed'] = $mk('角色名稱服務', 'uniquenamed', "cd {$pw}/uniquenamed && nohup ./uniquenamed gamesys.conf >{$logs}/uniquename.log 2>&1 &");
    $svc['gdeliveryd']  = $mk('基礎信息服務', 'gdeliveryd', "cd {$pw}/gdeliveryd/ && nohup ./gdeliveryd gamesys.conf >{$logs}/gdeliveryd.log 2>&1 &");
    $svc['g3dacd']      = $mk('3DAC 服務', 'g3dacd', "cd {$pw}/gdeliveryd/ && nohup ./g3dacd g3dacd.conf >{$logs}/g3dacd.log 2>&1 &");
    $svc['ghubd']       = $mk('通訊信息服務', 'ghubd', "cd {$pw}/ghubd/ && nohup ./ghubd gamesys.conf >{$logs}/ghubd.log 2>&1 &");
    $svc['logservice']  = $mk('日誌監控服務', 'logservice', "cd {$pw}/logservice && nohup ./logservice logservice.conf >{$logs}/logservice.log 2>&1 &");
    $svc['glinkd']      = $mk('登錄通訊服務', 'glinkd',
        "cd {$pw}/glinkd/ && nohup ./glinkd --ccs gamesys.conf 1 >{$logs}/glinkd1.log 2>&1 & "
        . "nohup ./glinkd --cls -i 1 gamesys.conf >{$logs}/glinkd2.log 2>&1 &");

    $gs = "cd {$pw}/gamed/ && "
        . "nohup ./gs gs.conf gmserver.conf config/gsalias1.conf >{$logs}/gs01.log 2>&1 & sleep 1; "
        . "nohup ./gs gs.conf gmserver.conf config/gsalias2.conf >{$logs}/gs02.log 2>&1 &";
    if (!$small) {
        $gs .= " sleep 1; nohup ./gs gs.conf gmserver.conf config/gsalias3.conf >{$logs}/gs03.log 2>&1 & sleep 1; "
             . "nohup ./gs gs.conf gmserver.conf config/gsalias4.conf >{$logs}/gs04.log 2>&1 & sleep 1; "
             . "nohup ./gs gs.conf gmserver.conf config/gsalias15.conf >{$logs}/gs15.log 2>&1 &";
        if ($gangs) {
            $gs .= " sleep 1; nohup ./gs gs.conf gmserver.conf config/gsalias28.conf >{$logs}/gs28.log 2>&1 & sleep 1; "
                 . "nohup ./gs gs.conf gmserver.conf config/gsalias29.conf >{$logs}/gs29.log 2>&1 & sleep 1; "
                 . "nohup ./gs gs.conf gmserver.conf config/gsalias30.conf >{$logs}/gs30.log 2>&1 &";
        }
    }
    $svc['gs'] = $mk('副本幫派地圖服務', 'gsalias', $gs);

    return $svc;
}

/* ============================================================
 *  三、遠端連線與執行
 * ============================================================ */
/** 取得共用目標伺服器 */
function gameserver_control_server($link)
{
    require_once __DIR__ . '/admin/remote_lib.php';
    $cfg = gameserver_config_get($link);
    return gameserver_target_server($link, $cfg);
}

/** 以 base64 包裝執行遠端 shell（避免引號問題），detached=背景執行 */
function gameserver_control_run($ssh, $script, $logPath = '', $detached = true, $timeout = 30): array
{
    $b64 = base64_encode((string)$script);
    if ($detached) {
        $log = ($logPath !== '') ? escapeshellarg($logPath) : '/dev/null';
        $cmd = "printf '%s' '$b64' | base64 -d | nohup sh >{$log} 2>&1 & echo __STARTED__";
    } else {
        $cmd = "printf '%s' '$b64' | base64 -d | sh 2>&1";
    }
    return remote_exec($ssh, $cmd, $timeout);
}

/* ============================================================
 *  四、狀態偵測
 * ============================================================ */
/**
 * 取得各服務即時狀態（含連線狀態）。
 * @return array { reachable: bool, error: string, services: array }
 */
function gameserver_services_status_ex($link): array
{
    $cfg = gameserver_control_get($link);
    $services = gameserver_service_registry($cfg);
    $out = [];
    foreach ($services as $key => $s) {
        $out[$key] = ['label' => $s['label'], 'running' => false, 'count' => 0, 'pids' => []];
    }

    $server = gameserver_control_server($link);
    if (!$server) {
        return ['reachable' => false, 'error' => '尚未指定目標伺服器。', 'services' => $out];
    }
    try {
        $ssh = remote_connect($server, 15);
        // 注意：部分舊版 procps 對 `ps -eo pid=,args=` 會忽略 args，需加 --no-headers 才正確。
        $r = remote_exec($ssh, 'ps -eww -o pid=,args= --no-headers', 15);
        foreach (preg_split('/\r?\n/', (string)$r['output']) as $line) {
            if (!preg_match('/^\s*(\d+)\s+(.*)$/', $line, $m)) {
                continue;
            }
            $pid  = (int)$m[1];
            $args = $m[2];
            foreach ($services as $key => $s) {
                if (stripos($args, $s['match']) !== false) {
                    $out[$key]['pids'][] = $pid;
                }
            }
        }
    } catch (Throwable $e) {
        foreach ($out as $key => &$row) {
            $row['count'] = count($row['pids']);
            $row['running'] = ($row['count'] > 0);
        }
        unset($row);
        return ['reachable' => false, 'error' => $e->getMessage(), 'services' => $out];
    }
    foreach ($out as $key => &$row) {
        $row['count'] = count($row['pids']);
        $row['running'] = ($row['count'] > 0);
    }
    unset($row);
    return ['reachable' => true, 'error' => '', 'services' => $out];
}

/**
 * 取得各服務即時狀態。
 * @return array key => [label, running(bool), count(int), pids(int[])]
 */
function gameserver_services_status($link): array
{
    $ex = gameserver_services_status_ex($link);
    return $ex['services'];
}

/** 是否已有任何服務在執行 */
function gameserver_any_running(array $status): bool
{
    foreach ($status as $row) {
        if (!empty($row['running'])) {
            return true;
        }
    }
    return false;
}

/** 距離上次啟動是否仍在防護期內 */
function gameserver_start_guard_active(array $cfg): bool
{
    $last = (string)($cfg['last_start_at'] ?? '');
    if ($last === '' || $last === '0000-00-00 00:00:00') {
        return false;
    }
    $ts = strtotime($last);
    if ($ts === false) {
        return false;
    }
    return (time() - $ts) < GAME_SERVER_START_GUARD_SECONDS;
}

/* ============================================================
 *  五、操作
 * ============================================================ */
/** 啟動整體伺服器（含防重複啟動防護） */
function gameserver_control_start_all($link, $by = ''): array
{
    $cfg = gameserver_control_get($link);
    if ((int)$cfg['enabled'] !== 1) {
        return ['ok' => false, 'error' => '伺服器控制功能已停用。'];
    }
    $ex = gameserver_services_status_ex($link);
    if (empty($ex['reachable'])) {
        return ['ok' => false, 'error' => '無法連線至目標伺服器（' . $ex['error'] . '），為安全起見已暫停啟動。'];
    }
    $status = $ex['services'];
    if (gameserver_any_running($status)) {
        return ['ok' => false, 'error' => '偵測到伺服器程序已在執行，已阻止重複啟動。'];
    }
    if (gameserver_start_guard_active($cfg)) {
        return ['ok' => false, 'error' => '啟動程序剛執行不久，請稍候再試（防止重複啟動）。'];
    }
    $server = gameserver_control_server($link);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器。'];
    }
    $script = trim((string)$cfg['start_script']);
    if ($script === '') {
        return ['ok' => false, 'error' => '尚未設定啟動腳本路徑。'];
    }
    try {
        $ssh = remote_connect($server, 20);
        $cmd = 'cd ' . escapeshellarg(rtrim((string)$cfg['pw_path'], '/')) . ' && nohup sh ' . escapeshellarg($script)
             . ' >' . escapeshellarg(rtrim((string)$cfg['pw_path'], '/') . '/logs/web_start.log') . ' 2>&1 & echo __STARTED__';
        $r = remote_exec($ssh, $cmd, 20);
        gameserver_control_mark_start($link);
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, 'server_start', $script, '');
        }
        return ['ok' => true, 'error' => '', 'message' => '已執行啟動腳本，伺服器啟動中…'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** 關閉整體伺服器（無限制） */
function gameserver_control_stop_all($link, $by = ''): array
{
    $cfg = gameserver_control_get($link);
    $server = gameserver_control_server($link);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器。'];
    }
    try {
        $ssh = remote_connect($server, 30);
        $script = trim((string)$cfg['stop_script']);
        if ($script !== '') {
            $cmd = 'nohup sh ' . escapeshellarg($script) . ' >' . escapeshellarg(rtrim((string)$cfg['pw_path'], '/') . '/logs/web_stop.log') . ' 2>&1 & echo __STARTED__';
            remote_exec($ssh, $cmd, 20);
        } else {
            // 無關閉腳本：逐一終止目標程序
            $services = gameserver_service_registry($cfg);
            $kill = '';
            foreach ($services as $s) {
                $kill .= 'pkill -9 -f ' . escapeshellarg($s['kill']) . ' 2>/dev/null; ';
            }
            remote_exec($ssh, $kill . 'echo __DONE__', 30);
        }
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, 'server_stop', $script, '');
        }
        return ['ok' => true, 'error' => '', 'message' => '已執行關閉程序。'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** 啟動單一服務 */
function gameserver_control_service_start($link, $key, $by = ''): array
{
    $cfg = gameserver_control_get($link);
    $services = gameserver_service_registry($cfg);
    if (!isset($services[$key])) {
        return ['ok' => false, 'error' => '未知的服務。'];
    }
    $ex = gameserver_services_status_ex($link);
    if (empty($ex['reachable'])) {
        return ['ok' => false, 'error' => '無法連線至目標伺服器（' . $ex['error'] . '）。'];
    }
    if (!empty($ex['services'][$key]['running'])) {
        return ['ok' => false, 'error' => $services[$key]['label'] . ' 已在執行中。'];
    }
    $server = gameserver_control_server($link);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器。'];
    }
    try {
        $ssh = remote_connect($server, 20);
        $log = rtrim((string)$cfg['pw_path'], '/') . '/logs/web_svc.log';        $r = gameserver_control_run($ssh, $services[$key]['start'], $log, true, 20);
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, 'service_start_' . $key, '', '');
        }
        return ['ok' => true, 'error' => '', 'message' => $services[$key]['label'] . ' 啟動指令已送出。'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** 關閉單一服務 */
function gameserver_control_service_stop($link, $key, $by = ''): array
{
    $cfg = gameserver_control_get($link);
    $services = gameserver_service_registry($cfg);
    if (!isset($services[$key])) {
        return ['ok' => false, 'error' => '未知的服務。'];
    }
    $server = gameserver_control_server($link);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器。'];
    }
    try {
        $ssh = remote_connect($server, 20);
        $cmd = 'pkill -9 -f ' . escapeshellarg($services[$key]['kill']) . ' 2>/dev/null; echo __DONE__';
        remote_exec($ssh, $cmd, 20);
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, 'service_stop_' . $key, '', '');
        }
        return ['ok' => true, 'error' => '', 'message' => $services[$key]['label'] . ' 已送出終止指令。'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

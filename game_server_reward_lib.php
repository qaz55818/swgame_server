<?php
/**
 * =============================================================
 *  踏雪笑傲 · 獎勵設定工具庫（game_server_reward_lib.php）
 *  -------------------------------------------------------------
 *  管理遠端 reward_data.lua（gamed/config/script/）：
 *    - 設定遠端檔案路徑（game_server_reward_config）
 *    - 透過 SSH/SFTP 讀取與寫回
 *    - 內容快取與初始快照（供「還原初始」）
 *    - 通用 Lua 設定解析器（詞法 + 語法，保留原始格式與位置），
 *      可辨識純量、索引表、具名表、巢狀表與函式（略過）
 *    - 以行內位元組位移就地替換數值，其餘內容、註解與縮排維持不變
 *    - 中文說明 + 英文原文（gameserver_reward_doc）
 *    - 寫入操作稽核（沿用 admin/remote_lib.php）
 *
 *  目標伺服器沿用 game_server_config.server_id（與「遊戲版本設定」共用）。
 *  資料表：game_server_reward_config（見 game_server_script_schema.sql）
 *  依賴：game_server_script_lib.php（編碼／目標伺服器）、admin/remote_lib.php
 * =============================================================
 */

if (defined('GAME_SERVER_REWARD_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_REWARD_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_script_lib.php';

if (!defined('GAME_SERVER_REWARD_TABLE')) {
    define('GAME_SERVER_REWARD_TABLE', 'game_server_reward_config');
}
if (!defined('GAME_SERVER_REWARD_DEFAULT_PATH')) {
    define('GAME_SERVER_REWARD_DEFAULT_PATH', '/root/xa274/gamed/config/script/reward_data.lua');
}

/* ============================================================
 *  一、資料表與設定存取（與生產合成設定同構）
 * ============================================================ */
function gameserver_reward_ensure($link): void
{
    if (!$link) {
        return;
    }
    $t = GAME_SERVER_REWARD_TABLE;
    try {
        $link->query("CREATE TABLE IF NOT EXISTS `$t` (
            `id` tinyint(1) NOT NULL DEFAULT 1,
            `path` varchar(255) NOT NULL DEFAULT '" . GAME_SERVER_REWARD_DEFAULT_PATH . "',
            `raw_cache` mediumtext NULL,
            `initial_raw` mediumtext NULL,
            `updated_by` varchar(50) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $link->query("INSERT IGNORE INTO `$t` (`id`,`path`) VALUES (1,'" . GAME_SERVER_REWARD_DEFAULT_PATH . "')");
    } catch (Throwable $e) {
        // 權限不足時略過
    }
}

function gameserver_reward_config_get($link): array
{
    $default = [
        'id' => 1, 'path' => GAME_SERVER_REWARD_DEFAULT_PATH,
        'raw_cache' => '', 'initial_raw' => '', 'updated_by' => '', 'updated_at' => '',
    ];
    if (!$link) {
        return $default;
    }
    try {
        $res = @$link->query("SELECT * FROM `" . GAME_SERVER_REWARD_TABLE . "` WHERE `id` = 1 LIMIT 1");
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

function gameserver_reward_set_path($link, $path, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $path = trim((string)$path);
    if ($path === '') {
        $path = GAME_SERVER_REWARD_DEFAULT_PATH;
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    try {
        $stmt = $link->prepare("INSERT INTO `" . GAME_SERVER_REWARD_TABLE . "` (`id`,`path`,`updated_by`)
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

function gameserver_reward_cache($link, $content, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $cfg = gameserver_reward_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        $initial = (string)$content;
    }
    $enc  = gameserver_encode_raw($content);
    $ienc = gameserver_encode_raw($initial);
    try {
        $stmt = $link->prepare("UPDATE `" . GAME_SERVER_REWARD_TABLE . "`
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
 *  二、編碼轉換（委派生產設定工具庫的通用實作）
 * ============================================================ */
function gameserver_reward_is_utf8($s): bool { return gameserver_produce_is_utf8($s); }
function gameserver_reward_to_utf8($s): string { return gameserver_produce_to_utf8($s); }
function gameserver_reward_from_utf8($s): string { return gameserver_produce_from_utf8($s); }

/* ============================================================
 *  三、遠端讀寫（SSH / SFTP）
 * ============================================================ */
function gameserver_reward_read($link): array
{
    $cfg = gameserver_reward_config_get($link);
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

function gameserver_reward_write($link, $content, $by = '', $action = 'save_reward'): array
{
    $cfg = gameserver_reward_config_get($link);
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
        $cached = gameserver_reward_cache($link, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, $action, $path, '');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

function gameserver_reward_refresh($link, $by = ''): array
{
    $read = gameserver_reward_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    if (!gameserver_reward_cache($link, $read['content'], $by)) {
        return ['ok' => false, 'error' => '讀取成功，但本機快取寫入失敗（請檢查資料庫）。'];
    }
    if (!empty($read['server']) && function_exists('remote_audit')) {
        remote_audit($link, $read['server'], 'refresh_reward', $read['path'], '');
    }
    return ['ok' => true, 'error' => ''];
}

function gameserver_reward_restore($link, $by = ''): array
{
    $cfg = gameserver_reward_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        return ['ok' => false, 'error' => '尚無初始快照，請先執行「讀取最新」。'];
    }
    return gameserver_reward_write($link, $initial, $by, 'restore_reward');
}

/* ============================================================
 *  四、通用 Lua 設定解析器（詞法 + 語法）
 * ============================================================ */
/** 詞法分析：切出 id / num / str / 標點 / 註解 / 空白，並記錄位元組位移 */
function gameserver_reward_lex(string $src): array
{
    $tokens = [];
    $n = strlen($src);
    $i = 0;
    while ($i < $n) {
        $c = $src[$i];
        if (ctype_space($c)) {
            $j = $i + 1;
            while ($j < $n && ctype_space($src[$j])) { $j++; }
            $tokens[] = ['t' => 'ws', 's' => $i, 'e' => $j];
            $i = $j;
            continue;
        }
        if ($c === '-' && $i + 1 < $n && $src[$i + 1] === '-') {
            $j = $i + 2;
            while ($j < $n && $src[$j] !== "\n" && $src[$j] !== "\r") { $j++; }
            $tokens[] = ['t' => 'cmt', 's' => $i, 'e' => $j];
            $i = $j;
            continue;
        }
        if ($c === '"' || $c === "'") {
            $q = $c;
            $j = $i + 1;
            while ($j < $n) {
                if ($src[$j] === '\\') { $j += 2; continue; }
                if ($src[$j] === $q) { $j++; break; }
                $j++;
            }
            $tokens[] = ['t' => 'str', 'v' => substr($src, $i, $j - $i), 's' => $i, 'e' => $j];
            $i = $j;
            continue;
        }
        if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($src[$i + 1]))) {
            $j = $i;
            $seenE = false;
            while ($j < $n) {
                $ch = $src[$j];
                if (ctype_digit($ch) || $ch === '.') { $j++; continue; }
                if (($ch === 'e' || $ch === 'E') && !$seenE) {
                    $seenE = true; $j++;
                    if ($j < $n && ($src[$j] === '+' || $src[$j] === '-')) { $j++; }
                    continue;
                }
                break;
            }
            $tokens[] = ['t' => 'num', 'v' => substr($src, $i, $j - $i), 's' => $i, 'e' => $j];
            $i = $j;
            continue;
        }
        if (ctype_alpha($c) || $c === '_') {
            $j = $i + 1;
            while ($j < $n && (ctype_alnum($src[$j]) || $src[$j] === '_')) { $j++; }
            $tokens[] = ['t' => 'id', 'v' => substr($src, $i, $j - $i), 's' => $i, 'e' => $j];
            $i = $j;
            continue;
        }
        $two = substr($src, $i, 2);
        if (in_array($two, ['==', '~=', '<=', '>=', '..', '::'], true)) {
            $tokens[] = ['t' => 'p', 'v' => $two, 's' => $i, 'e' => $i + 2];
            $i += 2;
            continue;
        }
        $tokens[] = ['t' => 'p', 'v' => $c, 's' => $i, 'e' => $i + 1];
        $i++;
    }
    return $tokens;
}

/** 受管理的頂層設定名稱（其餘如函式區域變數一律忽略） */
function gameserver_reward_known_bases(): array
{
    return [
        'RATIO_MAX_COUNT', 'NO_ITEM_COUNT_1', 'ITEM_COUNT', 'NO_ITEM_COUNT_2',
        'REWARD_TYPE_NULL', 'REWARD_TYPE_ESCORT', 'REWARD_TYPE_ROB_ESCORT', 'REWARD_TYPE_FACTION_WORK',
        'REWARD_TYPE_FACTION_ACTIVITY', 'REWARD_TYPE_ONLINE_REWARD', 'REWARD_TYPE_FACTION_ACTIVITY_LIMIT',
        'FACTION_CITY_COUNT', 'FACTION_MAIN_CITY_SECURITY', 'FACTION_GANGSTER_INN_LEVEL',
        'EscortEvent.Events', 'EscortEvent.Config',
        'EscortMeet', 'PartTimeEscort', 'NationEscortReward', 'KillAllReward',
        'OnlineRewardActivity', 'OnlineReward', 'LevelUpReward', 'AchieveReward',
        'FreshmanReward', 'SkillReward',
        'PlayerLevelRatio', 'DistanceRatio', 'SubFactionRatio', 'GangsterLevelRatio',
        'DartCarLevelRatio', 'InstanceScoreRatio',
    ];
}

/** 極簡 Lua 設定語法解析器（僅解析已知頂層指派，略過函式） */
class GameserverLuaParser
{
    private $tokens;
    private $n;
    private $pos = 0;
    private $src;

    public function __construct(string $src)
    {
        $this->src = $src;
        $this->tokens = gameserver_reward_lex($src);
        $this->n = count($this->tokens);
    }

    private function skipWs(): void
    {
        while ($this->pos < $this->n && ($this->tokens[$this->pos]['t'] === 'ws' || $this->tokens[$this->pos]['t'] === 'cmt')) {
            $this->pos++;
        }
    }

    private function cur()
    {
        $this->skipWs();
        return $this->pos < $this->n ? $this->tokens[$this->pos] : null;
    }

    private function at(string $v): bool
    {
        $t = $this->cur();
        return $t !== null && ($t['t'] === 'p' || $t['t'] === 'id') && $t['v'] === $v;
    }

    /** 解析所有已知的頂層指派 */
    public function parseAll(array $knownBases): array
    {
        $entries = [];
        while ($this->pos < $this->n) {
            $t = $this->cur();
            if ($t === null) {
                break;
            }
            if ($t['t'] !== 'id') {
                $this->pos++;
                continue;
            }
            $root = $t['v'];
            $save = $this->pos;
            $this->pos++;
            $path = $root;
            while (true) {
                $c = $this->cur();
                if ($c === null) { break; }
                if ($c['t'] === 'p' && $c['v'] === '.') {
                    $this->pos++;
                    $c2 = $this->cur();
                    if ($c2 !== null && $c2['t'] === 'id') { $root .= '.' . $c2['v']; $path = $root; $this->pos++; continue; }
                    break;
                }
                if ($c['t'] === 'p' && $c['v'] === '[') {
                    $this->pos++;
                    $key = $this->parseKeyExpr();
                    if ($this->at(']')) { $this->pos++; }
                    $path .= '[' . $key . ']';
                    continue;
                }
                break;
            }
            if ($this->at('=')) {
                $this->pos++;
                if (in_array($root, $knownBases, true)) {
                    $entries[] = (object)['base' => $root, 'path' => $path, 'node' => $this->parseValue()];
                } else {
                    $this->parseValue();
                }
                continue;
            }
            $this->pos = $save + 1;
        }
        return $entries;
    }

    /** 取得一組 [] 內索引的原始字串（去除空白與註解） */
    private function parseKeyExpr(): string
    {
        $parts = [];
        $depth = 0;
        while ($this->pos < $this->n) {
            $t = $this->tokens[$this->pos];
            if ($t['t'] === 'p' && ($t['v'] === '[' || $t['v'] === '{')) { $depth++; }
            elseif ($t['t'] === 'p' && ($t['v'] === ']' || $t['v'] === '}')) {
                if ($depth === 0) { break; }
                $depth--;
            }
            if ($t['t'] !== 'ws' && $t['t'] !== 'cmt') { $parts[] = $t['v']; }
            $this->pos++;
        }
        return implode('', $parts);
    }

    private function parseValue()
    {
        $t = $this->cur();
        if ($t === null) {
            return (object)['kind' => 'ref', 'value' => ''];
        }
        if ($t['t'] === 'p' && $t['v'] === '{') {
            return $this->parseTable();
        }
        if ($t['t'] === 'num') {
            $this->pos++;
            return (object)['kind' => 'scalar', 'vtype' => 'number', 'value' => $t['v'], 'start' => $t['s'], 'end' => $t['e']];
        }
        if ($t['t'] === 'str') {
            $this->pos++;
            return (object)['kind' => 'scalar', 'vtype' => 'string', 'value' => $t['v'], 'start' => $t['s'], 'end' => $t['e']];
        }
        if ($t['t'] === 'id') {
            if ($t['v'] === 'true' || $t['v'] === 'false') {
                $this->pos++;
                return (object)['kind' => 'scalar', 'vtype' => 'bool', 'value' => $t['v'], 'start' => $t['s'], 'end' => $t['e']];
            }
            if ($t['v'] === 'function') {
                $this->skipFunction();
                return (object)['kind' => 'ref', 'value' => 'function'];
            }
            $start = $t['s'];
            $end = $t['e'];
            $this->pos++;
            while (true) {
                $c = $this->cur();
                if ($c === null) { break; }
                if ($c['t'] === 'p' && $c['v'] === '.') {
                    $this->pos++;
                    $c2 = $this->cur();
                    if ($c2 !== null && $c2['t'] === 'id') { $end = $c2['e']; $this->pos++; continue; }
                    break;
                }
                if ($c['t'] === 'p' && $c['v'] === '[') {
                    $this->pos++;
                    $this->parseKeyExpr();
                    if ($this->at(']')) { $this->pos++; $end = $this->tokens[$this->pos - 1]['e']; }
                    continue;
                }
                break;
            }
            return (object)['kind' => 'ref', 'value' => substr($this->src, $start, $end - $start), 'start' => $start, 'end' => $end];
        }
        if ($t['t'] === 'p' && $t['v'] === '-') {
            $this->pos++;
            $num = $this->cur();
            if ($num !== null && $num['t'] === 'num') {
                $this->pos++;
                return (object)['kind' => 'scalar', 'vtype' => 'number', 'value' => '-' . $num['v'], 'start' => $t['s'], 'end' => $num['e']];
            }
        }
        $this->pos++;
        return (object)['kind' => 'ref', 'value' => (string)($t['v'] ?? ''), 'start' => $t['s'] ?? null, 'end' => $t['e'] ?? null];
    }

    private function parseTable()
    {
        $open = $this->cur();
        $this->pos++;
        $node = (object)['kind' => 'table', 'start' => $open['s'], 'end' => $open['e'], 'style' => 'empty', 'fields' => []];
        while (true) {
            $t = $this->cur();
            if ($t === null) {
                break;
            }
            if ($t['t'] === 'p' && $t['v'] === '}') {
                $node->end = $t['e'];
                $this->pos++;
                break;
            }
            if ($t['t'] === 'p' && ($t['v'] === ',' || $t['v'] === ';')) {
                $this->pos++;
                continue;
            }
            if ($t['t'] === 'p' && $t['v'] === '[') {
                $keyStart = $t['s'];
                $this->pos++;
                $key = $this->parseKeyExpr();
                if ($this->at(']')) { $this->pos++; }
                if ($this->at('=')) { $this->pos++; }
                $val = $this->parseValue();
                $node->fields[] = (object)['kind' => 'index', 'key' => $key, 'keyStart' => $keyStart, 'value' => $val];
                if ($node->style === 'empty') { $node->style = 'index'; }
                continue;
            }
            if ($t['t'] === 'id') {
                $save = $this->pos;
                $name = $t['v'];
                $this->pos++;
                if ($this->at('=')) {
                    $this->pos++;
                    $val = $this->parseValue();
                    $node->fields[] = (object)['kind' => 'name', 'key' => $name, 'keyStart' => $t['s'], 'value' => $val];
                    if ($node->style === 'empty') { $node->style = 'name'; }
                    continue;
                }
                $this->pos = $save;
            }
            $val = $this->parseValue();
            if ($val !== null) {
                $node->fields[] = (object)['kind' => 'pos', 'key' => null, 'value' => $val];
                if ($node->style === 'empty') { $node->style = 'pos'; }
            } else {
                $this->pos++;
            }
        }
        return $node;
    }

    private function skipFunction(): void
    {
        $depth = 0;
        while ($this->pos < $this->n) {
            $t = $this->tokens[$this->pos];
            if ($t['t'] === 'id') {
                if (in_array($t['v'], ['function', 'if', 'for', 'while'], true)) { $depth++; }
                elseif ($t['v'] === 'end') {
                    $depth--;
                    if ($depth <= 0) { $this->pos++; return; }
                }
            }
            $this->pos++;
        }
    }
}

/**
 * 解析檔案：回傳 entries（頂層指派）與 nodes（依序展開的純量節點，附 id）。
 * @return array { entries, nodes, eol }
 */
function gameserver_reward_parse($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $parser = new GameserverLuaParser((string)$content);
    $entries = $parser->parseAll(gameserver_reward_known_bases());
    $nodes = [];
    $counter = 0;
    $walk = function ($node) use (&$walk, &$nodes, &$counter) {
        if ($node === null) { return; }
        if ($node->kind === 'scalar') {
            $node->id = $counter;
            $nodes[$counter] = $node;
            $counter++;
            return;
        }
        if ($node->kind === 'table') {
            foreach ($node->fields as $f) {
                $walk($f->value);
            }
        }
    };
    foreach ($entries as $e) {
        $walk($e->node);
    }
    return ['entries' => $entries, 'nodes' => $nodes, 'eol' => $eol];
}

/**
 * 依節點 id 就地套用新值，回傳新內容。
 * @param array $edits id => 新值（字串）
 */
function gameserver_reward_apply_content($content, array $edits): string
{
    $parsed = gameserver_reward_parse($content);
    $nodes = $parsed['nodes'];
    $repls = [];
    foreach ($edits as $id => $new) {
        $id = (int)$id;
        if (!isset($nodes[$id])) { continue; }
        $node = $nodes[$id];
        $new = trim((string)$new);
        if ($node->vtype === 'number') {
            if (!preg_match('/^-?(\d+(\.\d+)?|\.\d+)$/', $new)) { continue; }
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
    usort($repls, function ($a, $b) { return $b[0] <=> $a[0]; });
    foreach ($repls as $r) {
        $content = substr($content, 0, $r[0]) . $r[2] . substr($content, $r[1]);
    }
    return $content;
}

/** 套用結構化更新並寫回遠端 */
function gameserver_reward_apply($link, array $edits, $by = ''): array
{
    $read = gameserver_reward_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = gameserver_reward_apply_content($read['content'], $edits);
    return gameserver_reward_write($link, $content, $by, 'save_reward');
}

/* ============================================================
 *  五、參數說明（中文 + 英文原文）
 * ============================================================ */
/** 依鍵名取得中文標籤 / 英文原文 / 說明 */
function gameserver_reward_field_doc(string $key): array
{
    $map = [
        'odds'          => ['機率', 'odds', '觸發機率（0~1）。'],
        'max_times'     => ['最大次數', 'max_times', '單趟最多觸發次數。'],
        'id'            => ['事件/物品 ID', 'id', '觸發或發放的物件編號。'],
        'prob'          => ['權重機率', 'prob', '同組清單中的相對權重（會正規化）。'],
        'trap'          => ['陷阱', 'trap', '陷阱事件設定。'],
        'adventure'     => ['奇遇', 'adventure', '奇遇事件設定。'],
        'poison'        => ['毒藥', 'poison', '補給站投毒事件設定。'],
        'list'          => ['清單', 'list', '可選項目清單。'],
        'prob2'         => ['機率', 'prob', '機率。'],
        'speak'         => ['台詞', 'speak', '玩家 / 成功 / 失敗台詞 ID。'],
        'delay'         => ['延遲', 'delay', 'NPC 回話與鏢車延遲秒數。'],
        'time'          => ['間隔秒數', 'time', '在線累積時間（秒）。'],
        'reward'        => ['獎勵清單', 'reward', '各等級對應獎勵。'],
        'level'         => ['等級上限', 'level', '小於等於此等級可領取。'],
        'reward_id'     => ['獎勵 ID', 'reward_id', '發放的獎勵編號。'],
        'rank'          => ['名次', 'rank', '小於等於此名次可獲得。'],
        'reward_tid'    => ['獎勵模板 ID', 'reward_tid', '獎勵模板編號。'],
        'index'         => ['序號', 'index', '獎勵序號。'],
        'activity_id'   => ['活動 ID', 'activity_id', '成就對應活動編號。'],
        'limit_id'      => ['限制 ID', 'limit_id', '成就限制編號。'],

        // 獎勵類型代碼（與程式 GRANT_REWARD_TYPE 對應；數值須一致）
        'REWARD_TYPE_NULL'                   => ['無',           'REWARD_TYPE_NULL',                   '未指定獎勵類型（空值），不發放任何獎勵。'],
        'REWARD_TYPE_ESCORT'                 => ['護送',         'REWARD_TYPE_ESCORT',                 '完成鏢車護送任務所發放的獎勵。'],
        'REWARD_TYPE_ROB_ESCORT'             => ['搶劫鏢車',     'REWARD_TYPE_ROB_ESCORT',             '成功搶劫他人鏢車所發放的獎勵。'],
        'REWARD_TYPE_FACTION_WORK'           => ['幫派打工',     'REWARD_TYPE_FACTION_WORK',           '完成幫派打工任務所發放的獎勵。'],
        'REWARD_TYPE_FACTION_ACTIVITY'       => ['幫派活動',     'REWARD_TYPE_FACTION_ACTIVITY',       '參與幫派活動所發放的獎勵。'],
        'REWARD_TYPE_ONLINE_REWARD'          => ['在線獎勵',     'REWARD_TYPE_ONLINE_REWARD',          '在線累積時間達標所發放的獎勵。'],
        'REWARD_TYPE_FACTION_ACTIVITY_LIMIT' => ['幫派限次活動', 'REWARD_TYPE_FACTION_ACTIVITY_LIMIT', '有次數限制的幫派活動所發放的獎勵。'],

        // 全域常數
        'RATIO_MAX_COUNT' => ['最大獎勵項數', 'RATIO_MAX_COUNT', '獎勵倍率回傳的最大項目數（對應程式 GRANT_REWARD_MASK_VALID_COUNT）。'],
        'NO_ITEM_COUNT_1' => ['無物品獎勵數（1）', 'NO_ITEM_COUNT_1', '第一組「無物品」獎勵的項目數量。'],
        'ITEM_COUNT'      => ['物品獎勵數', 'ITEM_COUNT', '含物品獎勵的項目數量。'],
        'NO_ITEM_COUNT_2' => ['無物品獎勵數（2）', 'NO_ITEM_COUNT_2', '第二組「無物品」獎勵的項目數量。'],

        // 幫派參數索引
        'FACTION_CITY_COUNT'         => ['分舵數量',     'FACTION_CITY_COUNT',         'GetFactionParam 的分舵數量索引。'],
        'FACTION_MAIN_CITY_SECURITY' => ['總舵治安值',   'FACTION_MAIN_CITY_SECURITY', 'GetFactionParam 的總舵治安值索引。'],
        'FACTION_GANGSTER_INN_LEVEL' => ['幫派黑店等級', 'FACTION_GANGSTER_INN_LEVEL', 'GetFactionParam 的幫派黑店等級索引。'],
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    return [$key, $key, ''];
}

/** 位置型清單的中文標籤（依表名與索引） */
function gameserver_reward_pos_doc(string $base, int $index): array
{
    $map = [
        'PlayerLevelRatio' => [
            0 => ['經驗倍率', 'exp_ratio'],
            1 => ['金錢倍率', 'money_ratio'],
        ],
        'SkillReward' => [
            0 => ['金錢類型', 'money_type'],
            1 => ['最小金額', 'min_money'],
            2 => ['最大金額', 'max_money'],
            3 => ['機率', 'odds'],
        ],
        'PartTimeEscort' => [
            0 => ['起點地圖', 'src'],
            1 => ['終點地圖', 'dst'],
            2 => ['鏢車模板', 'tids'],
        ],
        'NationEscortReward' => [
            0 => ['高級獎勵', 'high_reward'],
            1 => ['普通獎勵', 'low_reward'],
            2 => ['被劫獎勵', 'rob_reward'],
            3 => ['聲望', 'reputation'],
        ],
    ];
    if (isset($map[$base][$index])) {
        return $map[$base][$index];
    }
    return ['數值 ' . $index, 'value_' . $index];
}

/** 區段定義（順序即畫面順序） */
function gameserver_reward_sections(): array
{
    return [
        ['key' => 'const',  'label' => '全域常數',     'icon' => 'fa-solid fa-sliders',        'desc' => '獎勵系統的全域上限與數量常數。', 'bases' => ['RATIO_MAX_COUNT', 'NO_ITEM_COUNT_1', 'ITEM_COUNT', 'NO_ITEM_COUNT_2']],
        ['key' => 'rtype',  'label' => '獎勵類型代碼', 'icon' => 'fa-solid fa-tags',           'desc' => '獎勵類型列舉（須與程式 GRANT_REWARD_TYPE 對應）。', 'bases' => ['REWARD_TYPE_NULL', 'REWARD_TYPE_ESCORT', 'REWARD_TYPE_ROB_ESCORT', 'REWARD_TYPE_FACTION_WORK', 'REWARD_TYPE_FACTION_ACTIVITY', 'REWARD_TYPE_ONLINE_REWARD', 'REWARD_TYPE_FACTION_ACTIVITY_LIMIT']],
        ['key' => 'faction', 'label' => '幫派參數索引', 'icon' => 'fa-solid fa-flag',          'desc' => 'GetFactionParam(index) 的索引定義。', 'bases' => ['FACTION_CITY_COUNT', 'FACTION_MAIN_CITY_SECURITY', 'FACTION_GANGSTER_INN_LEVEL']],
        ['key' => 'events', 'label' => '護送事件',     'icon' => 'fa-solid fa-triangle-exclamation', 'desc' => '各路線的陷阱 / 奇遇 / 毒藥事件與觸發機率。', 'bases' => ['EscortEvent.Events']],
        ['key' => 'eventcfg', 'label' => '鏢車事件對應', 'icon' => 'fa-solid fa-link',         'desc' => '鏢車模板 ID 對應到的事件組編號。', 'bases' => ['EscortEvent.Config']],
        ['key' => 'meet',   'label' => '護送遭遇',     'icon' => 'fa-solid fa-comments',       'desc' => '遭遇事件的機率、台詞與延遲。', 'bases' => ['EscortMeet']],
        ['key' => 'parttime', 'label' => '兼職護送',   'icon' => 'fa-solid fa-clock',          'desc' => '兼職護送的起點 / 終點與鏢車模板。', 'bases' => ['PartTimeEscort']],
        ['key' => 'nation', 'label' => '國運護送獎勵', 'icon' => 'fa-solid fa-crown',          'desc' => '各 NPC 的高級 / 普通 / 被劫獎勵與聲望。', 'bases' => ['NationEscortReward']],
        ['key' => 'killall', 'label' => '全滅獎勵',    'icon' => 'fa-solid fa-skull',          'desc' => '依名次發放的全滅獎勵。', 'bases' => ['KillAllReward']],
        ['key' => 'online', 'label' => '在線獎勵',     'icon' => 'fa-solid fa-hourglass-half', 'desc' => '在線累積時間獎勵與活動編號。', 'bases' => ['OnlineRewardActivity', 'OnlineReward']],
        ['key' => 'levelup', 'label' => '升級獎勵',    'icon' => 'fa-solid fa-arrow-up',       'desc' => '達到指定等級發放的獎勵。', 'bases' => ['LevelUpReward']],
        ['key' => 'achieve', 'label' => '成就獎勵',    'icon' => 'fa-solid fa-trophy',         'desc' => '成就對應的活動 / 限制 / 獎勵。', 'bases' => ['AchieveReward']],
        ['key' => 'freshman', 'label' => '新手獎勵',   'icon' => 'fa-solid fa-gift',           'desc' => '新手序號對應的獎勵模板。', 'bases' => ['FreshmanReward']],
        ['key' => 'skill',  'label' => '技能獎勵',     'icon' => 'fa-solid fa-coins',          'desc' => '技能獎勵的金錢類型、區間與機率。', 'bases' => ['SkillReward']],
        ['key' => 'ratio',  'label' => '等級係數',     'icon' => 'fa-solid fa-chart-line',     'desc' => '玩家等級對應的經驗 / 金錢倍率。', 'bases' => ['PlayerLevelRatio']],
        ['key' => 'dist',   'label' => '距離係數',     'icon' => 'fa-solid fa-route',          'desc' => '鏢車途經城市數對應係數。', 'bases' => ['DistanceRatio']],
        ['key' => 'subfac', 'label' => '分舵係數',     'icon' => 'fa-solid fa-sitemap',        'desc' => '分舵數量對應係數。', 'bases' => ['SubFactionRatio']],
        ['key' => 'gang',   'label' => '客棧等級係數', 'icon' => 'fa-solid fa-hotel',          'desc' => '幫派黑店等級對應係數。', 'bases' => ['GangsterLevelRatio']],
        ['key' => 'dart',   'label' => '鏢車品質係數', 'icon' => 'fa-solid fa-truck',          'desc' => '鏢車貨物品質對應係數。', 'bases' => ['DartCarLevelRatio']],
        ['key' => 'inst',   'label' => '副本分值係數', 'icon' => 'fa-solid fa-dungeon',        'desc' => '副本分值對應係數。', 'bases' => ['InstanceScoreRatio']],
    ];
}

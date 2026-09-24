<?php
/**
 * =============================================================
 *  踏雪笑傲 · 副本評分設定工具庫（game_server_ectype_lib.php）
 *  -------------------------------------------------------------
 *  專用於遠端 ectype_score.lua（gamed/config/script/）：
 *    - 設定遠端檔案路徑（資料表 game_server_ectype_config）
 *    - 透過 SSH/SFTP 讀取與寫回，內容快取與初始快照（供「還原初始」）
 *    - 結構化解析／套用：
 *        EctypeScore.TeamExpNormal    組隊經驗加成（1~6 人）
 *        EctypeScore.GradeExpNormal   評價等級經驗加成（B/A/S/SS）
 *        EctypeScore.Cfg[副本ID][模式] 副本評分設定（評價條件／獎勵）
 *        SpecialUnit                  特殊單位（時間顯示等）
 *    - 自製 Lua 詞法／語法解析器：可正確辨識長註解 --[[ ... ]]（本檔大量
 *      使用 --[[!AUTO_n]] 標記），並記錄純量節點的位元組位移，就地替換、
 *      保留其餘註解與縮排。
 *    - 中文說明 + 英文原文（gameserver_ectype_doc）
 *    - 寫入操作稽核（沿用 admin/remote_lib.php）
 *
 *  目標伺服器沿用 game_server_config.server_id（與「遊戲版本設定」共用）。
 *  資料表：game_server_ectype_config（見 game_server_script_schema.sql）
 *  依賴：game_server_script_lib.php（編碼／目標伺服器）、admin/remote_lib.php
 * =============================================================
 */

if (defined('GAME_SERVER_ECTYPE_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_ECTYPE_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_script_lib.php';

if (!defined('GAME_SERVER_ECTYPE_TABLE')) {
    define('GAME_SERVER_ECTYPE_TABLE', 'game_server_ectype_config');
}
if (!defined('GAME_SERVER_ECTYPE_DEFAULT_PATH')) {
    define('GAME_SERVER_ECTYPE_DEFAULT_PATH', '/root/xa274/gamed/config/script/ectype_score.lua');
}

/* ============================================================
 *  一、資料表與設定存取（與生產合成／獎勵設定同構）
 * ============================================================ */
/** 建立資料表並補上預設列（可重複執行、非破壞性） */
function gameserver_ectype_ensure($link): void
{
    if (!$link) {
        return;
    }
    $t = GAME_SERVER_ECTYPE_TABLE;
    try {
        $link->query("CREATE TABLE IF NOT EXISTS `$t` (
            `id` tinyint(1) NOT NULL DEFAULT 1,
            `path` varchar(255) NOT NULL DEFAULT '" . GAME_SERVER_ECTYPE_DEFAULT_PATH . "',
            `raw_cache` mediumtext NULL,
            `initial_raw` mediumtext NULL,
            `updated_by` varchar(50) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $link->query("INSERT IGNORE INTO `$t` (`id`,`path`) VALUES (1,'" . GAME_SERVER_ECTYPE_DEFAULT_PATH . "')");
    } catch (Throwable $e) {
        // 權限不足時略過
    }
}

/** 讀取設定列（資料表不存在時回傳預設值） */
function gameserver_ectype_config_get($link): array
{
    $default = [
        'id'          => 1,
        'path'        => GAME_SERVER_ECTYPE_DEFAULT_PATH,
        'raw_cache'   => '',
        'initial_raw' => '',
        'updated_by'  => '',
        'updated_at'  => '',
    ];
    if (!$link) {
        return $default;
    }
    try {
        $res = @$link->query("SELECT * FROM `" . GAME_SERVER_ECTYPE_TABLE . "` WHERE `id` = 1 LIMIT 1");
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
function gameserver_ectype_set_path($link, $path, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $path = trim((string)$path);
    if ($path === '') {
        $path = GAME_SERVER_ECTYPE_DEFAULT_PATH;
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    try {
        $stmt = $link->prepare("INSERT INTO `" . GAME_SERVER_ECTYPE_TABLE . "` (`id`,`path`,`updated_by`)
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
function gameserver_ectype_cache($link, $content, $by = ''): bool
{
    if (!$link) {
        return false;
    }
    $cfg = gameserver_ectype_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        $initial = (string)$content;
    }
    $enc  = gameserver_encode_raw($content);
    $ienc = gameserver_encode_raw($initial);
    try {
        $stmt = $link->prepare("UPDATE `" . GAME_SERVER_ECTYPE_TABLE . "`
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
function gameserver_ectype_is_utf8($s): bool { return gameserver_produce_is_utf8($s); }
function gameserver_ectype_to_utf8($s): string { return gameserver_produce_to_utf8($s); }
function gameserver_ectype_from_utf8($s): string { return gameserver_produce_from_utf8($s); }

/* ============================================================
 *  三、遠端讀寫（SSH / SFTP）
 * ============================================================ */
/** 讀取遠端 ectype_score.lua */
function gameserver_ectype_read($link): array
{
    $cfg = gameserver_ectype_config_get($link);
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
function gameserver_ectype_write($link, $content, $by = '', $action = 'save_ectype'): array
{
    $cfg = gameserver_ectype_config_get($link);
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
        $cached = gameserver_ectype_cache($link, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, $action, $path, '');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

/* ============================================================
 *  四、高階操作
 * ============================================================ */
/** 讀取遠端並更新快取 */
function gameserver_ectype_refresh($link, $by = ''): array
{
    $read = gameserver_ectype_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    if (!gameserver_ectype_cache($link, $read['content'], $by)) {
        return ['ok' => false, 'error' => '讀取成功，但本機快取寫入失敗（請檢查資料庫）。'];
    }
    if (!empty($read['server']) && function_exists('remote_audit')) {
        remote_audit($link, $read['server'], 'refresh_ectype', $read['path'], '');
    }
    return ['ok' => true, 'error' => ''];
}

/** 還原初始內容快照 */
function gameserver_ectype_restore($link, $by = ''): array
{
    $cfg = gameserver_ectype_config_get($link);
    $initial = (string)($cfg['initial_raw'] ?? '');
    if ($initial === '') {
        return ['ok' => false, 'error' => '尚無初始快照，請先執行「讀取最新」。'];
    }
    return gameserver_ectype_write($link, $initial, $by, 'restore_ectype');
}

/* ============================================================
 *  五、Lua 詞法分析（修正長註解 --[[ ... ]] / 長字串 [[ ... ]]）
 * ============================================================ */
/**
 * 將來源切成 token 串（含位元組位移），token 類型：
 *   ws 空白 / cmt 註解 / str 字串 / num 數字 / id 識別字 / p 標點
 * 註：ectype_score.lua 使用 --[[!AUTO_n]] 長註解緊鄰字串常值，
 *     故必須正確辨識長註解，否則會把後續數值一併吞掉。
 */
function gameserver_ectype_lex(string $src): array
{
    $tokens = [];
    $n = strlen($src);
    $i = 0;
    while ($i < $n) {
        $c = $src[$i];

        // 空白
        if (ctype_space($c)) {
            $j = $i + 1;
            while ($j < $n && ctype_space($src[$j])) { $j++; }
            $tokens[] = ['t' => 'ws', 's' => $i, 'e' => $j];
            $i = $j;
            continue;
        }

        // 註解：--[[ ... ]] / --[==[ ... ]==] 或行註解 -- ...
        if ($c === '-' && $i + 1 < $n && $src[$i + 1] === '-') {
            if ($i + 2 < $n && $src[$i + 2] === '[') {
                $j = $i + 3;
                $eq = 0;
                while ($j < $n && $src[$j] === '=') { $eq++; $j++; }
                if ($j < $n && $src[$j] === '[') {
                    $close = ']' . str_repeat('=', $eq) . ']';
                    $end = strpos($src, $close, $j + 1);
                    $e = ($end === false) ? $n : $end + strlen($close);
                    $tokens[] = ['t' => 'cmt', 's' => $i, 'e' => $e];
                    $i = $e;
                    continue;
                }
            }
            $j = $i + 2;
            while ($j < $n && $src[$j] !== "\n" && $src[$j] !== "\r") { $j++; }
            $tokens[] = ['t' => 'cmt', 's' => $i, 'e' => $j];
            $i = $j;
            continue;
        }

        // 長字串 [[ ... ]] / [=[ ... ]=]
        if ($c === '[') {
            $j = $i + 1;
            $eq = 0;
            while ($j < $n && $src[$j] === '=') { $eq++; $j++; }
            if ($j < $n && $src[$j] === '[') {
                $close = ']' . str_repeat('=', $eq) . ']';
                $end = strpos($src, $close, $j + 1);
                $e = ($end === false) ? $n : $end + strlen($close);
                $tokens[] = ['t' => 'str', 'v' => substr($src, $i, $e - $i), 's' => $i, 'e' => $e];
                $i = $e;
                continue;
            }
        }

        // 引號字串
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

        // 數字（含小數與指數）
        if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($src[$i + 1]))) {
            $j = $i;
            $seenE = false;
            while ($j < $n) {
                $ch = $src[$j];
                if (ctype_digit($ch) || $ch === '.') { $j++; continue; }
                if (($ch === 'e' || $ch === 'E') && !$seenE) {
                    $seenE = true;
                    $j++;
                    if ($j < $n && ($src[$j] === '+' || $src[$j] === '-')) { $j++; }
                    continue;
                }
                break;
            }
            $tokens[] = ['t' => 'num', 'v' => substr($src, $i, $j - $i), 's' => $i, 'e' => $j];
            $i = $j;
            continue;
        }

        // 識別字
        if (ctype_alpha($c) || $c === '_') {
            $j = $i + 1;
            while ($j < $n && (ctype_alnum($src[$j]) || $src[$j] === '_')) { $j++; }
            $tokens[] = ['t' => 'id', 'v' => substr($src, $i, $j - $i), 's' => $i, 'e' => $j];
            $i = $j;
            continue;
        }

        // 標點（含雙字元運算子）
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

/**
 * 受管理的頂層設定名稱。
 * 只有這些根路徑的指派會被解析為可編輯節點，其餘（函式等）一律略過。
 */
function gameserver_ectype_known_bases(): array
{
    return [
        'EctypeScore.TeamExpNormal',
        'EctypeScore.GradeExpNormal',
        'EctypeScore.Cfg',
        'SpecialUnit',
    ];
}

/** 極簡 Lua 設定語法解析器（只解析已知頂層指派，略過函式） */
class EctypeLuaParser
{
    private $tokens;
    private $n;
    private $pos = 0;
    private $src;

    public function __construct(string $src)
    {
        $this->src = $src;
        $this->tokens = gameserver_ectype_lex($src);
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

    /** 解析所有已知的頂層指派，回傳 [{base, path, node}] */
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
function gameserver_ectype_parse_raw($content): array
{
    $content = gameserver_strip_bom($content);
    $eol = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $parser = new EctypeLuaParser((string)$content);
    $entries = $parser->parseAll(gameserver_ectype_known_bases());
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

/* ============================================================
 *  六、節點輔助
 * ============================================================ */
/** 由具名欄位取得值節點（找不到回傳 null） */
function gameserver_ectype_field($table, string $key)
{
    if (!$table || $table->kind !== 'table') {
        return null;
    }
    foreach ($table->fields as $f) {
        if ($f->kind === 'name' && $f->key === $key) {
            return $f->value;
        }
    }
    return null;
}

/** 由位置索引取得值節點（找不到回傳 null） */
function gameserver_ectype_at($table, int $index)
{
    if (!$table || $table->kind !== 'table') {
        return null;
    }
    $i = 0;
    foreach ($table->fields as $f) {
        if ($f->kind === 'pos') {
            if ($i === $index) { return $f->value; }
            $i++;
        }
    }
    return null;
}

/** 是否為參考（如 EctypeScore.TeamExpNormal / SpecialUnit.TIME） */
function gameserver_ectype_is_ref($node): bool
{
    return $node !== null && $node->kind === 'ref';
}

/** 純量節點的顯示字串（字串去引號並轉 UTF-8；ref 回傳原文） */
function gameserver_ectype_display($node): string
{
    if ($node === null) {
        return '';
    }
    if ($node->kind === 'ref') {
        return (string)$node->value;
    }
    if ($node->kind === 'scalar') {
        if ($node->vtype === 'string') {
            $s = $node->value;
            if (strlen($s) >= 2) { $s = substr($s, 1, -1); }
            return gameserver_ectype_to_utf8($s);
        }
        return (string)$node->value;
    }
    return '';
}

/**
 * 解析檔案並輸出結構化模型（供後台渲染）。
 * @return array { globals, dungeons, nodes, entries, eol }
 */
function gameserver_ectype_model($content): array
{
    $p = gameserver_ectype_parse_raw($content);
    $byPath = [];
    foreach ($p['entries'] as $e) {
        $byPath[$e->path] = $e->node;
    }
    $names = gameserver_ectype_extract_names($content);

    $dungeons = [];
    foreach ($p['entries'] as $e) {
        if (preg_match('/^EctypeScore\.Cfg\[(\d+)\]$/', $e->path, $m)) {
            $id = $m[1];
            $modes = [];
            if ($e->node->kind === 'table') {
                foreach ($e->node->fields as $f) {
                    if ($f->kind !== 'index') { continue; }
                    $modes[$f->key] = gameserver_ectype_mode_model($f->value);
                }
            }
            $dungeons[] = [
                'id'    => $id,
                'name'  => $names[$id] ?? ('副本 #' . $id),
                'modes' => $modes,
            ];
        }
    }

    return [
        'globals'  => [
            'TeamExpNormal'  => $byPath['EctypeScore.TeamExpNormal'] ?? null,
            'GradeExpNormal' => $byPath['EctypeScore.GradeExpNormal'] ?? null,
            'SpecialUnit'    => $byPath['SpecialUnit'] ?? null,
        ],
        'dungeons' => $dungeons,
        'nodes'    => $p['nodes'],
        'entries'  => $p['entries'],
        'eol'      => $p['eol'],
    ];
}

/** 解析單一模式設定 */
function gameserver_ectype_mode_model($node): array
{
    $out = [
        'score_cond'       => [],
        'add_draw_money'   => null,
        'add_draw_lottery' => null,
        'team_exp'         => null,
        'award'            => [],
        'extra'            => [],
    ];
    if (!$node || $node->kind !== 'table') {
        return $out;
    }
    foreach ($node->fields as $f) {
        if ($f->kind !== 'name') {
            $out['extra'][] = ['key' => (string)($f->key ?? ''), 'node' => $f->value];
            continue;
        }
        switch ($f->key) {
            case 'ScoreCond':
                if ($f->value->kind === 'table') {
                    foreach ($f->value->fields as $g) {
                        if ($g->kind === 'pos') { $out['score_cond'][] = gameserver_ectype_cond_model($g->value); }
                    }
                }
                break;
            case 'AddDrawMoney':
                $out['add_draw_money'] = $f->value;
                break;
            case 'AddDrawLottery':
                $out['add_draw_lottery'] = $f->value;
                break;
            case 'TeamExp':
                $out['team_exp'] = $f->value;
                break;
            case 'Award':
                if ($f->value->kind === 'table') {
                    foreach ($f->value->fields as $g) {
                        if ($g->kind === 'pos') { $out['award'][] = $g->value; }
                    }
                }
                break;
            default:
                $out['extra'][] = ['key' => $f->key, 'node' => $f->value];
        }
    }
    return $out;
}

/** 解析單一評價條件 */
function gameserver_ectype_cond_model($node): array
{
    $out = ['desc' => null, 'unit' => null, 'arg' => null, 'baddish' => []];
    if (!$node || $node->kind !== 'table') {
        return $out;
    }
    foreach ($node->fields as $f) {
        if ($f->kind !== 'name') { continue; }
        if ($f->key === 'Desc')   { $out['desc'] = $f->value; }
        elseif ($f->key === 'Unit') { $out['unit'] = $f->value; }
        elseif ($f->key === 'Arg')  { $out['arg']  = $f->value; }
        elseif ($f->key === 'Baddish' && $f->value->kind === 'table') {
            foreach ($f->value->fields as $g) {
                if ($g->kind === 'pos') { $out['baddish'][] = $g->value; }
            }
        }
    }
    return $out;
}

/* ============================================================
 *  七、副本名稱擷取（沿用原始檔的中文註解）
 * ============================================================ */
/** 遮蔽長註解（--[[ ... ]]）內容但保留換行，避免誤判註解區塊內的名稱 */
function gameserver_ectype_strip_long_comments(string $src): string
{
    $tokens = gameserver_ectype_lex($src);
    $spans = [];
    foreach ($tokens as $t) {
        if ($t['t'] === 'cmt' && substr($src, $t['s'], 3) === '--[') {
            $spans[] = [$t['s'], $t['e']];
        }
    }
    usort($spans, function ($a, $b) { return $b[0] <=> $a[0]; });
    $out = $src;
    foreach ($spans as $sp) {
        $seg = substr($out, $sp[0], $sp[1] - $sp[0]);
        $seg = preg_replace('/[^\r\n]/', ' ', $seg);
        $out = substr($out, 0, $sp[0]) . $seg . substr($out, $sp[1]);
    }
    return $out;
}

/**
 * 由原始內容擷取「副本ID => 中文名稱」。
 * 規則：EctypeScore.Cfg[ID] 之前的最近一行中文註解（---名稱）。
 */
function gameserver_ectype_extract_names($content): array
{
    $content = gameserver_ectype_strip_long_comments(gameserver_strip_bom((string)$content));
    $names = [];
    $last = '';
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        $t = trim($line);
        if ($t === '') { continue; }
        if (preg_match('/^EctypeScore\.Cfg\[(\d+)\]/', $t, $m)) {
            if ($last !== '') { $names[$m[1]] = $last; }
            $last = '';
            continue;
        }
        if (substr($t, 0, 2) === '--') {
            $c = preg_replace('/^[\\-\\s]+/u', '', $t);
            $c = preg_replace('/[\\-\\s]+$/u', '', $c);
            if ($c !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $c)) {
                $last = $c;
            }
        }
    }
    return $names;
}

/* ============================================================
 *  八、套用更新（就地替換純量值）
 * ============================================================ */
/**
 * 依節點 id 就地套用新值，回傳新內容。
 * @param array $edits id => 新值（字串，UTF-8）
 */
function gameserver_ectype_apply_content($content, array $edits): string
{
    $parsed = gameserver_ectype_parse_raw($content);
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

/**
 * 套用結構化更新並寫回遠端。
 * @param array  $edits       id => 新值（字串，UTF-8）
 * @param string $expectedMd5 若提供，會先比對遠端內容的 md5；不一致即拒絕
 *                            （避免表單開啟後遠端已被他人修改，導致節點位移錯亂）。
 */
function gameserver_ectype_apply($link, array $edits, $by = '', $expectedMd5 = ''): array
{
    $read = gameserver_ectype_read($link);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $content = $read['content'];
    if ($expectedMd5 !== '' && md5($content) !== $expectedMd5) {
        return ['ok' => false, 'error' => '遠端內容已變更（可能已被重新讀取或他人修改），請重新整理後再試。'];
    }
    // 遠端檔多為 GBK/ANSI，若內容非 UTF-8，需把（表單送出的）UTF-8 值轉回 GBK。
    if (!gameserver_ectype_is_utf8($content)) {
        foreach ($edits as $k => $v) {
            $edits[$k] = gameserver_ectype_from_utf8($v);
        }
    }
    $newContent = gameserver_ectype_apply_content($content, $edits);
    return gameserver_ectype_write($link, $newContent, $by, 'save_ectype');
}

/* ============================================================
 *  九、參數說明（中文 + 英文原文）
 * ============================================================ */
/**
 * 欄位對照：鍵名 => [中文標籤, 英文原文, 說明]。
 */
function gameserver_ectype_field_doc(string $key): array
{
    $map = [
        // 檔案頂層
        'EctypeScore'       => ['副本評分總表',       'EctypeScore',       '本檔的主表，所有副本評分設定皆掛在其下。'],
        'Cfg'               => ['設定表',            'Cfg',               '以副本 ID 為索引的評分設定集合：EctypeScore.Cfg[副本ID][模式]。'],
        'TeamExpNormal'     => ['組隊經驗加成',      'TeamExpNormal',     '共用組隊經驗加成表，索引 1~6 分別對應 1~6 人組隊的加成比例。'],
        'GradeExpNormal'    => ['評價經驗加成',      'GradeExpNormal',    '共用評價等級經驗加成表，索引 1~4 分別對應 B／A／S／SS 四級。'],
        'SpecialUnit'       => ['特殊單位',          'SpecialUnit',       '特殊單位定義表；TIME 代表以「分:秒」形式顯示的單位。'],
        'TIME'              => ['時間單位',          'SpecialUnit.TIME',  '時間專用單位，介面以「%d分%d秒」格式顯示（非一般數值）。'],

        // 模式層級
        'ScoreCond'         => ['評價條件',          'ScoreCond',         '一個模式下的評價條件清單，逐項計算權重後加總決定評價等級。'],
        'AddDrawMoney'      => ['二次抽獎金錢',      'AddDrawMoney',      '第二次抽獎消耗的金錢；優先扣綁定幣，不足再以交易幣補齊。'],
        'AddDrawLottery'    => ['二次抽獎彩票',      'AddDrawLottery',    '第二次抽獎發放的彩票 ID。'],
        'TeamExp'           => ['組隊經驗加成',      'TeamExp',           '本副本的組隊經驗加成；多數直接引用共用表 EctypeScore.TeamExpNormal。'],
        'Award'             => ['評價獎勵',          'Award',             '依評價總權重區間發放的獎勵（彩票 ID 與經驗加成），對應 B／A／S／SS。'],

        // 評價條件 / 獎勵欄位
        'Desc'              => ['名稱',              'Desc',              '評價條件或等級的顯示名稱（同時用於介面說明文字）。'],
        'Unit'              => ['單位',              'Unit',              '評價條件的數值單位；時間類填 SpecialUnit.TIME。'],
        'Arg'               => ['場景參數編號',      'Arg',               '對應副本場景參數的編號，見「場景參數」對照。'],
        'Baddish'           => ['等級區間',          'Baddish',           '由上而下分別為 B／A／S／SS 四個等級的數值區間、名稱、圖示與權重。'],
        'Range'             => ['數值區間',          'Range',             '該等級適用的參數數值範圍 {下界, 上界}（含端點）。'],
        'Icon'              => ['圖示編號',          'Icon',              '該等級對應的圖示資源編號（新版介面可能不使用）。'],
        'Weight'            => ['權重',              'Weight',            '該等級條件的計分權重；各條件權重加總後決定最終評價。'],
        'Lottery'           => ['彩票 ID',           'Lottery',           '該評價區間發放的彩票物品編號。'],
        'Exp'               => ['經驗加成',          'Exp',               '該評價區間的經驗加成比例；多數引用 EctypeScore.GradeExpNormal[N]。'],

        // 取用函式
        'GetCfg'            => ['取得設定',          'EctypeScore:GetCfg(idEctype, mode)',       '依副本 ID 與模式取得評分設定，找不到時回傳 nil。'],
        'HaveNPCScore'      => ['是否有 NPC 評分',   'EctypeScore:HaveNPCScore(idEctype, mode)', '判斷該副本模式是否含有以場景參數 9（戰鬥分數）計分的條件。'],
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    return [$key, $key, ''];
}

/** 評價等級（Baddish／Award 由下而上）標籤 */
function gameserver_ectype_grade_labels(): array
{
    return ['B', 'A', 'S', 'SS'];
}

/**
 * 場景參數對照（Arg 值 => 意義）。
 * 前三個為檔案頂端註解所載，其餘為各副本實際使用之參數彙整。
 */
function gameserver_ectype_arg_doc(): array
{
    return [
        '8'   => ['玩家死亡次數', '副本內玩家死亡的累計次數。'],
        '9'   => ['戰鬥積分',     '副本戰鬥表現所得的積分。'],
        '11'  => ['副本進行時間', '副本通關所花費的時間（秒）。'],
        '200' => ['美酒得分',     '千秋美酒副本的美酒評分。'],
        '201' => ['護送得分／金塊數量', '千秋美酒為護送得分；守衛寶庫為金塊數量。'],
        '203' => ['被擊次數',     '追擊欽犯副本中玩家被擊中的次數。'],
        '205' => ['逃跑盜賊',     '守衛寶庫副本中逃跑的盜賊數量。'],
        '215' => ['第五輪擊殺',   '桃谷迷陣第五輪的擊殺個數。'],
        '521' => ['闖關輪數',     '桃谷迷陣完成的關卡輪數。'],
        '902' => ['存活鏢師',     '鏢局夜戰副本中存活鏢師的人數。'],
        '910' => ['顧客好評',     '縱橫街市副本的顧客好評分。'],
    ];
}

/**
 * 文件說明（供「參數說明」分頁）。
 * @return array 區段 => [title, subtitle, rows:[[中文, 英文, 說明]]]
 */
function gameserver_ectype_doc(): array
{
    return [
        'overview' => [
            'title'    => '檔案結構總覽',
            'subtitle' => 'ectype_score.lua',
            'desc'     => '副本評分檔定義「每個副本、每個模式」的評價條件與獎勵。檔案結構為 '
                . 'EctypeScore.Cfg[副本ID][模式] = { ScoreCond = { ... }, Award = { ... } }，'
                . '另含全域共用的組隊／評價經驗加成表與特殊單位定義。',
            'rows'     => [
                ['副本評分總表', 'EctypeScore', '本檔主表；於 EctypeScore 之下掛載 Cfg 設定與共用加成表。'],
                ['設定表索引',   'EctypeScore.Cfg[副本ID][模式]', '第一層索引為副本 ID，第二層索引為模式編號（多為 1~4）。'],
                ['組隊經驗加成', 'EctypeScore.TeamExpNormal', '長度 6 的陣列，索引 1~6 對應 1~6 人組隊的經驗加成比例。'],
                ['評價經驗加成', 'EctypeScore.GradeExpNormal', '長度 4 的陣列，索引 1~4 對應 B／A／S／SS 的經驗加成比例。'],
                ['特殊單位',     'SpecialUnit', '特殊單位定義；TIME = 1 表示以「分:秒」顯示的時間單位。'],
                ['取得設定',     'EctypeScore:GetCfg(idEctype, mode)', '依副本 ID 與模式取回設定（找不到回傳 nil）。'],
                ['是否含分數條件', 'EctypeScore:HaveNPCScore(idEctype, mode)', '檢查該模式是否含有 Arg == 9（戰鬥分數）的條件。'],
            ],
        ],
        'globals' => [
            'title'    => '共用加成表',
            'subtitle' => 'TeamExpNormal / GradeExpNormal',
            'desc'     => '這兩張表被多數副本以參考方式共用（TeamExp = EctypeScore.TeamExpNormal、'
                . 'Exp = EctypeScore.GradeExpNormal[N]）。修改共用表會影響所有引用它的副本，請謹慎。',
            'rows'     => [
                ['1 人組隊加成', 'TeamExpNormal[1]', '單人時的經驗加成比例。'],
                ['2 人組隊加成', 'TeamExpNormal[2]', '二人組隊時的經驗加成比例。'],
                ['3 人組隊加成', 'TeamExpNormal[3]', '三人組隊時的經驗加成比例。'],
                ['4 人組隊加成', 'TeamExpNormal[4]', '四人組隊時的經驗加成比例。'],
                ['5 人組隊加成', 'TeamExpNormal[5]', '五人組隊時的經驗加成比例。'],
                ['6 人組隊加成', 'TeamExpNormal[6]', '六人組隊時的經驗加成比例。'],
                ['B 級經驗加成', 'GradeExpNormal[1]', '評價 B 的經驗加成比例。'],
                ['A 級經驗加成', 'GradeExpNormal[2]', '評價 A 的經驗加成比例。'],
                ['S 級經驗加成', 'GradeExpNormal[3]', '評價 S 的經驗加成比例。'],
                ['SS 級經驗加成', 'GradeExpNormal[4]', '評價 SS 的經驗加成比例。'],
            ],
        ],
        'mode' => [
            'title'    => '模式欄位',
            'subtitle' => 'ScoreCond / TeamExp / Award …',
            'desc'     => '每個模式包含評價條件（ScoreCond）、二次抽獎設定與獎勵（Award）。',
            'rows'     => [
                ['評價條件清單', 'ScoreCond', '逐項描述評價條件（名稱、單位、場景參數與四級區間權重）。'],
                ['二次抽獎金錢', 'AddDrawMoney', '第二次抽獎消耗的金錢（優先綁定幣，不足以交易幣補齊）。'],
                ['二次抽獎彩票', 'AddDrawLottery', '第二次抽獎發放的彩票 ID。'],
                ['組隊經驗加成', 'TeamExp', '本副本的組隊經驗加成，通常引用共用表 TeamExpNormal。'],
                ['評價獎勵',     'Award', '依評價總權重區間發放的彩票與經驗加成。'],
            ],
        ],
        'cond' => [
            'title'    => '評價條件欄位',
            'subtitle' => 'Desc / Unit / Arg / Baddish',
            'desc'     => '每個條件會依「場景參數數值」落在哪個區間，對應取得權重；所有條件的權重加總後，'
                . '再對照 Award 的區間決定最終評價與獎勵。',
            'rows'     => [
                ['條件名稱',   'Desc',    '條件的顯示名稱。'],
                ['單位',       'Unit',    '數值單位；時間類使用 SpecialUnit.TIME。'],
                ['場景參數',   'Arg',     '對應的場景參數編號（見「場景參數對照」）。'],
                ['等級區間表', 'Baddish', 'B／A／S／SS 四級的區間、名稱、圖示與權重。'],
                ['數值區間',   'Range',   '{下界, 上界}，含端點。'],
                ['等級名稱',   'Desc',    '該等級的顯示名稱（如「醬油／高手／奇才／武神」）。'],
                ['圖示編號',   'Icon',    '該等級對應的圖示資源編號。'],
                ['權重',       'Weight',  '該等級條件的計分權重。'],
            ],
        ],
        'award' => [
            'title'    => '評價獎勵欄位',
            'subtitle' => 'Award → Range / Lottery / Exp',
            'desc'     => 'Award 依「所有條件權重加總」落在哪個區間，發放對應彩票與經驗加成。',
            'rows'     => [
                ['權重區間', 'Range',   '{下界, 上界}，對應所有條件權重之和。'],
                ['彩票 ID',  'Lottery', '該區間發放的彩票物品編號。'],
                ['經驗加成', 'Exp',     '該區間的經驗加成；多數引用 GradeExpNormal[N]。'],
            ],
        ],
    ];
}

/**
 * 場景參數對照文件列（供渲染成表格）。
 * @return array [[Arg, 中文, 英文, 說明]]
 */
function gameserver_ectype_arg_rows(): array
{
    $out = [];
    foreach (gameserver_ectype_arg_doc() as $arg => $info) {
        $out[] = [(string)$arg, $info[0], 'arg_' . $arg, $info[1]];
    }
    return $out;
}

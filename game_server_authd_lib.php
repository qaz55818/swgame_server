<?php
/**
 * =============================================================
 *  踏雪笑傲 · 帳號驗證資料庫結構設定工具庫（game_server_authd_lib.php）
 *  -------------------------------------------------------------
 *  管理遠端 authd/build/table.xml（帳號驗證服務 authd 的資料庫結構描述）。
 *
 *  檔案結構（Analyzed Structure）：
 *    <application debug="false">          應用程式根節點（資料庫結構總表）
 *      <driver name="..."/>               JDBC 驅動類別
 *      <connection .../>                  資料庫連線（連線池、帳密）
 *      <table name="..." ...>             資料表定義
 *        <column .../>                    欄位（SQL 型別 / Java 型別）
 *        <primarykey .../>                主鍵
 *        <index .../>                     索引
 *      </table>
 *      <query name="...">                 具名查詢
 *        <table .../> <column .../> <select .../>
 *      </query>
 *      <procedure name="...">             預存程序
 *        <parameter .../>                 參數（IN / OUT）
 *      </procedure>
 *    </application>
 *
 *  提供的功能：
 *    1. 以 DOMDocument 解析 XML（保留註解、縮排、屬性順序與 BOM/編碼宣告）。
 *    2. 針對每個元素與屬性提供「中文說明 + 英文原文」對照（element/attr meta）。
 *    3. 逐屬性編輯、新增子元素、刪除節點（僅改動必要區塊，其餘原樣保留）。
 *    4. 支援整份原始內容直接編輯（raw）並於寫入前驗證 XML 合法性。
 *    5. 透過既有檔案框架（game_server_files_lib.php）經 SSH/SFTP 讀取、寫回、
 *       快取與「還原初始版本」。
 *
 *  目標檔案代碼：authd_table（見 game_server_files_lib registry）
 *  依賴：game_server_files_lib.php（gameserver_file_read / cache / restore）
 * =============================================================
 */

if (defined('GAME_SERVER_AUTHD_LIB_LOADED')) {
    return;
}
define('GAME_SERVER_AUTHD_LIB_LOADED', 1);

require_once __DIR__ . '/game_server_files_lib.php';

if (!defined('GAME_SERVER_AUTHD_KEY')) {
    define('GAME_SERVER_AUTHD_KEY', 'authd_table');
}

/* ============================================================
 *  一、元素與屬性的中英文對照（Metadata）
 * ============================================================ */

/**
 * 元素中文標籤與說明。
 * 部分元素（table / column）在不同父層語意不同，故傳入 $parent 判斷。
 *
 * @return array [中文標籤, 英文原文(tag), FontAwesome 圖示, 中文說明]
 */
function gameserver_authd_element_meta(string $tag, string $parent = ''): array
{
    // 依父層語意區分的元素
    if ($tag === 'table' && $parent === 'query') {
        return ['來源資料表', 'table', 'fa-table-list', '此查詢所使用的來源資料表與別名。'];
    }
    if ($tag === 'column' && $parent === 'query') {
        return ['選取欄位', 'column', 'fa-columns', '查詢要選取的欄位（別名對應來源欄位）。'];
    }

    $map = [
        'application' => ['應用程式根節點', 'application', 'fa-cube', '整份 XML 的根元素，描述帳號驗證服務（authd）的資料庫結構；debug 屬性控制除錯模式。'],
        'driver'      => ['JDBC 驅動',       'driver',      'fa-plug',            '資料庫連線使用的 JDBC 驅動類別。'],
        'connection'  => ['資料庫連線',      'connection',  'fa-database',        '一組 JDBC 連線設定（連線池大小、連線網址、帳號密碼）。'],
        'table'       => ['資料表定義',      'table',       'fa-table',           '定義一張資料表的欄位、主鍵與索引。'],
        'column'      => ['資料表欄位',      'column',      'fa-columns',         '資料表的一欄，對應 SQL 型別與 Java 型別。'],
        'primarykey'  => ['主鍵',            'primarykey',  'fa-key',             '資料表主鍵，可為多欄複合主鍵。'],
        'index'       => ['索引',            'index',       'fa-magnifying-glass','資料表索引，可設定是否唯一。'],
        'query'       => ['查詢定義',        'query',       'fa-magnifying-glass-chart', '具名 SQL 查詢，內含來源資料表、選取欄位與條件。'],
        'select'      => ['查詢語句',        'select',      'fa-filter',          '查詢的具名條件語句。'],
        'procedure'   => ['預存程序',        'procedure',   'fa-code',            '具名預存程序與其輸入／輸出參數。'],
        'parameter'   => ['程序參數',        'parameter',   'fa-right-left',      '預存程序的參數（含 SQL/Java 型別與 IN/OUT 方向）。'],
    ];
    return $map[$tag] ?? [$tag, $tag, 'fa-tag', ''];
}

/**
 * 屬性中文標籤、輸入型別與說明。
 * 輸入型別：bool（true/false 下拉）｜int（整數）｜text（文字）｜password（密碼）。
 *
 * @return array [中文標籤, 型別, 中文說明]
 */
function gameserver_authd_attr_meta(string $tag, string $attr, string $parent = ''): array
{
    // 查詢中的 table / column 屬性語意不同
    if ($tag === 'table' && $parent === 'query') {
        $overrides = [
            'name'  => ['來源資料表名稱', 'text', '此查詢來源的資料表名稱。'],
            'alias' => ['資料表別名',     'text', '查詢中使用的資料表別名（如 u）。'],
        ];
        if (isset($overrides[$attr])) {
            return $overrides[$attr];
        }
    }
    if ($tag === 'column' && $parent === 'query') {
        $overrides = [
            'name'   => ['欄位別名',   'text', '查詢結果中的欄位名稱／別名。'],
            'column' => ['來源欄位',   'text', '實際選取的來源欄位（含別名，如 u.id）。'],
        ];
        if (isset($overrides[$attr])) {
            return $overrides[$attr];
        }
    }

    $key = $tag . '.' . $attr;
    $map = [
        // 根節點
        'application.debug'     => ['除錯模式',       'bool',     '是否開啟除錯模式（true 開啟／false 關閉）。'],
        // 驅動
        'driver.name'           => ['驅動類別',       'text',     'JDBC 驅動完整類別名稱，例如 com.mysql.jdbc.Driver。'],
        // 連線
        'connection.name'       => ['連線名稱',       'text',     '連線識別名稱，供 table / procedure 的 connection 屬性引用。'],
        'connection.poolsize'   => ['連線池大小',     'int',      '連線池維持的連線數量。'],
        'connection.url'        => ['JDBC 連線網址',  'text',     '資料庫連線字串，格式 jdbc:mysql://主機:埠/資料庫?參數。'],
        'connection.username'   => ['使用者名稱',     'text',     '資料庫登入帳號。'],
        'connection.password'   => ['密碼',           'password', '資料庫登入密碼（此檔為明碼，請妥善設定遠端檔案權限）。'],
        // 資料表
        'table.name'            => ['資料表名稱',     'text',     '資料表（table）名稱。'],
        'table.connection'      => ['所屬連線',       'text',     '引用 connection 的 name 值。'],
        'table.operate'         => ['操作模式',       'text',     '資料表建置／同步模式；replaceA 表示以最新結構自動取代。'],
        // 欄位
        'column.name'           => ['欄位名稱',       'text',     '欄位（column）名稱。'],
        'column.sql-type'       => ['SQL 型別',       'text',     '資料庫 SQL 型別，例如 integer / varchar(32) / binary(16) / datetime。'],
        'column.java-type'      => ['Java 型別',      'text',     '對應的 Java 類別，例如 java.lang.Integer / java.lang.String / java.util.Date。'],
        'column.not-null'       => ['不可為空',       'bool',     '此欄位是否不允許 NULL（true 不可為空／false 可為空）。'],
        // 主鍵
        'primarykey.name'       => ['主鍵名稱',       'text',     '主鍵約束名稱。'],
        'primarykey.column'     => ['主鍵欄位',       'text',     '組成主鍵的欄位；複合主鍵以逗號分隔。'],
        // 索引
        'index.name'            => ['索引名稱',       'text',     '索引名稱。'],
        'index.unique'          => ['唯一索引',       'bool',     '是否為唯一索引（true 唯一／false 非唯一）。'],
        'index.column'          => ['索引欄位',       'text',     '索引包含的欄位；複合索引以逗號分隔。'],
        // 查詢
        'query.name'            => ['查詢名稱',       'text',     '查詢識別名稱（程式以此名稱取用）。'],
        'select.name'           => ['語句名稱',       'text',     '查詢語句名稱（程式以此選取）。'],
        'select.condition'      => ['查詢條件',       'text',     'SQL WHERE 條件；? 為參數佔位符。'],
        // 預存程序
        'procedure.name'        => ['程序名稱',       'text',     '預存程序名稱。'],
        'procedure.connection'  => ['所屬連線',       'text',     '引用 connection 的 name 值。'],
        'procedure.operate'     => ['操作模式',       'text',     '程序建立模式；replaceA 表示自動取代。'],
        'parameter.name'        => ['參數名稱',       'text',     '程序參數名稱。'],
        'parameter.sql-type'    => ['SQL 型別',       'text',     '參數的 SQL 型別。'],
        'parameter.java-type'   => ['Java 型別',      'text',     '參數的 Java 型別。'],
        'parameter.in'          => ['輸入參數',       'bool',     '是否為 IN 參數（由呼叫端傳入程序）。'],
        'parameter.out'         => ['輸出參數',       'bool',     '是否為 OUT 參數（由程序回傳）。'],
    ];
    return $map[$key] ?? [$attr, 'text', ''];
}

/* ============================================================
 *  二、解析（DOMDocument）
 * ============================================================ */

/**
 * 解析 table.xml 為結構樹。
 *
 * @return array {
 *   ok: bool, error: string,
 *   tree: ?array, flat: array<string,array>, counts: array<string,int>,
 *   encoding: string
 * }
 */
function gameserver_authd_parse($content): array
{
    $out = ['ok' => false, 'error' => '', 'tree' => null, 'flat' => [], 'counts' => [], 'encoding' => 'UTF-8'];
    $content = (string)$content;
    if (trim($content) === '') {
        $out['error'] = '內容為空。';
        return $out;
    }
    if (!class_exists('DOMDocument')) {
        $out['error'] = 'PHP 缺少 DOM 擴充，無法解析 XML。';
        return $out;
    }

    // 拆出編碼宣告（顯示用；寫回時由 DOMDocument 自行處理）
    if (preg_match('/<\?xml[^>]*encoding="([^"]+)"/i', $content, $m)) {
        $out['encoding'] = strtoupper($m[1]);
    }

    $doc = gameserver_authd_load_dom($content);
    if ($doc instanceof DOMDocument && $doc->documentElement) {
        $flat = [];
        $tree = gameserver_authd_walk($doc->documentElement, '/' . $doc->documentElement->tagName, '', $flat);
        $counts = [];
        foreach ($flat as $node) {
            $t = (string)$node['tag'];
            $counts[$t] = ($counts[$t] ?? 0) + 1;
        }
        $out['ok']       = true;
        $out['tree']     = $tree;
        $out['flat']     = $flat;
        $out['counts']   = $counts;
        return $out;
    }

    $out['error'] = 'XML 解析失敗：' . gameserver_authd_last_error();
    return $out;
}

/** 以 DOMDocument 載入 XML（抑制警告，回傳 DOMDocument 或 null） */
function gameserver_authd_load_dom($content): ?DOMDocument
{
    if (!class_exists('DOMDocument')) {
        return null;
    }
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    $doc->formatOutput = false;
    $ok = $doc->loadXML((string)$content, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    return $ok ? $doc : null;
}

/** 取得最近一次 libxml 錯誤訊息 */
function gameserver_authd_last_error(): string
{
    if (!function_exists('libxml_get_errors')) {
        return '';
    }
    $errs = libxml_get_errors();
    if (!$errs) {
        return '';
    }
    $e = $errs[0];
    return trim($e->message) . '（第 ' . (int)$e->line . ' 行）';
}

/**
 * 遞迴建立節點模型。
 * 路徑採 XPath 風格：/application/table[2]/column[3]（同標籤以 1 起算序號）。
 */
function gameserver_authd_walk(DOMElement $el, string $path, string $parentTag, array &$flat): array
{
    $attrs = [];
    foreach ($el->attributes as $a) {
        $attrs[(string)$a->name] = (string)$a->value;
    }
    $node = [
        'tag'      => (string)$el->tagName,
        'path'     => $path,
        'parent'   => $parentTag,
        'name'     => $attrs['name'] ?? '',
        'attrs'    => $attrs,
        'line'     => $el->getLineNo(),
        'children' => [],
    ];

    $counter = [];
    foreach ($el->childNodes as $c) {
        if ($c->nodeType !== XML_ELEMENT_NODE) {
            continue; // 略過註解與空白文字節點（寫回時仍原樣保留）
        }
        $t = (string)$c->tagName;
        $counter[$t] = ($counter[$t] ?? 0) + 1;
        $childPath = $path . '/' . $t . '[' . $counter[$t] . ']';
        $node['children'][] = gameserver_authd_walk($c, $childPath, (string)$el->tagName, $flat);
    }

    $flat[$path] = $node;
    return $node;
}

/* ============================================================
 *  三、編輯（僅變更必要區塊）
 * ============================================================ */

/**
 * 套用編輯並回傳新內容。
 *
 * @param string $content      原始 XML
 * @param array  $attrUpdates  [ ['path'=>元素路徑,'attr'=>屬性名,'value'=>新值], ... ]
 * @param array  $additions    [ ['parent'=>父元素路徑,'tag'=>標籤,'attrs'=>[k=>v]], ... ]
 * @param array  $deletions    [ 元素路徑, ... ]
 * @return array { ok, content, error, changed, added, deleted }
 */
function gameserver_authd_apply_edits(string $content, array $attrUpdates, array $additions, array $deletions): array
{
    // 無結構性變更時，採「逐行精準替換」以完整保留原始格式
    // （標籤內空白、自我閉合寫法、屬性對齊、註解與 BOM）。
    if (empty($additions) && empty($deletions)) {
        return gameserver_authd_apply_attr_lines($content, $attrUpdates);
    }

    $doc = gameserver_authd_load_dom($content);
    if (!$doc || !$doc->documentElement) {
        return ['ok' => false, 'content' => '', 'error' => 'XML 解析失敗：' . gameserver_authd_last_error(), 'changed' => 0, 'added' => 0, 'deleted' => 0];
    }

    $changed = 0;
    foreach ($attrUpdates as $u) {
        $path = (string)($u['path'] ?? '');
        $attr = (string)($u['attr'] ?? '');
        if ($path === '' || $attr === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]*$/', $attr)) {
            continue;
        }
        $el = gameserver_authd_find($doc, $path);
        if (!$el) {
            continue;
        }
        $val = (string)($u['value'] ?? '');
        if ($el->getAttribute($attr) === $val) {
            continue;
        }
        $el->setAttribute($attr, $val);
        $changed++;
    }

    $added = 0;
    foreach ($additions as $a) {
        $parentPath = (string)($a['parent'] ?? '');
        $tag        = (string)($a['tag'] ?? '');
        if ($tag === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $tag)) {
            continue;
        }
        $parent = ($parentPath === '') ? $doc->documentElement : gameserver_authd_find($doc, $parentPath);
        if (!$parent) {
            continue;
        }
        $newEl = $doc->createElement($tag);
        foreach ((array)($a['attrs'] ?? []) as $k => $v) {
            $k = (string)$k;
            if ($k === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]*$/', $k)) {
                continue;
            }
            $newEl->setAttribute($k, (string)$v);
        }
        gameserver_authd_append_indented($doc, $parent, $newEl);
        $added++;
    }

    $deleted = 0;
    foreach ($deletions as $path) {
        $path = (string)$path;
        if ($path === '' || $path === '/' . $doc->documentElement->tagName) {
            continue; // 不允許刪除根節點
        }
        $el = gameserver_authd_find($doc, $path);
        if ($el && $el->parentNode) {
            $parent = $el->parentNode;
            $prev = $el->previousSibling;
            if ($prev && $prev->nodeType === XML_TEXT_NODE) {
                $parent->removeChild($prev);
            }
            $parent->removeChild($el);
            $deleted++;
        }
    }

    $newContent = $doc->saveXML();
    return ['ok' => true, 'content' => $newContent, 'error' => '', 'changed' => $changed, 'added' => $added, 'deleted' => $deleted];
}

/** XML 屬性值轉義 */
function gameserver_authd_attr_encode($v): string
{
    return str_replace(['&', '"', '<', '>'], ['&amp;', '&quot;', '&lt;', '&gt;'], (string)$v);
}

/**
 * 逐行精準替換屬性值（無結構性變更時使用）。
 * 僅改寫目標元素所在行中該屬性的值，其餘位元組（含標籤內空白、縮排、註解、BOM）
 * 完全保持不變，可讓遠端檔案 diff 最小化。
 *
 * @return array { ok, content, error, changed, added, deleted }
 */
function gameserver_authd_apply_attr_lines(string $content, array $attrUpdates): array
{
    $bom = (substr($content, 0, 3) === "\xEF\xBB\xBF");
    $body = $bom ? substr($content, 3) : $content;
    $eol = (strpos($body, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', $body);

    $parsed = gameserver_authd_parse($body);
    if (!$parsed['ok']) {
        return ['ok' => false, 'content' => '', 'error' => $parsed['error'], 'changed' => 0, 'added' => 0, 'deleted' => 0];
    }

    $changed = 0;
    foreach ($attrUpdates as $u) {
        $path = (string)($u['path'] ?? '');
        $attr = (string)($u['attr'] ?? '');
        if ($path === '' || $attr === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]*$/', $attr)) {
            continue;
        }
        $node = $parsed['flat'][$path] ?? null;
        if (!$node) {
            continue;
        }
        $lineNo = (int)($node['line'] ?? 0);
        if ($lineNo < 1 || !isset($lines[$lineNo - 1])) {
            continue;
        }
        $val = (string)($u['value'] ?? '');
        if ((string)($node['attrs'][$attr] ?? null) === $val) {
            continue;
        }
        $line = $lines[$lineNo - 1];
        $pat  = '/(\s' . preg_quote($attr, '/') . '\s*=\s*")([^"]*)(")/';
        $new  = preg_replace_callback($pat, function ($m) use ($val) {
            return $m[1] . gameserver_authd_attr_encode($val) . $m[3];
        }, $line, 1);
        if ($new !== null && $new !== $line) {
            $lines[$lineNo - 1] = $new;
            $changed++;
        }
    }

    $out = implode($eol, $lines);
    if ($bom) {
        $out = "\xEF\xBB\xBF" . $out;
    }
    return ['ok' => true, 'content' => $out, 'error' => '', 'changed' => $changed, 'added' => 0, 'deleted' => 0];
}

/** 依 XPath 風格路徑（tag[n]）尋元素；根節點路徑支援 /tag 或 /tag[1] */
function gameserver_authd_find(DOMDocument $doc, string $path): ?DOMElement
{
    $path = trim($path);
    if ($path === '' || $path[0] !== '/' || !$doc->documentElement) {
        return null;
    }
    $segs = explode('/', ltrim($path, '/'));
    $root = $doc->documentElement;
    $first = preg_replace('/\[\d+\]$/', '', (string)array_shift($segs));
    if ($first !== $root->tagName) {
        return null;
    }
    $cur = $root;
    foreach ($segs as $seg) {
        if ($seg === '') {
            continue;
        }
        $idx = 1;
        $tag = $seg;
        if (preg_match('/^(.*)\[(\d+)\]$/', $seg, $m)) {
            $tag = $m[1];
            $idx = (int)$m[2];
        }
        $found = null;
        $count = 0;
        foreach ($cur->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && $c->tagName === $tag) {
                $count++;
                if ($count === $idx) {
                    $found = $c;
                    break;
                }
            }
        }
        if (!$found) {
            return null;
        }
        $cur = $found;
    }
    return $cur;
}

/** 依既有縮排將新元素附加至父元素（維持縮排風格） */
function gameserver_authd_append_indented(DOMDocument $doc, DOMElement $parent, DOMElement $newEl): void
{
    $childIndent = null;
    $closeIndent = null;

    foreach ($parent->childNodes as $c) {
        if ($c->nodeType === XML_ELEMENT_NODE) {
            $prev = $c->previousSibling;
            if ($prev && $prev->nodeType === XML_TEXT_NODE && strpos($prev->nodeValue, "\n") !== false) {
                $parts = explode("\n", $prev->nodeValue);
                $childIndent = end($parts);
            }
        }
    }
    $last = $parent->lastChild;
    if ($last && $last->nodeType === XML_TEXT_NODE && strpos($last->nodeValue, "\n") !== false) {
        $parts = explode("\n", $last->nodeValue);
        $closeIndent = end($parts);
        $parent->removeChild($last);
    }
    if ($childIndent === null) {
        $childIndent = "\t";
    }
    if ($closeIndent === null) {
        $closeIndent = '';
    }

    $parent->appendChild($doc->createTextNode("\n" . $childIndent));
    $parent->appendChild($newEl);
    $parent->appendChild($doc->createTextNode("\n" . $closeIndent));
}

/** 驗證 XML 是否合法（供 raw 編輯使用） */
function gameserver_authd_validate(string $content): array
{
    $doc = gameserver_authd_load_dom($content);
    if ($doc && $doc->documentElement) {
        return ['ok' => true, 'error' => ''];
    }
    return ['ok' => false, 'error' => gameserver_authd_last_error()];
}

/* ============================================================
 *  四、遠端操作
 * ============================================================ */

/** 讀取遠端最新內容並更新快取 */
function gameserver_authd_refresh($link, string $by = ''): array
{
    return gameserver_file_refresh($link, GAME_SERVER_AUTHD_KEY, $by);
}

/** 還原為初始版本快照 */
function gameserver_authd_restore($link, string $by = ''): array
{
    return gameserver_file_restore($link, GAME_SERVER_AUTHD_KEY, $by);
}

/** 取得目前快取內容（已解碼的原始位元組） */
function gameserver_authd_content($link): string
{
    $row = gameserver_file_get($link, GAME_SERVER_AUTHD_KEY);
    return (string)($row['raw_cache'] ?? '');
}

/**
 * 套用結構化編輯並寫回遠端。
 * 流程：重新讀取遠端 → 僅套用差異 → 寫回 → 更新快取 → 稽核。
 */
function gameserver_authd_apply($link, array $attrUpdates, array $additions, array $deletions, string $by = ''): array
{
    $read = gameserver_file_read($link, GAME_SERVER_AUTHD_KEY);
    if (!$read['ok']) {
        return ['ok' => false, 'error' => $read['error']];
    }
    $res = gameserver_authd_apply_edits($read['content'], $attrUpdates, $additions, $deletions);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error']];
    }
    if ($res['changed'] === 0 && $res['added'] === 0 && $res['deleted'] === 0) {
        return ['ok' => true, 'error' => '', 'warning' => '（未偵測到任何變更，遠端檔案未更動）'];
    }
    return gameserver_authd_write($link, $read['server'], $read['path'], $res['content'], $by,
        'items=' . $res['changed'] . ' add=' . $res['added'] . ' del=' . $res['deleted']);
}

/** 直接以 raw 內容寫回遠端（寫入前驗證 XML） */
function gameserver_authd_save_raw($link, string $content, string $by = ''): array
{
    $v = gameserver_authd_validate($content);
    if (!$v['ok']) {
        return ['ok' => false, 'error' => 'XML 格式錯誤，未寫入：' . $v['error']];
    }
    $row = gameserver_file_get($link, GAME_SERVER_AUTHD_KEY);
    if ($row === null) {
        return ['ok' => false, 'error' => '未知的設定檔。'];
    }
    $server = gameserver_file_target_server($link);
    if (!$server) {
        return ['ok' => false, 'error' => '尚未指定目標伺服器。'];
    }
    $path = (string)$row['path'];
    if ($path === '') {
        return ['ok' => false, 'error' => '尚未設定檔案路徑。'];
    }
    return gameserver_authd_write($link, $server, $path, $content, $by, 'raw save');
}

/** 共用寫入：SFTP put + 快取 + 稽核 */
function gameserver_authd_write($link, array $server, string $path, string $content, string $by, string $detail): array
{
    try {
        $sftp = remote_sftp($server, 30);
        if (!$sftp->put($path, $content)) {
            return ['ok' => false, 'error' => '寫入遠端檔案失敗，請確認權限：' . $path];
        }
        $cached = gameserver_file_cache($link, GAME_SERVER_AUTHD_KEY, $content, $by);
        if (function_exists('remote_audit')) {
            remote_audit($link, $server, 'save_authd_table', $path, $detail);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'error' => '', 'warning' => empty($cached) ? '（注意：本機快取更新失敗）' : ''];
}

/* ============================================================
 *  五、彙總（供介面摘要顯示）
 * ============================================================ */
/**
 * 由 flat 節點計算摘要數量。
 * @return array {connections,tables,columns,queries,procedures,parameters,indexes,primarykeys}
 */
function gameserver_authd_summary(array $flat): array
{
    $conn = 0; $tables = 0; $columns = 0; $queries = 0; $procs = 0; $params = 0; $indexes = 0; $pks = 0;
    foreach ($flat as $n) {
        $t = (string)$n['tag'];
        $p = (string)$n['parent'];
        if ($t === 'connection') {
            $conn++;
        } elseif ($t === 'table' && $p !== 'query') {
            $tables++;
        } elseif ($t === 'column' && $p !== 'query') {
            $columns++;
        } elseif ($t === 'query') {
            $queries++;
        } elseif ($t === 'procedure') {
            $procs++;
        } elseif ($t === 'parameter') {
            $params++;
        } elseif ($t === 'index') {
            $indexes++;
        } elseif ($t === 'primarykey') {
            $pks++;
        }
    }
    return [
        'connections' => $conn,
        'tables'      => $tables,
        'columns'     => $columns,
        'queries'     => $queries,
        'procedures'  => $procs,
        'parameters'  => $params,
        'indexes'     => $indexes,
        'primarykeys' => $pks,
    ];
}

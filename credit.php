<?php
/**
 * =============================================================
 *  踏雪笑傲 · 帳號信用系統工具庫
 *  -------------------------------------------------------------
 *  資料表：
 *    - credit_settings ：信用系統參數（預設分數 / 分數上下限 / 等級門檻 / 開關）
 *    - user_credit     ：每位會員一列，保存目前信用分數（預設 100 分）
 *    - credit_ledger   ：信用分數異動流水（可稽核）
 *
 *  管理端：admin/credit_admin.php（子視窗彈出 UI）
 *  前台：home.php
 *
 *  使用方式：
 *    require_once __DIR__ . '/credit.php';   // 先建立 $Link 並 set utf8
 *
 *  資料表尚未建立時，前台一律以預設分數（100）與預設等級運作。
 * =============================================================
 */

if (!defined('CREDIT_DEFAULT_SCORE')) {
    define('CREDIT_DEFAULT_SCORE', 100);
}

/** 預設信用參數設定 */
function credit_default_settings()
{
    return [
        'credit_enabled'  => '1',
        'default_score'   => '100',
        'min_score'       => '0',
        'max_score'       => '1000',
        'tier_excellent'  => '90',
        'tier_good'       => '75',
        'tier_fair'       => '60',
        'tier_poor'       => '40',
        'risk_threshold'  => '60',
        'show_on_home'    => '1',
    ];
}

/** 讀取信用參數設定（合併預設值） */
function credit_load_settings($Link)
{
    $settings = credit_default_settings();
    if (!$Link) {
        return $settings;
    }
    try {
        $res = $Link->query("SELECT `setting_key`, `setting_value` FROM `credit_settings`");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $settings[$row['setting_key']] = (string)$row['setting_value'];
            }
        }
    } catch (Throwable $e) {
        // 資料表尚未建立時沿用預設值
    }
    return $settings;
}

/** 儲存信用參數設定（僅接受已知鍵值） */
function credit_save_settings($Link, $values)
{
    if (!$Link || !is_array($values)) {
        return false;
    }
    $allowed = credit_default_settings();
    try {
        $stmt = $Link->prepare(
            "INSERT INTO `credit_settings` (`setting_key`,`setting_value`,`updated_at`)
             VALUES (?,?,NOW())
             ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`), `updated_at`=NOW()"
        );
        foreach ($values as $key => $val) {
            if (!array_key_exists($key, $allowed)) {
                continue;
            }
            $val = (string)$val;
            $stmt->bind_param("ss", $key, $val);
            $stmt->execute();
        }
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 建立信用相關資料表，並為既有會員補上預設分數列 */
function credit_ensure_table($Link)
{
    if (!$Link) {
        return;
    }
    $settings = credit_load_settings($Link);
    $default = (int)$settings['default_score'];
    if ($default <= 0) {
        $default = CREDIT_DEFAULT_SCORE;
    }
    try {
        $Link->query("CREATE TABLE IF NOT EXISTS `credit_settings` (
            `setting_key` varchar(40) NOT NULL,
            `setting_value` varchar(255) NOT NULL DEFAULT '',
            `description` varchar(200) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='帳號信用系統設定'");

        $Link->query("CREATE TABLE IF NOT EXISTS `user_credit` (
            `uid` int(11) NOT NULL,
            `score` int(11) NOT NULL DEFAULT 100 COMMENT '目前信用分數',
            `total_delta` int(11) NOT NULL DEFAULT 0 COMMENT '累計分數變動',
            `note` varchar(255) NOT NULL DEFAULT '' COMMENT '備註',
            `updated_by` varchar(50) NOT NULL DEFAULT '' COMMENT '最後異動 GM 帳號',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`uid`),
            KEY `idx_credit_score` (`score`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會員信用分數'");

        $Link->query("CREATE TABLE IF NOT EXISTS `credit_ledger` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `uid` int(11) NOT NULL DEFAULT 0,
            `change_amount` int(11) NOT NULL DEFAULT 0 COMMENT '正為加分、負為扣分',
            `score_before` int(11) NOT NULL DEFAULT 0,
            `score_after` int(11) NOT NULL DEFAULT 0,
            `type` varchar(32) NOT NULL DEFAULT 'admin_set' COMMENT 'admin_set/admin_add/admin_deduct/reset',
            `reason` varchar(255) NOT NULL DEFAULT '',
            `operator` varchar(50) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_credit_ledger_uid` (`uid`),
            KEY `idx_credit_ledger_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='信用分數異動流水'");

        $stmt = $Link->prepare(
            "INSERT INTO `user_credit` (`uid`,`score`,`total_delta`)
             SELECT `ID`, ?, 0 FROM `users`
             WHERE `ID` NOT IN (SELECT `uid` FROM `user_credit`)"
        );
        $stmt->bind_param("i", $default);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // 資料庫權限不足時略過，前台仍以預設值運作
    }
}

/** 等級定義（由高至低） */
function credit_level_map()
{
    return [
        'excellent' => ['label' => '極佳',   'color' => 'emerald', 'desc' => '信用優良，享全站功能與免鎖定特權。'],
        'good'      => ['label' => '良好',   'color' => 'sky',     'desc' => '信用正常，可正常使用各項會員功能。'],
        'fair'      => ['label' => '尚可',   'color' => 'amber',   'desc' => '信用普通，部分風險功能可能受限。'],
        'poor'      => ['label' => '待加強', 'color' => 'orange',  'desc' => '信用偏低，建議留意帳號使用行為。'],
        'risky'     => ['label' => '風險',   'color' => 'rose',    'desc' => '信用過低，帳號功能可能遭到限制。'],
    ];
}

/** 依分數取得等級（回傳 key/label/color/desc/threshold） */
function credit_level($score, $settings = null)
{
    if ($settings === null) {
        $settings = credit_default_settings();
    }
    $score = (int)$score;
    $map = credit_level_map();
    $excellent = (int)($settings['tier_excellent'] ?? 90);
    $good      = (int)($settings['tier_good'] ?? 75);
    $fair      = (int)($settings['tier_fair'] ?? 60);
    $poor      = (int)($settings['tier_poor'] ?? 40);

    if ($score >= $excellent) { $key = 'excellent'; }
    elseif ($score >= $good)  { $key = 'good'; }
    elseif ($score >= $fair)  { $key = 'fair'; }
    elseif ($score >= $poor)  { $key = 'poor'; }
    else                      { $key = 'risky'; }

    $meta = $map[$key];
    return [
        'key'   => $key,
        'label' => $meta['label'],
        'color' => $meta['color'],
        'desc'  => $meta['desc'],
    ];
}

/** 確保會員信用列存在 */
function credit_ensure_user($Link, $uid)
{
    $uid = (int)$uid;
    if (!$Link || $uid <= 0) {
        return false;
    }
    $settings = credit_load_settings($Link);
    $default = (int)$settings['default_score'];
    if ($default <= 0) {
        $default = CREDIT_DEFAULT_SCORE;
    }
    try {
        $stmt = $Link->prepare(
            "INSERT INTO `user_credit` (`uid`,`score`,`total_delta`,`created_at`,`updated_at`)
             VALUES (?,?,0,NOW(),NOW())
             ON DUPLICATE KEY UPDATE `updated_at` = `updated_at`"
        );
        $stmt->bind_param("ii", $uid, $default);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 取得會員信用資料（無資料時回傳預設分數） */
function credit_get($Link, $uid)
{
    $settings = credit_load_settings($Link);
    $default = (int)$settings['default_score'];
    if ($default <= 0) {
        $default = CREDIT_DEFAULT_SCORE;
    }
    $out = [
        'uid'         => (int)$uid,
        'score'       => $default,
        'total_delta' => 0,
        'note'        => '',
        'updated_by'  => '',
        'updated_at'  => '',
        'exists'      => false,
    ];
    $uid = (int)$uid;
    if (!$Link || $uid <= 0) {
        $out['level'] = credit_level($default, $settings);
        return $out;
    }
    credit_ensure_user($Link, $uid);
    try {
        $stmt = $Link->prepare("SELECT `score`,`total_delta`,`note`,`updated_by`,`updated_at` FROM `user_credit` WHERE `uid` = ? LIMIT 1");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $out['score']       = (int)$row['score'];
            $out['total_delta'] = (int)$row['total_delta'];
            $out['note']        = (string)$row['note'];
            $out['updated_by']  = (string)$row['updated_by'];
            $out['updated_at']  = (string)$row['updated_at'];
            $out['exists']      = true;
        }
    } catch (Throwable $e) {
        // 資料表尚未建立時沿用預設值
    }
    $out['level'] = credit_level($out['score'], $settings);
    return $out;
}

/**
 * 寫入信用分數（交易 + 流水），targetScore 為絕對分數。
 *
 * @return array{ok:bool,score:int,delta:int,level:array,message:string}
 */
function credit_write($Link, $uid, $targetScore, $type = 'admin_set', $reason = '', $operator = '', $note = '')
{
    $uid = (int)$uid;
    $targetScore = (int)$targetScore;
    $settings = credit_load_settings($Link);
    $min = (int)$settings['min_score'];
    $max = (int)$settings['max_score'];
    if ($targetScore < $min) { $targetScore = $min; }
    if ($max > 0 && $targetScore > $max) { $targetScore = $max; }

    if (!$Link || $uid <= 0) {
        return ['ok' => false, 'score' => 0, 'delta' => 0, 'level' => credit_level(0, $settings), 'message' => '參數不正確。'];
    }
    if ((int)$settings['credit_enabled'] !== 1) {
        return ['ok' => false, 'score' => 0, 'delta' => 0, 'level' => credit_level(0, $settings), 'message' => '信用系統目前未啟用。'];
    }

    credit_ensure_user($Link, $uid);

    try {
        $Link->begin_transaction();

        $stmt = $Link->prepare("SELECT `score` FROM `user_credit` WHERE `uid` = ? FOR UPDATE");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $old = (int)($row['score'] ?? credit_default_settings()['default_score']);
        $delta = $targetScore - $old;

        $stmt = $Link->prepare(
            "UPDATE `user_credit`
             SET `score` = ?, `total_delta` = `total_delta` + ?, `note` = ?, `updated_by` = ?, `updated_at` = NOW()
             WHERE `uid` = ?"
        );
        $stmt->bind_param("iissi", $targetScore, $delta, $note, $operator, $uid);
        $stmt->execute();
        $stmt->close();

        $stmt = $Link->prepare(
            "INSERT INTO `credit_ledger`
                (`uid`,`change_amount`,`score_before`,`score_after`,`type`,`reason`,`operator`)
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->bind_param("iiiisss", $uid, $delta, $old, $targetScore, $type, $reason, $operator);
        $stmt->execute();
        $stmt->close();

        $Link->commit();
        return [
            'ok'      => true,
            'score'   => $targetScore,
            'delta'   => $delta,
            'level'   => credit_level($targetScore, $settings),
            'message' => '信用分數已更新。',
        ];
    } catch (Throwable $e) {
        try { $Link->rollback(); } catch (Throwable $e2) {}
        return ['ok' => false, 'score' => 0, 'delta' => 0, 'level' => credit_level(0, $settings), 'message' => '交易失敗，請稍後再試。'];
    }
}

/**
 * 以加/減分方式調整信用分數（delta 可正可負）。
 *
 * @return array{ok:bool,score:int,delta:int,level:array,message:string}
 */
function credit_adjust($Link, $uid, $delta, $reason = '', $operator = '', $note = '')
{
    $delta = (int)$delta;
    if ($delta === 0) {
        return ['ok' => false, 'score' => 0, 'delta' => 0, 'level' => credit_level(0), 'message' => '調整分數不可為 0。'];
    }
    $current = credit_get($Link, $uid);
    $target = (int)$current['score'] + $delta;
    $type = $delta > 0 ? 'admin_add' : 'admin_deduct';
    return credit_write($Link, $uid, $target, $type, $reason, $operator, $note);
}

/** 讀取會員信用流水 */
function credit_recent_ledger($Link, $uid, $limit = 10)
{
    $rows = [];
    $uid = (int)$uid;
    $limit = max(1, (int)$limit);
    if (!$Link || $uid <= 0) {
        return $rows;
    }
    try {
        $stmt = $Link->prepare(
            "SELECT `change_amount`,`score_before`,`score_after`,`type`,`reason`,`operator`,`created_at`
             FROM `credit_ledger` WHERE `uid` = ? ORDER BY `id` DESC LIMIT $limit"
        );
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    } catch (Throwable $e) {
        // 資料表尚未建立
    }
    return $rows;
}

/** 信用統計（總人數 / 平均分 / 風險人數） */
function credit_stats($Link, $settings = null)
{
    if ($settings === null) {
        $settings = credit_load_settings($Link);
    }
    $risk = (int)($settings['risk_threshold'] ?? 60);
    $stats = ['total' => 0, 'avg' => 0, 'risk' => 0, 'managed' => 0];
    if (!$Link) {
        return $stats;
    }
    try {
        $res = $Link->query("SELECT COUNT(*) AS c, IFNULL(AVG(`score`),0) AS a, SUM(CASE WHEN `score` < $risk THEN 1 ELSE 0 END) AS r FROM `user_credit`");
        if ($res && ($row = $res->fetch_assoc())) {
            $stats['managed'] = (int)$row['c'];
            $stats['avg'] = (int)round((float)$row['a']);
            $stats['risk'] = (int)$row['r'];
        }
        $res = $Link->query("SELECT COUNT(*) AS c FROM `users`");
        if ($res && ($row = $res->fetch_assoc())) {
            $stats['total'] = (int)$row['c'];
        }
    } catch (Throwable $e) {
        // 資料表尚未建立
    }
    return $stats;
}

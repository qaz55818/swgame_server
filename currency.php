<?php
/**
 * =============================================================
 *  踏雪笑傲 · 儲值貨幣工具庫（點數 / 元寶）
 *  -------------------------------------------------------------
 *  資金模型：
 *    - 點數 (points)  ：儲值取得的站內貨幣（存於 user_wallet.points）
 *    - 元寶 (yuanbao) ：遊戲內高階貨幣（存於 user_wallet.yuanbao，
 *                       並同步寫入 point 表 aid=23 供遊戲伺服器讀取）
 *
 *  使用方式：
 *    require_once __DIR__ . '/currency.php';   // 先建立 $Link 並 set utf8
 *
 *  所有增減皆寫入 currency_ledger 交易流水，維持帳務可稽核。
 *  舊用戶以 point(aid=23) 餘額回填元寶；新用戶首次存取自動開錢包。
 * =============================================================
 */

if (!defined('CURRENCY_YUANBAO_AID')) {
    define('CURRENCY_YUANBAO_AID', 23);
}

/** 預設設定值 */
function currency_default_settings()
{
    return [
        'points_name'          => '點數',
        'yuanbao_name'         => '元寶',
        'exchange_enabled'     => '1',
        'exchange_rate'        => '1',
        'exchange_min'         => '100',
        'exchange_fee_percent' => '0',
    ];
}

/** 讀取貨幣設定（合併預設值） */
function currency_load_settings($Link)
{
    $settings = currency_default_settings();
    if (!$Link) {
        return $settings;
    }
    try {
        $res = $Link->query("SELECT `setting_key`, `setting_value` FROM `currency_settings`");
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

/** 取得（必要時建立）可寫入的錢包列 */
function currency_ensure_wallet($Link, $uid)
{
    $uid = (int)$uid;
    if (!$Link || $uid <= 0) {
        return false;
    }
    try {
        $stmt = $Link->prepare(
            "INSERT INTO `user_wallet` (`uid`, `created_at`, `updated_at`) VALUES (?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE `updated_at` = `updated_at`"
        );
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 取得錢包餘額（回傳 [points, yuanbao, total_points_in, total_yuanbao_in]） */
function currency_get_wallet($Link, $uid)
{
    $empty = ['points' => 0, 'yuanbao' => 0, 'total_points_in' => 0, 'total_yuanbao_in' => 0];
    $uid = (int)$uid;
    if (!$Link || $uid <= 0) {
        return $empty;
    }
    currency_ensure_wallet($Link, $uid);
    try {
        $stmt = $Link->prepare("SELECT `points`, `yuanbao`, `total_points_in`, `total_yuanbao_in` FROM `user_wallet` WHERE `uid` = ? LIMIT 1");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return $empty;
        }
        return [
            'points'           => (int)$row['points'],
            'yuanbao'          => (int)$row['yuanbao'],
            'total_points_in'  => (int)$row['total_points_in'],
            'total_yuanbao_in' => (int)$row['total_yuanbao_in'],
        ];
    } catch (Throwable $e) {
        return $empty;
    }
}

/**
 * 增減貨幣並寫入流水（負數代表扣減）。
 *
 * @return array{ok:bool,balance:int,message:string}
 */
function currency_add($Link, $uid, $currency, $amount, $type = 'adjust', $refNo = '', $desc = '', $operator = '')
{
    $uid = (int)$uid;
    $amount = (int)$amount;
    if (!$Link || $uid <= 0 || $amount === 0) {
        return ['ok' => false, 'balance' => 0, 'message' => '參數不正確。'];
    }
    $currency = ($currency === 'yuanbao') ? 'yuanbao' : 'points';
    $col = ($currency === 'yuanbao') ? 'yuanbao' : 'points';
    $totalCol = ($currency === 'yuanbao') ? 'total_yuanbao_in' : 'total_points_in';

    currency_ensure_wallet($Link, $uid);

    try {
        $Link->begin_transaction();

        $stmt = $Link->prepare("SELECT `$col` FROM `user_wallet` WHERE `uid` = ? FOR UPDATE");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $old = (int)($row[$col] ?? 0);

        $new = $old + $amount;
        if ($new < 0) {
            $Link->rollback();
            $label = ($currency === 'yuanbao') ? '元寶' : '點數';
            return ['ok' => false, 'balance' => $old, 'message' => $label . '餘額不足。'];
        }

        if ($amount > 0) {
            $stmt = $Link->prepare("UPDATE `user_wallet` SET `$col` = ?, `$totalCol` = `$totalCol` + ? WHERE `uid` = ?");
            $stmt->bind_param("iii", $new, $amount, $uid);
        } else {
            $stmt = $Link->prepare("UPDATE `user_wallet` SET `$col` = ? WHERE `uid` = ?");
            $stmt->bind_param("ii", $new, $uid);
        }
        $stmt->execute();
        $stmt->close();

        $stmt = $Link->prepare(
            "INSERT INTO `currency_ledger`
                (`uid`,`currency`,`change_amount`,`balance_after`,`type`,`ref_no`,`description`,`operator`)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param("isiissss", $uid, $currency, $amount, $new, $type, $refNo, $desc, $operator);
        $stmt->execute();
        $stmt->close();

        $Link->commit();
        return ['ok' => true, 'balance' => $new, 'message' => 'success'];
    } catch (Throwable $e) {
        try { $Link->rollback(); } catch (Throwable $e2) {}
        return ['ok' => false, 'balance' => 0, 'message' => '交易失敗，請稍後再試。'];
    }
}

/**
 * 將元寶同步至遊戲 point 表（aid=23），delta 可正可負。
 */
function currency_sync_point($Link, $uid, $delta, $aid = CURRENCY_YUANBAO_AID)
{
    $uid = (int)$uid;
    $delta = (int)$delta;
    $aid = (int)$aid;
    if (!$Link || $uid <= 0 || $delta === 0) {
        return true;
    }
    try {
        if ($delta > 0) {
            $stmt = $Link->prepare(
                "INSERT INTO `point` (`uid`, `aid`, `time`) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE `time` = `time` + VALUES(`time`)"
            );
            $stmt->bind_param("iii", $uid, $aid, $delta);
        } else {
            $stmt = $Link->prepare("UPDATE `point` SET `time` = `time` + ? WHERE `uid` = ? AND `aid` = ?");
            $stmt->bind_param("iii", $delta, $uid, $aid);
        }
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 產生兌換單號 */
function currency_gen_exchange_no($Link)
{
    do {
        $no = 'EX' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        try {
            $stmt = $Link->prepare("SELECT `id` FROM `exchange_orders` WHERE `exchange_no` = ? LIMIT 1");
            $stmt->bind_param("s", $no);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
        } catch (Throwable $e) {
            $exists = false;
        }
    } while ($exists);
    return $no;
}

/** 依設定將可兌換點數換算為元寶（含手續費） */
function currency_calc_yuanbao($points, $settings)
{
    $points = (int)$points;
    $rate = (float)($settings['exchange_rate'] ?? 1);
    $fee = (int)($settings['exchange_fee_percent'] ?? 0);
    $gross = (int)floor($points * $rate);
    $feeAmount = (int)floor($gross * $fee / 100);
    $gain = $gross - $feeAmount;
    if ($gain < 0) { $gain = 0; }
    return ['gross' => $gross, 'fee' => $feeAmount, 'gain' => $gain, 'rate' => $rate];
}

/**
 * 執行點數 → 元寶兌換。
 *
 * @return array{ok:bool,message:string,exchange_no?:string,points?:int,yuanbao?:int}
 */
function currency_exchange_yuanbao($Link, $uid, $username, $points, $operator = 'self')
{
    $uid = (int)$uid;
    $points = (int)$points;
    if (!$Link || $uid <= 0 || $points <= 0) {
        return ['ok' => false, 'message' => '兌換數量不正確。'];
    }

    $settings = currency_load_settings($Link);
    if ((int)$settings['exchange_enabled'] !== 1) {
        return ['ok' => false, 'message' => '兌換功能目前未開放。'];
    }
    $min = (int)$settings['exchange_min'];
    if ($min > 0 && $points < $min) {
        return ['ok' => false, 'message' => '單次最低兌換 ' . number_format($min) . ' 點。'];
    }

    $calc = currency_calc_yuanbao($points, $settings);
    if ($calc['gain'] <= 0) {
        return ['ok' => false, 'message' => '換算後元寶數量不足，請提高兌換點數。'];
    }

    $wallet = currency_get_wallet($Link, $uid);
    if ((int)$wallet['points'] < $points) {
        return ['ok' => false, 'message' => '點數餘額不足，目前可用 ' . number_format((int)$wallet['points']) . ' 點。'];
    }

    $exchangeNo = currency_gen_exchange_no($Link);

    // 1) 扣點數
    $deduct = currency_add($Link, $uid, 'points', -$points, 'exchange_out', $exchangeNo, '兌換元寶（' . number_format($points) . ' 點）', $operator);
    if (!$deduct['ok']) {
        return ['ok' => false, 'message' => $deduct['message']];
    }

    // 2) 加元寶
    $gain = (int)$calc['gain'];
    $add = currency_add($Link, $uid, 'yuanbao', $gain, 'exchange_in', $exchangeNo, '點數兌換（' . number_format($points) . ' 點 → ' . number_format($gain) . ' 元寶）', $operator);
    if (!$add['ok']) {
        // 回滾點數
        currency_add($Link, $uid, 'points', $points, 'refund', $exchangeNo, '兌換失敗退回點數', 'system');
        return ['ok' => false, 'message' => '兌換失敗，點數已退回。'];
    }

    // 3) 同步遊戲 point 表
    currency_sync_point($Link, $uid, $gain);

    // 4) 兌換紀錄
    try {
        $stmt = $Link->prepare(
            "INSERT INTO `exchange_orders`
                (`exchange_no`,`uid`,`username`,`points_used`,`rate`,`fee_percent`,`yuanbao_gained`,`status`,`note`)
             VALUES (?,?,?,?,?,?,?, 'completed', ?)"
        );
        $rateStr = number_format((float)$calc['rate'], 4, '.', '');
        $note = '手續費 ' . (int)$settings['exchange_fee_percent'] . '%';
        $feePercent = (int)$settings['exchange_fee_percent'];
        $stmt->bind_param("sisidiis", $exchangeNo, $uid, $username, $points, $rateStr, $feePercent, $gain, $note);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // 紀錄失敗不影響已完成的兌換
    }

    return [
        'ok'          => true,
        'message'     => '兌換成功！' . number_format($points) . ' 點已兌換為 ' . number_format($gain) . ' 元寶。',
        'exchange_no' => $exchangeNo,
        'points'      => $points,
        'yuanbao'     => $gain,
    ];
}

/**
 * 由登入 session 解析 uid / username（相容舊用戶無 user_id 的情況）。
 *
 * @return array{uid:int,username:string}
 */
function currency_resolve_user($Link, $username, $sessionUserId = '')
{
    $uid = 0;
    $name = (string)$username;
    if (!$Link) {
        return ['uid' => 0, 'username' => $name];
    }
    try {
        if ($sessionUserId !== '' && (int)$sessionUserId > 0) {
            $stmt = $Link->prepare("SELECT `ID`, `name` FROM `users` WHERE `ID` = ? LIMIT 1");
            $stmt->bind_param("i", $sessionUserId);
        } else {
            $stmt = $Link->prepare("SELECT `ID`, `name` FROM `users` WHERE `name` = ? LIMIT 1");
            $stmt->bind_param("s", $name);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $uid = (int)$row['ID'];
            $name = (string)$row['name'];
        } elseif ($sessionUserId !== '') {
            $uid = (int)$sessionUserId;
        }
    } catch (Throwable $e) {
        if ($sessionUserId !== '') { $uid = (int)$sessionUserId; }
    }
    return ['uid' => $uid, 'username' => $name];
}

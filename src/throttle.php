<?php
/**
 * 认证失败限流。
 *
 * 挡的是「拿字典撞密码」这类自动化尝试。没有这一层时，攻击者可以对着
 * /api/login 每秒发上百次请求，而 root/root 这种弱口令几秒就能撞开。
 *
 * 策略（窗口 + 计数，不引入额外的锁表状态，因此天然自愈）：
 *   · 同一账号在 throttle_window 秒内失败满 throttle_max_per_user 次 → 锁该账号
 *   · 同一 IP   在 throttle_window 秒内失败满 throttle_max_per_ip   次 → 锁该 IP
 * 解锁不需要额外代码：最早的失败记录滑出窗口后，计数自然掉回阈值以下，
 * 所以「重试等待时间」就是「最早那次失败还有多久过期」。
 *
 * 只统计失败（succeeded = 0）。登录成功会清掉该账号的失败记录，
 * 但刻意不清 IP 的——否则攻击者轮流换账号撞密码就能把 IP 计数刷回零。
 */

/** 允许的查询列白名单（列名会被拼进 SQL，所以必须限定取值） */
function throttle_columns()
{
    return ['identifier', 'ip'];
}

/** 统一标识的大小写与空白，避免 Alice / alice 被当成两个账号分别计数 */
function throttle_identifier($value)
{
    return mb_strtolower(trim((string) $value), 'UTF-8');
}

/**
 * 进入耗时操作前的检查。命中阈值直接抛 429，并带上 Retry-After 告诉客户端等多久。
 * @param string $action     login / register / forgot
 * @param string $identifier 被尝试的账号标识
 */
function throttle_guard($action, $identifier)
{
    if (!cfg('throttle_enabled')) {
        return;
    }
    $identifier = throttle_identifier($identifier);
    $window = (int) cfg('throttle_window');

    $byUser = throttle_failures($action, 'identifier', $identifier, $window);
    if ($byUser['count'] >= (int) cfg('throttle_max_per_user')) {
        throttle_reject($byUser['oldest'], $window, '该账号');
    }

    $byIp = throttle_failures($action, 'ip', client_ip(), $window);
    if ($byIp['count'] >= (int) cfg('throttle_max_per_ip')) {
        throttle_reject($byIp['oldest'], $window, '当前网络');
    }
}

/**
 * 窗口内某个维度的失败次数与最早一次的时间。
 * @return array ['count'=>int, 'oldest'=>string|null]
 */
function throttle_failures($action, $column, $value, $window)
{
    if (!in_array($column, throttle_columns(), true)) {
        throw new RuntimeException('throttle_failures 收到非法列名：' . $column);
    }

    $sql = 'SELECT COUNT(*) AS failures, MIN(created_at) AS oldest
              FROM auth_attempts
             WHERE action = ?
               AND `' . $column . '` = ?
               AND succeeded = 0
               AND created_at >= NOW() - INTERVAL ? SECOND';
    $stmt = db()->prepare($sql);
    $stmt->execute([$action, $value, (int) $window]);
    $row = $stmt->fetch();

    return [
        'count' => (int) $row['failures'],
        'oldest' => $row['oldest'],
    ];
}

/** 记一次尝试结果。$succeeded 为 true 时也记一行，方便事后审计「谁在什么时候登录成功过」 */
function throttle_record($action, $identifier, $succeeded)
{
    if (!cfg('throttle_enabled')) {
        return;
    }
    $stmt = db()->prepare(
        'INSERT INTO auth_attempts (action, identifier, ip, succeeded) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$action, throttle_identifier($identifier), client_ip(), $succeeded ? 1 : 0]);
}

/** 清掉某个账号的失败记录（登录成功后调用） */
function throttle_clear($action, $identifier)
{
    $stmt = db()->prepare(
        'DELETE FROM auth_attempts WHERE action = ? AND identifier = ? AND succeeded = 0'
    );
    $stmt->execute([$action, throttle_identifier($identifier)]);
}

/** 被限流时的统一响应 */
function throttle_reject($oldestFailure, $window, $subject)
{
    $oldest = $oldestFailure === null ? time() : (int) strtotime($oldestFailure);
    $retryAfter = max(1, $oldest + (int) $window - time());

    // Retry-After 是标准头，爬虫和正经客户端都会遵守
    header('Retry-After: ' . $retryAfter);

    app_log('warning', '认证尝试被限流', [
        'subject' => $subject,
        'retry_after' => $retryAfter,
        'ip' => client_ip(),
    ]);

    throw new ApiException(
        sprintf('%s尝试次数过多，请 %d 分钟后再试', $subject, max(1, (int) ceil($retryAfter / 60))),
        429
    );
}

/** 清掉已经滑出窗口的记录，顺手在登录和开后台时调用 */
function purge_expired_attempts()
{
    // 保留两个窗口的量：既能满足限流统计，又不至于让表无限增长
    $keep = (int) cfg('throttle_window') * 2;
    return db()->exec('DELETE FROM auth_attempts WHERE created_at < NOW() - INTERVAL ' . $keep . ' SECOND');
}

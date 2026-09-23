<?php
/**
 * 一次性令牌：邮箱验证与密码重置共用同一套机制。
 *
 * 与登录会话同样的思路——邮件里发的是随机原文，库里只存它的 SHA-256 摘要。
 * 这样即使数据库整张表泄露，攻击者也无法用里面的记录去走验证 / 重置流程。
 *
 * 三条约束：
 *   1) 限时（邮箱验证 24 小时、密码重置 30 分钟，见 config.php）
 *   2) 一次性（用过就写 used_at，再用即失败）
 *   3) 同一用户同一用途只保留最新一条（发新邮件时旧链接立即作废）
 */

function token_purposes()
{
    return ['email_verify', 'password_reset'];
}

function token_hash($raw)
{
    return hash('sha256', $raw);
}

function token_assert_purpose($purpose)
{
    if (!in_array($purpose, token_purposes(), true)) {
        throw new RuntimeException('非法令牌用途：' . $purpose);
    }
}

/**
 * 签发一枚令牌。
 * @return string 令牌原文——只在这一刻存在于内存里，库里只有摘要
 */
function token_issue($userId, $purpose, $ttlSeconds)
{
    token_assert_purpose($purpose);

    $raw = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + max(60, (int) $ttlSeconds));

    // 先删旧的：用户连点两次「发送邮件」时，只有最新那封里的链接有效，
    // 而不是出现「点旧链接提示失效」这种让人困惑的情况。
    db()->prepare('DELETE FROM one_time_tokens WHERE user_id = ? AND purpose = ?')
        ->execute([(int) $userId, $purpose]);

    db()->prepare(
        'INSERT INTO one_time_tokens (token, user_id, purpose, expires_at) VALUES (?, ?, ?, ?)'
    )->execute([token_hash($raw), (int) $userId, $purpose, $expiresAt]);

    return $raw;
}

/**
 * 校验一枚令牌但不消费它。
 *
 * 单独拆出来是因为「密码重置」有个顺序问题：必须先用令牌找到用户，
 * 才能拿用户名去校验新密码——但校验失败不该把链接烧掉。
 * 所以流程是 peek（只看不标记）→ 校验 → mark_used（原子标记）。
 *
 * 任何不通过的情况都回同一句话——分别提示「不存在 / 已过期 / 用过了」
 * 会帮攻击者判断令牌是否曾经存在。
 *
 * @return array 令牌所属的用户行，附带 token_hash
 */
function token_peek($raw, $purpose)
{
    token_assert_purpose($purpose);

    $raw = trim((string) $raw);
    // 长度和字符集不对就不必查库了
    if (!preg_match('/^[0-9a-f]{64}$/', $raw)) {
        throw new ApiException('链接无效或已失效，请重新获取', 400);
    }

    $stmt = db()->prepare(
        'SELECT t.token, t.user_id, t.expires_at, t.used_at, u.username, u.role, u.email
           FROM one_time_tokens t
           JOIN users u ON u.id = t.user_id
          WHERE t.token = ? AND t.purpose = ?
          LIMIT 1'
    );
    $stmt->execute([token_hash($raw), $purpose]);
    $row = $stmt->fetch();

    if ($row === false) {
        throw new ApiException('链接无效或已失效，请重新获取', 400);
    }
    if ($row['used_at'] !== null) {
        throw new ApiException('链接无效或已失效，请重新获取', 400);
    }
    if (strtotime($row['expires_at']) < time()) {
        db()->prepare('DELETE FROM one_time_tokens WHERE token = ?')->execute([$row['token']]);
        throw new ApiException('链接无效或已失效，请重新获取', 400);
    }

    return $row;
}

/**
 * 原子地把令牌标记为已用。
 * 带 used_at IS NULL 条件去更新，并用影响行数判断是否抢到了这一枚：
 * 两个人同时点同一个链接时，只有一个能成功。
 *
 * @return bool 抢到返回 true
 */
function token_mark_used($tokenHash)
{
    $stmt = db()->prepare('UPDATE one_time_tokens SET used_at = NOW() WHERE token = ? AND used_at IS NULL');
    $stmt->execute([$tokenHash]);
    return $stmt->rowCount() === 1;
}

/**
 * 校验并立即消费一枚令牌（没有「中途还要做别的校验」的场合用它）。
 * @return array 令牌所属的用户行
 */
function token_consume($raw, $purpose)
{
    $row = token_peek($raw, $purpose);
    if (!token_mark_used($row['token'])) {
        throw new ApiException('链接无效或已失效，请重新获取', 400);
    }
    return $row;
}

/** 清掉过期令牌（登录、开后台、跑安装时顺手调用） */
function purge_expired_tokens()
{
    return db()->exec('DELETE FROM one_time_tokens WHERE expires_at < NOW()');
}

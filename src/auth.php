<?php
/**
 * 认证层：注册、登录、退出、会话校验、改密、邮箱验证、密码重置。
 *
 * 这是全站唯一有权决定「谁登录了」「是不是管理员」「能不能改这个账号」的地方。
 * 前端的任何显示/隐藏都只是体验，真正的门槛全在这里。
 *
 * 两个反复出现的设计：
 *   · 只存摘要 —— 登录令牌与一次性令牌，库里都只放 SHA-256 摘要，原文只在 Cookie / 邮件里
 *   · 失败信息不细化 —— 「用户名或密码错误」「链接无效或已失效」都是唯一的说法，
 *     分别提示会把「哪些账号存在」白送给攻击者
 */

/** 一个与真实账号无关的占位哈希，仅用于“用户不存在”时补一次校验耗时 */
const DUMMY_PASSWORD_HASH = '$2y$10$y99fvIBY6OdDsdZD1SYAMOH3fn.2zs5pqu0N7.IC.jbzti9Chp0zy';

/** 令牌摘要：数据库里只存这个摘要，不存 Cookie 里的令牌原文 */
function hash_login_token($raw)
{
    return hash('sha256', $raw);
}

/** 会话总时长（秒） */
function session_ttl()
{
    return (int) cfg('session_ttl_days') * 86400;
}

// ----------------------------------------------------------------
// 校验：前端也校验，是为了少发请求、体验好；后端再校验一次，
// 是因为任何人都能绕过前端直接调接口，只有后端的结论算数。
// ----------------------------------------------------------------

function validate_username($username)
{
    if ($username === '') {
        throw new ApiException('请填写用户名', 422);
    }
    $len = mb_strlen($username, 'UTF-8');
    if ($len < cfg('username_min') || $len > cfg('username_max')) {
        throw new ApiException(
            '用户名长度需为 ' . cfg('username_min') . '~' . cfg('username_max') . ' 个字符',
            422
        );
    }
    // 控制字符（换行、制表符等）会让后台表格和日志变得难以阅读
    if (preg_match('/[\x00-\x1F\x7F]/u', $username)) {
        throw new ApiException('用户名包含非法字符', 422);
    }
    return $username;
}

/**
 * 密码强度校验。
 * 除了长度，额外挡掉两类「看起来有密码、实际等于没有」的情况：
 * 密码就是用户名本身、以及纯数字。
 * @param string $username 传进来时会额外做「不能与用户名相同」的判断
 */
function validate_password($password, $username = '')
{
    // 长度用「字符数」而不是字节数：否则两个汉字就有 6 字节，足以骗过下限
    if (mb_strlen($password, 'UTF-8') < cfg('password_min')) {
        throw new ApiException('密码至少需要 ' . cfg('password_min') . ' 位', 422);
    }
    // bcrypt 只取前 72 个字节，后面的部分会被静默丢弃：
    // 与其让用户以为「273 位的密码更安全」，不如明确拒绝。
    if (strlen($password) > cfg('password_max_bytes')) {
        throw new ApiException('密码不能超过 ' . cfg('password_max_bytes') . ' 个字节', 422);
    }
    if ($username !== '' && mb_strtolower($password, 'UTF-8') === mb_strtolower($username, 'UTF-8')) {
        throw new ApiException('密码不能和用户名相同', 422);
    }
    if (preg_match('/^\d+$/', $password)) {
        throw new ApiException('密码不能是纯数字', 422);
    }
}

// ----------------------------------------------------------------
// 用户表读写
// ----------------------------------------------------------------

/** 用户行的统一取法，保证各处拿到的字段一致 */
const USER_FIELDS = 'id, username, password_hash, role, email, email_verified_at, created_at, last_login_at, login_count';

/** 按用户名取一行（排序规则是 utf8mb4_unicode_ci，所以大小写不敏感） */
function find_user($username)
{
    $stmt = db()->prepare('SELECT ' . USER_FIELDS . ' FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** 按邮箱取一行（邮箱在写入前已统一成小写） */
function find_user_by_email($email)
{
    $stmt = db()->prepare('SELECT ' . USER_FIELDS . ' FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function find_user_by_id($id)
{
    $stmt = db()->prepare('SELECT ' . USER_FIELDS . ' FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * 新建用户。$role 只允许安装脚本传 'admin'，注册接口固定传 'user'。
 * @return array 新用户行
 */
function create_user($username, $password, $role = 'user', $email = null)
{
    $username = validate_username($username);
    validate_password($password, $username);

    $stmt = db()->prepare(
        'INSERT INTO users (username, password_hash, role, email) VALUES (?, ?, ?, ?)'
    );
    try {
        // 参数绑定：用户名哪怕写成 ' OR 1=1 -- 也只是一个普通字符串，不会被当成 SQL 执行
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $email]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {          // 唯一键冲突
            // 靠约束里的索引名区分是用户名还是邮箱撞了，好给出准确的提示
            $message = $e->getMessage();
            if (strpos($message, 'uk_users_email') !== false) {
                throw new ApiException('该邮箱已被注册', 409);
            }
            throw new ApiException('该用户名已被注册', 409);
        }
        throw $e;
    }

    return find_user_by_id((int) db()->lastInsertId());
}

// ----------------------------------------------------------------
// 注册 / 登录 / 退出
// ----------------------------------------------------------------

/**
 * 注册并直接进入登录态（与原页面“注册成功即登录”的行为一致）。
 * 邮箱验证邮件不在这里发——注册必须成功，发信失败不能让整个注册回滚，
 * 由调用方（api.php）决定怎么把发信结果告诉前端。
 */
function register_user($username, $password, $email = null)
{
    $user = create_user($username, $password, 'user', $email);
    // 注册后马上签了会话，等于完成了第一次登录，统计口径与原页面保持一致
    db()->prepare('UPDATE users SET last_login_at = NOW(), login_count = login_count + 1 WHERE id = ?')
        ->execute([$user['id']]);
    start_session_for((int) $user['id']);
    return $user;
}

/**
 * 校验账号密码并签发会话。
 * 失败原因一律回“用户名或密码错误”——分别提示“用户不存在”会把
 * 哪些账号已注册泄露给攻击者。
 */
function login_user($username, $password)
{
    // 先看有没有被限流：撞密码的请求应该在查库之前就被挡掉
    throttle_guard('login', $username);

    $user = find_user($username);
    $hash = $user === null ? DUMMY_PASSWORD_HASH : $user['password_hash'];
    if ($user === null || !password_verify($password, $hash)) {
        throttle_record('login', $username, false);
        app_log('warning', '登录失败', ['username' => $username, 'ip' => client_ip()]);
        throw new ApiException('用户名或密码错误', 401);
    }

    throttle_record('login', $username, true);
    throttle_clear('login', $username);

    db()->prepare('UPDATE users SET last_login_at = NOW(), login_count = login_count + 1 WHERE id = ?')
        ->execute([$user['id']]);

    start_session_for((int) $user['id']);
    app_log('info', '登录成功', ['username' => $user['username'], 'ip' => client_ip()]);

    return $user;
}

/** 签发会话：生成随机令牌 → 库里存摘要 → Cookie 里放原文 */
function start_session_for($userId)
{
    $ttl = session_ttl();
    $raw = bin2hex(random_bytes(32));             // 密码学安全的随机令牌，48 字节熵
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

    $stmt = db()->prepare('INSERT INTO sessions (token, user_id, expires_at, ip) VALUES (?, ?, ?, ?)');
    $stmt->execute([hash_login_token($raw), $userId, $expiresAt, client_ip()]);

    enforce_session_limit((int) $userId, $raw);
    write_session_cookie($raw, $ttl);
}

/**
 * 每个账号最多同时保留几个登录设备，超出的踢掉最旧的。
 * 目的是限制「密码泄露后被长期潜伏」：受害者改密码或重新登录时，
 * 攻击者塞进来的一堆会话不会无限期堆在那里。
 *
 * 排序用自增 id 而不是 created_at：created_at 只精确到秒，
 * 同一秒内连续几次登录排不出先后，「最旧的那个」会变成随机挑一个。
 * 另外明确排除刚签发的这一枚，避免任何意料之外的顺序问题把它挤掉。
 */
function enforce_session_limit($userId, $keepRawToken)
{
    $max = max(1, (int) cfg('max_sessions_per_user'));

    // 子查询需要裹一层派生表：MySQL 不允许在 DELETE 的子查询里直接读同一张表
    $sql = 'DELETE FROM sessions
             WHERE user_id = ?
               AND token <> ?
               AND token NOT IN (
                   SELECT token FROM (
                       SELECT token FROM sessions
                        WHERE user_id = ?
                        ORDER BY id DESC
                        LIMIT ' . $max . '
                   ) AS keep_rows
               )';
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId, hash_login_token($keepRawToken), $userId]);
    $evicted = $stmt->rowCount();
    if ($evicted > 0) {
        app_log('info', '会话数超出上限，已踢掉最旧的登录', ['user_id' => $userId, 'evicted' => $evicted]);
    }
    return $evicted;
}

/** 退出：删掉当前这一条会话（其它设备上的会话不受影响） */
function logout_current_session()
{
    $raw = current_token_raw();
    if ($raw !== null) {
        db()->prepare('DELETE FROM sessions WHERE token = ?')->execute([hash_login_token($raw)]);
    }
    write_session_cookie('', -86400);
}

function current_token_raw()
{
    $name = cfg('cookie_name');
    if (!isset($_COOKIE[$name])) {
        return null;
    }
    $raw = trim((string) $_COOKIE[$name]);
    // 长度与字符集都不对就不必查库了
    return preg_match('/^[0-9a-f]{64}$/', $raw) ? $raw : null;
}

function write_session_cookie($raw, $ttl)
{
    setcookie(cfg('cookie_name'), $raw, [
        'expires' => time() + $ttl,
        'path' => '/',
        'httponly' => true,          // 页面脚本读不到，被注入 XSS 时也带不走登录令牌
        'secure' => (bool) cfg('cookie_secure'),   // 上线 HTTPS 后在配置里打开
        'samesite' => 'Lax',         // 第三方页面发起的请求不带这个 Cookie
    ]);
}

// ----------------------------------------------------------------
// 会话撤销
// ----------------------------------------------------------------

/** 撤销某用户的全部会话，返回撤销条数 */
function revoke_all_sessions($userId)
{
    $stmt = db()->prepare('DELETE FROM sessions WHERE user_id = ?');
    $stmt->execute([(int) $userId]);
    return $stmt->rowCount();
}

/**
 * 撤销「除当前这一台以外」的所有会话。
 * 改密码后调用：攻击者可能已经拿着旧密码在别的设备登录了，
 * 改密必须把那边的登录一起废掉，否则改密码等于只改了个寂寞。
 */
function revoke_other_sessions($userId, $keepRawToken)
{
    if ($keepRawToken === null) {
        return revoke_all_sessions($userId);
    }
    $stmt = db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token <> ?');
    $stmt->execute([(int) $userId, hash_login_token($keepRawToken)]);
    return $stmt->rowCount();
}

/** 某用户当前有效的会话数 */
function active_session_count($userId)
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM sessions WHERE user_id = ? AND expires_at > NOW()'
    );
    $stmt->execute([(int) $userId]);
    return (int) $stmt->fetchColumn();
}

// ----------------------------------------------------------------
// “现在是谁”
// ----------------------------------------------------------------

/**
 * 当前登录用户；未登录返回 null。
 * 一个请求内只查一次库（结果缓存在静态变量里）。
 * @return array|null
 */
function current_user()
{
    static $resolved = false;
    static $user = null;
    if ($resolved) {
        return $user;
    }
    $resolved = true;

    $raw = current_token_raw();
    if ($raw === null) {
        return $user = null;
    }

    $stmt = db()->prepare(
        'SELECT u.id, u.username, u.role, u.email, u.email_verified_at, u.created_at, s.expires_at
           FROM sessions s JOIN users u ON u.id = s.user_id
          WHERE s.token = ? LIMIT 1'
    );
    $stmt->execute([hash_login_token($raw)]);
    $row = $stmt->fetch();
    if ($row === false) {
        return $user = null;                 // 令牌无效（已退出或被删除）
    }
    if (strtotime($row['expires_at']) < time()) {
        db()->prepare('DELETE FROM sessions WHERE token = ?')->execute([hash_login_token($raw)]);
        return $user = null;                 // 已过期，顺手清掉
    }

    // 滑动续期：剩余寿命不足一半时悄悄延长，常用的人就不会被半路踢下线
    if (strtotime($row['expires_at']) - time() < session_ttl() / 2) {
        $expiresAt = date('Y-m-d H:i:s', time() + session_ttl());
        db()->prepare('UPDATE sessions SET expires_at = ? WHERE token = ?')
            ->execute([$expiresAt, hash_login_token($raw)]);
        write_session_cookie($raw, session_ttl());
    }

    return $user = [
        'id' => (int) $row['id'],
        'username' => $row['username'],
        'role' => $row['role'],
        'email' => $row['email'],
        'email_verified_at' => $row['email_verified_at'],
        'created_at' => $row['created_at'],
    ];
}

/** 必须登录，否则 401 */
function require_login()
{
    $user = current_user();
    if ($user === null) {
        throw new ApiException('请先登录', 401);
    }
    return $user;
}

/** 必须是管理员，否则 401（未登录）或 403（登录了但权限不够） */
function require_admin()
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        throw new ApiException('需要管理员权限', 403);
    }
    return $user;
}

/** 清掉所有过期会话（登录和打开后台时顺手做一次，成本很低） */
function purge_expired_sessions()
{
    return db()->exec('DELETE FROM sessions WHERE expires_at < NOW()');
}

// ----------------------------------------------------------------
// 修改密码
// ----------------------------------------------------------------

/**
 * 改密码。三条约束：
 *   · 必须知道当前密码（防止有人趁你没锁屏，用你的登录态把密码改掉）
 *   · 新密码要过强度校验
 *   · 改完撤销其它所有会话，并重置失败计数
 *
 * 校验当前密码这一步也走限流：否则它就成了「不用登录也能撞密码」的入口。
 */
function change_password(array $user, $currentPassword, $newPassword)
{
    throttle_guard('password', $user['username']);

    $row = find_user($user['username']);
    if ($row === null || !password_verify($currentPassword, $row['password_hash'])) {
        throttle_record('password', $user['username'], false);
        app_log('warning', '改密失败：当前密码不正确', ['username' => $user['username'], 'ip' => client_ip()]);
        throw new ApiException('当前密码不正确', 401);
    }

    validate_password($newPassword, $user['username']);
    if (password_verify($newPassword, $row['password_hash'])) {
        throw new ApiException('新密码不能和当前密码相同', 422);
    }

    db()->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]);

    throttle_clear('password', $user['username']);
    $revoked = revoke_other_sessions((int) $user['id'], current_token_raw());
    app_log('info', '密码已修改', ['username' => $user['username'], 'revoked_sessions' => $revoked]);

    return ['revokedSessions' => $revoked];
}

// ----------------------------------------------------------------
// 邮箱验证
// ----------------------------------------------------------------

/**
 * 给用户发一封验证邮件。
 * 返回 false 而不是抛异常：注册流程不能因为发信失败就整体失败，
 * 用户已经建好了，顶多是稍后自己点「重发验证邮件」。
 */
function send_verification_mail(array $user)
{
    if (empty($user['email'])) {
        return false;
    }
    try {
        $ttlHours = (int) cfg('verify_ttl_hours');
        $raw = token_issue((int) $user['id'], 'email_verify', $ttlHours * 3600);
        $template = mail_template_verify($user['username'], absolute_url('/?verify=' . $raw), $ttlHours);
        send_mail($user['email'], $template['subject'], $template['body']);
        app_log('info', '验证邮件已发送', ['username' => $user['username']]);
        return true;
    } catch (Throwable $e) {
        app_log('error', '验证邮件发送失败', [
            'username' => $user['username'],
            'error' => $e->getMessage(),
        ]);
        return false;
    }
}

/** 消费验证令牌并标记邮箱已验证 */
function verify_email($rawToken)
{
    $row = token_consume($rawToken, 'email_verify');
    db()->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')
        ->execute([(int) $row['user_id']]);
    app_log('info', '邮箱验证通过', ['username' => $row['username']]);
    return $row;
}

/** 重发验证邮件（需要登录态，避免被当成垃圾邮件发送器） */
function resend_verification(array $user)
{
    if (empty($user['email'])) {
        throw new ApiException('这个账号还没有绑定邮箱，请先在个人中心填写', 422);
    }
    if (!empty($user['email_verified_at'])) {
        throw new ApiException('邮箱已经验证过了，无需重复验证', 409);
    }
    if (!send_verification_mail($user)) {
        throw new ApiException('验证邮件发送失败，请稍后再试', 502);
    }
    return true;
}

// ----------------------------------------------------------------
// 密码重置
// ----------------------------------------------------------------

/**
 * 发起密码重置。
 *
 * 无论邮箱是否注册过，接口的回答都完全一样（调用方固定回同一句话）。
 * 否则这个接口就变成了「查询某邮箱是否在本站注册过」的探测工具。
 * 所以这里返回布尔值只用于写日志，不用于生成响应。
 */
function request_password_reset($email)
{
    $email = mb_strtolower(trim((string) $email), 'UTF-8');
    $user = find_user_by_email($email);

    if ($user === null) {
        app_log('info', '密码重置请求：该邮箱未注册', ['ip' => client_ip()]);
        return false;
    }

    try {
        $ttlMinutes = (int) cfg('reset_ttl_minutes');
        $raw = token_issue((int) $user['id'], 'password_reset', $ttlMinutes * 60);
        $template = mail_template_reset($user['username'], absolute_url('/?reset=' . $raw), $ttlMinutes);
        send_mail($email, $template['subject'], $template['body']);
        app_log('info', '密码重置邮件已发送', ['username' => $user['username']]);
        return true;
    } catch (Throwable $e) {
        app_log('error', '密码重置邮件发送失败', ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * 用一次性令牌设置新密码。
 * 改完撤销该用户全部会话——能拿到重置链接的人就是账号的主人，
 * 之前所有设备的登录状态都该作废（其中可能就有攻击者的那一个）。
 */
function reset_password($rawToken, $newPassword)
{
    // 先只看不消费：密码强度不过关时不该把链接作废，
    // 否则用户输了个太短的密码，就得重新去收一封新邮件。
    $row = token_peek($rawToken, 'password_reset');
    validate_password($newPassword, $row['username']);

    if (!token_mark_used($row['token'])) {
        throw new ApiException('链接无效或已失效，请重新获取', 400);
    }

    db()->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $row['user_id']]);

    $revoked = revoke_all_sessions((int) $row['user_id']);
    throttle_clear('login', $row['username']);
    throttle_clear('password', $row['username']);
    app_log('info', '密码已通过重置链接更新', ['username' => $row['username'], 'revoked_sessions' => $revoked]);

    return ['revokedSessions' => $revoked];
}

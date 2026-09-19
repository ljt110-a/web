<?php
/**
 * 认证层：注册、登录、退出、会话校验、角色判断。
 *
 * 这是全站唯一有权决定“谁登录了”“是不是管理员”的地方。
 * 前端的任何显示/隐藏都只是体验，真正的门槛全在这里。
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
        throw new ApiException('用户名长度需为 2~20 个字符', 422);
    }
    // 控制字符（换行、制表符等）会让后台表格和日志变得难以阅读
    if (preg_match('/[\x00-\x1F\x7F]/u', $username)) {
        throw new ApiException('用户名包含非法字符', 422);
    }
    return $username;
}

function validate_password($password)
{
    if (strlen($password) < cfg('password_min')) {
        throw new ApiException('密码至少需要 ' . cfg('password_min') . ' 位', 422);
    }
    // bcrypt 只取前 72 个字节，后面的部分会被静默丢弃：
    // 与其让用户以为“273 位的密码更安全”，不如明确拒绝。
    if (strlen($password) > 72) {
        throw new ApiException('密码不能超过 72 个字节', 422);
    }
}

// ----------------------------------------------------------------
// 用户表读写
// ----------------------------------------------------------------

/** 按用户名取一行（排序规则是 utf8mb4_unicode_ci，所以大小写不敏感） */
function find_user($username)
{
    $stmt = db()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * 新建用户。$role 只允许安装脚本传 'admin'，注册接口固定传 'user'。
 * @return array ['id' => int, 'username' => string, 'role' => string]
 */
function create_user($username, $password, $role = 'user')
{
    $username = validate_username($username);
    validate_password($password);

    $stmt = db()->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
    try {
        // 参数绑定：用户名哪怕写成 ' OR 1=1 -- 也只是一个普通字符串，不会被当成 SQL 执行
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {          // 唯一键冲突
            throw new ApiException('该用户名已被注册', 409);
        }
        throw $e;
    }

    return [
        'id' => (int) db()->lastInsertId(),
        'username' => $username,
        'role' => $role,
    ];
}

// ----------------------------------------------------------------
// 注册 / 登录 / 退出
// ----------------------------------------------------------------

/** 注册并直接进入登录态（与原页面“注册成功即登录”的行为一致） */
function register_user($username, $password)
{
    $user = create_user($username, $password, 'user');
    // 注册后马上签了会话，等于完成了第一次登录，统计口径与原页面保持一致
    db()->prepare('UPDATE users SET last_login_at = NOW(), login_count = login_count + 1 WHERE id = ?')
        ->execute([$user['id']]);
    start_session_for($user['id']);
    return $user;
}

/**
 * 校验账号密码并签发会话。
 * 失败原因一律回“用户名或密码错误”——分别提示“用户不存在”会把
 * 哪些账号已注册泄露给攻击者。
 */
function login_user($username, $password)
{
    $user = find_user($username);
    $hash = $user === null ? DUMMY_PASSWORD_HASH : $user['password_hash'];
    if (!password_verify($password, $hash) || $user === null) {
        throw new ApiException('用户名或密码错误', 401);
    }

    db()->prepare('UPDATE users SET last_login_at = NOW(), login_count = login_count + 1 WHERE id = ?')
        ->execute([$user['id']]);

    start_session_for((int) $user['id']);
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

    write_session_cookie($raw, $ttl);
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
// “现在是谁”
// ----------------------------------------------------------------

/**
 * 当前登录用户；未登录返回 null。
 * 一个请求内只查一次库（结果缓存在静态变量里）。
 * @return array|null ['id'=>int,'username'=>string,'role'=>string]
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
        'SELECT u.id, u.username, u.role, s.expires_at
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
    db()->exec('DELETE FROM sessions WHERE expires_at < NOW()');
}

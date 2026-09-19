<?php
/**
 * 接口路由：把 /api/* 的请求分发到对应处理函数。
 *
 * 约定
 *  - 成功：HTTP 2xx + 业务数据
 *  - 失败：4xx/5xx + { "error": "一句能直接显示给用户的话" }
 *  - 所有写操作（POST / DELETE）都要求 application/json，见 http.php 里 body_json() 的说明
 */

require __DIR__ . '/bootstrap.php';

$dispatchPath = rtrim(request_path(), '/');
if ($dispatchPath === '') {
    $dispatchPath = '/api';
}
$method = request_method();

$routes = [
    'GET /api/health' => 'api_health',
    'POST /api/register' => 'api_register',
    'POST /api/login' => 'api_login',
    'POST /api/logout' => 'api_logout',
    'GET /api/me' => 'api_me',
    'POST /api/visit' => 'api_visit',
    'GET /api/admin/users' => 'api_admin_users',
    'DELETE /api/admin/users' => 'api_admin_user_delete',
];

// “路径存在、方法不对”要回 405，而不是和“路径不存在”混成 404
$knownPaths = [];
foreach (array_keys($routes) as $route) {
    $knownPaths[substr($route, strpos($route, ' ') + 1)] = true;
}

try {
    if (isset($routes[$method . ' ' . $dispatchPath])) {
        $handler = $routes[$method . ' ' . $dispatchPath];
        list($status, $payload) = $handler();
        json_out($payload, $status);
        return;
    }
    if (isset($knownPaths[$dispatchPath])) {
        json_out(['error' => '该接口不支持 ' . $method . ' 方法'], 405);
        return;
    }
    json_out(['error' => '接口不存在'], 404);
} catch (ApiException $e) {
    // 可预期的业务失败：直接把原因告诉前端
    json_out(['error' => $e->getMessage()], $e->status());
} catch (RuntimeException $e) {
    // 数据库连接失败 / 未安装：db.php 已经把原因翻译成可操作的提示
    json_out(['error' => $e->getMessage()], 503);
} catch (Throwable $e) {
    error_log('[web-one] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    // 未预期的错误：默认只回一句笼统的话，避免把 SQL 语句、路径等内部信息吐到浏览器
    json_out([
        'error' => cfg('debug') ? ('服务器内部错误：' . get_class($e) . ' - ' . $e->getMessage()) : '服务器内部错误，请稍后再试',
    ], 500);
}

// ============================================================
// 各接口的处理函数：统一返回 [HTTP 状态码, 响应数据]
// ============================================================

/** GET /api/health —— 自检：服务和数据库是否连通 */
function api_health()
{
    try {
        db()->query('SELECT 1');
    } catch (Throwable $e) {
        return [503, ['status' => 'database-unreachable', 'error' => $e->getMessage()]];
    }
    return [200, [
        'status' => 'ok',
        'php' => PHP_VERSION,
        'database' => cfg('db_name'),
        'time' => date('Y-m-d H:i:s'),
    ]];
}

/** POST /api/register {username,password} —— 注册即登录 */
function api_register()
{
    $body = body_json();
    $user = register_user(body_string($body, 'username'), body_password($body, 'password'));
    return [201, public_user($user)];
}

/** POST /api/login {username,password} */
function api_login()
{
    $body = body_json();
    purge_expired_sessions();
    $user = login_user(body_string($body, 'username'), body_password($body, 'password'));
    return [200, public_user($user)];
}

/** POST /api/logout —— 未登录也回成功，退出本来就是幂等的 */
function api_logout()
{
    body_json();
    logout_current_session();
    return [200, ['logged' => false]];
}

/** GET /api/me —— 页面加载时问一次“我是谁”，前端据此显示导航栏 */
function api_me()
{
    $user = current_user();
    if ($user === null) {
        return [200, ['logged' => false]];
    }
    return [200, public_user($user)];
}

/** POST /api/visit —— 记录一次访问并返回最新计数（页脚用） */
function api_visit()
{
    body_json();
    return [200, record_visit()];
}

/** GET /api/admin/users —— 后台面板数据（仅管理员） */
function api_admin_users()
{
    require_admin();
    return [200, admin_overview()];
}

/** DELETE /api/admin/users {username} —— 删除用户（仅管理员） */
function api_admin_user_delete()
{
    $actor = require_admin();
    $result = admin_delete_user(body_string(body_json(), 'username'), $actor);
    return [200, $result + admin_overview()];
}

/**
 * 给用户/接口返回的“安全视图”：白名单字段，
 * 这样以后 users 表加了敏感列，也不会被顺手吐到前端。
 */
function public_user(array $user)
{
    return [
        'logged' => true,
        'username' => $user['username'],
        'role' => $user['role'],
    ];
}

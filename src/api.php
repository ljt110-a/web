<?php
/**
 * 接口路由：把 /api/* 的请求分发到对应处理函数。
 *
 * 约定
 *  - 成功：HTTP 2xx + 业务数据
 *  - 失败：4xx/5xx + { "error": "一句能直接显示给用户的话" }
 *  - 所有写操作（POST / DELETE）都要求 application/json，见 http.php 里 body_json() 的说明
 *  - 每个响应都会带上安全响应头，见 security.php 的 security_headers()
 */

require_once __DIR__ . '/bootstrap.php';

$dispatchPath = rtrim(request_path(), '/');
if ($dispatchPath === '') {
    $dispatchPath = '/api';
}
$method = request_method();

// 每个响应都带安全头。放在分发之前，这样即使中途抛异常也照样带上。
send_security_headers();

if (cfg('log_requests')) {
    app_log('info', '请求', ['method' => $method, 'path' => $dispatchPath, 'ip' => client_ip()]);
}

$routes = [
    'GET /api/health' => 'api_health',
    'POST /api/register' => 'api_register',
    'POST /api/login' => 'api_login',
    'POST /api/logout' => 'api_logout',
    'GET /api/me' => 'api_me',
    'POST /api/visit' => 'api_visit',
    'POST /api/password' => 'api_password',
    'POST /api/forgot' => 'api_forgot',
    'POST /api/reset' => 'api_reset',
    'POST /api/email/verify' => 'api_email_verify',
    'POST /api/email/resend' => 'api_email_resend',
    'GET /api/messages' => 'api_messages_list',
    'POST /api/messages' => 'api_message_create',
    'DELETE /api/messages' => 'api_message_delete',
    'GET /api/memos' => 'api_memos_list',
    'POST /api/memos' => 'api_memo_create',
    'POST /api/memos/update' => 'api_memo_update',
    'DELETE /api/memos' => 'api_memo_delete',
    'GET /api/games' => 'api_games_list',
    'GET /api/study' => 'api_study_view',
    'POST /api/study/pomodoro' => 'api_pomodoro_create',
    'DELETE /api/study/pomodoro' => 'api_pomodoro_delete',
    'GET /api/novels' => 'api_novels_shelf',
    'POST /api/novels' => 'api_novel_import',
    'POST /api/novels/update' => 'api_novel_update',
    'DELETE /api/novels' => 'api_novel_delete',
    'GET /api/novel' => 'api_novel_open',
    'GET /api/novel/chapter' => 'api_novel_chapter',
    'POST /api/novel/progress' => 'api_novel_progress',
    'GET /api/software' => 'api_software_list',
    'GET /api/software/file' => 'api_software_file',
    'GET /api/software/icon' => 'api_software_icon',
    'GET /api/admin/games' => 'api_admin_games_list',
    'POST /api/admin/games' => 'api_admin_game_create',
    'POST /api/admin/games/update' => 'api_admin_game_update',
    'DELETE /api/admin/games' => 'api_admin_game_delete',
    'GET /api/admin/software' => 'api_admin_software_list',
    'POST /api/admin/software' => 'api_admin_software_create',
    'POST /api/admin/software/update' => 'api_admin_software_update',
    'DELETE /api/admin/software' => 'api_admin_software_delete',
    'POST /api/admin/software/grab' => 'api_admin_software_grab',
    'POST /api/admin/software/release' => 'api_admin_software_release',
    'POST /api/admin/software/identify' => 'api_admin_software_identify',
    'POST /api/admin/software/icon' => 'api_admin_software_icon_upload',
    'POST /api/admin/software/icon/fetch' => 'api_admin_software_icon_fetch',
    'DELETE /api/admin/software/icon' => 'api_admin_software_icon_clear',
    'GET /api/admin/users' => 'api_admin_users',
    'DELETE /api/admin/users' => 'api_admin_user_delete',
    'POST /api/admin/reset-password' => 'api_admin_reset_password',
    'GET /api/admin/system' => 'api_admin_system',
];

// “路径存在、方法不对”要回 405，而不是和“路径不存在”混成 404
$knownPaths = [];
foreach (array_keys($routes) as $route) {
    $knownPaths[substr($route, strpos($route, ' ') + 1)] = true;
}

// 会改动数据的请求，先确认来源是同站（CSRF 的第二道防线，详见 security.php）
$writeMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

try {
    if (in_array($method, $writeMethods, true)) {
        assert_same_origin();
    }

    if (isset($routes[$method . ' ' . $dispatchPath])) {
        $handler = $routes[$method . ' ' . $dispatchPath];
        list($status, $payload) = $handler();
        // 绝大多数接口回的是 JSON。软件仓库的安装包与图标是文件本身，
        // 这类处理函数返回 ['file' => [...]] 这个形状，分发层认它并原样流出去。
        // 只认这一个额外的键，别的键名一律照旧走 JSON，不会出现「拼错键名把文件当 JSON 发」。
        if (is_array($payload) && count($payload) === 1 && isset($payload['file']) && is_array($payload['file'])) {
            stream_file_response($payload['file']);
            return;
        }
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
    if ($e->status() >= 500) {
        app_log('error', '业务异常', ['message' => $e->getMessage(), 'status' => $e->status()]);
    }
    json_out(['error' => $e->getMessage()], $e->status());
} catch (RuntimeException $e) {
    // 数据库连接失败 / 未安装：db.php 已经把原因翻译成可操作的提示
    json_out(['error' => $e->getMessage()], 503);
} catch (Throwable $e) {
    error_log('[web-one] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    app_log('error', '未预期的错误', [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
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
        'env' => cfg('env'),
        'time' => date('Y-m-d H:i:s'),
    ]];
}

/**
 * POST /api/register {username,password,email?} —— 注册即登录
 * 填了邮箱会顺带发一封验证邮件；发信失败不影响注册结果，
 * 只把 verificationMailSent 置为 false，让前端提示用户稍后重发。
 */
function api_register()
{
    $body = body_json();
    $username = body_string($body, 'username');
    $email = body_email($body, 'email', false);

    throttle_guard('register', $username);
    try {
        $user = register_user($username, body_password($body, 'password'), $email);
    } catch (ApiException $e) {
        throttle_record('register', $username, false);
        throw $e;
    }
    throttle_record('register', $username, true);
    throttle_clear('register', $username);

    $payload = public_user($user);
    if ($email !== null) {
        $payload['verificationMailSent'] = send_verification_mail($user);
    }
    return [201, $payload];
}

/** POST /api/login {username,password} */
function api_login()
{
    $body = body_json();
    purge_expired_sessions();
    purge_expired_attempts();
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
    $stats = record_visit();

    // 页面加载是最真实的流量来源，顺手记一条轻量资源采样。
    // 内部先判断「到没到采样间隔」再采集，所以绝大多数请求只多了一次文件 mtime 判断。
    system_record_visit_sample();

    return [200, $stats];
}

/** POST /api/password {currentPassword,newPassword} —— 登录状态下修改自己的密码 */
function api_password()
{
    $body = body_json();
    $user = require_login();
    $result = change_password(
        $user,
        body_password($body, 'currentPassword'),
        body_password($body, 'newPassword')
    );
    return [200, ['changed' => true] + $result];
}

/**
 * POST /api/forgot {email} —— 发密码重置邮件。
 * 无论邮箱是否存在，响应都完全一致，避免这个接口被用来探测账号。
 */
function api_forgot()
{
    $body = body_json();
    $email = body_email($body, 'email', true);

    throttle_guard('forgot', $email);
    $sent = request_password_reset($email);
    throttle_record('forgot', $email, $sent);

    return [200, [
        'ok' => true,
        'message' => '如果这个邮箱已经注册，重置链接已经发出，请查收（也看一眼垃圾邮件箱）',
    ]];
}

/** POST /api/reset {token,password} —— 用邮件里的令牌设置新密码 */
function api_reset()
{
    $body = body_json();
    $result = reset_password(body_string($body, 'token'), body_password($body, 'password'));
    return [200, ['reset' => true] + $result];
}

/** POST /api/email/verify {token} —— 用邮件里的令牌完成邮箱验证 */
function api_email_verify()
{
    $body = body_json();
    $row = verify_email(body_string($body, 'token'));
    return [200, ['verified' => true, 'email' => $row['email']]];
}

/** POST /api/email/resend —— 重发邮箱验证邮件（需要登录，避免被当成群发工具） */
function api_email_resend()
{
    body_json();
    $user = require_login();
    resend_verification($user);
    return [200, ['sent' => true]];
}

// ============================================================
// 留言板：读公开、写要登录
// ============================================================

/** GET /api/messages?page=&perPage= —— 留言列表，游客也能看 */
function api_messages_list()
{
    list($minPerPage, $maxPerPage) = message_page_bounds();
    return [200, message_list(
        query_int('page', 1, 1, 100000),
        query_int('perPage', (int) cfg('message_page_size'), $minPerPage, $maxPerPage),
        current_user()
    )];
}

/** POST /api/messages {body} —— 发一条留言 */
function api_message_create()
{
    $body = body_json();
    $user = require_login();
    return [201, ['message' => message_create($user, body_string($body, 'body'))]];
}

/** DELETE /api/messages {id} —— 作者本人或管理员可删 */
function api_message_delete()
{
    $body = body_json();
    $actor = require_login();
    $id = body_int($body, 'id', 0, 0, 2147483647);
    if ($id <= 0) {
        throw new ApiException('缺少留言 id', 422);
    }
    return [200, message_delete($id, $actor)];
}

// ============================================================
// 备忘录：全部需要登录，且只能操作自己的
// ============================================================

/** GET /api/memos?filter=all|open|done */
function api_memos_list()
{
    $user = require_login();
    return [200, memo_list((int) $user['id'], query_string('filter', 10))];
}

/** POST /api/memos {title,body} —— 新建 */
function api_memo_create()
{
    $body = body_json();
    $user = require_login();
    return [201, ['memo' => memo_create(
        (int) $user['id'],
        body_string($body, 'title'),
        body_string($body, 'body')
    )]];
}

/**
 * POST /api/memos/update {id,title?,body?,done?} —— 部分更新。
 * 只把请求里出现过的字段交给 memo_update()，没出现的保持原值。
 */
function api_memo_update()
{
    $body = body_json();
    $user = require_login();
    $id = body_int($body, 'id', 0, 0, 2147483647);
    if ($id <= 0) {
        throw new ApiException('缺少备忘录 id', 422);
    }

    $fields = [];
    if (array_key_exists('title', $body)) {
        $fields['title'] = $body['title'];
    }
    if (array_key_exists('body', $body)) {
        $fields['body'] = $body['body'];
    }
    if (array_key_exists('done', $body)) {
        $fields['done'] = (bool) $body['done'];
    }

    return [200, ['memo' => memo_update((int) $user['id'], $id, $fields)]];
}

/** DELETE /api/memos {id} */
function api_memo_delete()
{
    $body = body_json();
    $user = require_login();
    $id = body_int($body, 'id', 0, 0, 2147483647);
    if ($id <= 0) {
        throw new ApiException('缺少备忘录 id', 422);
    }
    return [200, memo_delete((int) $user['id'], $id)];
}

// ============================================================
// 游戏板块：读公开、增删改要管理员
// ============================================================

/**
 * GET /api/games —— 前台列表，**永远只含已上架的**。
 *
 * 这里刻意传 null 而不是 current_user()：公开接口的结果不该随调用者身份变化。
 * 否则同一个地址，管理员带 Cookie 访问会多出「已下架」的条目——
 * 这既不符合接口名的语义，也让缓存、排障和测试都变得难以预期。
 * 管理员要看全部（含下架）用 GET /api/admin/games。
 */
function api_games_list()
{
    return [200, game_list(null)];
}

/** GET /api/admin/games —— 管理员列表，含已下架的 */
function api_admin_games_list()
{
    $actor = require_admin();
    return [200, game_list($actor)];
}

/** POST /api/admin/games {name,url,...} —— 这就是「预留的添加接口」 */
function api_admin_game_create()
{
    $actor = require_admin();
    $body = body_json();
    return [201, ['game' => game_create($actor, $body), 'list' => game_list($actor)]];
}

/** POST /api/admin/games/update {id,...} —— 部分更新，也用来上下架 */
function api_admin_game_update()
{
    $actor = require_admin();
    $body = body_json();
    $id = body_int($body, 'id', 0, 0, 2147483647);
    if ($id <= 0) {
        throw new ApiException('缺少游戏 id', 422);
    }

    // 只把请求里出现过的字段交下去，没出现的保持原值
    $editable = ['name', 'slug', 'publisher', 'genre', 'description', 'url', 'icon', 'sortOrder', 'enabled'];
    $fields = [];
    foreach ($editable as $key) {
        if (array_key_exists($key, $body)) {
            $fields[$key] = $key === 'enabled' ? (bool) $body[$key] : $body[$key];
        }
    }

    return [200, ['game' => game_update($actor, $id, $fields), 'list' => game_list($actor)]];
}

/** DELETE /api/admin/games {id} */
function api_admin_game_delete()
{
    $actor = require_admin();
    $body = body_json();
    $id = body_int($body, 'id', 0, 0, 2147483647);
    if ($id <= 0) {
        throw new ApiException('缺少游戏 id', 422);
    }

    $result = game_delete($actor, $id);
    return [200, $result + ['list' => game_list($actor)]];
}

// ============================================================
// 学习板块：番茄钟记录，读自己的、写自己的
// ============================================================

/**
 * GET /api/study —— 学习板块要显示的全部内容。
 * 未登录也能访问：这时只回默认时长等常量，items 与统计都是空的。
 * 计时器本身不需要账号，「把记录存下来」才需要。
 */
function api_study_view()
{
    $user = current_user();
    return [200, pomodoro_view($user === null ? null : (int) $user['id'])];
}

/** POST /api/study/pomodoro {subject,minutes,elapsed,finished} —— 记一轮 */
function api_pomodoro_create()
{
    $body = body_json();
    $user = require_login();
    // 这里刻意用「整个整数范围」取数，不在接口层夹到 1~180：
    // 一夹就把越界变成合法值（181 变 180），校验器再也看不到真正传进来的是什么。
    // 范围判断全部交给 validate_pomodoro_minutes / _elapsed，它们会明确报 422。
    return [201, ['pomodoro' => pomodoro_create(
        (int) $user['id'],
        body_string($body, 'subject'),
        body_int($body, 'minutes', 0, 0, 2147483647),
        body_int($body, 'elapsed', 0, 0, 2147483647),
        !empty($body['finished'])
    )]];
}

/** DELETE /api/study/pomodoro {id} */
function api_pomodoro_delete()
{
    $body = body_json();
    $user = require_login();
    $id = body_int($body, 'id', 0, 0, 2147483647);
    if ($id <= 0) {
        throw new ApiException('缺少记录 id', 422);
    }
    $result = pomodoro_delete((int) $user['id'], $id);
    return [200, $result + ['stats' => pomodoro_stats((int) $user['id'])]];
}

// ============================================================
// 小说阅读：私有数据，读写都要登录，且只能碰自己书架上的那几本
// ============================================================

/**
 * GET /api/novels —— 书架：这个账号导入的书，按最近阅读倒序。
 * 顺带把字数上下限之类都返回，前端才能设 maxlength 和提示文案，
 * 不必在 JS 里抄一份常量（抄的那份迟早和后端不一致）。
 */
function api_novels_shelf()
{
    $user = require_login();
    return [200, novel_shelf((int) $user['id'])];
}

/** POST /api/novels {title?, text, filename?} —— 送一段正文进来，认成一本书存下来 */
function api_novel_import()
{
    $body = body_json();
    $user = require_login();
    // 正文刻意不走 body_string：它会顺手 trim，而这里首尾空行本来就由规范化负责；
    // 送进来的是不是字符串，交给 novel_normalize_text 统一处理
    $text = isset($body['text']) && is_scalar($body['text']) ? (string) $body['text'] : '';
    // filename 只是书名的兜底（用户没填、正文里也猜不出时才用），不参与任何内容判断
    return [201, novel_import(
        (int) $user['id'],
        body_string($body, 'title'),
        $text,
        body_string($body, 'filename')
    )];
}

/** POST /api/novels/update {id,title} —— 目前只允许改书名（正文与分章结果不改） */
function api_novel_update()
{
    $body = body_json();
    $user = require_login();
    $id = body_int($body, 'id', 0, 0, 2147483647);
    if ($id <= 0) {
        throw new ApiException('缺少书的 id', 422);
    }
    if (!array_key_exists('title', $body)) {
        throw new ApiException('没有需要修改的内容', 422);
    }
    return [200, novel_rename((int) $user['id'], $id, body_string($body, 'title'))];
}

/** DELETE /api/novels {id} —— 章节由外键级联删掉 */
function api_novel_delete()
{
    $body = body_json();
    $user = require_login();
    $id = body_int($body, 'id', 0, 0, 2147483647);
    if ($id <= 0) {
        throw new ApiException('缺少书的 id', 422);
    }
    return [200, novel_delete((int) $user['id'], $id)];
}

/** GET /api/novel?id= —— 一本书的元信息与整份目录（不含正文） */
function api_novel_open()
{
    $user = require_login();
    return [200, novel_open((int) $user['id'], query_novel_id())];
}

/**
 * GET /api/novel/chapter?id=&seq= —— 取一章正文。
 *
 * seq 用整个整数范围取，不在这里夹到 0：一夹就把「-1」变成「0」悄悄读到第一章，
 * 越界判断（404）再也看不到真实输入——这是上一轮从 minutes 那个坑里学来的。
 */
function api_novel_chapter()
{
    $user = require_login();
    return [200, novel_read(
        (int) $user['id'],
        query_novel_id(),
        query_int('seq', 0, -2147483647, 2147483647)
    )];
}

/** POST /api/novel/progress {id,chapter,paragraph} —— 翻页与滚动时各存一次 */
function api_novel_progress()
{
    $body = body_json();
    $user = require_login();
    $id = body_int($body, 'id', 0, 0, 2147483647);
    if ($id <= 0) {
        throw new ApiException('缺少书的 id', 422);
    }
    return [200, novel_progress(
        (int) $user['id'],
        $id,
        body_int($body, 'chapter', 0, -2147483647, 2147483647),
        body_int($body, 'paragraph', 0, -2147483647, 2147483647)
    )];
}

/** 两个「按 id 打开一本书」的 GET 接口共用：没带 id 就是 422，不该被当成 id = 1 */
function query_novel_id()
{
    $id = query_int('id', 0, 0, 2147483647);
    if ($id <= 0) {
        throw new ApiException('缺少书的 id', 422);
    }
    return $id;
}

/** GET /api/admin/users?page=&perPage=&q= —— 后台面板数据（仅管理员） */
function api_admin_users()
{
    require_admin();
    return [200, admin_overview(...admin_query_params())];
}

/** DELETE /api/admin/users {username} —— 删除用户（仅管理员） */
function api_admin_user_delete()
{
    $actor = require_admin();
    $result = admin_delete_user(body_string(body_json(), 'username'), $actor);
    return [200, $result + admin_overview(...admin_query_params())];
}

/** POST /api/admin/reset-password {username,newPassword} —— 后台重置普通用户密码 */
function api_admin_reset_password()
{
    $actor = require_admin();
    $body = body_json();
    $result = admin_reset_password(
        body_string($body, 'username'),
        body_password($body, 'newPassword'),
        $actor
    );
    return [200, $result + admin_overview(...admin_query_params())];
}

/** 后台三个接口共用的分页 / 搜索参数 */
function admin_query_params()
{
    list($minPerPage, $maxPerPage) = admin_per_page_bounds();
    return [
        query_int('page', 1, 1, 100000),
        query_int('perPage', 10, $minPerPage, $maxPerPage),
        query_string('q'),
    ];
}

/**
 * GET /api/admin/system —— 资源监控快照（仅管理员）
 *
 * 采集全部来自 PHP 与 MySQL 原生能力，不调用任何操作系统命令，
 * 因此换到 Linux 上同样可用；详见 src/system.php 顶部的说明。
 */
function api_admin_system()
{
    require_admin();

    // 先记下会话的 SQL 计数，采集完再取一次，差值即「本次采集自身跑了多少条 SQL」
    $sqlBefore = system_sql_counter();
    $snapshot = system_snapshot($sqlBefore);

    // 面板打开本身就是一次有意义的采样点；内部到点才会真的写盘
    $snapshot['sampleRecorded'] = system_maybe_record_sample(system_sample_from_snapshot($snapshot));

    // 写完之后再读一次趋势，保证刚记下的这一条也在图上
    $snapshot['samples'] = system_samples((int) cfg('stats_max_samples'));

    return [200, $snapshot];
}

/**
 * 给用户/接口返回的“安全视图”：白名单字段，
 * 这样以后 users 表加了敏感列（比如 password_changed_at、内部标记），
 * 也不会被顺手吐到前端。
 */
function public_user(array $user)
{
    return [
        'logged' => true,
        'username' => $user['username'],
        'role' => $user['role'],
        'email' => isset($user['email']) ? $user['email'] : null,
        'emailVerified' => !empty($user['email_verified_at']),
        // 个人中心要显示注册时间；这里已经是「给人看」的视图，顺手格式化好
        'createdAt' => isset($user['created_at']) ? format_datetime($user['created_at']) : '—',
    ];
}

// ============================================================
// 软件仓库（src/software.php + src/softnet.php）
// ============================================================

/**
 * 取查询串里的软件 id。
 * 缺 id 要回 422 说清楚，而不是当成 0 —— 当成 0 会让「没传参数」和
 * 「这个 id 不存在」混成同一条 404，排查时看不出是谁的问题。
 */
function query_software_id()
{
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new ApiException('缺少软件 id', 422);
    }
    $id = (int) $_GET['id'];
    if ($id <= 0) {
        throw new ApiException('软件 id 不正确', 422);
    }
    return $id;
}

/**
 * 写操作里的软件 id，顺带把请求体交回去（图标上传还要读 image 字段）。
 * 缺 id 与查询串那条给出同样的 422：请求写错了，不该混进「这个 id 不存在」。
 *
 * @return array [body, id]
 */
function body_software_id()
{
    $body = body_json();
    $id = body_int($body, 'id', 0, 0, 2147483647);
    if ($id <= 0) {
        throw new ApiException('缺少软件 id', 422);
    }
    return [$body, $id];
}

/**
 * GET /api/software —— 公开清单。
 * 刻意传 null 而不是 current_user()：这一条对所有人返回同样的字节，
 * 未登录的人看到的就是已上架的全部，「还能不能上下架」这种判断只存在于
 * /api/admin/software。
 */
function api_software_list()
{
    return [200, software_list(null)];
}

/**
 * 这一条既服务访客也服务管理员：下架条目的图标与安装包只给管理员看，
 * 所以要认一下来客。认不出就当访客——这里绝不调 require_*，未登录是正常情况。
 */
function software_viewer_is_admin()
{
    $viewer = current_user();
    return $viewer !== null && $viewer['role'] === 'admin';
}

/** GET /api/software/file —— 把服务器代下载下来的安装包发给浏览器 */
function api_software_file()
{
    return [200, ['file' => software_serve_target(query_software_id(), 'file', software_viewer_is_admin())]];
}

/** GET /api/software/icon —— 本地图标。走这一条而不是直接外链，是为了不动 CSP 的 img-src 'self' */
function api_software_icon()
{
    return [200, ['file' => software_serve_target(query_software_id(), 'icon', software_viewer_is_admin())]];
}

/** GET /api/admin/software —— 管理员视角：含已下架的条目与抓包细节 */
function api_admin_software_list()
{
    $actor = require_admin();
    return [200, software_list($actor)];
}

/** POST /api/admin/software —— 新增一款（此刻还不碰远端，抓包是另一个动作） */
function api_admin_software_create()
{
    $actor = require_admin();
    $body = body_json();
    return [201, [
        'software' => software_create($actor, $body),
        'list' => software_list($actor),
    ]];
}

/** POST /api/admin/software/update —— 部分更新，只改请求里出现过的字段 */
function api_admin_software_update()
{
    $actor = require_admin();
    $body = body_json();
    $id = body_int($body, 'id', 0, 0, 2147483647);
    if ($id <= 0) {
        throw new ApiException('缺少软件 id', 422);
    }

    $editable = [
        'name', 'slug', 'category', 'platforms', 'tags', 'description',
        'homepage', 'githubUrl', 'giteeUrl', 'downloadUrl',
        'sourceMode', 'version', 'license', 'starCount', 'sortOrder', 'enabled',
    ];
    $fields = [];
    foreach ($editable as $key) {
        if (array_key_exists($key, $body)) {
            $fields[$key] = $body[$key];
        }
    }

    return [200, [
        'software' => software_update($actor, $id, $fields),
        'list' => software_list($actor),
    ]];
}

/** DELETE /api/admin/software —— 删条目，连本地安装包与图标一起删 */
function api_admin_software_delete()
{
    $actor = require_admin();
    list(, $id) = body_software_id();

    return [200, software_delete($actor, $id) + ['list' => software_list($actor)]];
}

/**
 * POST /api/admin/software/grab —— 服务器代下载：按取包方式把安装包抓到本机。
 *
 * 这是同步的：请求会一直挂着直到抓完或失败，最长可能几十秒。
 * 之所以不做成「起个后台任务再轮询」——PHP 这边没有常驻进程可以派活，
 * 内置服务器更是单线程，起了任务也只会把轮询请求一起堵住。
 * 前端要为这一条单独放宽超时（core.js 里 api() 的第四个参数）。
 */
function api_admin_software_grab()
{
    $actor = require_admin();
    list(, $id) = body_software_id();

    return [200, software_grab_file($actor, $id) + ['list' => software_list($actor)]];
}

/** POST /api/admin/software/release —— 不再由服务器代下载：删掉本地包，条目与图标留着 */
function api_admin_software_release()
{
    $actor = require_admin();
    list(, $id) = body_software_id();

    return [200, [
        'software' => software_release_file($actor, $id),
        'list' => software_list($actor),
    ]];
}

/**
 * POST /api/admin/software/identify —— 「自动识别信息」：只读仓库元数据，不动磁盘、不写库。
 *
 * 回来的字段由前端填进表单，管理员确认之后再点「保存此软件」。
 * 和抓包一样是同步出网，所以也要放宽前端超时。
 */
function api_admin_software_identify()
{
    $actor = require_admin();
    $body = body_json();

    return [200, software_identify($actor, body_string($body, 'githubUrl'), body_string($body, 'giteeUrl'))];
}

/** POST /api/admin/software/icon —— 管理员自己传一枚图标（base64 塞在 JSON 里，全站写操作都是 JSON） */
function api_admin_software_icon_upload()
{
    $actor = require_admin();
    list($body, $id) = body_software_id();

    return [200, software_upload_icon($actor, $id, body_string($body, 'image')) + ['list' => software_list($actor)]];
}

/** POST /api/admin/software/icon/fetch —— 「智能获取」：从仓库或官网顺手取一枚图标，同样要过出网闸门 */
function api_admin_software_icon_fetch()
{
    $actor = require_admin();
    list(, $id) = body_software_id();

    return [200, software_fetch_icon($actor, $id) + ['list' => software_list($actor)]];
}

/** DELETE /api/admin/software/icon —— 去掉本站图标，卡片退回首字母占位 */
function api_admin_software_icon_clear()
{
    $actor = require_admin();
    list(, $id) = body_software_id();

    return [200, [
        'software' => software_clear_icon($actor, $id),
        'list' => software_list($actor),
    ]];
}

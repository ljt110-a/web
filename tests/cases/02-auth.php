<?php
/**
 * 认证用例：注册、参数校验、登录、会话、退出、登录失败限流、每用户会话数上限。
 */

t_section('注册');

t_clear_jar('alice');
$res = t_request('POST', '/api/register', [
    'json' => ['username' => 'alice', 'password' => 'alicepw1'],
    'jar' => 'alice',
]);
t_eq(201, $res['status'], '注册成功返回 201');
t_eq(true, isset($res['json']['logged']) ? $res['json']['logged'] : null, '注册后直接进入登录态');
t_eq('alice', isset($res['json']['username']) ? $res['json']['username'] : null, '返回用户名');
t_eq('user', isset($res['json']['role']) ? $res['json']['role'] : null, '新注册用户角色是 user');
t_assert(t_token('alice') !== null, '注册后就拿到了会话 Cookie');

$res = t_request('POST', '/api/register', [
    'json' => ['username' => 'alice', 'password' => 'otherpw1'],
    'jar' => 'tmp',
]);
t_eq(409, $res['status'], '重复用户名返回 409');
t_contains($res['body'], '已被注册', '提示用户名已被注册');

$res = t_request('POST', '/api/register', ['json' => ['username' => 'shortpw', 'password' => 'abc'], 'jar' => 'tmp']);
t_eq(422, $res['status'], '密码过短被拒绝');

$res = t_request('POST', '/api/register', ['json' => ['username' => 'digits', 'password' => '12345678'], 'jar' => 'tmp']);
t_eq(422, $res['status'], '纯数字密码被拒绝');
t_contains($res['body'], '纯数字', '提示写明了原因');

$res = t_request('POST', '/api/register', ['json' => ['username' => 'sameuser', 'password' => 'sameuser'], 'jar' => 'tmp']);
t_eq(422, $res['status'], '密码与用户名相同被拒绝');

$res = t_request('POST', '/api/register', ['json' => ['username' => 'x', 'password' => 'validpw1'], 'jar' => 'tmp']);
t_eq(422, $res['status'], '用户名过短被拒绝');

$res = t_request('POST', '/api/register', ['json' => ['username' => "bad\nname", 'password' => 'validpw1'], 'jar' => 'tmp']);
t_eq(422, $res['status'], '用户名含控制字符被拒绝');

// 这些被拒绝的注册不该留下任何账号
t_eq(null, find_user('digits'), '被拒绝的注册没有写进数据库');
t_eq(null, find_user('sameuser'), '密码不合规的注册没有写进数据库');

t_section('会话与登录');

$res = t_request('GET', '/api/me', ['jar' => 'alice']);
t_eq(true, isset($res['json']['logged']) ? $res['json']['logged'] : null, '/api/me 认出已登录');
t_eq('alice', isset($res['json']['username']) ? $res['json']['username'] : null, '/api/me 返回当前用户名');

$res = t_request('GET', '/api/me', ['jar' => 'fresh']);
t_eq(false, isset($res['json']['logged']) ? $res['json']['logged'] : null, '陌生浏览器访问 /api/me 是未登录');

$res = t_request('POST', '/api/logout', ['json' => [], 'jar' => 'alice']);
t_eq(200, $res['status'], '退出返回 200');
$res = t_request('GET', '/api/me', ['jar' => 'alice']);
t_eq(false, isset($res['json']['logged']) ? $res['json']['logged'] : null, '退出后 /api/me 变成未登录');

$res = t_request('POST', '/api/login', ['json' => ['username' => 'alice', 'password' => 'wrongpw1'], 'jar' => 'alice']);
t_eq(401, $res['status'], '密码错误返回 401');
t_contains($res['body'], '用户名或密码错误', '错误提示不透露账号是否存在');

$res = t_request('POST', '/api/login', ['json' => ['username' => 'nobody-here', 'password' => 'whatever1'], 'jar' => 'tmp']);
t_eq(401, $res['status'], '账号不存在也返回同样的一句话');
t_contains($res['body'], '用户名或密码错误', '与密码错误的提示完全一致');

$res = t_request('POST', '/api/login', ['json' => ['username' => 'alice', 'password' => 'alicepw1'], 'jar' => 'alice']);
t_eq(200, $res['status'], '正确密码登录成功');
t_eq('alice', isset($res['json']['username']) ? $res['json']['username'] : null, '登录返回用户名');
t_assert(t_token('alice') !== null, '登录成功签发了会话');

t_section('每用户会话数上限');

t_request('POST', '/api/register', [
    'json' => ['username' => 'capuser', 'password' => 'cappw123'],
    'jar' => 'cap0',
]);
foreach (['cap1', 'cap2', 'cap3', 'cap4'] as $jar) {
    t_clear_jar($jar);
    t_request('POST', '/api/login', [
        'json' => ['username' => 'capuser', 'password' => 'cappw123'],
        'jar' => $jar,
    ]);
}
// 上限是 3（见 tests/run.php 的 WEB_ONE_MAX_SESSIONS_PER_USER），
// 注册那次 + 4 次登录共 5 个会话，最终只该留下最新的 3 个
$res = t_request('GET', '/api/me', ['jar' => 'cap1']);
t_eq(false, isset($res['json']['logged']) ? $res['json']['logged'] : null, '超出上限后最早的会话被踢掉');
$res = t_request('GET', '/api/me', ['jar' => 'cap2']);
t_eq(true, isset($res['json']['logged']) ? $res['json']['logged'] : null, '次新的会话仍然有效');
$res = t_request('GET', '/api/me', ['jar' => 'cap4']);
t_eq(true, isset($res['json']['logged']) ? $res['json']['logged'] : null, '最新登录的会话有效');
t_eq(3, active_session_count((int) find_user('capuser')['id']), '数据库里该用户只剩 3 个有效会话');

t_section('登录失败限流');

// 阈值是 3（WEB_ONE_THROTTLE_MAX_PER_USER）：前 3 次正常回 401，第 4 次开始被拦
for ($i = 1; $i <= 3; $i++) {
    $res = t_request('POST', '/api/login', [
        'json' => ['username' => 'thr_a', 'password' => 'nopepw123'],
        'jar' => 'thr',
    ]);
    t_eq(401, $res['status'], "第 {$i} 次错误密码返回 401");
}

$res = t_request('POST', '/api/login', [
    'json' => ['username' => 'thr_a', 'password' => 'nopepw123'],
    'jar' => 'thr',
]);
t_eq(429, $res['status'], '第 4 次被限流，返回 429');
t_assert(isset($res['headers']['retry-after']), '429 响应带 Retry-After 头');
t_assert((int) $res['headers']['retry-after'] > 0, 'Retry-After 是正数');

// 换个账号仍然失败（说明是账号维度在计数），但还没到每 IP 的阈值
$res = t_request('POST', '/api/login', ['json' => ['username' => 'thr_b', 'password' => 'nopepw123'], 'jar' => 'thr']);
t_eq(401, $res['status'], '换个账号还能正常尝试（说明没被整网封锁）');

// 每 IP 阈值：清空计数后，用 8 个不同账号各失败一次，避开「每账号」这条规则
db()->exec('DELETE FROM auth_attempts');
$unauthorized = 0;
for ($i = 0; $i < 8; $i++) {
    $res = t_request('POST', '/api/login', [
        'json' => ['username' => 'ip_probe_' . $i, 'password' => 'nopepw123'],
        'jar' => 'thr',
    ]);
    if ($res['status'] === 401) {
        $unauthorized++;
    }
}
t_eq(8, $unauthorized, '8 个不同账号各失败一次，都还没触发每账号阈值');
$res = t_request('POST', '/api/login', ['json' => ['username' => 'ip_probe_final', 'password' => 'nopepw123'], 'jar' => 'thr']);
t_eq(429, $res['status'], '同一 IP 失败满阈值后，换个账号也会被拦');

// 清掉限流记录：这是测试收尾，不是被断言放宽——上面的断言已经全部生效
db()->exec('DELETE FROM auth_attempts');

$res = t_request('POST', '/api/login', ['json' => ['username' => 'alice', 'password' => 'alicepw1'], 'jar' => 'alice']);
t_eq(200, $res['status'], '清空记录后可以正常登录（限流会自愈）');

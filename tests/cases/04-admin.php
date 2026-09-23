<?php
/**
 * 后台用例：权限门槛、分页、搜索、删除用户、重置密码。
 * 后台是全站权限最集中的地方，这里每一条「不允许」都必须真的不允许。
 */

t_section('后台权限门槛');

t_clear_jar('norm');
t_request('POST', '/api/register', ['json' => ['username' => 'norm', 'password' => 'normpw12'], 'jar' => 'norm']);

$res = t_request('GET', '/api/admin/users', ['jar' => 'norm']);
t_eq(403, $res['status'], '普通用户读后台数据被拒绝 403');
t_contains($res['body'], '管理员', '提示需要管理员权限');

$res = t_request('GET', '/api/admin/users', ['jar' => 'anon']);
t_eq(401, $res['status'], '未登录读后台返回 401');

$res = t_request('DELETE', '/api/admin/users', ['json' => ['username' => 'norm'], 'jar' => 'norm']);
t_eq(403, $res['status'], '普通用户不能删除任何人');

$res = t_request('POST', '/api/admin/reset-password', ['json' => ['username' => 'alice', 'newPassword' => 'hacked123'], 'jar' => 'norm']);
t_eq(403, $res['status'], '普通用户不能重置别人的密码');

t_section('管理员登录与概览');

t_clear_jar('admin');
$res = t_request('POST', '/api/login', ['json' => ['username' => 'root', 'password' => 'adminpass123'], 'jar' => 'admin']);
t_eq(200, $res['status'], '管理员登录成功');
t_eq('admin', isset($res['json']['role']) ? $res['json']['role'] : null, '返回角色 admin');

$res = t_request('GET', '/api/admin/users', ['jar' => 'admin']);
t_eq(200, $res['status'], '管理员可以读后台数据');
$overview = $res['json'];
t_assert(isset($overview['stats']['userCount']), '概览里有用户总数');
t_assert(isset($overview['stats']['onlineCount']), '概览里有在线人数');
t_assert(isset($overview['stats']['totalVisitors']), '概览里有累计独立访客（UV）');
t_assert(isset($overview['stats']['daily']) && is_array($overview['stats']['daily']), '概览里有近 7 天访问趋势');
t_assert(isset($overview['paging']['totalPages']), '概览里有分页元数据');
t_assert($overview['stats']['userCount'] >= 6, '用户总数包含了所有账号');
t_assert(count($overview['users']) <= 10, '默认每页不超过 10 条');

t_section('分页');

$page1 = t_request('GET', '/api/admin/users?perPage=2&page=1', ['jar' => 'admin']);
$page2 = t_request('GET', '/api/admin/users?perPage=2&page=2', ['jar' => 'admin']);
t_eq(200, $page1['status'], '带分页参数请求返回 200');
t_eq(2, count($page1['json']['users']), 'perPage=2 时返回 2 条');
t_eq($page1['json']['paging']['total'], $page1['json']['stats']['userCount'], 'userCount 是全表总数，不是当前页条数');
t_assert(
    $page1['json']['users'][0]['username'] !== $page2['json']['users'][0]['username'],
    '第 1 页与第 2 页返回的是不同的人'
);
t_eq('root', $page1['json']['users'][0]['username'], '管理员排在第一页最前面');

$over = t_request('GET', '/api/admin/users?perPage=2&page=999', ['jar' => 'admin']);
t_eq($over['json']['paging']['totalPages'], $over['json']['paging']['page'], '页码越界会被拉回最后一页');

$capped = t_request('GET', '/api/admin/users?perPage=9999', ['jar' => 'admin']);
t_eq(100, $capped['json']['paging']['perPage'], 'perPage 超过上限时被后端夹到 100');
$capped = t_request('GET', '/api/admin/users?perPage=-5', ['jar' => 'admin']);
t_eq(1, $capped['json']['paging']['perPage'], 'perPage 传负数会被夹到下限 1');

t_section('搜索');

$res = t_request('GET', '/api/admin/users?q=carol', ['jar' => 'admin']);
t_eq(1, count($res['json']['users']), '按用户名搜索命中 1 条');
t_eq('carol', $res['json']['users'][0]['username'], '命中的是 carol');
t_eq('carol', $res['json']['search'], '响应里回显了搜索词');

$res = t_request('GET', '/api/admin/users?q=dave@example.com', ['jar' => 'admin']);
t_eq(1, count($res['json']['users']), '按邮箱也能搜到');
t_eq(false, $res['json']['users'][0]['emailVerified'], 'dave 的邮箱显示为未验证');

$res = t_request('GET', '/api/admin/users?q=zzz-nobody', ['jar' => 'admin']);
t_eq(0, count($res['json']['users']), '搜不到时返回空列表而不是报错');

// 关键：% 是 LIKE 的通配符，没转义的话这里会匹配到所有人
$res = t_request('GET', '/api/admin/users?q=%25', ['jar' => 'admin']);
t_eq(0, count($res['json']['users']), 'LIKE 通配符被转义，搜 % 不会匹配到所有人');

$res = t_request('GET', '/api/admin/users?q=_', ['jar' => 'admin']);
t_eq(0, count($res['json']['users']), '下划线同样被转义，不会变成「任意一个字符」');

t_section('删除用户');

$res = t_request('POST', '/api/register', ['json' => ['username' => 'victim', 'password' => 'victimpw1'], 'jar' => 'victim']);
t_eq(201, $res['status'], '准备一个待删除的账号');
t_assert(t_token('victim') !== null, '该账号有活跃会话');

$res = t_request('DELETE', '/api/admin/users', ['json' => ['username' => 'root'], 'jar' => 'admin']);
t_eq(403, $res['status'], '管理员账号不可删除');

$res = t_request('DELETE', '/api/admin/users', ['json' => ['username' => 'victim'], 'jar' => 'admin']);
t_eq(200, $res['status'], '删除普通用户成功');
t_eq('victim', isset($res['json']['deleted']) ? $res['json']['deleted'] : null, '返回被删除的用户名');
t_assert(isset($res['json']['users']), '删除后顺带返回最新的列表');

$res = t_request('GET', '/api/me', ['jar' => 'victim']);
t_eq(false, isset($res['json']['logged']) ? $res['json']['logged'] : null, '被删除用户的会话立即失效（外键级联删掉会话）');
t_eq(null, find_user('victim'), '数据库里确实没有这个账号了');

$res = t_request('DELETE', '/api/admin/users', ['json' => ['username' => 'victim'], 'jar' => 'admin']);
t_eq(404, $res['status'], '重复删除返回 404');

t_section('后台重置用户密码');

t_request('POST', '/api/register', ['json' => ['username' => 'resetme', 'password' => 'oldpw1234'], 'jar' => 'resetme']);
$res = t_request('POST', '/api/admin/reset-password', [
    'json' => ['username' => 'resetme', 'newPassword' => 'adminreset1'],
    'jar' => 'admin',
]);
t_eq(200, $res['status'], '后台重置普通用户密码成功');
t_eq('resetme', isset($res['json']['reset']) ? $res['json']['reset'] : null, '返回被重置的账号');
t_assert(isset($res['json']['revokedSessions']) && $res['json']['revokedSessions'] >= 1, '重置后该用户所有会话被撤销');

$res = t_request('GET', '/api/me', ['jar' => 'resetme']);
t_eq(false, isset($res['json']['logged']) ? $res['json']['logged'] : null, '密码被重置的用户被踢下线');

$res = t_request('POST', '/api/login', ['json' => ['username' => 'resetme', 'password' => 'oldpw1234'], 'jar' => 'probe']);
t_eq(401, $res['status'], '旧密码失效');
$res = t_request('POST', '/api/login', ['json' => ['username' => 'resetme', 'password' => 'adminreset1'], 'jar' => 'probe']);
t_eq(200, $res['status'], '用户可以用管理员设的新密码登录');

$res = t_request('POST', '/api/admin/reset-password', ['json' => ['username' => 'root', 'newPassword' => 'whatever12'], 'jar' => 'admin']);
t_eq(400, $res['status'], '不能对自己的账号用后台重置（会提示改用修改密码）');

// 造第二个管理员，验证「管理员之间不能互相重置密码」
db()->exec("UPDATE users SET role = 'admin' WHERE username = 'norm'");
$res = t_request('POST', '/api/admin/reset-password', ['json' => ['username' => 'norm', 'newPassword' => 'whatever12'], 'jar' => 'admin']);
t_eq(403, $res['status'], '不能重置另一个管理员的密码');
t_contains($res['body'], '管理员', '提示写明了原因');
db()->exec("UPDATE users SET role = 'user' WHERE username = 'norm'");

$res = t_request('POST', '/api/admin/reset-password', ['json' => ['username' => 'zzz-nobody', 'newPassword' => 'whatever12'], 'jar' => 'admin']);
t_eq(404, $res['status'], '重置不存在的账号返回 404');

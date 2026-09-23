<?php
/**
 * 账号用例：修改密码、邮箱验证、找回密码。
 * 这三条都涉及「一次性凭据」，所以重点在验证凭据的时效性、一次性与作用范围。
 */

t_section('修改密码');

t_clear_jar('alice');
t_request('POST', '/api/login', ['json' => ['username' => 'alice', 'password' => 'alicepw1'], 'jar' => 'alice']);
t_clear_jar('alice2');
t_request('POST', '/api/login', ['json' => ['username' => 'alice', 'password' => 'alicepw1'], 'jar' => 'alice2']);
t_assert(t_token('alice2') !== null, '另开一台「设备」并登录成功');

$res = t_request('POST', '/api/password', [
    'json' => ['currentPassword' => 'wrongold1', 'newPassword' => 'brandnew1'],
    'jar' => 'alice',
]);
t_eq(401, $res['status'], '当前密码不正确时拒绝改密');

$res = t_request('POST', '/api/password', [
    'json' => ['currentPassword' => 'alicepw1', 'newPassword' => 'alicepw1'],
    'jar' => 'alice',
]);
t_eq(422, $res['status'], '新密码与当前密码相同被拒绝');

$res = t_request('POST', '/api/password', [
    'json' => ['currentPassword' => 'alicepw1', 'newPassword' => '123456'],
    'jar' => 'alice',
]);
t_eq(422, $res['status'], '新密码是纯数字被拒绝');

$res = t_request('POST', '/api/password', [
    'json' => ['currentPassword' => 'alicepw1', 'newPassword' => 'brandnew1'],
    'jar' => 'alice',
]);
t_eq(200, $res['status'], '改密成功');
t_assert(isset($res['json']['revokedSessions']) && $res['json']['revokedSessions'] >= 1, '改密顺带撤销了其它设备上的会话');

$res = t_request('GET', '/api/me', ['jar' => 'alice']);
t_eq(true, isset($res['json']['logged']) ? $res['json']['logged'] : null, '当前设备改密后依然是登录状态');
$res = t_request('GET', '/api/me', ['jar' => 'alice2']);
t_eq(false, isset($res['json']['logged']) ? $res['json']['logged'] : null, '另一台设备被踢下线');

$res = t_request('POST', '/api/login', ['json' => ['username' => 'alice', 'password' => 'alicepw1'], 'jar' => 'probe']);
t_eq(401, $res['status'], '旧密码不能再登录');
$res = t_request('POST', '/api/login', ['json' => ['username' => 'alice', 'password' => 'brandnew1'], 'jar' => 'alice']);
t_eq(200, $res['status'], '新密码可以登录');

t_section('邮箱验证');

t_clear_mail();
t_clear_jar('carol');
$res = t_request('POST', '/api/register', [
    'json' => ['username' => 'carol', 'password' => 'carolpw1', 'email' => 'Carol@Example.com'],
    'jar' => 'carol',
]);
t_eq(201, $res['status'], '带邮箱注册成功');
t_eq('carol@example.com', isset($res['json']['email']) ? $res['json']['email'] : null, '邮箱统一转成小写保存');
t_eq(false, isset($res['json']['emailVerified']) ? $res['json']['emailVerified'] : null, '刚注册时邮箱未验证');
t_eq(true, isset($res['json']['verificationMailSent']) ? $res['json']['verificationMailSent'] : null, '注册时发出了验证邮件');

$mail = t_last_mail();
t_contains($mail, 'carol@example.com', '验证邮件发给的是注册时填的邮箱');
t_contains($mail, '请验证你的邮箱', '邮件标题正确');
$verifyToken = t_token_from_mail('verify');
t_assert($verifyToken !== null, '邮件里带着验证链接的令牌');

$res = t_request('POST', '/api/email/verify', ['json' => ['token' => $verifyToken], 'jar' => 'carol']);
t_eq(200, $res['status'], '用邮件里的令牌验证成功');
$res = t_request('GET', '/api/me', ['jar' => 'carol']);
t_eq(true, isset($res['json']['emailVerified']) ? $res['json']['emailVerified'] : null, '/api/me 反映邮箱已验证');

$res = t_request('POST', '/api/email/verify', ['json' => ['token' => $verifyToken], 'jar' => 'carol']);
t_eq(400, $res['status'], '同一个验证令牌不能重复使用');

$res = t_request('POST', '/api/email/resend', ['json' => [], 'jar' => 'carol']);
t_eq(409, $res['status'], '已验证的账号重发验证邮件会被拒绝');

$res = t_request('POST', '/api/register', [
    'json' => ['username' => 'carol2', 'password' => 'carolpw2', 'email' => 'carol@example.com'],
    'jar' => 'tmp',
]);
t_eq(409, $res['status'], '同一邮箱不能注册两个账号');
t_contains($res['body'], '邮箱', '提示说的是邮箱重复');

// 没有邮箱的账号请求重发，应该提示先绑邮箱而不是报错
$res = t_request('POST', '/api/email/resend', ['json' => [], 'jar' => 'alice']);
t_eq(422, $res['status'], '没绑定邮箱的账号重发验证邮件被拒绝');

t_section('找回密码');

t_clear_mail();
$res = t_request('POST', '/api/forgot', ['json' => ['email' => 'nobody@example.com'], 'jar' => 'tmp']);
t_eq(200, $res['status'], '未注册的邮箱也返回 200');
$messageForUnknown = isset($res['json']['message']) ? $res['json']['message'] : '';
t_assert($messageForUnknown !== '', '返回一句提示文案');
t_eq([], t_mail_blocks(), '未注册的邮箱不会真的发出邮件');

$res = t_request('POST', '/api/register', [
    'json' => ['username' => 'dave', 'password' => 'davepw123', 'email' => 'dave@example.com'],
    'jar' => 'dave',
]);
t_eq(201, $res['status'], '准备一个用于找回密码的账号');

t_clear_mail();
$res = t_request('POST', '/api/forgot', ['json' => ['email' => 'dave@example.com'], 'jar' => 'tmp']);
t_eq(200, $res['status'], '已注册邮箱返回 200');
t_eq($messageForUnknown, isset($res['json']['message']) ? $res['json']['message'] : null, '注册与未注册的响应文案完全一致（不泄露账号是否存在）');

$mail = t_last_mail();
t_contains($mail, '重置你的密码', '发出了重置邮件');
t_contains($mail, 'dave@example.com', '重置邮件发给本人');
$resetToken = t_token_from_mail('reset');
t_assert($resetToken !== null, '邮件里带着重置链接的令牌');

// 重复请求要把旧链接作废，只保留最新那封里的链接
$res = t_request('POST', '/api/forgot', ['json' => ['email' => 'dave@example.com'], 'jar' => 'tmp']);
t_eq(200, $res['status'], '可以再次请求重置');
$newResetToken = t_token_from_mail('reset');
t_assert($newResetToken !== null && $newResetToken !== $resetToken, '第二次请求换了新令牌');
$res = t_request('POST', '/api/reset', ['json' => ['token' => $resetToken, 'password' => 'whatever123'], 'jar' => 'tmp']);
t_eq(400, $res['status'], '上一次的旧链接已经作废');

$resetToken = $newResetToken;

$res = t_request('POST', '/api/reset', ['json' => ['token' => $resetToken, 'password' => '123'], 'jar' => 'tmp']);
t_eq(422, $res['status'], '重置时的弱密码会被拒绝');

// 关键行为：密码不合法不该把链接烧掉，否则用户得重新收一封邮件
$res = t_request('POST', '/api/reset', ['json' => ['token' => $resetToken, 'password' => 'newdave123'], 'jar' => 'tmp']);
t_eq(200, $res['status'], '被拒绝的弱密码不会消耗掉重置链接');

$res = t_request('POST', '/api/reset', ['json' => ['token' => $resetToken, 'password' => 'another123'], 'jar' => 'tmp']);
t_eq(400, $res['status'], '重置令牌是一次性的，用过即失效');

$res = t_request('POST', '/api/reset', ['json' => ['token' => str_repeat('a', 64), 'password' => 'whatever123'], 'jar' => 'tmp']);
t_eq(400, $res['status'], '伪造的令牌被拒绝');
$res = t_request('POST', '/api/reset', ['json' => ['token' => 'not-a-token', 'password' => 'whatever123'], 'jar' => 'tmp']);
t_eq(400, $res['status'], '格式不对的令牌被拒绝');

$res = t_request('POST', '/api/login', ['json' => ['username' => 'dave', 'password' => 'davepw123'], 'jar' => 'probe']);
t_eq(401, $res['status'], '重置后旧密码失效');
$res = t_request('POST', '/api/login', ['json' => ['username' => 'dave', 'password' => 'newdave123'], 'jar' => 'probe']);
t_eq(200, $res['status'], '重置后的新密码可以登录');

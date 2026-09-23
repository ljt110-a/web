<?php
/**
 * 基础用例：健康检查、安全响应头、路由语义、静态资源、同源校验。
 * 这些是所有功能的地基——它们坏了，后面的失败信息会很难判断真假。
 */

t_section('健康检查');

$res = t_request('GET', '/api/health');
t_eq(200, $res['status'], 'GET /api/health 返回 200');
t_eq('ok', isset($res['json']['status']) ? $res['json']['status'] : null, 'health 报告状态 ok');
t_eq(cfg('db_name'), isset($res['json']['database']) ? $res['json']['database'] : null, 'health 报告的是测试库');

t_section('安全响应头');

t_eq('nosniff', isset($res['headers']['x-content-type-options']) ? $res['headers']['x-content-type-options'] : null, '带 X-Content-Type-Options: nosniff');
t_eq('DENY', isset($res['headers']['x-frame-options']) ? $res['headers']['x-frame-options'] : null, '带 X-Frame-Options: DENY');
t_eq('same-origin', isset($res['headers']['referrer-policy']) ? $res['headers']['referrer-policy'] : null, '带 Referrer-Policy');
t_assert(isset($res['headers']['x-request-id']), '带 X-Request-Id，便于和服务器日志对上');
t_assert(isset($res['headers']['content-security-policy']), '带 Content-Security-Policy');

$csp = isset($res['headers']['content-security-policy']) ? $res['headers']['content-security-policy'] : '';
t_contains($csp, "script-src 'self'", 'CSP 限制脚本只能来自本站');
t_contains($csp, "frame-ancestors 'none'", 'CSP 禁止被任何页面嵌套');
t_assert(strpos($csp, "script-src 'self' 'unsafe-inline'") === false, 'CSP 没有给脚本放开 unsafe-inline');

t_section('路由语义');

$res = t_request('GET', '/api/login');
t_eq(405, $res['status'], '路径存在但方法不对返回 405（不是 404）');
t_contains($res['body'], '不支持', '405 的提示说明方法不对');

$res = t_request('GET', '/api/no-such-endpoint');
t_eq(404, $res['status'], '不存在的接口返回 404');

$res = t_request('POST', '/api/login', ['raw_body' => '{"username":"a"}', 'content_type' => '']);
t_eq(415, $res['status'], '写操作不带 JSON Content-Type 返回 415（CSRF 第一道防线）');

$res = t_request('POST', '/api/login', ['raw_body' => '{不是合法 json']);
t_eq(400, $res['status'], '请求体不是合法 JSON 返回 400');

t_section('页面与静态资源');

$res = t_request('GET', '/');
t_eq(200, $res['status'], '首页返回 200');
t_contains($res['body'], '我的空间', '首页是真实的 HTML 页面');
t_contains($res['headers']['content-type'], 'text/html', '首页 Content-Type 是 text/html');
t_assert(isset($res['headers']['x-content-type-options']), '首页也带安全响应头');

$res = t_request('GET', '/assets/css/style.css');
t_eq(200, $res['status'], '样式表可以直接访问');
t_contains($res['body'], '--accent-1', '样式表内容完整');

$res = t_request('GET', '/assets/js/app.js');
t_eq(200, $res['status'], '前端脚本可以直接访问');

$res = t_request('GET', '/docs/readme.md');
t_eq(404, $res['status'], '不存在的路径返回 404');

t_section('同源校验与目录穿越');

$res = t_request('POST', '/api/login', [
    'json' => ['username' => 'alice', 'password' => 'whatever1'],
    'headers' => ['Origin' => 'http://evil.example'],
]);
t_eq(403, $res['status'], '来自其它站点的写请求被拒绝 403');

$res = t_request('POST', '/api/login', [
    'json' => ['username' => 'alice', 'password' => 'whatever1'],
    'headers' => ['Origin' => t_base_url()],
]);
t_eq(401, $res['status'], '同源请求正常放行（这里因密码错误回 401，而不是 403）');

// 目录穿越：即使被 URL 规范化，也不该把 public/ 之外的文件吐出来
$res = t_request('GET', '/../src/config.local.php');
t_assert($res['status'] === 404 || $res['status'] === 400, '尝试读取 src/config.local.php 被挡在 public/ 之外');
t_assert(strpos($res['body'], 'db_pass') === false, '响应里没有泄露配置内容');

<?php
/**
 * 安全与运维基础件：响应头、来源校验、请求 ID、文件日志。
 *
 * 这一层刻意不依赖数据库——它要能在「数据库都连不上」的时候照常工作，
 * 否则最需要日志的故障场景反而写不出日志。
 */

/** 本次请求的短 ID，用来把前端看到的错误和服务器日志对上 */
function request_id()
{
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(4));
    }
    return $id;
}

/** 递归创建目录；失败返回 false，不抛异常 */
function ensure_dir($dir)
{
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0775, true) || is_dir($dir);
}

/**
 * 写一行应用日志。
 * 日志落盘失败（权限、磁盘满）绝不能连累业务，因此整体静默降级到 error_log。
 */
function app_log($level, $message, array $context = [])
{
    $line = sprintf(
        "[%s] [%s] [%s] %s%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        request_id(),
        $message,
        $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $dir = cfg('log_dir');
    if (ensure_dir($dir)) {
        $file = rtrim($dir, '/\\') . '/' . date('Y-m-d') . '.log';
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false) {
            return;
        }
    }
    error_log('[web-one] ' . trim($line));
}

/**
 * 要下发的安全响应头。
 * @return array<string,string>
 */
function security_headers()
{
    $headers = [
        // 让浏览器不要自作聪明地猜 Content-Type（否则上传的文本可能被当成脚本执行）
        'X-Content-Type-Options' => 'nosniff',
        // 本站不需要被任何页面嵌套，双击劫持（clickjacking）直接堵掉
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => implode('; ', [
            "default-src 'self'",
            // 样式里有内联的 style 属性与 <noscript><style>，所以样式必须允许 inline；
            // 脚本没有任何内联，保持严格的 'self'。
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self'",
            "img-src 'self' data:",
            "connect-src 'self'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]),
        'Referrer-Policy' => 'same-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        'X-Request-Id' => request_id(),
    ];

    // HSTS 只有在确实走 HTTPS 时才发：本地 http 下发了会把自己锁死
    if (is_https() && (cfg('cookie_secure') || is_production())) {
        $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
    }

    return $headers;
}

/** 下发所有安全响应头 */
function send_security_headers()
{
    foreach (security_headers() as $name => $value) {
        header($name . ': ' . $value);
    }
}

/** 当前请求是否走 HTTPS（兼容反向代理终止 TLS 的情况） */
function is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    // 反代场景：只有在我们自己配置了信任代理时才该看这个头，本地开发用不到，
    // 但判断「当前是不是 HTTPS」用于决定要不要发 HSTS，风险仅限于多一个头。
    return isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
}

/** 当前请求的协议 + 主机，用于拼同源判断与绝对地址 */
function request_host()
{
    if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
        return (string) $_SERVER['HTTP_HOST'];
    }
    return 'localhost';
}

/**
 * 写操作的「同源」校验——CSRF 的第二道防线。
 *
 * 第一道是「必须用 application/json 提交」（跨站的 <form> 设不了这个 Content-Type），
 * 再加 Cookie 的 SameSite=Lax，理论上已经挡住了；这一道是为了防住
 * 「浏览器插件改写请求」或「将来有人放宽了 SameSite」这类意外。
 *
 * 判据：Origin 的「主机[:端口]」必须等于本次请求的 Host。
 * 这是可靠的，因为浏览器发请求时 Host 由它请求的 URL 决定——
 * 攻击者页面在 evil.com，它让浏览器往我们站点发请求时 Host 是我们的域名，
 * 而 Origin 会是 evil.com，两者必然对不上。
 *
 * 浏览器发同源请求时会带 Origin；命令行工具（curl）通常不带——
 * 因此「没有 Origin」放行，「有 Origin 但对不上」拒绝。
 */
function assert_same_origin()
{
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
    if ($origin === '' || strtolower($origin) === 'null') {
        return;
    }

    $host = parse_url($origin, PHP_URL_HOST);
    $scheme = parse_url($origin, PHP_URL_SCHEME);
    if ($host === null || $host === false || $scheme === null || $scheme === false) {
        app_log('warning', 'Origin 头无法解析', ['origin' => $origin]);
        throw new ApiException('请求来源不合法', 403);
    }

    $port = parse_url($origin, PHP_URL_PORT);
    $originHostPort = strtolower($host . ($port === null || $port === false ? '' : ':' . $port));
    if ($originHostPort !== strtolower(request_host())) {
        app_log('warning', '跨来源请求被拒绝', ['origin' => $origin, 'host' => request_host()]);
        throw new ApiException('请求来源不合法，请从本站页面操作', 403);
    }
}

/**
 * 面向前端的「站点绝对地址」，用于邮件里的验证 / 重置链接。
 * 配置里的 app_url 是唯一来源——不能拿 Host 头拼，那是用户可控的，
 * 攻击者可以把重置链接指向自己的域名（Host 头注入）。
 */
function absolute_url($path)
{
    $base = rtrim((string) cfg('app_url'), '/');
    if ($base === '') {
        throw new RuntimeException('配置项 app_url 未设置，无法生成邮件里的链接');
    }
    return $base . '/' . ltrim($path, '/');
}

/**
 * 生产环境的配置风险清单。doctor.php 会打印它，出了问题能一眼看出是配置还是代码。
 * @return string[]
 */
function security_warnings()
{
    $warnings = [];

    if (cfg('debug')) {
        $warnings[] = 'debug 处于打开状态：接口会把内部错误详情返回给前端，线上必须关掉（设 env=production 可强制关闭）';
    }
    if (!cfg('cookie_secure')) {
        $warnings[] = 'cookie_secure 为 false：登录 Cookie 会在 HTTP 上明文传输，上线配好 HTTPS 后必须打开';
    }
    if (cfg('admin_pass') === 'root') {
        $warnings[] = '初始管理员口令仍是演示用的 root/root：登录后台后请立刻改密码';
    }
    if (cfg('mail_driver') === 'log') {
        $warnings[] = 'mail_driver=log：验证 / 重置邮件只写进 var/mail 文件，没有真正发出去';
    }
    if (strpos((string) cfg('app_url'), 'localhost') !== false) {
        $warnings[] = 'app_url 仍指向 localhost：邮件里的链接会指向本机，上线后必须改成真实域名';
    }
    if (cfg('db_user') === 'root') {
        $warnings[] = '数据库用的是 root 账号：线上建议单独建一个只对 web_one 库有权限的账号';
    }
    if (cfg('soft_fetch_allow_private')) {
        $warnings[] = 'soft_fetch_allow_private 为 true：软件仓库允许去抓内网地址。这是给测试用的假源站留的口子，线上必须关掉';
    }

    return $warnings;
}

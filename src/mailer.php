<?php
/**
 * 邮件发送：邮箱验证与密码重置的投递通道。
 *
 * 三种驱动，用 config.php 的 mail_driver 切换：
 *   log   默认。不真的发信，把整封邮件写进 var/mail/YYYY-MM-DD.log。
 *         本地开发用这个最舒服——重置链接直接就能复制出来点。
 *   smtp  连真实 SMTP 服务器（支持 ssl 直连与 starttls 升级、AUTH LOGIN）。
 *   mail  交给 PHP 的 mail()，依赖本机的 sendmail / 邮件代理配置。
 *
 * 正文一律用 base64 编码：既绕开非 ASCII 字符在各家服务器上的编码差异，
 * 又天然不会出现「以 . 开头的行」——省掉 SMTP 的 dot-stuffing 处理。
 */

/** 发一封纯文本邮件；失败抛异常，由调用方决定要不要把它变成用户可见的错误 */
function send_mail($to, $subject, $body)
{
    $to = trim((string) $to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('收件地址不合法：' . $to);
    }

    // 头部字段绝不能带换行，否则等于把任意头（甚至整封新邮件）注入进来。
    // 顺便在这里就把非 ASCII 主题编码掉：三个驱动都直接用这个值，
    // 漏掉任何一处都会出现「本地日志里正常、真实邮箱里是乱码」这种难查的问题。
    // mail_encode_header() 内部已经包含净化步骤。
    $subject = mail_encode_header($subject);
    $driver = cfg('mail_driver');

    if ($driver === 'log') {
        return mail_driver_log($to, $subject, $body);
    }
    if ($driver === 'smtp') {
        return mail_driver_smtp($to, $subject, $body);
    }
    if ($driver === 'mail') {
        return mail_driver_mail($to, $subject, $body);
    }
    throw new RuntimeException('未知的 mail_driver：' . $driver);
}

// ============================================================
// 驱动一：log —— 写文件，开发期用
// ============================================================

function mail_driver_log($to, $subject, $body)
{
    $dir = cfg('mail_dir');
    if (!ensure_dir($dir)) {
        throw new RuntimeException('邮件目录不可写：' . $dir);
    }
    $file = rtrim($dir, '/\\') . '/' . date('Y-m-d') . '.log';

    $block = str_repeat('=', 72) . "\n"
        . '时间：' . date('Y-m-d H:i:s') . "\n"
        . '收件：' . $to . "\n"
        . '主题：' . mail_decode_header_for_log($subject) . "\n"
        . str_repeat('-', 72) . "\n"
        . $body . "\n\n";

    if (@file_put_contents($file, $block, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('写入邮件文件失败：' . $file);
    }
    return true;
}

// ============================================================
// 驱动二：mail —— PHP mail()
// ============================================================

function mail_driver_mail($to, $subject, $body)
{
    $from = mail_from_address();
    $headers = implode("\r\n", [
        'From: ' . mail_from_header(),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ]);
    $encoded = rtrim(chunk_split(base64_encode($body), 76, "\r\n"));

    $ok = @mail($to, $subject, $encoded, $headers);
    if ($ok === false) {
        throw new RuntimeException('PHP mail() 发送失败，请检查本机的邮件代理配置');
    }
    return true;
}

// ============================================================
// 驱动三：smtp —— 最小 SMTP 客户端
// ============================================================

function mail_driver_smtp($to, $subject, $body)
{
    $host = (string) cfg('smtp_host');
    $port = (int) cfg('smtp_port');
    $encryption = (string) cfg('smtp_encryption');
    $timeout = (int) cfg('smtp_timeout');

    $socket = smtp_open($host, $port, $encryption, $timeout);
    try {
        smtp_expect($socket, [220], '建立连接');

        smtp_write($socket, 'EHLO ' . smtp_helo_name());
        smtp_expect($socket, [250], 'EHLO');

        if ($encryption === 'starttls') {
            smtp_write($socket, 'STARTTLS');
            smtp_expect($socket, [220], 'STARTTLS');
            $ok = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) {
                throw new RuntimeException('STARTTLS 加密握手失败，请确认服务器支持 TLS');
            }
            // 升级加密后要重新打招呼，服务器会重新播报能力
            smtp_write($socket, 'EHLO ' . smtp_helo_name());
            smtp_expect($socket, [250], 'STARTTLS 后的 EHLO');
        }

        if ((string) cfg('smtp_user') !== '') {
            smtp_write($socket, 'AUTH LOGIN');
            smtp_expect($socket, [334], 'AUTH LOGIN');
            smtp_write($socket, base64_encode((string) cfg('smtp_user')));
            smtp_expect($socket, [334], 'AUTH 用户名');
            smtp_write($socket, base64_encode((string) cfg('smtp_pass')));
            smtp_expect($socket, [235], 'AUTH 口令');
        }

        smtp_write($socket, 'MAIL FROM:<' . mail_from_address() . '>');
        smtp_expect($socket, [250], 'MAIL FROM');

        smtp_write($socket, 'RCPT TO:<' . $to . '>');
        smtp_expect($socket, [250, 251], 'RCPT TO');

        smtp_write($socket, 'DATA');
        smtp_expect($socket, [354], 'DATA');

        // 正文是 base64，不会出现以 . 开头的行，因此不需要 dot-stuffing
        fwrite($socket, mail_build_message($to, $subject, $body) . "\r\n.\r\n");
        smtp_expect($socket, [250], '邮件正文');

        smtp_write($socket, 'QUIT');
    } finally {
        @fclose($socket);
    }

    return true;
}

/** 建立 TCP/SSL 连接 */
function smtp_open($host, $port, $encryption, $timeout)
{
    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        max(1, $timeout)
    );
    if ($socket === false) {
        throw new RuntimeException("连不上 SMTP 服务器 {$host}:{$port}（{$errstr}）");
    }
    stream_set_timeout($socket, max(1, $timeout));
    return $socket;
}

/**
 * 读一条 SMTP 响应。
 * 多行响应的格式是「250-能力A」「250-能力B」「250 最后一行」——
 * 只有第四列是空格的那一行才代表响应结束。
 *
 * @param array $expected 可接受的状态码
 */
function smtp_expect($socket, array $expected, $stage)
{
    $lines = [];
    while (($line = fgets($socket, 1024)) !== false) {
        $lines[] = rtrim($line, "\r\n");
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    if ($lines === []) {
        throw new RuntimeException("SMTP 在「{$stage}」阶段没有收到响应，连接可能已断开");
    }

    $code = (int) substr($lines[count($lines) - 1], 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException(
            "SMTP 在「{$stage}」阶段返回 {$code}：" . implode(' | ', $lines)
        );
    }
    return $code;
}

function smtp_write($socket, $line)
{
    fwrite($socket, $line . "\r\n");
}

/** EHLO 用的本机名：优先取 app_url 的域名，取不到就退回 localhost */
function smtp_helo_name()
{
    $host = parse_url((string) cfg('app_url'), PHP_URL_HOST);
    return ($host === null || $host === false || $host === '') ? 'localhost' : $host;
}

/** 拼出完整的 RFC 5322 邮件（base64 正文） */
function mail_build_message($to, $subject, $body)
{
    $headers = [
        'Date: ' . date('r'),
        'From: ' . mail_from_header(),
        'To: <' . $to . '>',
        'Subject: ' . $subject,
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . smtp_helo_name() . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $encoded = rtrim(chunk_split(base64_encode($body), 76, "\r\n"));

    return implode("\r\n", $headers) . "\r\n\r\n" . $encoded;
}

/** From 头的完整形式（带显示名） */
function mail_from_header()
{
    $address = mail_from_address();
    $name = mail_encode_header((string) cfg('mail_from_name'));
    return $name === '' ? $address : $name . ' <' . $address . '>';
}

/** 发件地址；配置里填了非法值就退回一个能过语法检查的本地地址 */
function mail_from_address()
{
    $from = trim((string) cfg('mail_from'));
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return $from;
    }
    return 'no-reply@' . smtp_helo_name();
}

/** 去掉会破坏头部结构的字符 */
function mail_sanitize_header($value)
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', (string) $value));
}

/** 非 ASCII 的头部要按 RFC 2047 编码，否则中文主题会变成乱码 */
function mail_encode_header($value)
{
    $value = mail_sanitize_header($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/** 日志用：把编码过的主题还原成中文，方便人直接看 */
function mail_decode_header_for_log($value)
{
    if (strpos($value, '=?UTF-8?B?') === 0) {
        $inner = substr($value, 10, -2);
        $decoded = base64_decode($inner, true);
        if ($decoded !== false) {
            return $decoded;
        }
    }
    return $value;
}

// ============================================================
// 业务邮件模板
// ============================================================

/** @return array ['subject'=>string, 'body'=>string] */
function mail_template_verify($username, $link, $ttlHours)
{
    $subject = '请验证你的邮箱';
    $body = <<<TEXT
{$username}，你好：

感谢注册。请点击下面的链接完成邮箱验证：

{$link}

链接 {$ttlHours} 小时内有效，且只能使用一次。
如果这不是你本人的操作，忽略本邮件即可，账号不会有任何变化。

—— web-one
TEXT;
    return ['subject' => $subject, 'body' => $body];
}

/** @return array ['subject'=>string, 'body'=>string] */
function mail_template_reset($username, $link, $ttlMinutes)
{
    $subject = '重置你的密码';
    $body = <<<TEXT
{$username}，你好：

我们收到了重置密码的请求。请点击下面的链接设置新密码：

{$link}

链接 {$ttlMinutes} 分钟内有效，且只能使用一次。
如果你没有发起这个请求，忽略本邮件即可，密码不会改变。

—— web-one
TEXT;
    return ['subject' => $subject, 'body' => $body];
}

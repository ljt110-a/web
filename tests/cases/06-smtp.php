<?php
/**
 * SMTP 客户端用例。
 *
 * src/mailer.php 里的 SMTP 客户端是本项目唯一「没法对着真实服务跑」的代码，
 * 所以这里起一个假的 SMTP 服务器（tests/fake_smtp.php），
 * 让客户端对着它走完 EHLO → AUTH LOGIN → MAIL FROM → RCPT TO → DATA → QUIT 全流程，
 * 再把抓到的对话逐项核对。这样这段代码就不是「写完没人验证过」的状态了。
 */

t_section('SMTP 客户端');

$root = dirname(__DIR__, 2);
$captureFile = $root . '/var/test-logs/smtp-capture.json';
$smtpPort = 2525;

@unlink($captureFile);
if (!is_dir(dirname($captureFile))) {
    mkdir(dirname($captureFile), 0775, true);
}

$fake = proc_open(
    t_command([PHP_BINARY, __DIR__ . '/../fake_smtp.php', $smtpPort, $captureFile]),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $fakePipes,
    $root
);

if (!is_resource($fake)) {
    t_fail('启动假 SMTP 服务器', 'proc_open 失败');
} elseif (!t_wait_for_server($smtpPort, 5)) {
    // 把子进程的 stderr 一起报出来——「启动失败」最没用的就是不给原因
    stream_set_blocking($fakePipes[2], false);
    $fakeStderr = trim((string) stream_get_contents($fakePipes[2]));
    t_fail('启动假 SMTP 服务器', '5 秒内没有开始监听 ' . $smtpPort . ($fakeStderr === '' ? '' : '；stderr：' . $fakeStderr));
} else {
    t_pass('假 SMTP 服务器已在 ' . $smtpPort . ' 端口就绪');

    // 直接改内存里的配置，让 send_mail 走 smtp 驱动（配置是从全局 $CONFIG 读的）
    $GLOBALS['CONFIG']['mail_driver'] = 'smtp';
    $GLOBALS['CONFIG']['smtp_host'] = '127.0.0.1';
    $GLOBALS['CONFIG']['smtp_port'] = $smtpPort;
    $GLOBALS['CONFIG']['smtp_encryption'] = 'none';
    $GLOBALS['CONFIG']['smtp_user'] = 'smtp-test-user';
    $GLOBALS['CONFIG']['smtp_pass'] = 'smtp-test-pass';
    $GLOBALS['CONFIG']['mail_from'] = 'no-reply@web-one.test';
    $GLOBALS['CONFIG']['smtp_timeout'] = 5;

    $body = "第一行：中文正文\n第二行：包含 = 和换行";
    $subject = 'SMTP 客户端自检';
    $sendError = '';
    try {
        send_mail('someone@example.com', $subject, $body);
    } catch (Throwable $e) {
        $sendError = $e->getMessage();
    }
    t_assert($sendError === '', '通过 SMTP 驱动成功发信', $sendError);

    // 等假服务器把抓包写完
    $captured = null;
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        if (is_file($captureFile)) {
            $decoded = json_decode((string) file_get_contents($captureFile), true);
            if (is_array($decoded) && isset($decoded['dialogue'])) {
                $captured = $decoded;
                break;
            }
        }
        usleep(100000);
    }

    if ($captured === null) {
        t_fail('读到 SMTP 抓包结果', '超时或文件格式不对');
    } else {
        t_pass('读到 SMTP 抓包结果');

        t_eq('smtp-test-user', $captured['auth_user'], 'AUTH LOGIN 提交了正确的用户名');
        t_eq('smtp-test-pass', $captured['auth_pass'], 'AUTH LOGIN 提交了正确的口令');
        t_contains($captured['from'], 'no-reply@web-one.test', 'MAIL FROM 用的是配置里的发件地址');
        t_eq(['<someone@example.com>'], $captured['rcpt'], 'RCPT TO 是收件人地址');

        t_contains($captured['body'], '第一行：中文正文', '正文 base64 解码后内容正确');
        t_contains($captured['body'], '第二行：包含 = 和换行', '正文的换行与特殊字符保持原样');

        t_contains($captured['headers'], 'MIME-Version: 1.0', '带上了 MIME 头');
        t_contains($captured['headers'], 'Content-Type: text/plain; charset=UTF-8', '正文声明为 UTF-8 纯文本');
        t_contains($captured['headers'], 'Content-Transfer-Encoding: base64', '正文用 base64 编码');
        t_assert(strpos($captured['headers'], 'Subject: =?UTF-8?B?') !== false, '非 ASCII 主题按 RFC 2047 编码，不会变成乱码');

        $dialogue = implode("\n", $captured['dialogue']);
        t_contains($dialogue, 'EHLO', '客户端发了 EHLO 打招呼');
        t_contains($dialogue, 'AUTH LOGIN', '客户端做了 AUTH LOGIN');
        t_contains($dialogue, 'DATA', '客户端进入了 DATA 阶段');
        t_contains($dialogue, 'QUIT', '客户端正常退出连接');
        t_assert(strpos($dialogue, 'STARTTLS') === false, '加密方式为 none 时不会发 STARTTLS');

        // 假服务器的 EHLO 是 4 行响应，能走到 AUTH 说明多行响应解析正确
        t_assert(strpos($dialogue, 'AUTH LOGIN') !== false, 'EHLO 的多行响应被正确读完（否则后续命令会错位）');
    }

    // 连不上的情况必须抛异常，不能静默「假装发出去了」
    $GLOBALS['CONFIG']['smtp_port'] = 2599;
    $connectionError = '';
    try {
        send_mail('someone@example.com', '连不上', '内容');
    } catch (Throwable $e) {
        $connectionError = $e->getMessage();
    }
    t_assert($connectionError !== '', 'SMTP 连不上时抛出异常而不是静默成功');
    t_contains($connectionError, '2599', '错误信息里带上了目标端口，便于排查');
}

@proc_terminate($fake);
@proc_close($fake);

t_section('邮件头注入防护');

$GLOBALS['CONFIG']['mail_driver'] = 'log';
t_clear_mail();

$injected = false;
try {
    send_mail('someone@example.com', "正常主题\r\nBcc: attacker@example.com", '正文');
    $injected = true;
} catch (Throwable $e) {
    t_fail('仍然能把邮件写进日志', $e->getMessage());
}
if ($injected) {
    $mail = t_last_mail();
    t_contains($mail, '正常主题', '主题里的正常内容保留');
    // 注意判据是「有没有以 Bcc: 开头的行」，而不是「文本里有没有 Bcc:」——
    // 被过滤掉的换行会变成空格，Bcc: 这几个字仍会作为主题正文留在同一行里
    t_assert(preg_match('/^Bcc:/m', $mail) === 0, '主题里的换行被过滤，无法注入额外的邮件头');
    t_assert(
        preg_match('/^主题：正常主题\s+Bcc: attacker@example\.com$/m', $mail) === 1,
        '换行被替换成空格，注入内容降级成同一行里的普通文本'
    );
}

$badRecipient = '';
try {
    send_mail('不是邮箱地址', '主题', '正文');
} catch (Throwable $e) {
    $badRecipient = $e->getMessage();
}
t_assert($badRecipient !== '', '收件地址不合法时直接拒绝');

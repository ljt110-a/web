<?php
/**
 * 假 SMTP 服务器（仅测试用）。
 *
 *   php tests/fake_smtp.php <端口> <抓包文件>
 *
 * 会一直监听，直到收到**一次完整的 SMTP 会话**（握手 → 可选认证 →
 * MAIL FROM / RCPT TO / DATA → QUIT），然后把这段对话、AUTH 凭据和
 * 邮件正文写进抓包文件，退出。
 *
 * 为什么是「循环接受连接」而不是只收一个连接：
 * 测试框架在正式连上来之前，会先用一个探测连接确认端口已经就绪。
 * 如果这里只处理一个连接，那个探测连接就会把唯一的机会用掉，
 * 真正要测的客户端反而什么都收不到——现象是「客户端连上了但服务器不响应」，
 * 很容易误判成客户端写得不对。
 *
 * 存在的意义：src/mailer.php 里的 SMTP 客户端是本项目唯一没法对着真实服务跑的代码，
 * 没有这个假服务器，它就只能靠肉眼审查。有了它，全流程都是被真实验证过的。
 */

$port = isset($argv[1]) ? (int) $argv[1] : 2525;
$captureFile = isset($argv[2]) ? $argv[2] : null;

if ($captureFile === null) {
    fwrite(STDERR, "用法：php tests/fake_smtp.php <端口> <抓包文件>\n");
    exit(1);
}

function capture_write($file, array $payload)
{
    file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * 处理一个连接上的完整 SMTP 对话。
 * @return array 会话记录，含 completed / saw_command 两个判定用的标志
 */
function handle_session($connection)
{
    $dialogue = [];

    $parsed = [
        'auth_user' => null,
        'auth_pass' => null,
        'from' => null,
        'rcpt' => [],
        'headers' => '',
        'body' => '',
        'raw_bytes' => 0,
        'completed' => false,
        'saw_command' => false,
    ];

    $authStep = 0;
    $inData = false;
    $rawData = '';

    fwrite($connection, "220 fake.web-one.test ESMTP ready\r\n");

    while (($line = fgets($connection, 4096)) !== false) {
        $dialogue[] = 'C: ' . rtrim($line, "\r\n");

        if ($inData) {
            if (rtrim($line, "\r\n") === '.') {
                $inData = false;
                $parsed['completed'] = true;
                fwrite($connection, "250 2.0.0 Ok: queued as FAKE1\r\n");
                continue;
            }
            $rawData .= $line;
            continue;
        }

        $command = rtrim($line, "\r\n");
        $upper = strtoupper($command);

        // AUTH LOGIN 是一个三步对话：命令 → 用户名 → 口令
        if ($authStep === 1) {
            $parsed['auth_user'] = base64_decode($command, true);
            $authStep = 2;
            fwrite($connection, "334 UGFzc3dvcmQ6\r\n");
            continue;
        }
        if ($authStep === 2) {
            $parsed['auth_pass'] = base64_decode($command, true);
            $authStep = 0;
            fwrite($connection, "235 2.7.0 Authentication successful\r\n");
            continue;
        }

        if ($command === '') {
            continue;
        }
        $parsed['saw_command'] = true;

        if (strpos($upper, 'EHLO') === 0 || strpos($upper, 'HELO') === 0) {
            // 故意用多行响应：正好验证客户端能正确读到「250 空格」那一行才算结束
            fwrite($connection, "250-fake.web-one.test\r\n250-AUTH LOGIN PLAIN\r\n250-SIZE 10485760\r\n250 8BITMIME\r\n");
            continue;
        }
        if (strpos($upper, 'AUTH LOGIN') === 0) {
            $authStep = 1;
            fwrite($connection, "334 VXNlcm5hbWU6\r\n");
            continue;
        }
        if (strpos($upper, 'MAIL FROM:') === 0) {
            $parsed['from'] = trim(substr($command, 10));
            fwrite($connection, "250 2.1.0 Ok\r\n");
            continue;
        }
        if (strpos($upper, 'RCPT TO:') === 0) {
            $parsed['rcpt'][] = trim(substr($command, 8));
            fwrite($connection, "250 2.1.5 Ok\r\n");
            continue;
        }
        if ($upper === 'DATA') {
            $inData = true;
            fwrite($connection, "354 End data with <CR><LF>.<CR><LF>\r\n");
            continue;
        }
        if ($upper === 'QUIT') {
            fwrite($connection, "221 2.0.0 Bye\r\n");
            break;
        }
        if ($upper === 'RSET' || $upper === 'NOOP') {
            fwrite($connection, "250 2.0.0 Ok\r\n");
            continue;
        }

        fwrite($connection, "502 5.5.2 Command not implemented\r\n");
    }

    $parsed['raw_bytes'] = strlen($rawData);
    $parts = explode("\r\n\r\n", $rawData, 2);
    if (count($parts) === 2) {
        $parsed['headers'] = $parts[0];
        $decoded = base64_decode($parts[1], true);
        $parsed['body'] = $decoded === false ? '' : $decoded;
    }

    // 对话记录最后再挂上：上面的处理过程里它是独立变量，读起来更清楚
    $parsed['dialogue'] = $dialogue;

    return $parsed;
}

$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    capture_write($captureFile, ['error' => "无法监听 {$port}：{$errstr}"]);
    exit(1);
}

$deadline = time() + 30;
while (time() < $deadline) {
    $connection = @stream_socket_accept($server, 5);
    if ($connection === false) {
        continue;
    }

    $session = handle_session($connection);
    fclose($connection);

    if ($session['completed']) {
        capture_write($captureFile, $session);
        fclose($server);
        exit(0);
    }

    // 探测连接（只连上来就断开）什么都不会写，继续等真正的客户端
}

capture_write($captureFile, ['error' => '30 秒内没有收到一次完整的 SMTP 会话']);
fclose($server);
exit(1);

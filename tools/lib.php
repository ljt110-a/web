<?php
/**
 * 启动 / 停止脚本共用的底层工具。
 *
 * 为什么这些逻辑放在 PHP 里而不是批处理里：
 *   1. .bat 必须是纯 ASCII —— cmd.exe 是「按当前代码页逐字节」读批处理文件的，
 *      文件里一有中文，多字节序列就会让它读错行（切到 65001 也一样，
 *      这是 cmd 由来已久的问题）。所以中文输出与判断逻辑都交给 PHP，那里是 UTF-8。
 *   2. 判断「端口上是不是本项目的服务」要发 HTTP 请求再解析 JSON，
 *      netstat 只能看出「被占了」，看不出「被谁占了」。
 *   3. 放 PHP 里可以直接 `php tools/launch.php --check=8000` 单独验证。
 *
 * 这里用到的 netstat / tasklist 是本地维护脚本在跑，不在 Web 请求路径上；
 * 网页接口那边一律不依赖系统命令（见 src/system.php 的说明）。
 */

/** 拼本机地址：IPv6 要加方括号 */
function local_url($host, $port, $path)
{
    return 'http://' . ($host === '::1' ? '[::1]' : $host) . ':' . $port . $path;
}

/**
 * 端口上有没有人监听。
 * IPv4 与 IPv6 都要试：`php -S localhost:8000` 在有的系统上只绑 ::1，
 * 只探 127.0.0.1 会误判成「空闲」，随后启动第二个服务器就会失败。
 */
function port_is_open($port)
{
    foreach (['127.0.0.1', '::1'] as $host) {
        $socket = @fsockopen($host, (int) $port, $errno, $errstr, 0.4);
        if ($socket !== false) {
            fclose($socket);
            return true;
        }
    }
    return false;
}

/**
 * 端口状态：
 *   free  = 没人监听，可以用
 *   ours  = 已经是本项目的服务（认得出 /api/health）
 *   other = 被别的程序占着
 */
function port_status($port)
{
    if (!port_is_open($port)) {
        return 'free';
    }

    $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    foreach (['127.0.0.1', '::1'] as $host) {
        $body = @file_get_contents(local_url($host, (int) $port, '/api/health'), false, $context);
        if ($body === false) {
            continue;
        }
        $json = json_decode($body, true);
        // /api/health 是本项目独有的接口，返回的 JSON 一定带 status 与 database
        if (is_array($json) && isset($json['status']) && isset($json['database'])) {
            return 'ours';
        }
    }
    return 'other';
}

/**
 * 等服务器真的能响应。
 *
 * 只要求「服务器有没有回应」，不要求数据库连通：
 * /api/health 在数据库连不上时会返回 503 加一段带 status 的 JSON，
 * 那也说明服务本身活着——数据库的问题该由前面的安装步骤负责报错。
 */
function wait_ready($port, $timeout = 15)
{
    $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
    $deadline = microtime(true) + max(1, (int) $timeout);

    while (microtime(true) < $deadline) {
        foreach (['127.0.0.1', '::1'] as $host) {
            $body = @file_get_contents(local_url($host, (int) $port, '/api/health'), false, $context);
            if ($body === false) {
                continue;
            }
            $json = json_decode($body, true);
            if (is_array($json) && isset($json['status'])) {
                return true;
            }
        }
        usleep(200000);   // 200 毫秒后再试
    }
    return false;
}

/**
 * 监听某个端口的进程是谁。
 * @return array|null ['pid' => int, 'image' => string]
 */
function port_owner($port)
{
    $lines = [];
    // 只列 TCP，少解析一堆 UDP 行
    @exec('netstat -ano -p TCP 2>nul', $lines);

    $pid = null;
    foreach ($lines as $line) {
        // 形如：TCP    127.0.0.1:8000    0.0.0.0:0    LISTENING    12345
        if (preg_match('/^\s*TCP\s+\S*:' . (int) $port . '\s+\S+\s+LISTENING\s+(\d+)/i', $line, $m)) {
            $pid = (int) $m[1];
            break;
        }
    }
    if ($pid === null || $pid === 0) {
        return null;
    }

    $image = '';
    $out = [];
    @exec('tasklist /FI "PID eq ' . $pid . '" /NH /FO CSV 2>nul', $out);
    if (isset($out[0]) && preg_match('/^"([^"]+)"/', $out[0], $m2)) {
        $image = $m2[1];
    }

    return ['pid' => $pid, 'image' => $image];
}

/** 项目根目录（tools/ 的上一级） */
function project_root()
{
    return dirname(__DIR__);
}

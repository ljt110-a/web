<?php
/**
 * 测试基础设施：断言、HTTP 客户端、Cookie 罐、邮件读取。
 *
 * 刻意不引入 PHPUnit 之类的依赖：这个项目要求「clone 下来就能跑」，
 * 而跑测试的门槛一旦变成「先装 composer」，测试就会慢慢没人跑。
 * 这里只需要 PHP 本身 + 自带的 pdo_mysql。
 */

// ------------------------------------------------------------
// 断言与结果统计
// ------------------------------------------------------------

/**
 * 状态数组的**引用**。所有写入都必须经过它。
 *
 * 这里踩过一个坑：最初 t_section() 直接写 $GLOBALS['T_STATE']['section']，
 * 于是数组在 t_state() 之前就被创建了，t_state() 的 isset 判断为真、跳过初始化，
 * 结果 passed/failed/failures 三个键根本不存在——
 * 表现是「全部用例通过，汇总却报失败 0 项」还带一个 foreach 警告。
 * 所以现在统一由这个函数保证四个键一定齐全，不管谁先被调用。
 */
function &t_state_ref()
{
    if (!isset($GLOBALS['T_STATE']) || !is_array($GLOBALS['T_STATE'])) {
        $GLOBALS['T_STATE'] = [];
    }
    $defaults = ['passed' => 0, 'failed' => 0, 'failures' => [], 'section' => ''];
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $GLOBALS['T_STATE'])) {
            $GLOBALS['T_STATE'][$key] = $value;
        }
    }
    return $GLOBALS['T_STATE'];
}

/** 状态的只读副本（拿来做判断和汇总用） */
function t_state()
{
    $state = t_state_ref();
    return $state;
}

function t_section($title)
{
    $state = &t_state_ref();
    $state['section'] = $title;
    echo "\n── {$title} " . str_repeat('─', max(0, 58 - mb_strlen($title, 'UTF-8'))) . "\n";
}

function t_pass($label)
{
    $state = &t_state_ref();
    $state['passed']++;
    echo "  ✓ {$label}\n";
}

function t_fail($label, $detail = '')
{
    $state = &t_state_ref();
    $state['failed']++;
    $state['failures'][] = $state['section'] . ' › ' . $label . ($detail === '' ? '' : '  → ' . $detail);
    echo "  ✗ {$label}" . ($detail === '' ? '' : "\n      → {$detail}") . "\n";
}

/** 核心断言：失败时把实际值与期望值都打出来，方便直接定位 */
function t_assert($condition, $label, $detail = '')
{
    if ($condition) {
        t_pass($label);
        return true;
    }
    t_fail($label, $detail);
    return false;
}

function t_eq($expected, $actual, $label)
{
    return t_assert(
        $expected === $actual,
        $label,
        '期望 ' . t_show($expected) . '，实际 ' . t_show($actual)
    );
}

/** 值渲染成一行易读的文本 */
function t_show($value)
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_null($value)) {
        return 'null';
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    $text = (string) $value;
    return mb_strlen($text, 'UTF-8') > 160 ? mb_substr($text, 0, 160, 'UTF-8') . '…' : $text;
}

/** 响应文本里是否包含某段内容 */
function t_contains($haystack, $needle, $label)
{
    return t_assert(
        strpos((string) $haystack, $needle) !== false,
        $label,
        '在响应里找不到 ' . t_show($needle)
    );
}

// ------------------------------------------------------------
// HTTP 客户端
// ------------------------------------------------------------

function t_base_url()
{
    return 'http://127.0.0.1:' . $GLOBALS['T_PORT'];
}

/** 每次请求发出去前，把请求也记一份，失败时能复现 */
function t_log_request($method, $path, $note)
{
    $GLOBALS['T_REQUESTS'][] = "{$method} {$path}" . ($note === '' ? '' : " ({$note})");
}

/**
 * 发一个请求。
 *
 * @param array $options 支持：
 *   json         => array  以 application/json 提交的请求体
 *   raw_body     => string 原样提交的请求体（用来测「不是合法 JSON」的情况）
 *   content_type => string 覆盖 Content-Type（传 '' 表示故意不带）
 *   headers      => array  额外请求头
 *   jar          => string 用哪个 Cookie 罐（默认 default）
 *   jsession     => string 直接用某个令牌当 Cookie（模拟另一台设备）
 */
function t_request($method, $path, array $options = [])
{
    $url = t_base_url() . $path;
    $headers = isset($options['headers']) ? $options['headers'] : [];
    $body = '';
    $hasContentType = false;

    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'Content-Type') === 0) {
            $hasContentType = true;
        }
    }

    if (array_key_exists('json', $options)) {
        $body = json_encode($options['json'], JSON_UNESCAPED_UNICODE);
        if (!$hasContentType) {
            $headers['Content-Type'] = 'application/json';
        }
    } elseif (array_key_exists('raw_body', $options)) {
        $body = $options['raw_body'];
        if (isset($options['content_type'])) {
            if ($options['content_type'] !== '') {
                $headers['Content-Type'] = $options['content_type'];
            }
        } elseif (!$hasContentType) {
            $headers['Content-Type'] = 'application/json';
        }
    }

    // 组装 Cookie：既能用罐里的，也能临时塞一个指定令牌
    $jarName = isset($options['jar']) ? $options['jar'] : 'default';
    $cookies = t_jar_cookies($jarName);
    if (isset($options['jsession'])) {
        $cookies[WEB_ONE_COOKIE_NAME] = $options['jsession'];
    }
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headers['Cookie'] = implode('; ', $pairs);
    }

    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'ignore_errors' => true,   // 4xx/5xx 也要拿到响应体，而不是让 file_get_contents 返回 false
            'timeout' => 15,
        ],
    ]);

    t_log_request($method, $path, $jarName);

    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = isset($http_response_header) ? $http_response_header : [];

    if ($responseBody === false && $responseHeaders === []) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'json' => null];
    }

    t_capture_cookies($jarName, $responseHeaders);

    $status = 0;
    $parsed = [];
    foreach ($responseHeaders as $index => $line) {
        if ($index === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $parsed[strtolower(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
    }

    $decoded = json_decode((string) $responseBody, true);

    return [
        'status' => $status,
        'headers' => $parsed,
        'body' => (string) $responseBody,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

// ------------------------------------------------------------
// Cookie 罐：一个罐相当于一个浏览器
// ------------------------------------------------------------

function t_jar_cookies($name)
{
    if (!isset($GLOBALS['T_JARS'][$name])) {
        $GLOBALS['T_JARS'][$name] = [];
    }
    return $GLOBALS['T_JARS'][$name];
}

function t_capture_cookies($jarName, array $responseHeaders)
{
    if (!isset($GLOBALS['T_JARS'][$jarName])) {
        $GLOBALS['T_JARS'][$jarName] = [];
    }
    foreach ($responseHeaders as $line) {
        if (stripos($line, 'Set-Cookie:') !== 0) {
            continue;
        }
        $cookie = trim(substr($line, 11));
        $firstPart = substr($cookie, 0, strpos($cookie, ';') === false ? strlen($cookie) : strpos($cookie, ';'));
        $eq = strpos($firstPart, '=');
        if ($eq === false) {
            continue;
        }
        $name = trim(substr($firstPart, 0, $eq));
        $value = trim(substr($firstPart, $eq + 1));
        if ($value === '') {
            // 退出登录就是把值清空：罐里也该跟着删掉
            unset($GLOBALS['T_JARS'][$jarName][$name]);
        } else {
            $GLOBALS['T_JARS'][$jarName][$name] = $value;
        }
    }
}

/** 清空某个罐（模拟换浏览器 / 清 Cookie） */
function t_clear_jar($name = 'default')
{
    $GLOBALS['T_JARS'][$name] = [];
}

/** 从罐里取出当前登录令牌原文 */
function t_token($name = 'default')
{
    $cookies = t_jar_cookies($name);
    return isset($cookies[WEB_ONE_COOKIE_NAME]) ? $cookies[WEB_ONE_COOKIE_NAME] : null;
}

// ------------------------------------------------------------
// 邮件（mail_driver=log 时写进 var/test-mail）
// ------------------------------------------------------------

function t_mail_file()
{
    $dir = $GLOBALS['T_MAIL_DIR'];
    if (!is_dir($dir)) {
        return null;
    }
    $files = glob(rtrim($dir, '/\\') . '/*.log');
    if ($files === false || $files === []) {
        return null;
    }
    sort($files);
    return $files[count($files) - 1];
}

/** 清空邮件目录，让每个用例只看自己发出的那几封 */
function t_clear_mail()
{
    $dir = $GLOBALS['T_MAIL_DIR'];
    if (!is_dir($dir)) {
        return;
    }
    foreach ((array) glob(rtrim($dir, '/\\') . '/*.log') as $file) {
        @unlink($file);
    }
}

/** @return string[] 每封邮件一个字符串 */
function t_mail_blocks()
{
    $file = t_mail_file();
    if ($file === null) {
        return [];
    }
    $content = (string) file_get_contents($file);
    $blocks = [];
    foreach (explode(str_repeat('=', 72), $content) as $block) {
        $block = trim($block);
        if ($block !== '') {
            $blocks[] = $block;
        }
    }
    return $blocks;
}

function t_last_mail()
{
    $blocks = t_mail_blocks();
    return $blocks === [] ? '' : $blocks[count($blocks) - 1];
}

/** 从邮件正文里抠出链接上的令牌 */
function t_token_from_mail($param, $block = null)
{
    $block = $block === null ? t_last_mail() : $block;
    if (preg_match('/[?&]' . preg_quote($param, '/') . '=([0-9a-f]{64})/', $block, $m)) {
        return $m[1];
    }
    return null;
}

// ------------------------------------------------------------
// 被测服务器进程
// ------------------------------------------------------------

/**
 * 把路径统一成当前系统的分隔符。
 * Windows 的 cmd 对 `E:\a/b` 这种混用斜杠的路径会直接报
 * “filename, directory name, or volume label syntax is incorrect”，
 * 所以凡是交给 shell 的路径都要先过一遍这里。
 */
function t_native_path($path)
{
    return DIRECTORY_SEPARATOR === '\\' ? str_replace('/', '\\', $path) : $path;
}

/**
 * 把参数列表拼成一条可以交给 proc_open 的命令。
 *
 * 坑点：在 Windows 上，cmd.exe 会按自己的规则处理引号——
 * 当命令行里出现**两个以上**带引号的片段时（`"php.exe" "script.php" ...`），
 * 它会把引号剥错，拼出一条非法路径，报
 * “The filename, directory name, or volume label syntax is incorrect”，
 * 而错误信息里完全看不出跟引号有关。
 * 解法是每个参数各自加引号之后，整条命令再包一层引号，cmd 就会老实解析。
 * 这样即使路径里带空格也是安全的。
 */
function t_command(array $args)
{
    $quoted = [];
    foreach ($args as $arg) {
        $quoted[] = escapeshellarg(t_native_path($arg));
    }
    $command = implode(' ', $quoted);
    return DIRECTORY_SEPARATOR === '\\' ? '"' . $command . '"' : $command;
}

/**
 * 以独立进程启动 PHP 内置服务器，并注入测试用的环境变量。
 * 之所以要独立进程：配置在 bootstrap 时就被读进内存了，
 * 同一进程里改环境变量无法影响已经加载的配置。
 */
function t_server_start($root, $port, array $envOverrides)
{
    // getenv() 不带参数会返回全部环境变量，避免 proc_open 把 PATH 之类的清空
    $env = getenv();
    foreach ($envOverrides as $key => $value) {
        $env[$key] = (string) $value;
    }

    $logDir = $root . '/var/test-logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $command = t_command([
        PHP_BINARY,
        '-S', '127.0.0.1:' . (int) $port,
        '-t', 'public',
        'public/index.php',
    ]);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', t_native_path($logDir) . '/server.out.log', 'a'],
        2 => ['file', t_native_path($logDir) . '/server.err.log', 'a'],
    ];

    $pipes = [];
    $process = proc_open($command, $descriptors, $pipes, $root, $env);
    if (!is_resource($process)) {
        return null;
    }
    $GLOBALS['T_SERVER'] = $process;

    return $process;
}

/** 等服务器开始监听；超时返回 false */
function t_wait_for_server($port, $timeoutSeconds = 10)
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', (int) $port, $errno, $errstr, 0.2);
        if ($socket !== false) {
            fclose($socket);
            return true;
        }
        usleep(100000);
    }
    return false;
}

function t_server_stop()
{
    if (!isset($GLOBALS['T_SERVER']) || !is_resource($GLOBALS['T_SERVER'])) {
        return;
    }
    proc_terminate($GLOBALS['T_SERVER']);
    proc_close($GLOBALS['T_SERVER']);
    $GLOBALS['T_SERVER'] = null;
}

/** 服务器 stderr 的最后若干行，启动失败时用来看原因 */
function t_server_log($root, $lines = 15)
{
    $file = $root . '/var/test-logs/server.err.log';
    if (!is_file($file)) {
        return '（没有 server.err.log）';
    }
    $content = explode("\n", trim((string) file_get_contents($file)));
    return implode("\n", array_slice($content, -$lines));
}

// ------------------------------------------------------------
// 收尾
// ------------------------------------------------------------

function t_summary()
{
    $state = t_state();
    $total = $state['passed'] + $state['failed'];

    echo "\n" . str_repeat('=', 64) . "\n";
    if ($state['failed'] === 0) {
        echo "全部通过：{$state['passed']}/{$total}\n";
        echo str_repeat('=', 64) . "\n";
        return 0;
    }

    echo "失败 {$state['failed']} 项（通过 {$state['passed']}/{$total}）：\n";
    foreach ($state['failures'] as $failure) {
        echo "  · {$failure}\n";
    }
    echo str_repeat('=', 64) . "\n";
    return 1;
}

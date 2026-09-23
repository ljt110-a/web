<?php
/**
 * 一键启动：检查环境 → 安装/升级数据库 → 挑端口 → 启动服务器 → 等就绪 → 打开浏览器。
 *
 *   php tools/launch.php                  正常启动
 *   php tools/launch.php --no-browser     只启动，不开浏览器
 *   php tools/launch.php --port=9000      指定端口（不指定就自动挑）
 *   php tools/launch.php --check=8000     只看某个端口是什么情况，不做任何事
 *
 * 由 start.bat 调用，也可以直接在命令行里跑——所有逻辑都在这一个文件里，
 * 就是为了能单独验证，而不是把判断塞进一坨批处理里只能靠双击试。
 *
 * 中文输出放在这里而不是 .bat 里，是因为 cmd.exe 按当前代码页逐字节读批处理文件，
 * 文件里一有中文就会读错行；PHP 这边是 UTF-8，没有这个问题。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("launch.php 只允许在命令行运行\n");
}

require __DIR__ . '/lib.php';

$root = project_root();
$argvList = isset($argv) ? $argv : [];

/** 从命令行参数里取 --key=value */
function arg_value(array $argvList, $key, $default = null)
{
    foreach ($argvList as $arg) {
        if (strpos($arg, '--' . $key . '=') === 0) {
            return substr($arg, strlen($key) + 3);
        }
    }
    return $default;
}

$checkPort = arg_value($argvList, 'check');
$forcePort = arg_value($argvList, 'port');
$noBrowser = in_array('--no-browser', $argvList, true)
    || getenv('WEBONE_NO_BROWSER') === '1';

// ------------------------------------------------------------
// 诊断模式：只报告某个端口的状况，什么都不改
// ------------------------------------------------------------
if ($checkPort !== null) {
    $port = (int) $checkPort;
    $status = port_status($port);
    $owner = port_owner($port);
    echo $port . ' → ' . $status;
    if ($owner !== null) {
        echo '（PID ' . $owner['pid'] . '，' . ($owner['image'] === '' ? '未知程序' : $owner['image']) . '）';
    }
    echo "\n";
    exit(0);
}

echo "============================================================\n";
echo "  web-one 一键启动\n";
echo "============================================================\n\n";

// ------------------------------------------------------------
// 1/4 配置
// ------------------------------------------------------------
$configFile = $root . '/src/config.local.php';
if (!is_file($configFile)) {
    echo "[1/4] 缺少 src/config.local.php\n\n";
    echo "  先执行一次：\n";
    echo "      copy src\\config.example.php src\\config.local.php\n";
    echo "  再把里面的 db_pass 改成你本机 MySQL 的口令。\n\n";
    exit(1);
}
echo "[1/4] 配置就绪\n";

// ------------------------------------------------------------
// 2/4 数据库
// install.php 是幂等的：库表已存在就跳过，管理员已存在也不会覆盖密码。
// 每次启动顺手跑一遍，既能补上缺的表，也能把旧库升级到最新结构。
// ------------------------------------------------------------
echo "[2/4] 检查数据库...\n";
$exitCode = 0;
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/database/install.php'), $exitCode);
if ($exitCode !== 0) {
    echo "\n[失败] 数据库安装/升级没有通过，原因见上面的输出。\n\n";
    echo "  想看详细诊断，执行：\n";
    echo '      ' . PHP_BINARY . ' database/doctor.php' . "\n\n";
    exit(1);
}

// ------------------------------------------------------------
// 3/4 挑端口
// ------------------------------------------------------------
$candidates = $forcePort !== null
    ? [(int) $forcePort]
    : [8000, 8001, 8002, 8003, 8004, 8080];

$port = null;
$alreadyRunning = false;
foreach ($candidates as $candidate) {
    $status = port_status($candidate);
    if ($status === 'free') {
        $port = $candidate;
        break;
    }
    if ($status === 'ours') {
        $port = $candidate;
        $alreadyRunning = true;
        break;
    }
    // other：别人占着，试下一个
}

if ($port === null) {
    echo "\n[失败] 这些端口都被占用了：" . implode('、', $candidates) . "\n\n";
    echo "  可以手动指定一个端口再启动：\n";
    echo '      ' . PHP_BINARY . " tools/launch.php --port=9000\n\n";
    exit(1);
}

// ------------------------------------------------------------
// 4/4 启动服务器（已经在跑就跳过）
// ------------------------------------------------------------
if ($alreadyRunning) {
    echo "[3/4] 服务已经在 {$port} 端口上跑着了\n";
    echo "[4/4] 跳过启动，直接打开浏览器\n";
} else {
    echo "[3/4] 选用 {$port} 端口\n";
    // 把端口记下来，stop.php 靠它找到该停哪一个
    $portFile = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'running.port';
    @file_put_contents($portFile, (string) $port);

    // 用 start 另开一个最小化的窗口跑服务器：
    // 关掉那个窗口就等于停止服务，而且它不会跟着本窗口一起退出。
    //
    // 为什么不把这行命令直接塞进 exec()：那会变成多层嵌套引号，
    // 而 cmd 对引号的处理很容易出错。改成先写一个只有两行的临时 .bat，
    // 再 start 那个文件——只传一个带引号的路径，简单可靠。
    //
    // 服务器的输出必须重定向到文件（而不是留在这个控制台里）：
    // 否则那个分离出去的子进程会一直握着本进程的 stdout 管道，
    // 调用方（start.bat、或者任何把输出接进管道的脚本）会一直等不到 EOF 而卡住。
    // 顺带的好处是有了 var/server.log 可以事后翻。
    $runner = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'run-server.bat';
    $logFile = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'server.log';
    $runnerBody = "@echo off\r\n"
        . 'cd /d "' . $root . "\"\r\n"
        . '"' . PHP_BINARY . '" -S 127.0.0.1:' . $port . ' -t public public\\index.php'
        . ' > "' . $logFile . "\" 2>&1\r\n";
    if (@file_put_contents($runner, $runnerBody) === false) {
        echo "\n[失败] 写不了 var/run-server.bat，请确认 var/ 目录可写。\n\n";
        exit(1);
    }

    // 同理，start 自身也不要往本进程的管道里写东西
    @exec('start "web-one server" /MIN "' . $runner . '" >nul 2>&1');

    echo "[4/4] 启动服务器并等待就绪...\n";
    if (!wait_ready($port, 20)) {
        echo "\n[失败] 服务器 20 秒内没有响应。\n\n";
        echo "  可能是端口 {$port} 被防火墙拦了，或者 PHP 启动失败。\n";
        echo "  手动跑一次看看它报什么错：\n";
        echo '      ' . PHP_BINARY . " -S 127.0.0.1:{$port} -t public public\\index.php\n\n";
        exit(1);
    }
    echo "      服务器已就绪\n";
}

// ------------------------------------------------------------
// 打开浏览器
// ------------------------------------------------------------
$url = 'http://127.0.0.1:' . $port;
echo "\n  网站地址： {$url}\n\n";

if ($noBrowser) {
    echo "  （--no-browser 或 WEBONE_NO_BROWSER=1，已跳过自动打开浏览器）\n\n";
} else {
    // /c start "" "url" —— 第一个空引号是窗口标题的位置，不能省
    @exec('start "" "' . $url . '"');
}

echo "  服务器日志： var/server.log\n";
echo "  停止服务：  双击 stop.bat，或关掉那个最小化的「web-one server」窗口\n\n";
exit(0);

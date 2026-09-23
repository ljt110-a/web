<?php
/**
 * 停止后台运行的服务器。
 *
 *   php tools/stop.php               停掉启动器起的那一个
 *   php tools/stop.php --port=8000   指定端口
 *   php tools/stop.php --check       只看看现在是什么情况，不动手
 *
 * 由 stop.bat 调用，也可以直接跑。
 *
 * 两条安全护栏：
 *   1. 只结束 php.exe。端口可能被别的程序占着，替用户杀掉陌生的进程越界了。
 *   2. 找不到端口记录时默认按 8000 找，但仍要走第 1 条检查。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("stop.php 只允许在命令行运行\n");
}

require __DIR__ . '/lib.php';

$root = project_root();
$argvList = isset($argv) ? $argv : [];

$forcePort = null;
$checkOnly = false;
foreach ($argvList as $arg) {
    if (strpos($arg, '--port=') === 0) {
        $forcePort = (int) substr($arg, 7);
    }
    if ($arg === '--check') {
        $checkOnly = true;
    }
}

// 端口从启动时记下的文件里读；没有就用默认的 8000
$port = $forcePort;
$fromFile = false;
if ($port === null) {
    $portFile = $root . '/var/running.port';
    if (is_file($portFile)) {
        $port = (int) trim((string) file_get_contents($portFile));
        $fromFile = true;
    }
    if ($port <= 0) {
        $port = 8000;
    }
}

echo "============================================================\n";
echo "  停止 web-one 服务\n";
echo "============================================================\n\n";
echo '检查端口：' . $port . ($fromFile ? '（来自 var/running.port）' : '（默认值）') . "\n\n";

$owner = port_owner($port);

if ($owner === null) {
    echo "这个端口上没有人在监听，服务本来就没在跑。\n";
    @unlink($root . '/var/running.port');
    echo "\n（顺手清掉了 var/running.port）\n\n";
    exit(0);
}

echo '占用进程：PID ' . $owner['pid'] . '（' . ($owner['image'] === '' ? '取不到名字' : $owner['image']) . "）\n";

if (strcasecmp($owner['image'], 'php.exe') !== 0) {
    echo "\n[没有动手] 这个端口上跑的不是 php.exe，为安全起见不结束它。\n";
    echo '  如果确认是本项目占着，请手动结束 PID ' . $owner['pid'] . "。\n\n";
    exit(1);
}

if ($checkOnly) {
    echo "\n（--check：只看不动手）\n\n";
    exit(0);
}

$out = [];
$code = 0;
exec('taskkill /PID ' . $owner['pid'] . ' /F 2>&1', $out, $code);
if ($code !== 0) {
    echo "\n[失败] 结束进程没有成功：\n  " . implode("\n  ", $out) . "\n";
    echo "  可能需要以管理员身份运行。\n\n";
    exit(1);
}

@unlink($root . '/var/running.port');
echo "\n已停止（PID " . $owner['pid'] . "）。\n\n";
exit(0);

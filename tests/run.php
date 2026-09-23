<?php
/**
 * 端到端测试入口：
 *
 *   php tests/run.php
 *
 * 它做的事情：
 *   1) 把配置用环境变量固定成「测试专用」（独立测试库、邮件写文件、限流阈值调小）
 *   2) 重建测试库 web_one_test（不动你的 web_one，随便跑）
 *   3) 起一个独立的 PHP 内置服务器
 *   4) 依次跑 tests/cases 下的用例文件
 *   5) 关服务器、打印汇总，全通过退出码 0，有失败退出码 1
 *
 * 用例覆盖的是「行为」而不是「实现」：全部通过 HTTP 接口验证，
 * 所以重构内部代码不会轻易把测试改坏，但真实的接口契约变化一定会被发现。
 */

$root = dirname(__DIR__);

// 需要一个没被占用的端口；8110 起，逐个往上试
$port = 8123;

$env = [
    'WEB_ONE_ENV' => 'local',
    'WEB_ONE_DB_NAME' => 'web_one_test',
    'WEB_ONE_APP_URL' => 'http://127.0.0.1:' . $port,
    // 邮件走 log 驱动：内容写进 var/test-mail，测试直接从里面抠令牌
    'WEB_ONE_MAIL_DRIVER' => 'log',
    'WEB_ONE_MAIL_DIR' => $root . '/var/test-mail',
    'WEB_ONE_LOG_DIR' => $root . '/var/test-logs',
    // 打开 debug：测试失败时能看到真实的异常信息，而不是「服务器内部错误」
    'WEB_ONE_DEBUG' => '1',
    'WEB_ONE_ADMIN_USER' => 'root',
    'WEB_ONE_ADMIN_PASS' => 'adminpass123',
    // 限流阈值调小，好在几秒钟内把「锁账号」和「锁网络」都验证到；
    // 每 IP 用 8 而不是 3，是为了让「每用户阈值」的用例先命中它自己的规则
    'WEB_ONE_THROTTLE_MAX_PER_USER' => '3',
    'WEB_ONE_THROTTLE_MAX_PER_IP' => '8',
    'WEB_ONE_MAX_SESSIONS_PER_USER' => '3',
    // 留言发帖限流与备忘录条数上限也调小，几秒钟就能验证到边界
    'WEB_ONE_MESSAGE_RATE_MAX' => '3',
    'WEB_ONE_MESSAGE_RATE_WINDOW' => '60',
    'WEB_ONE_MEMO_MAX_COUNT' => '3',
    // 番茄钟记录条数上限同样调小，好验证「到上限拒绝写入」那条分支
    'WEB_ONE_POMODORO_MAX_COUNT' => '5',
    // 小说的三道上限各调小一点：书架本数按「换个账号灌满」验证；
    // 账号总量压到 12 万字——仍高于单章 3 万的切分阈值，又小到几秒钟就能撞上去；
    // 章节数 40 是为了几秒钟就能造出一本「每行都像标题」的书
    'WEB_ONE_NOVEL_MAX_COUNT' => '3',
    'WEB_ONE_NOVEL_MAX_TOTAL_CHARS' => '120000',
    'WEB_ONE_NOVEL_MAX_CHAPTERS' => '40',
    // 采样间隔设为 0：每次请求都记一条，好验证采样文件确实在累积
    'WEB_ONE_STATS_SAMPLE_INTERVAL' => '0',
    // 测试用的采样目录单独放，不去动开发时的 var/stats
    'WEB_ONE_STATS_DIR' => $root . '/var/test-stats',
    'WEB_ONE_LOG_REQUESTS' => '0',
];

// 环境变量必须在 bootstrap 之前生效：配置是在 require 的那一刻读进内存的
foreach ($env as $key => $value) {
    putenv($key . '=' . $value);
}

require __DIR__ . '/lib.php';
// database/lib.php 内部会 require_once 掉 src/bootstrap.php，顺便把配置读进来
require $root . '/database/lib.php';

define('WEB_ONE_COOKIE_NAME', cfg('cookie_name'));

$GLOBALS['T_PORT'] = $port;
$GLOBALS['T_MAIL_DIR'] = $root . '/var/test-mail';
$GLOBALS['T_JARS'] = [];
$GLOBALS['T_REQUESTS'] = [];

echo "web-one 端到端测试\n";
echo str_repeat('=', 64) . "\n";
echo '测试库：' . cfg('db_user') . '@' . cfg('db_host') . ':' . cfg('db_port') . '/' . cfg('db_name') . "\n";
echo '被测地址：http://127.0.0.1:' . $port . "（独立进程，不影响正在运行的服务）\n";

// ------------------------------------------------------------
// 1. 重建测试库
// ------------------------------------------------------------
db_server()->exec('DROP DATABASE IF EXISTS `' . cfg('db_name') . '`');
install_ensure_database();
$statements = install_apply_schema();
$changes = install_migrate();
$adminCreated = false;
install_ensure_admin($adminCreated);
// 游戏板块的初始内容：与真实安装保持一致，测试库也要有这两条
$gameSeeds = install_ensure_game_seeds();
echo '测试库已重建（建表 ' . $statements . ' 条、迁移 ' . count($changes) . " 项、管理员已就绪、游戏初始内容 {$gameSeeds} 条）\n";

// ------------------------------------------------------------
// 2. 清掉上一次的邮件与日志，避免用例读到旧内容
// ------------------------------------------------------------
if (!is_dir($GLOBALS['T_MAIL_DIR'])) {
    mkdir($GLOBALS['T_MAIL_DIR'], 0775, true);
}
t_clear_mail();
foreach (['/server.out.log', '/server.err.log'] as $name) {
    @unlink($root . '/var/test-logs' . $name);
}
// 采样文件也清掉，否则「采样是否在累积」的用例会被上一轮的数据干扰
foreach ((array) glob($root . '/var/test-stats/*.ndjson') as $file) {
    @unlink($file);
}

// ------------------------------------------------------------
// 3. 起服务器
// ------------------------------------------------------------
$process = t_server_start($root, $port, $env);
if ($process === null) {
    echo "无法启动被测服务器（proc_open 失败）\n";
    exit(1);
}
register_shutdown_function('t_server_stop');

if (!t_wait_for_server($port, 10)) {
    echo "被测服务器在 10 秒内没有开始监听 {$port}，stderr 末尾：\n" . t_server_log($root) . "\n";
    exit(1);
}

// ------------------------------------------------------------
// 4. 跑用例
// ------------------------------------------------------------
$cases = glob(__DIR__ . '/cases/*.php');
sort($cases);
foreach ($cases as $case) {
    require $case;
}

// ------------------------------------------------------------
// 5. 收尾
// ------------------------------------------------------------
t_server_stop();

$exitCode = t_summary();

if ($exitCode === 0) {
    echo "测试库 web_one_test 与 var/test-mail 里的邮件都保留了，方便事后核对。\n";
} else {
    echo "提示：最近发出的请求依次是\n";
    foreach (array_slice($GLOBALS['T_REQUESTS'], -25) as $request) {
        echo "  · {$request}\n";
    }
}

exit($exitCode);

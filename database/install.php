<?php
/**
 * 一次性安装脚本（命令行运行，不做成网页接口，避免任何人访问一个 URL 就能改库结构）：
 *
 *   php database/install.php
 *
 * 如果 src/config.local.php 还没配好，可以临时把账号口令带上：
 *   php database/install.php --user=root --pass=你的MySQL密码
 *
 * 它按顺序做四件事：
 *   1) 建库 web_one（utf8mb4，中文和 emoji 才不会被截断）
 *   2) 执行 schema.sql 建表
 *   3) 写入初始管理员（用户名/密码取自 src/config.local.php）
 *   4) 打印结果与下一步操作
 * 重复执行是安全的：建库建表都是 IF NOT EXISTS，管理员已存在就跳过。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("install.php 只允许在命令行运行：php database/install.php\n");
}

require __DIR__ . '/../src/bootstrap.php';

// 任何未捕获的异常都只打印一句人话，不把堆栈和 SQL 原文糊在命令行上
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, "\n安装中断：" . $e->getMessage() . "\n");
    exit(1);
});

if (!extension_loaded('pdo_mysql')) {
    exit("缺少 PHP 扩展 pdo_mysql，请在 php.ini 中打开 extension=pdo_mysql 后重试\n");
}

// ---------- 可选：用命令行参数覆盖本次安装使用的数据库账号 ----------
foreach ($argv as $arg) {
    if (strpos($arg, '--user=') === 0) {
        $GLOBALS['CONFIG']['db_user'] = substr($arg, 7);
    }
    if (strpos($arg, '--pass=') === 0) {
        $GLOBALS['CONFIG']['db_pass'] = substr($arg, 7);
    }
}
if (!is_file(__DIR__ . '/../src/config.local.php')) {
    echo "提示：没找到 src/config.local.php，本次使用 src/config.php 的默认值。\n";
    echo "      建议复制 src/config.example.php 为 src/config.local.php 并填入真实口令。\n\n";
}

echo "== web-one 安装 ==\n";
echo '数据库：' . cfg('db_user') . '@' . cfg('db_host') . ':' . cfg('db_port') . '/' . cfg('db_name') . "\n";

// ---------- 1. 建库 ----------
$server = db_server();
$server->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    cfg('db_name')
));
$server->exec('USE `' . cfg('db_name') . '`');
echo "[1/4] 数据库已就绪\n";

// ---------- 2. 建表 ----------
$schema = file_get_contents(__DIR__ . '/schema.sql');
if ($schema === false) {
    exit("读取 database/schema.sql 失败\n");
}
$statements = 0;
foreach (split_sql($schema) as $sql) {
    $server->exec($sql);
    $statements++;
}
echo "[2/4] 已执行 {$statements} 条建表语句（users / sessions / visits）\n";

// ---------- 3. 初始管理员 ----------
$adminUser = cfg('admin_user');
$adminPass = cfg('admin_pass');

$stmt = $server->prepare('SELECT id, role FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$adminUser]);
$existing = $stmt->fetch();

if ($existing !== false) {
    if ($existing['role'] === 'admin') {
        echo "[3/4] 管理员 {$adminUser} 已存在，保留其现有密码（不覆盖）\n";
    } else {
        exit("[3/4] 用户名 {$adminUser} 已被普通账号占用，无法建立管理员。请修改配置里的 admin_user 后重跑。\n");
    }
} else {
    $insert = $server->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
    $insert->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), 'admin']);
    echo "[3/4] 已创建管理员 {$adminUser}（密码来自配置 admin_pass）\n";
    echo "      ⚠ 这是演示口令，登录后台后请尽快改成自己的密码\n";
}

// ---------- 4. 清理 + 汇报 ----------
$server->exec('DELETE FROM sessions WHERE expires_at < NOW()');
$counts = [];
foreach (['users', 'sessions', 'visits'] as $table) {
    $counts[$table] = (int) $server->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
echo "[4/4] 过期会话已清理\n";
echo '现有数据：users=' . $counts['users'] . ' sessions=' . $counts['sessions'] . ' visits=' . $counts['visits'] . "\n";

echo "\n安装完成，下一步：\n";
echo "  1) 启动后端：  php -S localhost:8000 -t public public/index.php\n";
echo "  2) 浏览器打开：http://localhost:8000\n";
echo '  3) 用管理员 ' . $adminUser . ' 登录（口令见 src/config.local.php）' . "\n";

/**
 * 把 .sql 文件切成一条一条可执行的语句。
 * 做法：丢掉整行注释，再按分号分割——本项目的 schema 里没有存储过程和分号字符串，
 * 所以这样切分是安全的。
 */
function split_sql($sql)
{
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
        if (strpos(trim($line), '--') === 0) {
            continue;
        }
        $lines[] = $line;
    }
    $clean = preg_replace('/\s+/', ' ', implode(' ', $lines));
    $result = [];
    foreach (explode(';', $clean) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $result[] = $statement;
        }
    }
    return $result;
}

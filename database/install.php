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
 *   3) 迁移：给已有的老表补上 v2 新增的列与索引（幂等）
 *   4) 写入初始管理员，并清理过期数据
 *
 * 重复执行是安全的：建库建表都是 IF NOT EXISTS，管理员已存在也不会覆盖密码。
 * 真正的实现都在 database/lib.php，本文件只负责命令行交互与输出。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("install.php 只允许在命令行运行：php database/install.php\n");
}

require __DIR__ . '/lib.php';

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

// ---------- 1. 建库 + 2. 建表 ----------
install_ensure_database();
echo "[1/4] 数据库已就绪\n";

$statements = install_apply_schema();
echo "[2/4] 已执行 {$statements} 条建表语句\n";
echo "      users / sessions / visits / auth_attempts / one_time_tokens / messages / memos / games\n";

// ---------- 3. 迁移已有库 ----------
$changes = install_migrate();
if ($changes === []) {
    echo "[3/4] 表结构已是最新，无需迁移\n";
} else {
    echo '[3/4] 已升级 ' . count($changes) . " 项：\n";
    foreach ($changes as $change) {
        echo "      · {$change}\n";
    }
}

// ---------- 4. 管理员 + 清理 ----------
$created = false;
$exists = install_ensure_admin($created);
if ($exists === false) {
    echo "[4/4] 管理员已存在，保留其现有密码（不覆盖）\n";
} else {
    echo '[4/4] 已创建管理员 ' . cfg('admin_user') . "（密码来自配置 admin_pass）\n";
    echo "      ⚠ 这是演示口令，登录后台后请尽快改成自己的密码\n";
}

$gameSeeds = install_ensure_game_seeds();
if ($gameSeeds > 0) {
    echo "      游戏板块写入 {$gameSeeds} 条初始内容（王者荣耀 / 原神）\n";
}

install_purge_expired();
$counts = install_counts();
echo '      现有数据：';
$parts = [];
foreach ($counts as $table => $count) {
    $parts[] = $table . '=' . ($count === null ? '缺失' : $count);
}
echo implode(' ', $parts) . "\n";

echo "\n安装完成，下一步：\n";
echo "  1) 启动后端：  php -S localhost:8000 -t public public/index.php\n";
echo "  2) 浏览器打开：http://localhost:8000\n";
echo '  3) 用管理员 ' . cfg('admin_user') . " 登录（口令见 src/config.local.php）\n";
echo "  4) 环境体检：  php database/doctor.php\n";
echo "  5) 跑测试：    php tests/run.php\n";

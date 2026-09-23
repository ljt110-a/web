<?php
/**
 * 数据库结构迁移（命令行运行）：
 *
 *   php database/migrate.php
 *
 * 用途：把「上一版已经建好的库」升级到当前 schema，
 * 补上新增的列与索引，并回填能推算出来的历史数据。
 *
 * 与 install.php 的分工：
 *   install.php  首次安装或重建（建库 + 建表 + 建管理员）
 *   migrate.php  只动结构，不碰管理员、不建库以外的任何数据
 *
 * 每次代码更新后跑一遍是安全的：所有步骤都会先检查再执行，重复运行无副作用。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("migrate.php 只允许在命令行运行：php database/migrate.php\n");
}

require __DIR__ . '/lib.php';

set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, "\n迁移中断：" . $e->getMessage() . "\n");
    exit(1);
});

if (!extension_loaded('pdo_mysql')) {
    exit("缺少 PHP 扩展 pdo_mysql，请在 php.ini 中打开 extension=pdo_mysql 后重试\n");
}

echo "== web-one 结构迁移 ==\n";
echo '数据库：' . cfg('db_user') . '@' . cfg('db_host') . ':' . cfg('db_port') . '/' . cfg('db_name') . "\n\n";

$statements = install_apply_schema();
echo "补齐缺失的表：执行 {$statements} 条建表语句（已存在的表会被跳过）\n";

$changes = install_migrate();
if ($changes === []) {
    echo "结构检查完毕：已经是最新版本，没有需要改动的地方\n";
} else {
    echo '已升级 ' . count($changes) . " 项：\n";
    foreach ($changes as $change) {
        echo "  · {$change}\n";
    }
}

// 游戏板块是升级过程中新加的表：已有库升上来会是一张空表，
// 这里补一次初始内容，让「跑完 migrate 就能用」成立。
// install_ensure_game_seeds() 只在表为空时写入，绝不会覆盖你维护过的数据。
$gameSeeds = install_ensure_game_seeds();
if ($gameSeeds > 0) {
    echo "\n游戏板块写入 {$gameSeeds} 条初始内容（王者荣耀 / 原神）\n";
}

echo "\n迁移完成。建议接着跑一次 php database/doctor.php 确认环境。\n";

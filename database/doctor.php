<?php
/**
 * 环境体检：
 *
 *   php database/doctor.php
 *
 * 出问题时的第一站。它按「排查顺序」逐层往下检查：
 *   运行环境 → 配置 → 数据库连通 → 表结构完整性 → 目录可写 → 生产风险
 * 任何一层不过就明确指出「哪里不对、怎么修」，而不是丢一句「连接失败」。
 *
 * 退出码：0 = 全部通过（可能带警告）；1 = 有必须先解决的问题。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("doctor.php 只允许在命令行运行：php database/doctor.php\n");
}

require __DIR__ . '/lib.php';

$problems = 0;
$warnings = 0;

function ok($label, $detail = '')
{
    echo '  ✓ ' . $label . ($detail === '' ? '' : '（' . $detail . '）') . "\n";
}

function warn($label, $advice = '')
{
    global $warnings;
    $warnings++;
    echo '  ! ' . $label . ($advice === '' ? '' : "\n      → " . $advice) . "\n";
}

function bad($label, $advice = '')
{
    global $problems;
    $problems++;
    echo '  ✗ ' . $label . ($advice === '' ? '' : "\n      → " . $advice) . "\n";
}

function section($title)
{
    echo "\n── {$title}\n";
}

echo "== web-one 环境体检 ==\n";
echo '时间：' . date('Y-m-d H:i:s') . '   PHP：' . PHP_VERSION . '   SAPI：' . PHP_SAPI . "\n";

// ------------------------------------------------------------
section('1. 运行环境');
// ------------------------------------------------------------
if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
    ok('PHP 版本满足要求（>= 7.3）', PHP_VERSION);
} else {
    bad('PHP 版本过低：' . PHP_VERSION, '本项目用到 7.0+ 的空合并运算符与 7.3 的语法，请升级到 7.3 以上');
}

foreach (['pdo_mysql' => '连 MySQL 必需', 'mbstring' => '中文长度校验必需', 'json' => '接口收发 JSON 必需'] as $ext => $why) {
    if (extension_loaded($ext)) {
        ok("扩展 {$ext} 已启用", $why);
    } else {
        bad("缺少扩展 {$ext}", "在 php.ini 里打开 extension={$ext} 后重试（{$why}）");
    }
}
if (extension_loaded('openssl')) {
    ok('扩展 openssl 已启用', 'SMTP 的 STARTTLS 与 SSL 加密要用');
} else {
    warn('缺少扩展 openssl', '只有用 smtp 驱动发信时才需要；用 log / mail 驱动不受影响');
}

// ------------------------------------------------------------
section('2. 配置');
// ------------------------------------------------------------
$localFile = __DIR__ . '/../src/config.local.php';
if (is_file($localFile)) {
    ok('src/config.local.php 存在');
} else {
    bad('没有 src/config.local.php', '复制 src/config.example.php 为 src/config.local.php，填入真实的口令');
}
ok('当前环境 env', cfg('env'));
if (cfg('db_pass') === '') {
    bad('数据库口令是空的', '在 config.local.php 里填 db_pass；也可以用环境变量 WEB_ONE_DB_PASS');
} else {
    ok('数据库口令已填写', '长度 ' . strlen((string) cfg('db_pass')) . ' 字符');
}

// ------------------------------------------------------------
section('3. 数据库连通');
// ------------------------------------------------------------
$connected = false;
try {
    $version = db()->query('SELECT VERSION()')->fetchColumn();
    $connected = true;
    ok('连接成功', cfg('db_user') . '@' . cfg('db_host') . ':' . cfg('db_port') . '/' . cfg('db_name') . '  ·  MySQL ' . $version);
    // 「今日访问」这类统计靠 NOW()，连接时区配错会整整差 8 小时，所以顺手报出来
    ok('会话时区', (string) db()->query('SELECT @@session.time_zone')->fetchColumn());
} catch (Throwable $e) {
    bad('连接失败：' . $e->getMessage(), '确认 MySQL 服务已启动；口令要与「当前占着 3306 端口的那个实例」一致');
    echo "      本机若装了两套 MySQL（Windows 服务 MySQL80 与 phpStudy 的 MySQL 5.7），\n";
    echo "      它们抢同一个 3306 端口，谁先起来谁占着。用下面两条命令确认现在是谁在监听：\n";
    echo "        netstat -ano | findstr \":3306\"\n";
    echo "        powershell \"Get-Service *mysql*\"\n";
}

// ------------------------------------------------------------
section('4. 表结构完整性');
// ------------------------------------------------------------
/** 期望的表与列：漏了任何一列，对应的功能就会在运行时才炸 */
$expected = [
    'users' => ['id', 'username', 'password_hash', 'role', 'email', 'email_verified_at', 'password_changed_at', 'created_at', 'last_login_at', 'login_count'],
    'sessions' => ['id', 'token', 'user_id', 'created_at', 'expires_at', 'ip'],
    'visits' => ['id', 'day', 'created_at', 'ip', 'ip_hash', 'user_id'],
    'auth_attempts' => ['id', 'action', 'identifier', 'ip', 'succeeded', 'created_at'],
    'one_time_tokens' => ['token', 'user_id', 'purpose', 'created_at', 'expires_at', 'used_at'],
    'messages' => ['id', 'user_id', 'body', 'created_at'],
    'memos' => ['id', 'user_id', 'title', 'body', 'done', 'created_at', 'updated_at'],
    'games' => ['id', 'slug', 'name', 'publisher', 'genre', 'description', 'url', 'icon', 'sort_order', 'enabled', 'created_at'],
    'pomodoros' => ['id', 'user_id', 'subject', 'minutes', 'elapsed', 'finished', 'created_at'],
    'novels' => ['id', 'user_id', 'title', 'chapter_count', 'char_count', 'progress_chapter', 'progress_paragraph', 'created_at', 'updated_at'],
    'novel_chapters' => ['id', 'novel_id', 'seq', 'title', 'content', 'char_count'],
    'softs' => ['id', 'slug', 'name', 'category', 'platforms', 'tags', 'description', 'homepage', 'github_url', 'gitee_url', 'download_url', 'source_mode', 'version', 'license', 'icon_file', 'icon_kind', 'star_count', 'size_bytes', 'file_ext', 'file_sha256', 'file_display', 'file_origin', 'file_fetched_at', 'enabled', 'sort_order', 'created_at', 'updated_at'],
];

if ($connected) {
    $missingTables = [];
    $missingColumns = [];
    foreach ($expected as $table => $columns) {
        if (!sql_table_exists($table)) {
            $missingTables[] = $table;
            continue;
        }
        foreach ($columns as $column) {
            if (!sql_column_exists($table, $column)) {
                $missingColumns[] = $table . '.' . $column;
            }
        }
    }

    if ($missingTables === [] && $missingColumns === []) {
        ok(count($expected) . ' 张表与全部列都在', implode(' / ', array_keys($expected)));
    } else {
        if ($missingTables !== []) {
            bad('缺少表：' . implode('、', $missingTables), '执行 php database/install.php');
        }
        if ($missingColumns !== []) {
            bad('缺少列：' . implode('、', $missingColumns), '执行 php database/migrate.php 补上 v2 新增的列');
        }
    }

    if (sql_index_exists('sessions', 'uk_sessions_token')) {
        ok('索引 sessions.uk_sessions_token 存在', '会话按自增 id 排序，token 走唯一索引');
    } else {
        bad('缺少索引 sessions.uk_sessions_token', '执行 php database/migrate.php');
    }

    // 管理员到底有没有
    try {
        $adminCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($adminCount > 0) {
            ok('管理员账号存在', $adminCount . ' 个');
        } else {
            bad('库里一个管理员都没有', '执行 php database/install.php；若用户表里已有同名普通账号，先改名或删除它');
        }
    } catch (Throwable $e) {
        bad('统计管理员失败：' . $e->getMessage());
    }
}

// ------------------------------------------------------------
section('5. 目录可写');
// ------------------------------------------------------------
foreach ([
    'log_dir' => '应用日志',
    'stats_dir' => '资源监控的采样文件',
    'mail_dir' => 'mail_driver=log 时的邮件',
    'soft_dir' => '软件仓库的安装包与图标（服务器代下载落在这里）',
] as $key => $purpose) {
    $dir = cfg($key);
    if (!is_dir($dir) && !ensure_dir($dir)) {
        bad("无法创建目录 {$key}：{$dir}", '检查上一级目录的权限');
        continue;
    }
    $probe = rtrim($dir, '/\\') . '/.write-probe';
    if (@file_put_contents($probe, 'ok') === false) {
        bad("目录不可写 {$key}：{$dir}", "给这个目录写权限（{$purpose} 需要写入）");
    } else {
        @unlink($probe);
        ok("目录可写 {$key}", $dir);
    }
}

// ------------------------------------------------------------
section('6. 生产风险');
// ------------------------------------------------------------
$risks = security_warnings();
if ($risks === []) {
    ok('没有发现明显的配置风险');
} else {
    foreach ($risks as $risk) {
        warn($risk);
    }
}

// ------------------------------------------------------------
section('7. 数据概况');
// ------------------------------------------------------------
if ($connected) {
    $counts = install_counts();
    $parts = [];
    foreach ($counts as $table => $count) {
        $parts[] = $table . '=' . ($count === null ? '缺失' : $count);
    }
    ok('各表行数', implode('  ', $parts));
}

// ------------------------------------------------------------
echo "\n" . str_repeat('=', 64) . "\n";
if ($problems === 0) {
    echo "体检通过" . ($warnings > 0 ? "（{$warnings} 条提醒，不阻断运行）" : '，没有发现问题') . "\n";
    echo "下一步：php -S localhost:8000 -t public public/index.php  然后打开 http://localhost:8000\n";
    echo str_repeat('=', 64) . "\n";
    exit(0);
}
echo "发现 {$problems} 个必须先解决的问题" . ($warnings > 0 ? "，另有 {$warnings} 条提醒" : '') . "\n";
echo "按上面每条后面的 → 处理，然后再跑一次本脚本。\n";
echo str_repeat('=', 64) . "\n";
exit(1);

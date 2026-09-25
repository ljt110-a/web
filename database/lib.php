<?php
/**
 * 安装与迁移的公共实现。
 *
 * 这里放「真正干活」的函数，install.php 与 migrate.php 只是薄薄的命令行包装，
 * tests/run.php 也复用同一套函数来搭测试库——这样安装路径只有一份代码，
 * 不会出现「测试用的建表逻辑和正式的不一样」这种最坑人的情况。
 *
 * 所有函数都设计成幂等的：重复执行只补齐缺的东西，不覆盖已有数据。
 */

require_once __DIR__ . '/../src/bootstrap.php';

/** 目标表是否已存在 */
function sql_table_exists($table)
{
    $stmt = db_server()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([cfg('db_name'), $table]);
    return (int) $stmt->fetchColumn() > 0;
}

/** 目标列是否已存在 */
function sql_column_exists($table, $column)
{
    $stmt = db_server()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([cfg('db_name'), $table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/** 目标索引是否已存在 */
function sql_index_exists($table, $index)
{
    $stmt = db_server()->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([cfg('db_name'), $table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

/** 补列；已存在则跳过。返回 true 表示这次真的加了 */
function sql_add_column($table, $column, $definition)
{
    if (sql_column_exists($table, $column)) {
        return false;
    }
    db_server()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    return true;
}

/** 补索引；已存在则跳过。返回 true 表示这次真的加了 */
function sql_add_index($table, $index, $definition)
{
    if (sql_index_exists($table, $index)) {
        return false;
    }
    db_server()->exec("ALTER TABLE `{$table}` ADD {$definition}");
    return true;
}

/** 1) 建库（已存在则跳过）并切到该库 */
function install_ensure_database()
{
    $server = db_server();
    $server->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        cfg('db_name')
    ));
    $server->exec('USE `' . cfg('db_name') . '`');
    return $server;
}

/**
 * 2) 执行 schema.sql（全是 CREATE TABLE IF NOT EXISTS，因此对已有库安全）。
 * @return int 实际执行的语句条数
 */
function install_apply_schema()
{
    $file = __DIR__ . '/schema.sql';
    $schema = file_get_contents($file);
    if ($schema === false) {
        throw new RuntimeException('读取 database/schema.sql 失败，请确认文件存在');
    }
    $server = install_ensure_database();
    $count = 0;
    foreach (sql_split($schema) as $statement) {
        $server->exec($statement);
        $count++;
    }
    return $count;
}

/**
 * 3) 把「已经存在的老库」升级到 v2 结构。
 *
 * schema.sql 只能建新表，加不了新列，所以旧库必须靠这一段补。
 * 每一步都先查 information_schema 再动手，重复执行不会有副作用。
 *
 * @return array 本次实际做了哪些改动（用于打印给人看）
 */
function install_migrate()
{
    install_ensure_database();
    $changes = [];

    // ---- users：邮箱与验证、改密时间 ----
    if (sql_add_column('users', 'email', "VARCHAR(120) NULL DEFAULT NULL COMMENT '邮箱，可空'")) {
        $changes[] = 'users.email';
    }
    if (sql_add_column('users', 'email_verified_at', "DATETIME NULL DEFAULT NULL COMMENT '邮箱验证通过时间'")) {
        $changes[] = 'users.email_verified_at';
    }
    if (sql_add_column('users', 'password_changed_at', "DATETIME NULL DEFAULT NULL COMMENT '最后一次改密时间'")) {
        $changes[] = 'users.password_changed_at';
    }
    if (sql_add_index('users', 'uk_users_email', 'UNIQUE KEY `uk_users_email` (`email`)')) {
        $changes[] = '索引 users.uk_users_email';
    }
    if (sql_add_index('users', 'idx_users_role_id', 'KEY `idx_users_role_id` (`role`, `id`)')) {
        $changes[] = '索引 users.idx_users_role_id';
    }

    // ---- sessions：主键从 token 换成自增 id ----
    // 「踢掉最旧的会话」需要可靠的登录先后顺序，而 created_at 只有秒精度。
    // 这一步必须在加索引之前做：换成新主键后 token 才需要补唯一索引。
    //
    // 注意子句顺序：MySQL 的列定义要按「类型 → 属性 → COMMENT → 位置」写，
    // COMMENT 放在 FIRST 后面会直接报 1064 语法错误。
    if (!sql_column_exists('sessions', 'id')) {
        db_server()->exec(
            'ALTER TABLE `sessions`
               DROP PRIMARY KEY,
               ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
                 COMMENT \'自增主键：唯一作用是确定登录先后顺序\' FIRST,
               ADD PRIMARY KEY (`id`),
               ADD UNIQUE KEY `uk_sessions_token` (`token`)'
        );
        $changes[] = 'sessions 主键改为自增 id（会话按登录先后排序才可靠）';
    }

    // ---- sessions：在线状态与会话上限都要按 (user_id, expires_at) 筛 ----
    if (sql_add_index('sessions', 'idx_sessions_user_expires', 'KEY `idx_sessions_user_expires` (`user_id`, `expires_at`)')) {
        $changes[] = '索引 sessions.idx_sessions_user_expires';
    }

    // ---- visits：UV 去重需要的列与索引 ----
    if (sql_add_column('visits', 'ip_hash', "CHAR(64) NULL DEFAULT NULL COMMENT 'IP 的 SHA-256 摘要，用于 UV 去重'")) {
        $changes[] = 'visits.ip_hash';
    }
    if (sql_add_column('visits', 'user_id', "INT UNSIGNED NULL DEFAULT NULL COMMENT '已登录时的用户 ID'")) {
        $changes[] = 'visits.user_id';
    }
    if (sql_add_index('visits', 'idx_visits_day_ip', 'KEY `idx_visits_day_ip` (`day`, `ip_hash`)')) {
        $changes[] = '索引 visits.idx_visits_day_ip';
    }

    // ---- 回填：老数据的 ip_hash 为空，用现有 ip 算一遍，否则 UV 会漏统计 ----
    $backfilled = db_server()->exec(
        'UPDATE visits SET ip_hash = SHA2(ip, 256) WHERE ip_hash IS NULL AND ip IS NOT NULL AND ip <> \'\''
    );
    if ($backfilled > 0) {
        $changes[] = "回填 visits.ip_hash（{$backfilled} 行）";
    }

    return $changes;
}

/**
 * 4) 确保初始管理员存在。
 * 已存在且是管理员 → 保留其现有密码（绝不覆盖，否则重跑安装会把人家改过的密码打回去）。
 * 用户名被普通账号占用 → 抛异常，让人去改配置。
 */
function install_ensure_admin(&$created = null)
{
    // 这几个函数用的是「未指定库」的服务器连接，所以都要自己先把库选好，
    // 不能指望调用方先执行过 install_ensure_database()——doctor.php 就是直接调它们的。
    install_ensure_database();

    $adminUser = cfg('admin_user');
    $adminPass = cfg('admin_pass');
    $created = false;

    $stmt = db_server()->prepare('SELECT id, role FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$adminUser]);
    $existing = $stmt->fetch();

    if ($existing !== false) {
        if ($existing['role'] !== 'admin') {
            throw new RuntimeException(
                "用户名 {$adminUser} 已被普通账号占用，无法建立管理员。请修改配置里的 admin_user 后重跑。"
            );
        }
        return false;
    }

    $insert = db_server()->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
    $insert->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), 'admin']);
    $created = true;
    return true;
}

/**
 * 游戏板块的初始内容。
 *
 * 刻意只在**表为空**时写入，而不是每次都 INSERT IGNORE：
 *   前者语义是「初始内容」，你删掉了某一条之后再跑安装脚本，它不会自己长回来；
 *   后者语义是「必备数据」，一旦删掉就会在下次安装时复活，反而更让人意外。
 * 表里一旦有数据，这里什么都不做——安装脚本永远不会覆盖你在后台改过的东西。
 *
 * @return int 本次写入的条数
 */
function install_ensure_game_seeds()
{
    install_ensure_database();

    if (!sql_table_exists('games')) {
        return 0;
    }
    $existing = (int) db_server()->query('SELECT COUNT(*) FROM `games`')->fetchColumn();
    if ($existing > 0) {
        return 0;
    }

    $seeds = [
        [
            'honor-of-kings', '王者荣耀', '腾讯', 'MOBA 竞技',
            '5v5 公平竞技手游，一局大约 15 分钟；想加新游戏就在后台的「添加游戏」里填一条。',
            'https://pvp.qq.com/', '⚔️', 10,
        ],
        [
            'genshin-impact', '原神', '米哈游', '开放世界 RPG',
            '在提瓦特大陆自由探索的开放世界冒险，支持多端互通。',
            'https://ys.mihoyo.com/', '🌏', 20,
        ],
    ];

    $stmt = db_server()->prepare(
        'INSERT INTO games (slug, name, publisher, genre, description, url, icon, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $created = 0;
    foreach ($seeds as $seed) {
        $stmt->execute($seed);
        $created++;
    }
    return $created;
}

/** 顺手清掉过期会话与过期限流记录，安装/登录/开后台时都可以调 */
function install_purge_expired()
{
    install_ensure_database();
    $sessions = db_server()->exec('DELETE FROM sessions WHERE expires_at < NOW()');
    $attempts = db_server()->exec(
        'DELETE FROM auth_attempts WHERE created_at < NOW() - INTERVAL ' . (int) (cfg('throttle_window') * 2) . ' SECOND'
    );
    $tokens = db_server()->exec('DELETE FROM one_time_tokens WHERE expires_at < NOW()');
    return ['sessions' => $sessions, 'attempts' => $attempts, 'tokens' => $tokens];
}

/** 各表当前行数 */
function install_counts()
{
    install_ensure_database();
    $counts = [];
    foreach (['users', 'sessions', 'visits', 'auth_attempts', 'one_time_tokens', 'messages', 'memos', 'games', 'pomodoros', 'novels', 'novel_chapters', 'softs'] as $table) {
        if (!sql_table_exists($table)) {
            $counts[$table] = null;
            continue;
        }
        $counts[$table] = (int) db_server()->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }
    return $counts;
}

/**
 * 把 .sql 文件切成一条一条可执行的语句。
 * 做法：丢掉整行注释，再按分号分割——本项目的 schema 里没有存储过程和分号字符串，
 * 所以这样切分是安全的（schema.sql 里若将来出现这些，需要换成真正的 SQL 解析器）。
 */
function sql_split($sql)
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

<?php
/**
 * 数据库连接（PDO + MySQL）。
 * 这里只做一件事：给出一个可用的、全请求共享的 PDO 实例，
 * 以及把“连不上/没装好”这类底层报错翻译成用户看得懂的话。
 */

/**
 * 取得 PDO 连接（第一次调用时真正连接，之后复用同一个实例）。
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        cfg('db_host'),
        cfg('db_port'),
        cfg('db_name')
    );

    try {
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            // 出错的 SQL 直接抛异常，而不是返回 false 让代码继续往下跑
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // 关联数组取列，避免同时拿到数字下标的重复副本
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // 关闭“预处理模拟”，使用 MySQL 服务端的原生预处理：
            // 参数是真正绑定进协议的，不是拼进 SQL 字符串的，这才是防 SQL 注入的根本。
            PDO::ATTR_EMULATE_PREPARES => false,
            // 让 MySQL 的 NOW() / CURRENT_TIMESTAMP 与 PHP 的日期保持同一时区，
            // 否则“今日访问”会差 8 小时。
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION time_zone = '+08:00'",
        ]);
    } catch (PDOException $e) {
        throw db_friendly_error($e);
    }

    return $pdo;
}

/**
 * 用一个“还没有数据库”的连接执行安装类操作（建库、建表）。
 */
function db_server(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', cfg('db_host'), cfg('db_port'));
    try {
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        throw db_friendly_error($e);
    }
    return $pdo;
}

/**
 * 把 PDO 的原始报错换成可操作的提示。
 * 注意：只转述“哪里不对”，绝不把 SQL 语句、连接串原文吐给浏览器。
 */
function db_friendly_error(PDOException $e): RuntimeException
{
    $msg = $e->getMessage();
    if (strpos($msg, 'Unknown database') !== false || strpos($msg, '1049') !== false) {
        return new RuntimeException(
            '数据库还不存在：请在项目根目录用命令行执行 php database/install.php 完成安装',
            0,
            $e
        );
    }
    if (strpos($msg, 'not found') !== false || strpos($msg, '1146') !== false) {
        return new RuntimeException(
            '数据表还不完整：请执行 php database/install.php 补建数据表',
            0,
            $e
        );
    }
    if (strpos($msg, 'Access denied') !== false) {
        return new RuntimeException(
            '数据库账号或密码不对：请检查 src/config.local.php 里的 db_user / db_pass',
            0,
            $e
        );
    }
    return new RuntimeException(
        '数据库连接失败：' . $msg . '（请确认 MySQL 服务已启动，并检查 src/config.local.php）',
        0,
        $e
    );
}

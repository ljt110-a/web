<?php
/**
 * 资源监控：把「这套应用现在吃得怎么样」变成能看懂的数字和趋势。
 *
 * 数据来源刻意全部选「不需要外部命令、不需要装任何扩展」的四类：
 *   1. PHP 自身：内存占用 / 峰值 / 上限、本次请求耗时、已包含的文件数
 *   2. 磁盘：disk_free_space / disk_total_space（PHP 原生，Windows 与 Linux 都可用）
 *   3. MySQL：SHOW GLOBAL STATUS 的性能计数器 + performance_schema 的服务端内存分布
 *             + information_schema 的各表占用空间
 *   4. 业务：用户数、会话数、留言数、备忘录数、今日 PV / UV
 *
 * 为什么不读操作系统的整体内存与 CPU：
 *   Windows 上那条路要走 wmic，而它已被本机的安全策略拉黑；
 *   更重要的是，一旦依赖平台命令，这套代码就绑死在 Windows 上了。
 *   上面四类都是 PHP 与 SQL 的原生能力，换到 Linux 上照样跑。
 *   真要 OS 级的内存 / CPU 曲线，交给专门的监控栈（如 Prometheus + node_exporter）更合适，
 *   这个面板负责的是「应用自己这一层」——恰恰是排障时最常需要的那一层。
 */

/** 把 "256M" / "1G" / "-1" 这类 php.ini 写法换算成字节；不限则返回 0 */
function system_parse_bytes($text)
{
    $text = trim((string) $text);
    if ($text === '' || $text === '-1') {
        return 0;
    }
    $unit = strtolower(substr($text, -1));
    $value = (float) $text;
    if ($unit === 'g') {
        $value *= 1024 * 1024 * 1024;
    } elseif ($unit === 'm') {
        $value *= 1024 * 1024;
    } elseif ($unit === 'k') {
        $value *= 1024;
    }
    return (int) $value;
}

/** 字节数 → 人能读的写法 */
function system_format_bytes($bytes, $precision = 1)
{
    $bytes = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }
    return round($bytes, $index === 0 ? 0 : $precision) . ' ' . $units[$index];
}

/**
 * 本次请求的 SQL 条数。
 *
 * 用 MySQL 的会话级计数器 Questions 的差值来数，而不是给 PDO 写子类去包装
 * prepare/query/exec——PHP 各版本 PDO::query 的签名不完全一致，
 * 覆写很容易触发「声明不兼容」的警告，为了一个监控数字不值当。
 * 会话级计数只在当前连接上累加，所以差值就是「这条连接执行了多少条语句」。
 */
function system_sql_counter()
{
    $stmt = db()->query("SHOW SESSION STATUS LIKE 'Questions'");
    $row = $stmt->fetch();
    return $row === false ? 0 : (int) $row['Value'];
}

// ------------------------------------------------------------
// 分项采集
// ------------------------------------------------------------

function system_php_metrics()
{
    $limitBytes = system_parse_bytes(ini_get('memory_limit'));
    $usedBytes = memory_get_usage(true);

    $started = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);

    return [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'memoryBytes' => $usedBytes,
        'memoryRealBytes' => memory_get_usage(false),
        'peakBytes' => memory_get_peak_usage(true),
        'limitBytes' => $limitBytes,
        'limitText' => (string) ini_get('memory_limit'),
        'usedPercentOfLimit' => $limitBytes > 0 ? round($usedBytes / $limitBytes * 100, 2) : null,
        'requestMs' => round((microtime(true) - $started) * 1000, 1),
        'includedFiles' => count(get_included_files()),
        'extensions' => count(get_loaded_extensions()),
        'opcacheEnabled' => function_exists('opcache_get_status')
            && is_array(@opcache_get_status(false)),
    ];
}

function system_disk_metrics()
{
    // Windows 下按盘符，其它平台看根目录
    $targets = DIRECTORY_SEPARATOR === '\\' ? ['C:', 'E:'] : ['/'];
    $disks = [];

    foreach ($targets as $mount) {
        $free = @disk_free_space($mount);
        $total = @disk_total_space($mount);
        if ($free === false || $total === false || $total <= 0) {
            continue;
        }
        $disks[] = [
            'mount' => $mount,
            'freeBytes' => (float) $free,
            'totalBytes' => (float) $total,
            'usedBytes' => (float) ($total - $free),
            'usedPercent' => round((1 - $free / $total) * 100, 1),
        ];
    }
    return $disks;
}

/** SHOW GLOBAL STATUS 全量取回来（几百行，都在内存里，很便宜） */
function system_mysql_status()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach (db()->query('SHOW GLOBAL STATUS')->fetchAll() as $row) {
        $cache[$row['Variable_name']] = $row['Value'];
    }
    return $cache;
}

function system_mysql_metrics()
{
    $status = system_mysql_status();
    // 取不到就返回 0，而不是让整个面板挂掉——监控本来就该能容忍某个数据源缺失
    $get = function ($key) use ($status) {
        return isset($status[$key]) ? (float) $status[$key] : 0.0;
    };

    $uptime = (int) $get('Uptime');
    $questions = (int) $get('Questions');
    $readRequests = (int) $get('Innodb_buffer_pool_read_requests');
    $reads = (int) $get('Innodb_buffer_pool_reads');

    // 缓冲池命中率：读请求里有多少是直接在内存里命中的。低于 99% 通常意味着缓冲池偏小
    $hitRate = $readRequests > 0
        ? round((1 - $reads / $readRequests) * 100, 3)
        : null;

    $metrics = [
        'version' => (string) db()->query('SELECT VERSION()')->fetchColumn(),
        'uptime' => $uptime,
        'uptimeText' => system_format_duration($uptime),
        'questions' => $questions,
        'queries' => (int) $get('Queries'),
        'qps' => $uptime > 0 ? round($questions / $uptime, 3) : 0,
        'slowQueries' => (int) $get('Slow_queries'),
        'threadsConnected' => (int) $get('Threads_connected'),
        'threadsRunning' => (int) $get('Threads_running'),
        'maxConnections' => (int) $get('Max_used_connections'),
        'connections' => (int) $get('Connections'),
        'abortedConnects' => (int) $get('Aborted_connects'),
        'abortedClients' => (int) $get('Aborted_clients'),
        'tmpDiskTables' => (int) $get('Created_tmp_disk_tables'),
        'tmpTables' => (int) $get('Created_tmp_tables'),
        'bufferPoolReads' => $reads,
        'bufferPoolReadRequests' => $readRequests,
        'bufferPoolHitRate' => $hitRate,
        'bytesReceived' => (int) $get('Bytes_received'),
        'bytesSent' => (int) $get('Bytes_sent'),
        'comSelect' => (int) $get('Com_select'),
        'comInsert' => (int) $get('Com_insert'),
        'comUpdate' => (int) $get('Com_update'),
        'comDelete' => (int) $get('Com_delete'),
    ];

    $metrics['memoryByComponent'] = system_mysql_memory_breakdown();
    $metrics['tables'] = system_table_sizes();

    return $metrics;
}

/**
 * MySQL 服务端内存按组件分布（performance_schema）。
 * 某些环境默认关掉了 performance_schema，这时返回空数组，
 * 由上层显示「该数据源不可用」，而不是报错。
 */
function system_mysql_memory_breakdown($limit = 8)
{
    try {
        $enabled = (int) db()->query('SELECT @@performance_schema')->fetchColumn();
        if ($enabled !== 1) {
            return [];
        }
        $rows = db()->query(
            'SELECT EVENT_NAME, SUM(CURRENT_NUMBER_OF_BYTES_USED) AS bytes
               FROM performance_schema.memory_summary_global_by_event_name
              GROUP BY EVENT_NAME
             HAVING bytes > 0
             ORDER BY bytes DESC
             LIMIT ' . max(1, (int) $limit)
        )->fetchAll();
    } catch (Throwable $e) {
        app_log('warning', '读取 MySQL 内存分布失败', ['error' => $e->getMessage()]);
        return [];
    }

    $list = [];
    foreach ($rows as $row) {
        $name = $row['EVENT_NAME'];
        // memory/innodb/buf_buf_pool → innodb/buf_buf_pool，前缀是固定的噪音
        if (strpos($name, 'memory/') === 0) {
            $name = substr($name, 7);
        }
        $list[] = ['name' => $name, 'bytes' => (int) $row['bytes']];
    }
    return $list;
}

/** 各表占用的磁盘空间 */
function system_table_sizes()
{
    $stmt = db()->prepare(
        'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS bytes
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ?
          ORDER BY DATA_LENGTH + INDEX_LENGTH DESC'
    );
    $stmt->execute([cfg('db_name')]);

    $list = [];
    foreach ($stmt->fetchAll() as $row) {
        $list[] = [
            'table' => $row['TABLE_NAME'],
            'rows' => (int) $row['TABLE_ROWS'],
            'bytes' => (int) $row['bytes'],
        ];
    }
    return $list;
}

/** 业务层面的规模，和上面那些机器指标放在一起才好判断「现在算不算大」 */
function system_app_metrics()
{
    $today = date('Y-m-d');
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS pv, COUNT(DISTINCT ip_hash) AS uv FROM visits WHERE day = ?'
    );
    $stmt->execute([$today]);
    $visits = $stmt->fetch();

    $sessions = db()->query(
        'SELECT COUNT(*) AS total, COALESCE(SUM(expires_at > NOW()), 0) AS alive FROM sessions'
    )->fetch();

    return [
        'users' => (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'admins' => (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
        'sessions' => (int) $sessions['total'],
        'sessionsAlive' => (int) $sessions['alive'],
        'messages' => message_count(),
        'memos' => memo_count(),
        'games' => game_count(),
        'visitsToday' => (int) $visits['pv'],
        'visitorsToday' => (int) $visits['uv'],
    ];
}

/** 复数：秒 → “2 天 3 小时 5 分” */
function system_format_duration($seconds)
{
    $seconds = max(0, (int) $seconds);
    $days = (int) floor($seconds / 86400);
    $hours = (int) floor(($seconds % 86400) / 3600);
    $minutes = (int) floor(($seconds % 3600) / 60);

    if ($days > 0) {
        return $days . ' 天 ' . $hours . ' 小时';
    }
    if ($hours > 0) {
        return $hours . ' 小时 ' . $minutes . ' 分';
    }
    return $minutes . ' 分 ' . ($seconds % 60) . ' 秒';
}

// ------------------------------------------------------------
// 汇总
// ------------------------------------------------------------

/**
 * 采集一次完整快照。
 * @param int $sqlBefore 采集开始前的会话 SQL 计数，用来算出本次采集自身跑了多少条
 */
function system_snapshot($sqlBefore = 0)
{
    $php = system_php_metrics();

    $snapshot = [
        'sampledAt' => date('Y-m-d H:i:s'),
        'php' => $php,
        'disks' => system_disk_metrics(),
        'mysql' => system_mysql_metrics(),
        'app' => system_app_metrics(),
    ];

    $snapshot['php']['queriesThisRequest'] = max(0, system_sql_counter() - (int) $sqlBefore);
    $snapshot['verdict'] = system_verdict($snapshot);
    $snapshot['samples'] = system_samples((int) cfg('stats_max_samples'));

    return $snapshot;
}

/**
 * 把数字翻译成人话。
 * 监控面板最容易变成「一堆看不懂的曲线」，所以每条结论都写清
 * 「看到什么现象」+「说明什么」+「要做什么」。
 */
function system_verdict(array $snapshot)
{
    $verdict = [];
    $php = $snapshot['php'];
    $mysql = $snapshot['mysql'];
    $app = $snapshot['app'];

    // ---- PHP 内存 ----
    if ($php['usedPercentOfLimit'] !== null && $php['usedPercentOfLimit'] >= 80) {
        $verdict[] = ['level' => 'warn', 'text' => sprintf(
            'PHP 内存已用到上限的 %.0f%%（%s / %s），接近打爆 memory_limit；调大上限或检查是否有循环里堆积数据',
            $php['usedPercentOfLimit'],
            system_format_bytes($php['memoryBytes']),
            $php['limitText']
        )];
    } else {
        $verdict[] = ['level' => 'ok', 'text' => sprintf(
            'PHP 本次请求占用 %s（峰值 %s），上限 %s，余量充足',
            system_format_bytes($php['memoryBytes']),
            system_format_bytes($php['peakBytes']),
            $php['limitText']
        )];
    }
    if (!$php['opcacheEnabled']) {
        $verdict[] = ['level' => 'info', 'text' => 'OPcache 未启用：每次请求都要重新编译 PHP 文件，生产环境建议打开'];
    }

    // ---- 磁盘 ----
    foreach ($snapshot['disks'] as $disk) {
        if ($disk['usedPercent'] >= 90) {
            $verdict[] = ['level' => 'warn', 'text' => sprintf(
                '%s 盘已用 %.1f%%，只剩 %s，需要尽快清理',
                $disk['mount'],
                $disk['usedPercent'],
                system_format_bytes($disk['freeBytes'])
            )];
        } elseif ($disk['usedPercent'] >= 80) {
            $verdict[] = ['level' => 'info', 'text' => sprintf(
                '%s 盘已用 %.1f%%，留意一下增长',
                $disk['mount'],
                $disk['usedPercent']
            )];
        }
    }

    // ---- InnoDB 缓冲池 ----
    if ($mysql['bufferPoolHitRate'] !== null) {
        if ($mysql['bufferPoolHitRate'] < 99) {
            $verdict[] = ['level' => 'warn', 'text' => sprintf(
                'InnoDB 缓冲池命中率只有 %.2f%%，说明不少读请求走了磁盘：缓冲池偏小（innodb_buffer_pool_size）或存在全表扫描',
                $mysql['bufferPoolHitRate']
            )];
        } else {
            $verdict[] = ['level' => 'ok', 'text' => sprintf(
                'InnoDB 缓冲池命中率 %.2f%%，读请求基本都命中内存',
                $mysql['bufferPoolHitRate']
            )];
        }
    }

    // ---- 临时表落盘 ----
    if ($mysql['questions'] > 50) {
        $diskTmpRatio = $mysql['tmpDiskTables'] / $mysql['questions'] * 100;
        if ($diskTmpRatio >= 5) {
            $verdict[] = ['level' => 'warn', 'text' => sprintf(
                '临时表落盘比例 %.1f%%（%d / %d），通常是 ORDER BY / GROUP BY 没走索引导致的，检查相关查询的执行计划',
                $diskTmpRatio,
                $mysql['tmpDiskTables'],
                $mysql['questions']
            )];
        } else {
            $verdict[] = ['level' => 'ok', 'text' => sprintf(
                '临时表落盘比例 %.1f%%，几乎没有大排序落到磁盘',
                $diskTmpRatio
            )];
        }
    }

    // ---- 慢查询 ----
    if ($mysql['slowQueries'] > 0) {
        $verdict[] = ['level' => 'info', 'text' => sprintf(
            '累计慢查询 %d 条（阈值由 long_query_time 决定），可以打开 slow_query_log 看具体是哪些语句',
            $mysql['slowQueries']
        )];
    }

    // ---- 连接与线程 ----
    $verdict[] = ['level' => 'ok', 'text' => sprintf(
        'MySQL 已运行 %s，当前连接 %d 个、正在执行 %d 个，平均 %.2f 次查询/秒',
        $mysql['uptimeText'],
        $mysql['threadsConnected'],
        $mysql['threadsRunning'],
        $mysql['qps']
    )];
    if ($mysql['connections'] > 0) {
        $abortRatio = $mysql['abortedConnects'] / $mysql['connections'] * 100;
        if ($abortRatio >= 5) {
            $verdict[] = ['level' => 'warn', 'text' => sprintf(
                '连接失败比例偏高（%d / %d = %.1f%%）：可能是口令错误、客户端超时，或者触顶了 max_connections',
                $mysql['abortedConnects'],
                $mysql['connections'],
                $abortRatio
            )];
        }
    }

    // ---- 表空间 ----
    $visits = system_find_table_size($mysql['tables'], 'visits');
    if ($visits !== null && $visits['bytes'] > 0) {
        $allBytes = 0;
        foreach ($mysql['tables'] as $table) {
            $allBytes += $table['bytes'];
        }
        if ($allBytes > 0 && $visits['bytes'] / $allBytes >= 0.5 && $visits['rows'] > 1000) {
            $verdict[] = ['level' => 'info', 'text' => sprintf(
                'visits 表占了这个库 %.0f%% 的空间（约 %d 行 / %s）：它是唯一会随流量无限增长的表，建议按 deploy/CHECKLIST.md 定期汇总或清理',
                $visits['bytes'] / $allBytes * 100,
                $visits['rows'],
                system_format_bytes($visits['bytes'])
            )];
        }
    }

    // ---- 应用规模：和上面的机器指标放一起，才好判断「现在算不算大」 ----
    $verdict[] = ['level' => 'info', 'text' => sprintf(
        '应用规模：%d 个账号（%d 个管理员）、%d 个有效会话、%d 条留言、%d 条备忘录、%d 个游戏；今日访问 %d 次 / %d 人',
        $app['users'],
        $app['admins'],
        $app['sessionsAlive'],
        $app['messages'],
        $app['memos'],
        $app['games'],
        $app['visitsToday'],
        $app['visitorsToday']
    )];

    // ---- 监控自身的说明 ----
    $verdict[] = ['level' => 'info', 'text' => sprintf(
        '本次采集自身执行了 %d 条 SQL、耗时 %s 毫秒（这套面板的全部数据都来自 PHP 与 MySQL 原生能力，没有依赖任何平台命令）',
        $php['queriesThisRequest'],
        $php['requestMs']
    )];

    return $verdict;
}

/** 在表大小列表里按名字找一项 */
function system_find_table_size(array $tables, $name)
{
    foreach ($tables as $table) {
        if ($table['table'] === $name) {
            return $table;
        }
    }
    return null;
}

// ------------------------------------------------------------
// 采样历史：用来画趋势
// ------------------------------------------------------------

function system_sample_file()
{
    return rtrim(cfg('stats_dir'), '/\\') . '/samples.ndjson';
}

/**
 * 读取采样历史。
 *
 * 用 NDJSON（一行一个 JSON）而不是一个 JSON 数组文件：
 * 追加一行就能写，不需要「读出整份 → 改 → 写回」，
 * 也就不会因为两次请求同时写而把文件写坏。
 */
function system_samples($limit = 120)
{
    $file = system_sample_file();
    if (!is_file($file)) {
        return [];
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || $lines === []) {
        return [];
    }
    $lines = array_slice($lines, -max(1, (int) $limit));

    $samples = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded) && isset($decoded['t'])) {
            $samples[] = $decoded;
        }
    }
    return $samples;
}

/** 文件里最后一条采样的时间戳 */
function system_last_sample_time()
{
    $file = system_sample_file();
    if (!is_file($file)) {
        return null;
    }
    // 采样文件本身有上限（见下面的裁剪），整份读进来很便宜
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || $lines === []) {
        return null;
    }
    $decoded = json_decode($lines[count($lines) - 1], true);
    return is_array($decoded) && isset($decoded['t']) ? (int) $decoded['t'] : null;
}

/**
 * 该不该采样了？两次采样之间至少间隔 stats_sample_interval 秒。
 *
 * 特意先判断再采集：采集本身要跑十来条 SQL，
 * 不能在每次页面访问时白跑一遍只为发现「还没到时间」。
 */
function system_sample_due($minInterval = null)
{
    $interval = $minInterval === null ? (int) cfg('stats_sample_interval') : (int) $minInterval;
    if ($interval < 0) {
        return false;
    }
    $last = system_last_sample_time();
    return $last === null || (time() - $last) >= $interval;
}

/**
 * 轻量采样：只取「免费」或「一条极小查询」就能拿到的指标。
 * 页面访问（POST /api/visit）会调它，所以必须便宜——
 * 因此这里不碰 SHOW GLOBAL STATUS，MySQL 侧的字段留空，画图时跳过即可。
 */
function system_sample_light()
{
    $started = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);

    return [
        't' => time(),
        'mem' => round(memory_get_usage(true) / 1048576, 2),
        'peak' => round(memory_get_peak_usage(true) / 1048576, 2),
        'ms' => round((microtime(true) - $started) * 1000, 1),
        'q' => system_sql_counter(),
        'src' => 'visit',
    ];
}

/** 完整采样：后台的系统状态面板专用，指标齐全 */
function system_sample_from_snapshot(array $snapshot)
{
    return [
        't' => time(),
        'mem' => round($snapshot['php']['memoryBytes'] / 1048576, 2),
        'peak' => round($snapshot['php']['peakBytes'] / 1048576, 2),
        'ms' => $snapshot['php']['requestMs'],
        'q' => $snapshot['php']['queriesThisRequest'],
        'threads' => $snapshot['mysql']['threadsConnected'],
        'hit' => $snapshot['mysql']['bufferPoolHitRate'],
        'sessions' => $snapshot['app']['sessionsAlive'],
        'src' => 'panel',
    ];
}

/** 到了采样间隔就记一条；返回是否真的写了 */
function system_maybe_record_sample(array $sample, $minInterval = null)
{
    if (!system_sample_due($minInterval)) {
        return false;
    }

    $dir = cfg('stats_dir');
    if (!ensure_dir($dir)) {
        app_log('warning', '采样目录不可写，跳过本次采样', ['dir' => $dir]);
        return false;
    }

    $written = @file_put_contents(
        system_sample_file(),
        json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
    if ($written === false) {
        return false;
    }

    system_trim_samples((int) cfg('stats_max_samples'));
    return true;
}

/** 页面访问时的便捷入口：轻量采集 + 写盘，两件事都只在到点时才做 */
function system_record_visit_sample()
{
    if (!system_sample_due()) {
        return false;
    }
    return system_maybe_record_sample(system_sample_light());
}

/** 采样太多就裁掉最旧的，避免文件无限增长 */
function system_trim_samples($keep)
{
    $keep = max(10, (int) $keep);
    $file = system_sample_file();
    if (!is_file($file)) {
        return;
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    // 攒到两倍上限才裁一次，避免每次采样都重写整个文件
    if ($lines === false || count($lines) <= $keep * 2) {
        return;
    }
    @file_put_contents($file, implode("\n", array_slice($lines, -$keep)) . "\n", LOCK_EX);
}

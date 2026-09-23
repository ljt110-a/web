<?php
/**
 * 访问统计：页脚的“累计访问”、后台的“今日访问”与独立访客（UV）。
 *
 * 原单文件版本是记在浏览器 localStorage 里的，等于每人各算各的；
 * 换成服务端写数据库之后，才是所有访客共享的同一个真实计数。
 *
 * 两套口径：
 *   PV（访问量） = 行数            —— 每次刷新都算一次，反映“被看了多少次”
 *   UV（访客数） = 去重后的 IP 摘要 —— 同一天同一个 IP 只算一个，反映“有多少人”
 */

/** 记录一次页面访问，并返回最新统计 */
function record_visit()
{
    $user = current_user();
    $stmt = db()->prepare('INSERT INTO visits (day, ip, ip_hash, user_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        date('Y-m-d'),
        client_ip(),
        client_ip_hash(),
        $user === null ? null : (int) $user['id'],
    ]);
    return visit_stats();
}

/**
 * @return array 累计 / 今日的 PV 与 UV
 */
function visit_stats()
{
    $today = date('Y-m-d');

    // COUNT 会自己忽略 NULL，所以历史上没有 ip_hash 的行不会被误算成一个访客。
    // 今日 UV 用 CASE WHEN 把范围限定在今天，避免再发一条查询。
    $stmt = db()->prepare(
        'SELECT COUNT(*)                                     AS total_visits,
                COALESCE(SUM(day = ?), 0)                    AS today_visits,
                COUNT(DISTINCT ip_hash)                      AS total_visitors,
                COUNT(DISTINCT CASE WHEN day = ? THEN ip_hash END) AS today_visitors
           FROM visits'
    );
    $stmt->execute([$today, $today]);
    $row = $stmt->fetch();

    return [
        'totalVisits' => (int) $row['total_visits'],
        'todayVisits' => (int) $row['today_visits'],
        'totalVisitors' => (int) $row['total_visitors'],
        'todayVisitors' => (int) $row['today_visitors'],
    ];
}

/**
 * 最近 N 天的访问趋势（后台用）。
 * 只返回「有记录的日子」，前端按天补零没必要——空白的一天本来也不该占一行。
 *
 * @return array<int, array{day:string, visits:int, visitors:int}>
 */
function visit_daily_trend($days = 7)
{
    $days = max(1, min(30, (int) $days));
    $stmt = db()->prepare(
        'SELECT day, COUNT(*) AS visits, COUNT(DISTINCT ip_hash) AS visitors
           FROM visits
          WHERE day >= DATE_SUB(CURDATE(), INTERVAL ' . ($days - 1) . ' DAY)
          GROUP BY day
          ORDER BY day DESC'
    );
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'day' => $row['day'],
            'visits' => (int) $row['visits'],
            'visitors' => (int) $row['visitors'],
        ];
    }
    return $rows;
}

/**
 * 清理过老的访问明细（可选，默认不启用）。
 * visits 是唯一一张会随流量无限增长的表，数据量大了可以定期清理，
 * 也可以在需要长期留存时改成按天汇总到另一张表。
 */
function purge_old_visits($days = 365)
{
    $days = max(1, (int) $days);
    return db()->exec('DELETE FROM visits WHERE day < DATE_SUB(CURDATE(), INTERVAL ' . $days . ' DAY)');
}

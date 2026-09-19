<?php
/**
 * 访问统计：页脚的“累计访问”和后台的“今日访问”。
 *
 * 原单文件版本是记在浏览器 localStorage 里的，等于每人各算各的；
 * 换成服务端写数据库之后，才是所有访客共享的同一个真实计数。
 */

/** 记录一次页面访问，并返回最新统计 */
function record_visit()
{
    $stmt = db()->prepare('INSERT INTO visits (day, ip) VALUES (?, ?)');
    $stmt->execute([date('Y-m-d'), client_ip()]);
    return visit_stats();
}

/** @return array ['totalVisits'=>int,'todayVisits'=>int] */
function visit_stats()
{
    // 今日访问用一个“带索引的 day 等值条件”来数，见 schema.sql 里 idx_visits_day 的说明
    $row = db()->prepare('SELECT COUNT(*) AS total_visits, COALESCE(SUM(day = ?), 0) AS today_visits FROM visits');
    $row->execute([date('Y-m-d')]);
    $stat = $row->fetch();
    return [
        'totalVisits' => (int) $stat['total_visits'],
        'todayVisits' => (int) $stat['today_visits'],
    ];
}

<?php
/**
 * 访问统计用例：PV（访问量）与 UV（独立访客）的口径要能对上。
 * 重点验证「同一 IP 多次访问 PV 涨、UV 不涨」——这是去重有没有生效的唯一判据。
 */

t_section('访问统计：PV 与 UV');

$first = t_request('POST', '/api/visit', ['json' => [], 'jar' => 'visitor']);
t_eq(200, $first['status'], 'POST /api/visit 返回 200');
t_assert(isset($first['json']['totalVisits']), '返回累计访问量');
t_assert(isset($first['json']['todayVisits']), '返回今日访问量');
t_assert(isset($first['json']['totalVisitors']), '返回累计独立访客');
t_assert(isset($first['json']['todayVisitors']), '返回今日独立访客');

$firstVisits = $first['json'];
t_eq(1, $firstVisits['todayVisits'], '首次访问后今日 PV 为 1');
t_eq(1, $firstVisits['todayVisitors'], '首次访问后今日 UV 为 1');

$second = t_request('POST', '/api/visit', ['json' => [], 'jar' => 'visitor']);
$secondVisits = $second['json'];

t_eq($firstVisits['todayVisits'] + 1, $secondVisits['todayVisits'], '同一 IP 再访问，今日 PV 加 1');
t_eq($firstVisits['todayVisitors'], $secondVisits['todayVisitors'], '同一 IP 再访问，今日 UV 不变（去重生效）');
t_eq($firstVisits['totalVisits'] + 1, $secondVisits['totalVisits'], '累计 PV 加 1');
t_eq($firstVisits['totalVisitors'], $secondVisits['totalVisitors'], '累计 UV 也不因为同一个人而增加');

$third = t_request('POST', '/api/visit', ['json' => [], 'jar' => 'visitor']);
t_assert($third['json']['todayVisits'] > $third['json']['todayVisitors'], 'PV 可以大于 UV（同一个人来了多次）');
t_assert($third['json']['todayVisitors'] >= 1, 'UV 至少是 1');

// visits 表里每一行都该有 ip_hash，否则 UV 会漏统计
$missing = (int) db()->query('SELECT COUNT(*) FROM visits WHERE ip_hash IS NULL')->fetchColumn();
t_eq(0, $missing, '每一条访问记录都写入了 ip_hash');

// 已登录用户的访问会带上 user_id，方便以后做「登录用户 vs 游客」的区分
$logged = t_request('POST', '/api/visit', ['json' => [], 'jar' => 'admin']);
t_eq(200, $logged['status'], '已登录状态下也能记账');
$linked = (int) db()->query('SELECT COUNT(*) FROM visits WHERE user_id IS NOT NULL')->fetchColumn();
t_assert($linked >= 1, '已登录用户的访问记录了 user_id');

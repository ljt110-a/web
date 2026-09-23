<?php
/**
 * 留言板用例。
 *
 * 重点验证三件事：
 *   1. 权限边界：读公开、写要登录、删除限作者或管理员
 *   2. 内容边界：空、超长、控制字符都要被挡住
 *   3. 发帖限流：窗口内超过阈值返回 429，且解锁靠时间自然滑出
 */

t_section('留言板：读公开');

$res = t_request('GET', '/api/messages');
t_eq(200, $res['status'], '游客不登录也能读留言列表');
t_eq(0, count($res['json']['items']), '测试库一开始没有留言');
t_assert(isset($res['json']['maxLen']), '返回单条留言的字数上限');
t_assert(isset($res['json']['rateLimit']['max']), '返回发帖限流参数，前端才能提示用户');

t_section('留言板：写要登录');

$res = t_request('POST', '/api/messages', ['json' => ['body' => '游客尝试发帖'], 'jar' => 'guest']);
t_eq(401, $res['status'], '未登录发帖被拒绝 401');
t_contains($res['body'], '请先登录', '提示需要登录');

$res = t_request('DELETE', '/api/messages', ['json' => ['id' => 1], 'jar' => 'guest']);
t_eq(401, $res['status'], '未登录删除留言被拒绝 401');

t_section('留言板：内容校验');

t_clear_jar('m_author');
t_request('POST', '/api/register', ['json' => ['username' => 'm_author', 'password' => 'authorpw1'], 'jar' => 'm_author']);

$res = t_request('POST', '/api/messages', ['json' => ['body' => '   '], 'jar' => 'm_author']);
t_eq(422, $res['status'], '只有空白的留言被拒绝');

$res = t_request('POST', '/api/messages', ['json' => ['body' => str_repeat('字', 501)], 'jar' => 'm_author']);
t_eq(422, $res['status'], '超过 500 字的留言被拒绝');
t_contains($res['body'], '500', '提示里说明了字数上限');

$res = t_request('POST', '/api/messages', ['json' => ['body' => "正常开头\x07带响铃控制字符"], 'jar' => 'm_author']);
t_eq(422, $res['status'], '含控制字符的留言被拒绝');

// 边界值要精确：正好 500 字应当通过，501 字才该被拒
$res = t_request('POST', '/api/messages', ['json' => ['body' => str_repeat('字', 500)], 'jar' => 'm_author']);
t_eq(201, $res['status'], '正好 500 字的留言可以发布');

t_section('留言板：发布与展示');

$first = t_request('POST', '/api/messages', [
    'json' => ['body' => "第一行内容\n第二行 <b>不该被当成标签</b>"],
    'jar' => 'm_author',
]);
t_eq(201, $first['status'], '登录用户可以发帖');
t_eq('m_author', isset($first['json']['message']['author']) ? $first['json']['message']['author'] : null, '返回作者名');
t_eq(true, isset($first['json']['message']['mine']) ? $first['json']['message']['mine'] : null, '自己发的标记为 mine');
t_eq(true, isset($first['json']['message']['canDelete']) ? $first['json']['message']['canDelete'] : null, '自己的留言可删除');
t_eq(
    "第一行内容\n第二行 <b>不该被当成标签</b>",
    isset($first['json']['message']['body']) ? $first['json']['message']['body'] : null,
    '换行原样保存，HTML 标签也原样保存（渲染时才由前端按纯文本处理）'
);
$firstId = (int) $first['json']['message']['id'];

$res = t_request('GET', '/api/messages', ['jar' => 'guest']);
t_assert(count($res['json']['items']) >= 2, '列表中能看到刚发的留言');
$mineItem = null;
foreach ($res['json']['items'] as $item) {
    if ($item['id'] === $firstId) {
        $mineItem = $item;
    }
}
t_assert($mineItem !== null, '能在列表里找到指定的那条');
t_eq(false, $mineItem['mine'], '游客看到同一条留言时 mine 为 false');
t_eq(false, $mineItem['canDelete'], '游客看到同一条留言时 canDelete 为 false');

t_section('留言板：删除权限');

t_clear_jar('m_other');
t_request('POST', '/api/register', ['json' => ['username' => 'm_other', 'password' => 'otherpw12'], 'jar' => 'm_other']);

$res = t_request('DELETE', '/api/messages', ['json' => ['id' => $firstId], 'jar' => 'm_other']);
t_eq(403, $res['status'], '别人不能删我的留言');
t_contains($res['body'], '只能删除自己', '提示写明了原因');

$res = t_request('DELETE', '/api/messages', ['json' => ['id' => $firstId], 'jar' => 'admin']);
t_eq(200, $res['status'], '管理员可以删他人的留言');
t_eq($firstId, isset($res['json']['deleted']) ? $res['json']['deleted'] : null, '返回被删除的 id');

$res = t_request('DELETE', '/api/messages', ['json' => ['id' => $firstId], 'jar' => 'admin']);
t_eq(404, $res['status'], '重复删除返回 404');

$res = t_request('DELETE', '/api/messages', ['json' => [], 'jar' => 'admin']);
t_eq(422, $res['status'], '没带 id 时返回 422');

t_section('留言板：分页');

// 发帖限流是「每账号 3 条 / 60 秒」，所以造数据要分散到不同账号上，
// 否则第 4 条开始就被限流，分页用例会因为数据根本没造出来而失败
$pagerPlan = ['m_pager1' => 3, 'm_pager2' => 2];
foreach ($pagerPlan as $pagerName => $count) {
    t_clear_jar($pagerName);
    t_request('POST', '/api/register', [
        'json' => ['username' => $pagerName, 'password' => 'pagerpw12'],
        'jar' => $pagerName,
    ]);
    for ($i = 1; $i <= $count; $i++) {
        $res = t_request('POST', '/api/messages', [
            'json' => ['body' => $pagerName . ' 的第 ' . $i . ' 条'],
            'jar' => $pagerName,
        ]);
        t_eq(201, $res['status'], $pagerName . ' 发第 ' . $i . ' 条成功');
    }
}

$res = t_request('GET', '/api/messages?perPage=2&page=1');
t_eq(2, count($res['json']['items']), 'perPage=2 时只返回 2 条');
$total = $res['json']['total'];
t_assert($total >= 6, '分页用例所需的数据已经就位（共 ' . $total . ' 条）');
t_eq(2, $res['json']['perPage'], '响应里回显了每页条数');

$page2 = t_request('GET', '/api/messages?perPage=2&page=2');
t_assert(
    $page2['json']['items'][0]['id'] < $res['json']['items'][0]['id'],
    '越靠后的页拿到的是更早的留言（最新的在第一页）'
);
t_eq($total, $page2['json']['total'], '分页不改变总数');

$over = t_request('GET', '/api/messages?perPage=2&page=999');
t_eq($over['json']['totalPages'], $over['json']['page'], '页码越界会被拉回最后一页');

$capped = t_request('GET', '/api/messages?perPage=9999');
t_eq(50, $capped['json']['perPage'], 'perPage 超过上限时被夹到 50');

t_section('留言板：发帖限流');

// 阈值是 3（WEB_ONE_MESSAGE_RATE_MAX）：同一个账号连发 3 条之后就发不动了
t_clear_jar('m_flood');
t_request('POST', '/api/register', ['json' => ['username' => 'm_flood', 'password' => 'floodpw12'], 'jar' => 'm_flood']);

$accepted = 0;
for ($i = 1; $i <= 3; $i++) {
    $res = t_request('POST', '/api/messages', ['json' => ['body' => '连发第 ' . $i . ' 条'], 'jar' => 'm_flood']);
    if ($res['status'] === 201) {
        $accepted++;
    }
}
t_eq(3, $accepted, '窗口内前 3 条都能发出');

$res = t_request('POST', '/api/messages', ['json' => ['body' => '第 4 条就该被拦'], 'jar' => 'm_flood']);
t_eq(429, $res['status'], '超过阈值后返回 429');
t_assert(isset($res['headers']['retry-after']), '429 带 Retry-After，告诉客户端还要等多久');

// 限流是按账号算的，换个没发过的账号应该照常能发
$res = t_request('POST', '/api/messages', ['json' => ['body' => '换个账号就不受限'], 'jar' => 'm_other']);
t_eq(201, $res['status'], '限流只针对触发的那个账号，不影响别人');

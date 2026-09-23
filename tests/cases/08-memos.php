<?php
/**
 * 备忘录用例。
 *
 * 最要紧的一条是**账号隔离**：A 不能看到、也不能改删 B 的备忘录。
 * 实现上这不是靠「查出来再判断归属」，而是把 user_id 写进每条 SQL 的 WHERE，
 * 所以这里的断言也要覆盖「拿着别人的 id 去改/删会怎样」。
 */

t_section('备忘录：必须登录');

$res = t_request('GET', '/api/memos', ['jar' => 'anon']);
t_eq(401, $res['status'], '未登录读备忘录列表返回 401');

$res = t_request('POST', '/api/memos', ['json' => ['title' => '游客的备忘录'], 'jar' => 'anon']);
t_eq(401, $res['status'], '未登录新建备忘录返回 401');

$res = t_request('POST', '/api/memos/update', ['json' => ['id' => 1, 'done' => true], 'jar' => 'anon']);
t_eq(401, $res['status'], '未登录更新备忘录返回 401');

$res = t_request('DELETE', '/api/memos', ['json' => ['id' => 1], 'jar' => 'anon']);
t_eq(401, $res['status'], '未登录删除备忘录返回 401');

t_section('备忘录：新建与字段校验');

t_clear_jar('memo_a');
t_request('POST', '/api/register', ['json' => ['username' => 'memo_a', 'password' => 'memopw123'], 'jar' => 'memo_a']);

$res = t_request('GET', '/api/memos', ['jar' => 'memo_a']);
t_eq(200, $res['status'], '登录后可以读备忘录');
t_eq(0, count($res['json']['items']), '新账号一条都没有');
t_eq('all', $res['json']['filter'], '默认筛选是 all');
t_assert(isset($res['json']['stats']['total']), '返回数量统计');
t_eq(3, $res['json']['stats']['limit'], '返回每账号条数上限（测试环境设为 3）');

$res = t_request('POST', '/api/memos', ['json' => ['title' => '只有标题'], 'jar' => 'memo_a']);
t_eq(201, $res['status'], '只填标题也能新建');
t_eq('', isset($res['json']['memo']['body']) ? $res['json']['memo']['body'] : null, '正文默认为空');
t_eq(false, isset($res['json']['memo']['done']) ? $res['json']['memo']['done'] : null, '默认未完成');

$res = t_request('POST', '/api/memos', ['json' => ['title' => '   '], 'jar' => 'memo_a']);
t_eq(422, $res['status'], '标题只有空白被拒绝');

$res = t_request('POST', '/api/memos', ['json' => ['title' => str_repeat('题', 81)], 'jar' => 'memo_a']);
t_eq(422, $res['status'], '标题超过 80 字被拒绝');

$res = t_request('POST', '/api/memos', ['json' => ['title' => '正文超长', 'body' => str_repeat('字', 2001)], 'jar' => 'memo_a']);
t_eq(422, $res['status'], '正文超过 2000 字被拒绝');

$res = t_request('POST', '/api/memos', [
    'json' => ['title' => "多行标题", 'body' => "第一行\n第二行 <script>alert(1)</script>"],
    'jar' => 'memo_a',
]);
t_eq(201, $res['status'], '正文里的换行与 HTML 标签原样保存');
$memoId = (int) $res['json']['memo']['id'];
t_assert($memoId > 0, '返回新建备忘录的 id');

t_section('备忘录：账号隔离');

// B 账号登场：它不该看到 A 的任何东西，也不该能用 id 改到 A 的数据
t_clear_jar('memo_b');
t_request('POST', '/api/register', ['json' => ['username' => 'memo_b', 'password' => 'memopw456'], 'jar' => 'memo_b']);

$res = t_request('GET', '/api/memos', ['jar' => 'memo_b']);
t_eq(0, count($res['json']['items']), 'B 看不到 A 的备忘录（列表是按账号过滤的）');
t_eq(0, $res['json']['stats']['total'], 'B 的统计里也不包含 A 的数据');

t_request('POST', '/api/memos', ['json' => ['title' => 'B 自己的备忘录'], 'jar' => 'memo_b']);
$res = t_request('GET', '/api/memos', ['jar' => 'memo_b']);
t_eq(1, count($res['json']['items']), 'B 只看得到自己那一条');

$res = t_request('POST', '/api/memos/update', ['json' => ['id' => $memoId, 'title' => 'B 试图改 A 的'], 'jar' => 'memo_b']);
t_eq(404, $res['status'], 'B 拿着 A 的 id 更新会返回 404（WHERE 里带 user_id，根本不匹配）');

$res = t_request('DELETE', '/api/memos', ['json' => ['id' => $memoId], 'jar' => 'memo_b']);
t_eq(404, $res['status'], 'B 拿着 A 的 id 删除也会返回 404');

$res = t_request('GET', '/api/memos', ['jar' => 'memo_a']);
$titles = [];
foreach ($res['json']['items'] as $item) {
    $titles[] = $item['title'];
}
t_eq(2, count($res['json']['items']), 'A 的备忘录一条都没被 B 动过');
t_assert(in_array('多行标题', $titles, true), 'A 那条原始数据还在');
t_assert(!in_array('B 试图改 A 的', $titles, true), 'B 的越权修改确实没有生效');

t_section('备忘录：部分更新');

$before = null;
foreach (t_request('GET', '/api/memos', ['jar' => 'memo_a'])['json']['items'] as $item) {
    if ($item['id'] === $memoId) {
        $before = $item;
    }
}
t_assert($before !== null, '先取出更新前的快照');

$res = t_request('POST', '/api/memos/update', ['json' => ['id' => $memoId, 'done' => true], 'jar' => 'memo_a']);
t_eq(200, $res['status'], '只传 done 也能更新');
t_eq(true, isset($res['json']['memo']['done']) ? $res['json']['memo']['done'] : null, 'done 变成 true');
t_eq($before['title'], $res['json']['memo']['title'], '没传的 title 保持原值');
t_eq($before['body'], $res['json']['memo']['body'], '没传的 body 保持原值');

$res = t_request('POST', '/api/memos/update', ['json' => ['id' => $memoId, 'title' => '只改标题'], 'jar' => 'memo_a']);
t_eq(200, $res['status'], '只传 title 也能更新');
t_eq('只改标题', $res['json']['memo']['title'], '标题已更新');
t_eq(true, $res['json']['memo']['done'], 'done 保持 true 不受影响');

$res = t_request('POST', '/api/memos/update', ['json' => ['id' => $memoId, 'unknown' => 'x'], 'jar' => 'memo_a']);
t_eq(422, $res['status'], '只传不认识的字段会返回 422');
t_contains($res['body'], '没有需要修改', '提示说明了原因');

$res = t_request('POST', '/api/memos/update', ['json' => ['title' => '缺少 id'], 'jar' => 'memo_a']);
t_eq(422, $res['status'], '没带 id 返回 422');

$res = t_request('POST', '/api/memos/update', ['json' => ['id' => 999999, 'done' => true], 'jar' => 'memo_a']);
t_eq(404, $res['status'], '更新不存在的备忘录返回 404');

t_section('备忘录：筛选');

$res = t_request('GET', '/api/memos?filter=done', ['jar' => 'memo_a']);
$allDone = true;
foreach ($res['json']['items'] as $item) {
    if ($item['done'] !== true) {
        $allDone = false;
    }
}
t_eq(true, $allDone, 'filter=done 只返回已完成的');
t_eq('done', $res['json']['filter'], '回显了当前筛选条件');

$res = t_request('GET', '/api/memos?filter=open', ['jar' => 'memo_a']);
$allOpen = true;
foreach ($res['json']['items'] as $item) {
    if ($item['done'] !== false) {
        $allOpen = false;
    }
}
t_eq(true, $allOpen, 'filter=open 只返回未完成的');

// 查询参数里的中文必须自己 URL 编码：浏览器会自动编，测试里得手动编，
// 直接把原始的多字节字符塞进 URL 会让服务器回 400，那时候测的就不是业务逻辑了
$res = t_request('GET', '/api/memos?filter=' . urlencode('乱写的'), ['jar' => 'memo_a']);
t_eq('all', $res['json']['filter'], '无法识别的筛选条件按 all 处理，而不是报错');

t_section('备忘录：条数上限');

t_clear_jar('memo_limit');
t_request('POST', '/api/register', ['json' => ['username' => 'memo_limit', 'password' => 'limitpw12'], 'jar' => 'memo_limit']);

for ($i = 1; $i <= 3; $i++) {
    $res = t_request('POST', '/api/memos', ['json' => ['title' => '第 ' . $i . ' 条'], 'jar' => 'memo_limit']);
    t_eq(201, $res['status'], '上限内第 ' . $i . ' 条创建成功');
}

$res = t_request('POST', '/api/memos', ['json' => ['title' => '第 4 条'], 'jar' => 'memo_limit']);
t_eq(409, $res['status'], '超过每条账号上限返回 409');
t_contains($res['body'], '上限', '提示说明了上限');

$res = t_request('GET', '/api/memos', ['jar' => 'memo_limit']);
t_eq(3, $res['json']['stats']['total'], '库里确实只留下了 3 条');

// 删掉一条之后又能建了
$firstId = (int) $res['json']['items'][0]['id'];
$res = t_request('DELETE', '/api/memos', ['json' => ['id' => $firstId], 'jar' => 'memo_limit']);
t_eq(200, $res['status'], '删除自己的备忘录成功');
t_eq($firstId, isset($res['json']['deleted']) ? $res['json']['deleted'] : null, '返回被删除的 id');

$res = t_request('POST', '/api/memos', ['json' => ['title' => '腾出位置后新建'], 'jar' => 'memo_limit']);
t_eq(201, $res['status'], '删掉一条后又有额度了');

$res = t_request('DELETE', '/api/memos', ['json' => ['id' => 999999], 'jar' => 'memo_limit']);
t_eq(404, $res['status'], '删除不存在的备忘录返回 404');

$res = t_request('DELETE', '/api/memos', ['json' => [], 'jar' => 'memo_limit']);
t_eq(422, $res['status'], '删除时没带 id 返回 422');

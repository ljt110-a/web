<?php
/**
 * 学习板块（番茄钟）用例。
 *
 * 这一张表和 memos 一样是私有数据，所以最要紧的仍然是**账号隔离**：
 * 拿着别人的记录 id 去删，应该 404 而不是 403——后端从来不做「查出来再判断归属」，
 * user_id 一直写在 WHERE 里，别人的 id 在这张表的可见范围内根本不存在。
 *
 * 另一半价值在数值校验：elapsed 与 minutes 是一对必须自洽的数，
 * 前端计时器出 bug 或被手改时，宁可拒绝写入，也不要存进一条算不出完成度的记录。
 */

t_section('学习板块：二级页面路由');

$res = t_request('GET', '/study');
t_eq(200, $res['status'], 'GET /study 返回 200');
t_contains($res['headers']['content-type'], 'text/html', '返回的是 HTML');
t_contains($res['body'], '学习板块', '页面里有板块标题');
t_contains($res['body'], '/assets/js/study.js', '页面引用了学习板块的脚本（绝对路径）');
t_contains($res['body'], 'id="pomo-list"', '有承载记录的容器');
t_contains($res['body'], 'id="pomo-progress"', '有番茄钟的进度环');
t_assert(isset($res['headers']['content-security-policy']), '二级页面也带 CSP');

$res = t_request('GET', '/study.html');
t_eq(200, $res['status'], '/study.html 也能访问（与 /study 是同一个文件）');
$res = t_request('GET', '/study/');
t_eq(200, $res['status'], '/study/ 带斜杠也能访问');
$res = t_request('GET', '/study/not-exist');
t_eq(404, $res['status'], '学习板块下的未知路径返回 404');

$res = t_request('GET', '/');
t_contains($res['body'], 'href="/study"', '首页导航里有通往学习板块的入口');

t_section('学习板块：游客可以读配置，不能写记录');

$res = t_request('GET', '/api/study', ['jar' => 'anon']);
t_eq(200, $res['status'], '未登录也能 GET /api/study（计时器不需要账号）');
t_eq(false, $res['json']['logged'], '响应里如实说明当前未登录');
t_eq(0, count($res['json']['items']), '未登录时记录列表是空的');
t_eq(0, $res['json']['stats']['total'], '未登录时统计全是 0');
t_eq(25, $res['json']['defaults']['minutes'], '下发默认时长给前端');
t_assert(isset($res['json']['defaults']['maxMinutes']), '下发时长上限，前端不必自己抄一份数字');

$res = t_request('POST', '/api/study/pomodoro', ['json' => ['minutes' => 25, 'elapsed' => 1500, 'finished' => true], 'jar' => 'anon']);
t_eq(401, $res['status'], '未登录写入返回 401');

$res = t_request('DELETE', '/api/study/pomodoro', ['json' => ['id' => 1], 'jar' => 'anon']);
t_eq(401, $res['status'], '未登录删除返回 401');

t_section('学习板块：写入与数值校验');

t_clear_jar('study_a');
t_request('POST', '/api/register', ['json' => ['username' => 'study_a', 'password' => 'studypw123'], 'jar' => 'study_a']);

$res = t_request('POST', '/api/study/pomodoro', [
    'json' => ['subject' => '高数第二章', 'minutes' => 25, 'elapsed' => 1500, 'finished' => true],
    'jar' => 'study_a',
]);
t_eq(201, $res['status'], '跑满一轮可以记录');
$first = (int) $res['json']['pomodoro']['id'];
t_assert($first > 0, '返回新建记录的 id');
t_eq(100, $res['json']['pomodoro']['percent'], '跑满一轮完成度是 100%');
t_eq('高数第二章', $res['json']['pomodoro']['subject'], '中文标题原样返回');

$res = t_request('POST', '/api/study/pomodoro', [
    'json' => ['minutes' => 15, 'elapsed' => 300, 'finished' => false],
    'jar' => 'study_a',
]);
t_eq(201, $res['status'], '中途放弃也记一条');
t_eq(33, $res['json']['pomodoro']['percent'], '完成度按实际走的时间算（300/900 ≈ 33%）');
t_eq('', $res['json']['pomodoro']['subject'], '不填名称时是空串而不是 null');

$res = t_request('POST', '/api/study/pomodoro', ['json' => ['minutes' => 0, 'elapsed' => 0], 'jar' => 'study_a']);
t_eq(422, $res['status'], '分钟数为 0 被拒绝');

$res = t_request('POST', '/api/study/pomodoro', ['json' => ['minutes' => 181, 'elapsed' => 0], 'jar' => 'study_a']);
t_eq(422, $res['status'], '超过 180 分钟被拒绝');

$res = t_request('POST', '/api/study/pomodoro', ['json' => ['minutes' => 5, 'elapsed' => 400], 'jar' => 'study_a']);
t_eq(422, $res['status'], '实际时间明显超出计划时间被拒绝');

$res = t_request('POST', '/api/study/pomodoro', ['json' => ['minutes' => 5, 'elapsed' => 320], 'jar' => 'study_a']);
t_eq(201, $res['status'], '计划 300 秒、走满后多算 20 秒是计时器的正常抖动，接受');

$res = t_request('POST', '/api/study/pomodoro', ['json' => ['subject' => str_repeat('字', 41), 'minutes' => 5, 'elapsed' => 60], 'jar' => 'study_a']);
t_eq(422, $res['status'], '名称超过 40 字被拒绝');

$res = t_request('POST', '/api/study/pomodoro', [
    'json' => ['subject' => "第一行\n第二行", 'minutes' => 5, 'elapsed' => 60],
    'jar' => 'study_a',
]);
t_eq(201, $res['status'], '名称里带换行也收');
t_eq('第一行 第二行', $res['json']['pomodoro']['subject'], '换行被压成空格：这只是个标签，不该占两行');

$res = t_request('POST', '/api/study/pomodoro', [
    'json' => ['subject' => '<img src=x onerror=alert(1)>', 'minutes' => 5, 'elapsed' => 60],
    'jar' => 'study_a',
]);
t_eq(201, $res['status'], '名称里的 HTML 标签原样保存');
t_eq('<img src=x onerror=alert(1)>', $res['json']['pomodoro']['subject'], '后端不做转义，交给前端按纯文本渲染');

$res = t_request('GET', '/api/study', ['jar' => 'study_a']);
t_eq(200, $res['status'], '登录后能读到自己的记录');
t_assert($res['json']['logged'], '响应说明当前已登录');
t_eq(5, count($res['json']['items']), '上面被接受的那 5 条都在（另外 4 次校验失败不该留下行）');
t_eq(5, $res['json']['stats']['total'], '统计总数一致');
t_eq(5, $res['json']['stats']['today'], '全部记在今天');
t_eq(1, $res['json']['stats']['finished'], '只有 1 条是跑完的');
t_eq('第一行 第二行', $res['json']['items'][1]['subject'], '列表按新的在前排列（最新那条是 HTML 标签那一条）');

t_section('学习板块：条数上限');

// 上限在测试环境里被压到 5（见 tests/run.php 的 WEB_ONE_POMODORO_MAX_COUNT），
// 换个新账号重新灌满，免得被上面的数据干扰计数。
t_clear_jar('study_cap');
t_request('POST', '/api/register', ['json' => ['username' => 'study_cap', 'password' => 'studypw123'], 'jar' => 'study_cap']);
for ($i = 1; $i <= 5; $i++) {
    $res = t_request('POST', '/api/study/pomodoro', ['json' => ['minutes' => 5, 'elapsed' => 300, 'finished' => true], 'jar' => 'study_cap']);
    t_eq(201, $res['status'], '上限之内第 ' . $i . ' 条能写入');
}
$res = t_request('POST', '/api/study/pomodoro', ['json' => ['minutes' => 5, 'elapsed' => 300, 'finished' => true], 'jar' => 'study_cap']);
t_eq(409, $res['status'], '到上限后拒绝继续写入');
t_contains($res['json']['error'], '上限', '拒绝时说的是「已达上限」，不是一句笼统的失败');

t_section('学习板块：账号隔离与删除');

t_clear_jar('study_b');
t_request('POST', '/api/register', ['json' => ['username' => 'study_b', 'password' => 'studypw456'], 'jar' => 'study_b']);

$res = t_request('GET', '/api/study', ['jar' => 'study_b']);
t_eq(0, count($res['json']['items']), 'B 看不到 A 的记录');
t_eq(0, $res['json']['stats']['total'], 'B 的统计里也不包含 A 的数据');

$res = t_request('DELETE', '/api/study/pomodoro', ['json' => ['id' => $first], 'jar' => 'study_b']);
t_eq(404, $res['status'], 'B 拿 A 的记录 id 去删：查不到，返回 404');

$res = t_request('DELETE', '/api/study/pomodoro', ['json' => ['id' => $first], 'jar' => 'study_a']);
t_eq(200, $res['status'], 'A 删自己的记录成功');
t_eq($first, $res['json']['deleted'], '响应里回的是被删的 id');
t_eq(4, $res['json']['stats']['total'], '删完之后再统计少了一条');

$res = t_request('DELETE', '/api/study/pomodoro', ['json' => ['id' => $first], 'jar' => 'study_a']);
t_eq(404, $res['status'], '同一条删第二次返回 404');

$res = t_request('DELETE', '/api/study/pomodoro', ['json' => ['id' => 0], 'jar' => 'study_a']);
t_eq(422, $res['status'], '缺少 id 的删除请求被拒绝');

$res = t_request('GET', '/api/study', ['jar' => 'study_a']);
t_eq(4, count($res['json']['items']), '列表里确实少了一条');

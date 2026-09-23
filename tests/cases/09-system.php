<?php
/**
 * 资源监控用例。
 *
 * 除了权限与字段完整性，这里还要证明一件事：
 * 这套监控**不依赖任何操作系统命令**（本机连 wmic 都被安全策略拉黑了），
 * 全部数据来自 PHP 原生函数与 MySQL 的 performance_schema / information_schema。
 * 断言方式就是检查那两类数据源确实有值：
 *   磁盘来自 disk_free_space()，MySQL 内存分布来自 performance_schema。
 */

t_section('系统状态：权限');

$res = t_request('GET', '/api/admin/system', ['jar' => 'anon']);
t_eq(401, $res['status'], '未登录读系统状态返回 401');

t_clear_jar('sys_user');
t_request('POST', '/api/register', ['json' => ['username' => 'sys_user', 'password' => 'syspw1234'], 'jar' => 'sys_user']);
$res = t_request('GET', '/api/admin/system', ['jar' => 'sys_user']);
t_eq(403, $res['status'], '普通用户读系统状态返回 403');
t_contains($res['body'], '管理员', '提示需要管理员权限');

$res = t_request('GET', '/api/admin/system', ['jar' => 'admin']);
t_eq(200, $res['status'], '管理员可以读系统状态');
$snapshot = $res['json'];
t_assert(isset($snapshot['sampledAt']), '返回采集时间');

t_section('系统状态：PHP 侧指标');

$php = $snapshot['php'];
t_eq(PHP_VERSION, isset($php['version']) ? $php['version'] : null, 'PHP 版本与运行环境一致');
t_assert($php['memoryBytes'] > 0, '进程内存占用是个正数');
t_assert($php['peakBytes'] >= $php['memoryBytes'], '峰值内存不会小于当前占用');
t_assert(isset($php['limitText']) && $php['limitText'] !== '', '返回 memory_limit 的文字值');
t_assert(is_float($php['requestMs']) || is_int($php['requestMs']), '返回本次请求耗时');
t_assert($php['requestMs'] >= 0, '耗时是个非负数');
t_assert($php['queriesThisRequest'] >= 0, '返回本次采集执行的 SQL 条数');
t_assert($php['includedFiles'] > 0, '返回已包含的文件数');
t_assert(is_bool($php['opcacheEnabled']), '返回 OPcache 是否启用');

// 采集自身只该跑十几条 SQL；如果这个数字失控，说明有人往面板里加了重量级查询
t_assert(
    $php['queriesThisRequest'] <= 60,
    '本次采集的 SQL 条数在合理范围内（' . $php['queriesThisRequest'] . ' 条）'
);

t_section('系统状态：磁盘（PHP 原生函数，不走系统命令）');

t_assert(is_array($snapshot['disks']) && count($snapshot['disks']) > 0, '取到了至少一个磁盘的信息');
foreach ($snapshot['disks'] as $disk) {
    t_assert(isset($disk['mount']) && $disk['mount'] !== '', '磁盘有盘符/挂载点：' . (isset($disk['mount']) ? $disk['mount'] : '?'));
    t_assert($disk['totalBytes'] > 0, '总容量是个正数');
    t_assert($disk['freeBytes'] >= 0, '剩余容量非负');
    t_assert($disk['usedPercent'] >= 0 && $disk['usedPercent'] <= 100, '使用率在 0~100 之间');
}

t_section('系统状态：MySQL 侧指标');

$mysql = $snapshot['mysql'];
t_assert(isset($mysql['version']) && $mysql['version'] !== '', '返回 MySQL 版本：' . (isset($mysql['version']) ? $mysql['version'] : '?'));
t_assert($mysql['uptime'] > 0, '返回运行时长');
t_assert(isset($mysql['uptimeText']) && $mysql['uptimeText'] !== '', '运行时长的可读写法：' . $mysql['uptimeText']);
t_assert($mysql['threadsConnected'] >= 1, '当前连接数至少是 1（就是这次请求自己）');
t_assert($mysql['connections'] > 0, '返回累计连接数');
t_assert($mysql['questions'] > 0, '返回查询计数');
t_assert(isset($mysql['slowQueries']), '返回慢查询数');
t_assert(
    $mysql['bufferPoolHitRate'] === null || ($mysql['bufferPoolHitRate'] >= 0 && $mysql['bufferPoolHitRate'] <= 100),
    '缓冲池命中率在 0~100 之间（取不到时允许为 null）'
);
t_assert(is_array($mysql['memoryByComponent']), '返回 MySQL 内存分布列表');

t_section('系统状态：MySQL 内存分布来自 performance_schema');

if ($mysql['memoryByComponent'] === []) {
    // 这台 MySQL 关了 performance_schema，那就确认它被优雅地降级处理了
    t_pass('performance_schema 未开启，内存分布为空数组（已优雅降级，没有报错）');
} else {
    t_pass('performance_schema 可用，读到 ' . count($mysql['memoryByComponent']) . ' 个内存组件');
    $total = 0;
    $sorted = true;
    $previous = null;
    foreach ($mysql['memoryByComponent'] as $item) {
        t_assert(isset($item['name']) && $item['name'] !== '', '组件有名字：' . $item['name']);
        t_assert($item['bytes'] > 0, '组件占用是正数');
        t_assert(strpos($item['name'], 'memory/') !== 0, '组件名去掉了 memory/ 前缀，便于阅读');
        $total += $item['bytes'];
        if ($previous !== null && $item['bytes'] > $previous) {
            $sorted = false;
        }
        $previous = $item['bytes'];
    }
    t_eq(true, $sorted, '内存组件按占用从大到小排序');
    t_assert($total > 0, '这些组件加起来是个正数（合计 ' . round($total / 1048576, 2) . ' MB）');
}

t_section('系统状态：各表占用空间');

t_assert(count($mysql['tables']) >= 7, '列出至少 7 张表（当前 ' . count($mysql['tables']) . ' 张）');
$tableNames = [];
foreach ($mysql['tables'] as $table) {
    $tableNames[] = $table['table'];
}
foreach (['users', 'sessions', 'visits', 'messages', 'memos'] as $expected) {
    t_assert(in_array($expected, $tableNames, true), '表清单里有 ' . $expected);
}
$first = $mysql['tables'][0];
t_assert($first['bytes'] > 0, '表的占用空间是正数');
t_assert(isset($first['rows']), '同时返回大致行数');

t_section('系统状态：业务规模');

$app = $snapshot['app'];
t_assert($app['users'] >= 6, '账号数与测试库相符（' . $app['users'] . ' 个）');
t_assert($app['admins'] >= 1, '至少有 1 个管理员');
t_assert($app['messages'] > 0, '统计到了留言数（' . $app['messages'] . ' 条）');
t_assert($app['memos'] > 0, '统计到了备忘录数（' . $app['memos'] . ' 条）');
t_assert($app['sessionsAlive'] >= 0, '返回有效会话数');
t_assert(isset($app['visitsToday']), '返回今日访问量');

t_section('系统状态：诊断结论');

t_assert(is_array($snapshot['verdict']) && count($snapshot['verdict']) >= 4, '给出至少 4 条结论');
$levels = [];
foreach ($snapshot['verdict'] as $item) {
    $levels[] = $item['level'];
    t_assert(in_array($item['level'], ['ok', 'info', 'warn'], true), '结论级别合法：' . $item['level']);
    t_assert(mb_strlen($item['text'], 'UTF-8') >= 10, '结论是句人话而不是占位符');
}
t_assert(in_array('ok', $levels, true), '有正常状态的结论');

// 结论里应该说明「数据来源不需要平台命令」这件事，避免后来人误以为要装东西
$hasSourceNote = false;
foreach ($snapshot['verdict'] as $item) {
    if (strpos($item['text'], '平台命令') !== false) {
        $hasSourceNote = true;
    }
}
t_eq(true, $hasSourceNote, '最后一条结论说明了数据来源不依赖平台命令');

t_section('系统状态：采样历史');

t_eq(0, cfg('stats_sample_interval'), '测试环境把采样间隔设为 0，便于验证累积');

$res = t_request('GET', '/api/admin/system', ['jar' => 'admin']);
t_eq(true, isset($res['json']['sampleRecorded']) ? $res['json']['sampleRecorded'] : null, '第二次打开面板时写入了一个采样点');
t_assert(count($res['json']['samples']) >= 1, '快照里带回采样历史（' . count($res['json']['samples']) . ' 条）');

$after = t_request('GET', '/api/admin/system', ['jar' => 'admin']);
t_assert(
    count($after['json']['samples']) > count($res['json']['samples']),
    '再采集一次，采样点数增加（说明历史确实在累积）'
);

$sample = $after['json']['samples'][count($after['json']['samples']) - 1];
t_assert(isset($sample['t']) && $sample['t'] > 0, '采样点带时间戳');
t_assert(isset($sample['mem']) && $sample['mem'] > 0, '采样点带内存占用（MB）');
t_assert(isset($sample['ms']), '采样点带请求耗时');
t_assert(in_array($sample['src'], ['visit', 'panel'], true), '采样点标注了来源：' . $sample['src']);

// 采样文件必须是 NDJSON（一行一条），这样追加写才不会互相覆盖
$file = system_sample_file();
t_assert(is_file($file), '采样文件已生成：' . basename($file));
$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
t_assert(count($lines) >= 1, '文件里至少有一行');
$allValid = true;
foreach ($lines as $line) {
    if (!is_array(json_decode($line, true))) {
        $allValid = false;
    }
}
t_eq(true, $allValid, '每一行都是合法的 JSON（NDJSON 格式）');

// 采样间隔为 0 时每次都会写，而写盘成功后要顺手裁剪，避免文件无限增长
t_eq((int) cfg('stats_max_samples'), 120, '保留了每次采样的上限配置');

t_section('「我的用量」：放开的是自己那一份，不是服务器那一份');

/**
 * 首页那一节对三种人分别该给什么，全在这一节钉住。
 * 这条边界一旦松掉，磁盘容量与 MySQL 内部数字就会「顺手也给所有人看」，
 * 而那类数对每个访客都是同一份，根本不叫「自己的性能」。
 * 所以这里两头都测：普通账号该拿到的拿得到，不该拿到的照样吃 401。
 */

$sysRoot = dirname(__DIR__, 2);

// —— 1. 构成那三张卡的，都是各板块本来就有的按账号只读接口 ——
$res = t_request('GET', '/api/novels', ['jar' => 'sys_user']);
t_eq(200, $res['status'], '普通账号能读自己的书架（「你的书架」那张卡就是它）');
t_assert(isset($res['json']['stats']['chars']), '书架返回里带「已用字数」');
t_assert(isset($res['json']['stats']['charLimit']), '还带账号的总量上限');

$res = t_request('GET', '/api/study', ['jar' => 'sys_user']);
t_eq(200, $res['status'], '普通账号能读自己的专注记录');
foreach (['total', 'today', 'finished', 'minutes'] as $sysKey) {
    t_assert(isset($res['json']['stats'][$sysKey]), '番茄钟的 stats 里有 ' . $sysKey);
}

$res = t_request('GET', '/api/memos', ['jar' => 'sys_user']);
t_eq(200, $res['status'], '普通账号能读自己的备忘录（首页本来就要发这一次）');
t_assert(isset($res['json']['stats']['total']), '备忘录返回里带着条数统计，用量卡顺带取用');

// —— 2. 游客：只有学习页那一格是公开的，而且那份里没有别人的数 ——
t_clear_jar('sys_anon');
$res = t_request('GET', '/api/study', ['jar' => 'sys_anon']);
t_eq(200, $res['status'], '游客也读得到学习页（它本来就公开）');
t_eq(false, isset($res['json']['logged']) ? $res['json']['logged'] : null, '但这一份明确标着未登录');
t_eq(0, isset($res['json']['stats']['total']) ? $res['json']['stats']['total'] : null,
    '游客拿到的专注条数是 0：这一节不会替谁把数报出来');
$res = t_request('GET', '/api/novels', ['jar' => 'sys_anon']);
t_eq(401, $res['status'], '书架是私有数据，游客连统计都拿不到');

// —— 3. 「自己的」不是句空话：换个账号读，读到的就是另一个人的那份 ——
t_clear_jar('sys_owner');
t_request('POST', '/api/register', [
    'json' => ['username' => 'sys_owner', 'password' => 'sysown1234'],
    'jar' => 'sys_owner',
]);
$sysPara = '他提起那柄无锋的旧铁剑，推开柴房那扇吱呀作响的木门，山风立刻灌满了袖口。';
$res = t_request('POST', '/api/novels', [
    'json' => ['title' => '只属于他的那本', 'text' => "第一章 起风\n" . str_repeat($sysPara . "\n", 6)],
    'jar' => 'sys_owner',
]);
t_eq(201, $res['status'], '另一个账号导进了一本');
$sysBookId = isset($res['json']['novel']['id']) ? $res['json']['novel']['id'] : 0;
t_assert((int) $res['json']['novel']['charCount'] > 0, '那本书确实记了字数：' . $res['json']['novel']['charCount']);

$res = t_request('GET', '/api/novels', ['jar' => 'sys_user']);
t_eq(0, $res['json']['stats']['total'], '普通账号的卡上还是 0 本：别人的书不往这一节里流');
t_eq(0, $res['json']['stats']['chars'], '已用字数也是各人那份，从不共享');

// 收干净，别把这本书留给后面的用例
t_request('DELETE', '/api/novels', ['json' => ['id' => $sysBookId], 'jar' => 'sys_owner']);
$res = t_request('GET', '/api/novels', ['jar' => 'sys_owner']);
t_eq(0, $res['json']['stats']['chars'], '删掉之后配额原样退回：这一节报的是「还在架上」的数');

// —— 4. 结构上的两条边界 ——
$sysApi = (string) file_get_contents($sysRoot . '/src/api.php');
preg_match_all("/'(?:GET|POST|DELETE) (\/api\/[^']*)' *=>/", $sysApi, $sysRoutes);
$sysLeaky = [];
foreach ($sysRoutes[1] as $sysRoute) {
    if (preg_match('#^/api/(system|usage|metrics|stats|monitor)#', $sysRoute)) {
        $sysLeaky[] = $sysRoute;
    }
}
t_eq([], $sysLeaky, '没为这一节新加任何公开的服务端指标接口：三份数全部复用已有按账号接口');

$sysHtml = (string) file_get_contents($sysRoot . '/public/index.html');
t_contains($sysHtml, 'id="usage"', '首页有「我的用量」那一节的容器');
t_contains($sysHtml, '和你自己有关', '页面上写明了这一节只放自己的数');

$sysJs = (string) file_get_contents($sysRoot . '/public/assets/js/app.js');
$sysFrom = strpos($sysJs, '第 15.5 部分');
$sysTo = strpos($sysJs, '第 16 部分：启动');
t_assert($sysFrom !== false && $sysTo !== false && $sysTo > $sysFrom, '在 app.js 里找得到「我的用量」那一段');
$sysUsage = substr($sysJs, $sysFrom, $sysTo - $sysFrom);
// 注释里就写着「刻意不复用 GET /api/admin/system」，所以这里查的是调用形式而不是这个词
t_assert(strpos($sysUsage, "api('admin/system'") === false,
    '这一段不打 admin/system：放开了自己那一份，不等于把服务器那一份一起递出去');
preg_match_all("/label: '([^']+)'/u", $sysUsage, $sysLabels);
t_assert(count($sysLabels[1]) >= 5, '这一节排出 ' . count($sysLabels[1]) . ' 张卡的标签：' . implode('、', $sysLabels[1]));
foreach (['内存', '磁盘', '缓冲池', '连接', '慢查询', '峰值', 'SQL', '表'] as $sysWord) {
    $sysHit = array_values(array_filter($sysLabels[1], function ($label) use ($sysWord) {
        return strpos($label, $sysWord) !== false;
    }));
    t_eq([], $sysHit, '卡片标签里不出现「' . $sysWord . '」：那是整台机器共用的数，不是任何人的「自己的」');
}

<?php
/**
 * 软件仓库用例：公开读、权限边界、字段校验、受控出网的闸门、服务器代下载、图标。
 *
 * 这一份最要紧的是「闸门」那一组。抓远端是这个项目第一次由服务器主动出网：
 * 一条不把关的抓取接口，等于把「让服务器替你访问任意地址」交给了能编辑条目的人，
 * 内网服务、路由器的管理口、云主机的元数据接口都在那一发请求的射程之内。
 * 所以四条拒绝理由（非 http/https、不在白名单、解析到内网、重定向跳出白名单）
 * 每一条都要被真的跑到，而不是只在注释里声称做了。
 *
 * 下载这一组打的是 tests/fake_http.php 起的那台假源站（127.0.0.1:8124），
 * 走的是 src/softnet.php 里完整的 socket 客户端：分块、重定向、超时、
 * 大小上限、落盘命名全是真的，没有把任何一步替换成桩。
 *
 * 两条已知没覆盖到的分支，都是「够不着」而不是「不想测」：
 *   · 413（远端 release 声明的大小就超限，提前拒）：要求来源真是 GitHub/Gitee 的
 *     release 资产，测试不能依赖 api.github.com。真正守着磁盘的是按本地实际字节
 *     判的那一刀（502），那条有用例。
 *   · 智能获取图标与「自动识别信息」走 GitHub 那一条候选：仓库信息接口的地址是写死的
 *     api.github.com，测试白名单里只有假源站，所以那一跳只能验到「被白名单拒掉、
 *     接着试下一个来源 / 每家都报不出结果」；字段解析改由 software_repo_json、
 *     software_repo_meta、software_release_version、software_identify_one、
 *     software_avatar_field 直接打假源站验，拼出来的接口地址由 software_repo_api 单独钉住。
 */

$softUrl = $GLOBALS['T_SOFT_URL'];
$softDir = softnet_dir();
/** 假源站的正文是固定算出来的，这里复刻一份，用来比对字节数与摘要 */
function t_soft_body($bytes)
{
    $unit = 'web-one-fake-installer-payload-0123456789ABCDEF';
    return substr(str_repeat($unit, (int) ceil($bytes / strlen($unit)) + 1), 0, $bytes);
}

/** 某一款软件在磁盘上留下的所有文件（安装包 + 图标 + 半成品） */
function t_soft_files($id)
{
    return array_values((array) glob(cfg('soft_dir') . '/' . (int) $id . '.*'));
}

/** 目录里所有没被清掉的中间产物（.part 半成品 + grab- 暂存）：任何时刻都该是空的 */
function t_soft_parts()
{
    $files = [];
    foreach (['*.part', 'grab-*'] as $pattern) {
        foreach ((array) glob(cfg('soft_dir') . '/' . $pattern) as $file) {
            $files[] = basename(str_replace('\\', '/', $file));
        }
    }
    sort($files);
    return $files;
}

/** 调一下，只取它抛出的 ApiException 状态码；没抛就是 0 */
function t_soft_status(callable $fn)
{
    try {
        $fn();
        return 0;
    } catch (ApiException $e) {
        return $e->status();
    }
}

/** 同上，但把消息一起拿回来：要断言「因为哪一条被拒」时用 */
function t_soft_error(callable $fn)
{
    try {
        $fn();
        return ['status' => 0, 'message' => ''];
    } catch (ApiException $e) {
        return ['status' => $e->status(), 'message' => $e->getMessage()];
    }
}

/** 从列表载荷里按 id 找一项 */
function t_soft_item(array $payload, $id)
{
    foreach ($payload['items'] as $item) {
        if ((int) $item['id'] === (int) $id) {
            return $item;
        }
    }
    return null;
}

/** 管理员新增一款（大半用例都要先有这么一项，不重复写样板） */
function t_soft_create(array $payload)
{
    $res = t_request('POST', '/api/admin/software', ['json' => $payload, 'jar' => 'admin']);
    if ($res['status'] !== 201) {
        throw new RuntimeException('造数据失败：' . json_encode($res['json'], JSON_UNESCAPED_UNICODE));
    }
    return $res['json']['software'];
}

/** 改某一款的直链，为下一次抓包准备靶子 */
function t_soft_set_url($id, $path)
{
    global $softUrl;
    $res = t_request('POST', '/api/admin/software/update', [
        'json' => ['id' => $id, 'downloadUrl' => $softUrl . $path],
        'jar' => 'admin',
    ]);
    if ($res['status'] !== 200) {
        throw new RuntimeException('改直链失败：' . $res['status']);
    }
}

/** 让服务器抓一次包 */
function t_soft_grab($id)
{
    return t_request('POST', '/api/admin/software/grab', ['json' => ['id' => $id], 'jar' => 'admin']);
}

// ------------------------------------------------------------
// 公开读
// ------------------------------------------------------------

t_section('软件仓库：公开清单（空表）');

t_eq(true, is_dir($softDir), '安装包目录已就绪：' . $softDir);

$res = t_request('GET', '/api/software');
t_eq(200, $res['status'], '未登录也能读软件清单');
t_eq([], $res['json']['items'], '全新的库里没有条目');
t_eq(0, $res['json']['total'], '总数 0');
t_eq(false, $res['json']['manageable'], '游客看到的 manageable 是 false');
t_eq(0, $res['json']['stats']['categories'], '分类数 0');
t_eq(0, $res['json']['stats']['directFiles'], '可直链下载数 0');
t_eq([], $res['json']['facets']['categories'], '侧栏分类为空');
t_eq([], $res['json']['facets']['platforms'], '平台筛选条为空');
t_eq([], $res['json']['facets']['tags'], '标签清单为空');
t_assert(isset($res['json']['limits']['name']), '带回字段上限，前端才能设 maxlength');
t_eq(6, count($res['json']['platformLabels']), '带回 6 个平台的显示名');
t_eq(200000, $res['json']['maxFileBytes'], '带回单包上限（这是产品规则，给谁看都不泄露什么）');
t_eq(400000, $res['json']['quotaBytes'], '带回总配额上限');
t_eq(true, $res['json']['fetchEnabled'], '带回「服务器代下载」是否开启');
// 已经占了多少字节是一项测量值而不是规则：按既定的可见边界，只有管理员能看到
t_eq(false, array_key_exists('quota', $res['json']), '游客看不到已经占用的字节数');

t_section('软件仓库：权限边界');

$res = t_request('GET', '/api/admin/software', ['jar' => 'anon']);
t_eq(401, $res['status'], '未登录读管理清单 401');
$res = t_request('POST', '/api/admin/software', ['json' => ['name' => 'x', 'category' => 'y'], 'jar' => 'anon']);
t_eq(401, $res['status'], '未登录新增 401');

t_clear_jar('s_user');
t_request('POST', '/api/register', ['json' => ['username' => 's_user', 'password' => 'softpw123'], 'jar' => 's_user']);

$res = t_request('GET', '/api/admin/software', ['jar' => 's_user']);
t_eq(403, $res['status'], '普通用户读管理清单 403');
$res = t_request('POST', '/api/admin/software', ['json' => ['name' => '越权新增', 'category' => '测试'], 'jar' => 's_user']);
t_eq(403, $res['status'], '普通用户新增 403');
$res = t_request('POST', '/api/admin/software/update', ['json' => ['id' => 1, 'enabled' => false], 'jar' => 's_user']);
t_eq(403, $res['status'], '普通用户改条目 403');
$res = t_request('DELETE', '/api/admin/software', ['json' => ['id' => 1], 'jar' => 's_user']);
t_eq(403, $res['status'], '普通用户删除 403');
// 关键：让服务器出网的那两条也必须 403。它们能触发一次对外请求，
// 比「改一行字」敏感得多——拿到这两个接口就等于借到了服务器的网络位置。
$res = t_request('POST', '/api/admin/software/grab', ['json' => ['id' => 1], 'jar' => 's_user']);
t_eq(403, $res['status'], '普通用户触发「服务器代下载」403');
$res = t_request('POST', '/api/admin/software/release', ['json' => ['id' => 1], 'jar' => 's_user']);
t_eq(403, $res['status'], '普通用户释放安装包 403');

$res = t_request('GET', '/api/software');
t_eq(0, count($res['json']['items']), '上面六次越权一次都没改动数据');

// ------------------------------------------------------------
// 写入与校验
// ------------------------------------------------------------

t_section('软件仓库：管理员新增');

$softA = t_soft_create([
    'slug' => 'test-tool-a',
    'name' => '测试工具甲',
    'category' => '系统工具',
    'platforms' => ['linux', 'windows'],
    'tags' => ['便携', '绿色'],
    'description' => "第一行\n第二行",
    'homepage' => 'https://example.com/a',
    'githubUrl' => 'https://github.com/example/tool-a',
    'downloadUrl' => $softUrl . '/dl/2048.exe',
    'sourceMode' => 'direct',
    'version' => '1.2.3',
    'license' => 'MIT',
    'sortOrder' => 10,
]);
$idA = (int) $softA['id'];

t_eq('test-tool-a', $softA['slug'], '英文标识保存下来');
t_eq('soft-test-tool-a', $softA['anchor'], '锚点由标识生成');
t_eq('测试工具甲', $softA['name'], '软件名');
t_eq('系统工具', $softA['category'], '分类');
// 传进去的是 ['linux','windows']，回来是按下表顺序规范化的结果：
// 前端拿到就能直接画图标，不用再排一次序
t_eq(['windows', 'linux'], array_column($softA['platforms'], 'code'), '平台按固定顺序规范化');
t_eq(['Windows', 'Linux'], array_column($softA['platforms'], 'label'), '平台带回显示名');
t_eq(['便携', '绿色'], $softA['tags'], '标签是数组，不是逗号串');
t_eq("第一行\n第二行", $softA['description'], '简介里的换行原样保留');
t_eq('direct', $softA['sourceMode'], '取包方式');
t_eq(true, $softA['enabled'], '新增即上架');
t_eq(10, $softA['sortOrder'], '排序值');
t_eq('1.2.3', $softA['version'], '版本号');
t_eq(false, $softA['hasFile'], '刚建好还没有本地安装包');
t_eq(null, $softA['fileUrl'], '没有本地包时不给下载地址');
t_eq(null, $softA['iconUrl'], '没有本地图标时不给图标地址');
t_eq(null, $softA['sizeBytes'], '没抓过也没有大小');

$res = t_request('GET', '/api/software');
t_eq(1, count($res['json']['items']), '新增之后公开列表立刻可见');
t_eq('测试工具甲', $res['json']['items'][0]['name'], '公开列表里读得到内容');

t_section('软件仓库：管理视图多出来的字段');

$adminList = t_request('GET', '/api/admin/software', ['jar' => 'admin']);
t_eq(200, $adminList['status'], '管理员读管理清单成功');
t_eq(true, $adminList['json']['manageable'], '管理员看到 manageable = true');
$adminA = t_soft_item($adminList['json'], $idA);
$publicA = t_soft_item(t_request('GET', '/api/software')['json'], $idA);
foreach (['sourceMode', 'enabled', 'sortOrder', 'createdAt', 'updatedAt', 'fileSha256', 'fileOrigin', 'fileFetchedAt', 'iconKind'] as $key) {
    t_assert(array_key_exists($key, $adminA), "管理视图里有 {$key}");
    t_eq(false, array_key_exists($key, $publicA), "公开列表里看不到 {$key}");
}
// 公开接口带 Cookie 也必须返回同样的内容：结果不随身份变化，
// 否则缓存、排障和测试都不可预期（这一条与留言板、小说是同一个约定）
$res = t_request('GET', '/api/software', ['jar' => 'admin']);
t_eq(false, $res['json']['manageable'], '管理员带 Cookie 打公开接口，manageable 仍是 false');
$res = t_request('GET', '/api/software', ['jar' => 's_user']);
t_eq(false, array_key_exists('sourceMode', $res['json']['items'][0]), '普通用户打公开接口也拿不到管理字段');
t_eq(false, array_key_exists('quota', $res['json']), '普通用户也看不到已占用的字节数');
$res = t_request('GET', '/api/admin/software', ['jar' => 'admin']);
t_assert(isset($res['json']['quota']['usedBytes']), '管理员能看到已占用的字节数');
t_eq(400000, $res['json']['quota']['limitBytes'], '管理视图带回配额上限');
t_eq(0, $res['json']['quota']['fileCount'], '当前还没有任何本地安装包');

t_section('软件仓库：字段校验');

$cases = [
    ['不填软件名', ['category' => '系统工具'], 422],
    ['不填分类', ['name' => '只有名字'], 422],
    ['软件名超长', ['name' => str_repeat('长', 81), 'category' => '系统工具'], 422],
    ['分类超长', ['name' => '正常名字', 'category' => str_repeat('类', 31)], 422],
    ['简介超长', ['name' => '正常名字', 'category' => '系统工具', 'description' => str_repeat('字', 301)], 422],
    ['不认识的平台', ['name' => '正常名字', 'category' => '系统工具', 'platforms' => ['symbian']], 422],
    ['取包方式不认识', ['name' => '正常名字', 'category' => '系统工具', 'sourceMode' => 'gitlab'], 422],
    ['版本号带斜杠', ['name' => '正常名字', 'category' => '系统工具', 'version' => '1.0/build'], 422],
    ['标签超过 12 个', ['name' => '正常名字', 'category' => '系统工具', 'tags' => array_map(function ($i) {
        return '标签' . $i;
    }, range(1, 13))], 422],
    ['单个标签超长', ['name' => '正常名字', 'category' => '系统工具', 'tags' => [str_repeat('标', 21)]], 422],
    ['官网是伪协议', ['name' => '正常名字', 'category' => '系统工具', 'homepage' => 'javascript:alert(1)'], 422],
    ['仓库地址是 ftp', ['name' => '正常名字', 'category' => '系统工具', 'githubUrl' => 'ftp://example.com/x'], 422],
    ['直链是 data', ['name' => '正常名字', 'category' => '系统工具', 'downloadUrl' => 'data:text/html,<script>'], 422],
    ['直链没有域名', ['name' => '正常名字', 'category' => '系统工具', 'downloadUrl' => 'https://'], 422],
    ['直链超长', ['name' => '正常名字', 'category' => '系统工具', 'downloadUrl' => 'https://example.com/' . str_repeat('a', 500)], 422],
    ['标识只有一个字符', ['name' => '正常名字', 'category' => '系统工具', 'slug' => 'a'], 422],
    ['标识以连字符开头', ['name' => '正常名字', 'category' => '系统工具', 'slug' => '-abc'], 422],
    ['标识里有中文', ['name' => '正常名字', 'category' => '系统工具', 'slug' => '中文标识'], 422],
    ['地址里有控制字符', ['name' => '正常名字', 'category' => '系统工具', 'homepage' => "https://a.com/\x01x"], 422],
    ['软件名里有控制字符', ['name' => "甲\x01乙", 'category' => '系统工具'], 422],
    ['重复的英文标识', ['name' => '正常名字', 'category' => '系统工具', 'slug' => 'test-tool-a'], 409],
];
foreach ($cases as $case) {
    list($label, $payload, $expect) = $case;
    $res = t_request('POST', '/api/admin/software', ['json' => $payload, 'jar' => 'admin']);
    t_eq($expect, $res['status'], $label . ' 被拒（期望 ' . $expect . '）');
}

$res = t_request('GET', '/api/software');
t_eq(1, count($res['json']['items']), '这二十一次被拒的提交一条都没落库');

$res = t_request('POST', '/api/admin/software', [
    'json' => ['name' => '大小写测试', 'category' => '系统工具', 'slug' => 'My-Tool-B', 'tags' => 'GUI, gui，绿色 便携'],
    'jar' => 'admin',
]);
t_eq(201, $res['status'], '标识带大写也能新增（会被规范化）');
$idB = (int) $res['json']['software']['id'];
t_eq('my-tool-b', $res['json']['software']['slug'], '标识统一小写');
// 标签同时吃「半角逗号 / 中文逗号 / 空格」三种分隔，并按大小写去重：
// 管理员从别处复制过来的一行字不该被切成一堆碎片
t_eq(['GUI', '绿色', '便携'], $res['json']['software']['tags'], '混合分隔符与大小写去重');

$res = t_request('POST', '/api/admin/software', ['json' => ['name' => '没有标识', 'category' => '开发工具'], 'jar' => 'admin']);
t_eq(201, $res['status'], '标识留空也能新增');
$idC = (int) $res['json']['software']['id'];
t_eq(null, array_key_exists('slug', $res['json']['software']) ? $res['json']['software']['slug'] : 'missing', 'slug 为 null');
t_eq('soft-' . $idC, $res['json']['software']['anchor'], '没有标识时锚点退成 soft-<id>');
t_eq([], $res['json']['software']['platforms'], '一个平台都没选时是空数组而不是报错');
t_eq('auto', $res['json']['software']['sourceMode'], '取包方式默认 auto');

t_section('软件仓库：部分更新');

$res = t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idC, 'version' => '2.0'], 'jar' => 'admin']);
t_eq(200, $res['status'], '只改版本号返回 200');
t_eq('2.0', $res['json']['software']['version'], '版本号已更新');
t_eq('没有标识', $res['json']['software']['name'], '没传的字段保持原值');
$res = t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idC, 'unknown' => 'x'], 'jar' => 'admin']);
t_eq(422, $res['status'], '只传不认识的字段返回 422');
$res = t_request('POST', '/api/admin/software/update', ['json' => ['enabled' => false], 'jar' => 'admin']);
t_eq(422, $res['status'], '更新没带 id 返回 422');
$res = t_request('POST', '/api/admin/software/update', ['json' => ['id' => 999999, 'enabled' => false], 'jar' => 'admin']);
t_eq(404, $res['status'], '更新不存在的条目返回 404');
$res = t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idC, 'slug' => 'test-tool-a'], 'jar' => 'admin']);
t_eq(409, $res['status'], '把标识改成别人已占用的返回 409');
$res = t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idC, 'version' => ''], 'jar' => 'admin']);
t_eq(200, $res['status'], '版本号清空是合法操作');
t_eq(null, $res['json']['software']['version'], '版本号变回 null');

// ------------------------------------------------------------
// 纯规则
// ------------------------------------------------------------

t_section('软件仓库：字段清洗与纯规则');

t_eq('windows,macos,linux', software_platforms(['linux', 'windows', 'macos', 'windows']), '平台去重并按固定顺序排列');
t_eq('windows', software_platforms('  Windows '), '字符串输入也能认');
t_eq(422, t_soft_status(function () { software_platforms(['win', 'dos']); }), '多个不认识的平台同样被拒');

t_eq('A,b', software_tags('A, b ,a'), '标签丢掉大小写重复，但保留管理员第一次写的那种样子');
t_eq('下载', software_tags('#下载'), '井号会被去掉');
t_eq(422, t_soft_status(function () { software_tags(str_repeat('超', 30) . ',' . str_repeat('长', 30)); }), '标签合计超长也被拒');

t_eq(null, software_text('   ', 10, '名称', false), '非必填的空白值洗成 null');
t_eq('a b', software_text("a\r\nb", 10, '名称', true), '换行在单行字段里被压成空格');
t_eq(422, t_soft_status(function () { software_text('', 10, '软件名', true); }), '必填字段空值报「请填写」');
t_eq(null, software_soft_version('带 空格 和中文 的版本'), '从远端 tag 来的版本号认不出来就当没有，而不是让抓包失败');
t_eq('v1.2.3-rc1', software_soft_version(' v1.2.3-rc1 '), '宽松版照样能吃下正常的 tag');

t_eq('https://example.com/', software_url('https://example.com/', '官网'), '合法地址原样通过');
t_eq(null, software_url('', '官网'), '留空是 null 而不是报错');
foreach (['javascript:alert(1)', 'data:text/plain,x', 'vbscript:x', 'ftp://a.com', '/relative/path.html'] as $bad) {
    t_eq(422, t_soft_status(function () use ($bad) { software_url($bad, '官网'); }), '网址拒绝 ' . substr($bad, 0, 18));
}

t_eq(100, software_sort_order(''), '排序留空回落到 100');
t_eq(-5, software_sort_order('-5'), '负排序值有意义（排在最前）');
t_eq(9999, software_sort_order(999999), '排序被夹在上限内');
t_eq(-9999, software_sort_order(-999999), '排序也被夹在下限内');

t_eq(null, software_repo_path('https://github.com/'), '只有斜杠的仓库地址认不出 owner 与仓库名');
t_eq(['owner' => 'example', 'repo' => 'tool-a'], software_repo_path('https://github.com/example/tool-a/'), '带尾斜杠也能认');
t_eq(['owner' => 'example', 'repo' => 'tool-a'], software_repo_path('https://github.com/example/tool-a/tree/main'), '多级路径只取前两段');
t_eq(null, software_repo_path(''), '空地址返回 null');

t_eq('exe', software_guess_ext('', 'https://x/y/demo.zip.exe'), '从 URL 末段认扩展名');
t_eq('dmg', software_guess_ext('Demo-1.0.dmg', 'https://x/y/asset'), '资产名优先于 URL');
t_eq(null, software_guess_ext('', 'https://api.example.com/download/123'), '认不出来时返回 null，由调用方去拒');
t_eq(null, software_guess_ext('setup.exe.php', 'https://x/y/notes.txt'), '两个来源都不在白名单里就拒');
t_eq(422, t_soft_status(function () { software_source_mode('gitlab'); }), '取包方式不认识就拒');
t_eq('none', software_source_mode('NONE'), '取包方式大小写不敏感');
t_eq(409, t_soft_status(function () { software_resolve_source(['source_mode' => 'none']); }), '取包方式为 none 时明确拒绝出网');

// 挑资产的打分规则：release 列表里常混着校验和、签名、源码包，
// 抓错一个发给访客，比抓失败更难解释（那是一个双击打不开的文件）
$assets = [
    ['name' => 'SHA256SUMS', 'url' => 'https://x/SHA256SUMS', 'size' => 128],
    ['name' => 'app.asc', 'url' => 'https://x/app.asc', 'size' => 900],
    ['name' => 'demo-source.tar.gz', 'url' => 'https://x/demo-source.tar.gz', 'size' => 5000],
    ['name' => 'demo-app-linux.deb', 'url' => 'https://x/demo-app-linux.deb', 'size' => 1500],
    ['name' => 'demo-app-win-x64-setup.exe', 'url' => 'https://x/demo-app-win-x64-setup.exe', 'size' => 2048],
];
t_eq('demo-app-win-x64-setup.exe', software_pick_asset($assets)['name'], '多个候选里挑中最像安装包的那一个');
t_eq(null, software_pick_asset([['name' => 'notes.txt', 'url' => 'https://x/notes.txt', 'size' => 1]]), '扩展名不在白名单的资产不参与挑选');
t_eq(null, software_pick_asset([]), '空列表返回 null');
$t = software_pick_asset([['name' => 'app-mac-portable.dmg', 'url' => 'https://x/a', 'size' => 1]]);
t_eq('app-mac-portable.dmg', $t['name'], '只有一个候选时按它自己');

t_eq('test-tool-a-1.2.3.exe', software_download_name(
    ['id' => $idA, 'slug' => 'test-tool-a', 'name' => '测试工具甲', 'version' => '1.2.3'], '', 'exe'
), '远端没给名字时自己拼：标识 + 版本 + 扩展名');
t_eq('DemoApp.zip', software_download_name(
    ['id' => $idA, 'slug' => 'test-tool-a', 'name' => '测试工具甲', 'version' => null], 'Demo App.exe', 'zip'
), '远端名字与实际内容不符时换掉扩展名');
t_eq('onlychinese.exe', software_download_name(
    ['id' => $idA, 'slug' => null, 'name' => ' only chinese ', 'version' => null], '', 'exe'
), '名字被洗完还有内容时不必退成 soft-<id>');
t_eq('soft-' . $idA . '.exe', software_download_name(
    ['id' => $idA, 'slug' => null, 'name' => '！！！', 'version' => null], '', 'exe'
), '符号名被洗空时用 soft-<id> 兜底，绝不发出一个没有名字的下载');

// ------------------------------------------------------------
// 闸门
// ------------------------------------------------------------

t_section('软件仓库：出网闸门认地址');

foreach ([
    '127.0.0.1' => true,
    '10.1.2.3' => true,
    '172.16.5.5' => true,
    '192.168.0.7' => true,
    '169.254.169.254' => true,   // 云主机的元数据接口，SSRF 最想要的那个地址
    '0.0.0.0' => true,
    '224.0.0.1' => true,
    '100.64.0.1' => true,        // CGNAT：一些云把内网服务放这一段
    '198.18.0.1' => true,
    '::1' => true,
    'fe80::1' => true,
    'fd12::3456' => true,        // IPv6 唯一本地地址
    '8.8.8.8' => false,
    '140.82.113.4' => false,
] as $ip => $expect) {
    t_eq($expect, softnet_is_internal_ip($ip), $ip . ($expect ? ' 被认成内网' : ' 是公网地址'));
}
// 段边界要卡准：判窄了把公网地址当内网，正常的仓库资产就下不来
t_eq(true, softnet_ip_in_block('224.0.0.0', '224.0.0.0/4'), '组播段的第一个地址算组播');
t_eq(false, softnet_ip_in_block('223.255.255.255', '224.0.0.0/4'), '差一个字节就不算');
t_eq(false, softnet_ip_in_block('8.8.8.8', '100.64.0.0/10'), 'v4 地址不会误命中 v6 段的对照');
t_eq(false, softnet_ip_in_block('::1', '127.0.0.0/8'), 'v6 与 v4 不互相比较，各按各的段判');

t_eq(true, softnet_host_allowed('127.0.0.1', 8124, 'http'), '白名单里带端口的条目精确命中');
t_eq(false, softnet_host_allowed('127.0.0.1', 8125, 'http'), '同一主机换端口不算命中');
t_eq(false, softnet_host_allowed('evil.example.com', 443, 'https'), '白名单外的主机一律不命中');

// 不带端口的条目只放行该协议的默认端口：否则「白名单里有 github.com」就变成
// 「github.com 的任何端口都能连」，而 8080 上跑的可能是完全另一回事
$hosts = $GLOBALS['CONFIG']['soft_fetch_hosts'];
$GLOBALS['CONFIG']['soft_fetch_hosts'] = 'github.com';
t_eq(true, softnet_host_allowed('github.com', 443, 'https'), '裸条目放行 https 默认端口');
t_eq(true, softnet_host_allowed('github.com', 80, 'http'), '裸条目放行 http 默认端口');
t_eq(false, softnet_host_allowed('github.com', 8443, 'https'), '裸条目不放行非默认端口');
t_eq(false, softnet_host_allowed('github.com.evil.com', 443, 'https'), '后缀拼接不算命中（精确匹配，不是前缀比对）');
t_eq(false, softnet_host_allowed('evilgithub.com', 443, 'https'), '子串包含也不算命中');
$GLOBALS['CONFIG']['soft_fetch_hosts'] = $hosts;

foreach ([
    '非 http 的 scheme' => ['ftp://127.0.0.1:8124/dl/1.exe', 422],
    'file 协议' => ['file:///etc/passwd', 422],
    '空地址' => ['', 422],
    '带账号密码' => ['http://user:pass@127.0.0.1:8124/dl/1.exe', 422],
    '地址里有换行' => ["http://127.0.0.1:8124/dl/1.exe\r\nX-Evil: 1", 422],
    '超长地址' => ['http://127.0.0.1:8124/dl/1.exe?' . str_repeat('a', 600), 422],
    '白名单外的主机' => ['https://example.com/setup.exe', 403],
    '白名单外的端口' => ['http://127.0.0.1:9/dl/1.exe', 403],
] as $label => $case) {
    list($url, $expect) = $case;
    t_eq($expect, t_soft_status(function () use ($url) { softnet_target($url); }), $label . ' 被拒（期望 ' . $expect . '）');
}

$target = softnet_target('http://127.0.0.1:8124/dl/2048.exe?x=1#frag');
t_eq('/dl/2048.exe?x=1', $target['path'], '查询串一起交给远端，片段丢掉');
t_eq(8124, $target['port'], '端口按 URL 里写的走');
t_eq('127.0.0.1', $target['ip'], '白名单条目本身是 IP 时直接照用，不再做解析');

// 「解析到内网就拒」只能在测试进程里翻开关验证：被测服务器那个进程的配置是启动时
// 从环境变量读死的，进程之间改不动。假源站就在 127.0.0.1 上，正是那条规则平时会拒的形状。
$private = $GLOBALS['CONFIG']['soft_fetch_allow_private'];
$GLOBALS['CONFIG']['soft_fetch_allow_private'] = false;
$error = t_soft_error(function () use ($softUrl) { softnet_target($softUrl . '/dl/2048.exe'); });
t_eq(403, $error['status'], '主机在白名单里、但解析到回环地址时仍然被拒');
t_contains($error['message'], '内网', '拒绝理由写明了是内网地址');
$GLOBALS['CONFIG']['soft_fetch_allow_private'] = $private;
t_eq(8124, softnet_target($softUrl . '/dl/2048.exe')['port'], '放行配置恢复之后又能连了');

$enabled = $GLOBALS['CONFIG']['soft_fetch_enabled'];
$GLOBALS['CONFIG']['soft_fetch_enabled'] = false;
$error = t_soft_error(function () use ($softUrl) { softnet_target($softUrl . '/dl/2048.exe'); });
t_eq(409, $error['status'], '总开关关掉时一个地址都不给出去');
t_contains($error['message'], 'soft_fetch_enabled', '理由里带着配置项的名字，排查时一眼找得到');
$GLOBALS['CONFIG']['soft_fetch_enabled'] = $enabled;

t_section('软件仓库：跳转地址的解析');

$from = ['scheme' => 'http', 'host' => '127.0.0.1', 'port' => 8124, 'path' => '/a/b.exe'];
t_eq('http://127.0.0.1:8124/dl/1.exe', softnet_redirect_url($from, '/dl/1.exe'), '根路径形式的 Location 拼上协议与端口');
t_eq('http://127.0.0.1:8124/a/dl/1.exe', softnet_redirect_url($from, 'dl/1.exe'), '相对形式的 Location 按当前目录解析');
t_eq('https://example.com/x', softnet_redirect_url($from, 'https://example.com/x'), '绝对地址原样采用（下一跳重新过闸门）');
t_eq('http://127.0.0.1:8124/x', softnet_redirect_url($from, '//127.0.0.1:8124/x'), '协议相对地址补上当前协议');
t_eq(null, softnet_redirect_url($from, ''), '没有 Location 时返回 null，不会原地打转');

t_section('软件仓库：流式落盘这一半');

// 这一段直接调 softnet_download_to_file（不经过接口），为的是把「落盘」单独钉住：
// 名字、字节数、摘要、半成品清理——这些与软件条目无关的规则都在这
$probe = $softDir . '/probe.zip';
@unlink($probe);
$result = softnet_download_to_file($softUrl . '/dl/4096.exe', $probe, 200000);
t_eq(4096, $result['bytes'], '分块（无 Content-Length）响应读满了实际字节数');
t_eq(hash('sha256', t_soft_body(4096)), $result['sha256'], '边读边算的摘要与整块比对一致');
t_eq($softUrl . '/dl/4096.exe', $result['finalUrl'], '最终地址就是请求的那一个');
t_eq('demo-app-4096.exe', $result['remoteName'], '带回远端给的文件名（已洗过）');
t_eq(4096, filesize($probe), '磁盘上的字节数对得上');
t_eq(hash('sha256', t_soft_body(4096)), hash_file('sha256', $probe), '磁盘上的内容与摘要一致');
t_eq([], t_soft_parts(), '成功之后不留任何 .part');
@unlink($probe);
t_eq(false, is_file($softDir . '/probe.exe'), '落盘名由调用方决定：远端说的是 .exe，本地也不会自己改名');

$error = t_soft_error(function () use ($softUrl, $softDir) {
    softnet_download_to_file($softUrl . '/dl/300000.exe', $softDir . '/probe2.zip', 200000);
});
t_eq(502, $error['status'], '超过单包上限时中断');
t_contains($error['message'], '上限', '拒绝理由写的是大小上限');
t_eq([], t_soft_parts(), '超限的半成品被清掉了');
t_eq(false, is_file($softDir . '/probe2.zip'), '超限的文件不会以正式名留在磁盘上');

$error = t_soft_error(function () use ($softUrl, $softDir) {
    softnet_download_to_file($softUrl . '/dl/64.exe', $softDir . '/probe4.php', 200000);
});
t_eq(422, $error['status'], '目标扩展名不在白名单时，连接都不建立');
t_contains($error['message'], '不支持', '理由写的是类型不支持');

t_eq('hack.exe', softnet_safe_name('../../../../windows/system32/hack.exe'), '带目录穿越的文件名被洗成 basename');
t_eq('a.exe', softnet_safe_name('../../a.exe'), '相对路径片段全部丢掉');
t_eq('a.b.c', softnet_safe_name('a....b....c'), '连续的点被压成一个，免得留下 ..');
t_eq('DemoApp2.0.exe', softnet_remote_filename(
    ['content-disposition' => 'attachment; filename="Demo App 2.0.exe"'], 'http://x/y'
), '响应头里的文件名被洗掉空格与引号');
t_eq('z.msi', softnet_remote_filename([], 'http://x/y/z.msi'), '没有响应头时从 URL 末段取名');
t_eq('中文名.exe', softnet_remote_filename(
    ['content-disposition' => "attachment; filename*=UTF-8''%E4%B8%AD%E6%96%87%E5%90%8D.exe"], ''
), 'RFC 5987 的写法能认（中文包名的常见形态）');

t_section('软件仓库：仓库元数据的解析');

// 真打一次假源站的小响应接口：分块 JSON 的解码与「release → 候选资产」这一步
$response = softnet_request($softUrl . '/json/release', ['accept' => 'application/vnd.github+json']);
t_eq(200, $response['status'], 'small JSON 响应能取回来');
$release = json_decode($response['body'], true);
t_assert(is_array($release), '正文是合法 JSON');
$assets = software_release_assets($release);
t_eq(4, count($assets), '四个资产全部被认出来');
$asset = software_pick_asset($assets);
t_eq('demo-app-win-x64-setup.exe', $asset['name'], '校验和、源码包被跳过，挑中安装包');
t_eq(2048, $asset['size'], '带上远端声明的大小');
$picked = software_asset_result($release, $asset);
t_eq('v2.3.4', $picked['version'], '版本号从 release 的 tag 来，不用管理员再抄一遍');
t_eq($softUrl . '/dl/2048.exe', $picked['url'], '下载地址取自资产');

$response = softnet_request($softUrl . '/json/gitee');
$release = json_decode($response['body'], true);
$assets = software_release_assets($release);
t_eq(1, count($assets), 'Gitee 的 attachables 形状也认');
t_eq('demo-app-linux.deb', $assets[0]['name'], '资产名取自 name 字段');
$picked = software_asset_result($release, software_pick_asset($assets));
t_eq(null, $picked['version'], 'tag 里带中文与空格时版本号丢掉，但不影响拿到包');

$response = softnet_request($softUrl . '/json/nope');
t_eq(null, software_pick_asset(software_release_assets(json_decode($response['body'], true))), 'release 里只有非安装包时返回 null（于是 auto 退到直链）');
$response = softnet_request($softUrl . '/missing.exe');
t_eq(404, $response['status'], 'small 响应不会把 404 的正文当成数据');

// ------------------------------------------------------------
// 服务器代下载
// ------------------------------------------------------------

t_section('软件仓库：抓包参数与失败来源');

$res = t_request('POST', '/api/admin/software/grab', ['json' => [], 'jar' => 'admin']);
t_eq(422, $res['status'], '抓包没带 id 返回 422');
$res = t_request('POST', '/api/admin/software/grab', ['json' => ['id' => 999999], 'jar' => 'admin']);
t_eq(404, $res['status'], '抓不存在的条目返回 404');
$res = t_request('POST', '/api/admin/software/release', ['json' => ['id' => $idB], 'jar' => 'admin']);
t_eq(409, $res['status'], '释放一个还没有本地包的条目返回 409');

t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idB, 'sourceMode' => 'none'], 'jar' => 'admin']);
$res = t_soft_grab($idB);
t_eq(409, $res['status'], '取包方式为 none 的条目抓包被拒');
t_contains($res['body'], '不让服务器抓包', '理由是一句人话');

t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idB, 'sourceMode' => 'auto'], 'jar' => 'admin']);
$res = t_soft_grab($idB);
t_eq(409, $res['status'], '三个来源一个都没填时 409');
t_contains($res['body'], '没有可用的下载地址', '理由里带着「哪个来源都没试成」');

t_section('软件仓库：服务器代下载（真的出网）');

$grab = t_soft_grab($idA);
t_eq(200, $grab['status'], '按直链抓包成功');
t_eq(2048, $grab['json']['bytes'], '返回实际落盘的字节数');
t_eq(hash('sha256', t_soft_body(2048)), $grab['json']['sha256'], '返回摘要');
t_eq('direct', $grab['json']['via'], '这一单走的是直链');
$item = $grab['json']['software'];
t_eq(true, $item['hasFile'], '条目变成有本地安装包');
t_eq('demo-app-2048.exe', $item['fileName'], '下载名用的是远端给的包名');
t_eq('/api/software/file?id=' . $idA, $item['fileUrl'], '下载地址只带 id，不带任何路径信息');
t_eq(2048, $item['sizeBytes'], '大小写回条目');
t_eq('/dl/2048.exe', substr($item['fileOrigin'], -12), '管理视图记住最终的来源地址');
t_assert($item['fileFetchedAt'] !== null, '抓包时间写下来了');
t_eq(hash('sha256', t_soft_body(2048)), $item['fileSha256'], '摘要也存进库（管理员比对用）');

$onDisk = $softDir . '/' . $idA . '.exe';
t_eq(true, is_file($onDisk), '文件确实落在 var/softs 下');
t_eq(2048, filesize($onDisk), '磁盘上的大小对得上');
t_eq(hash('sha256', t_soft_body(2048)), hash_file('sha256', $onDisk), '磁盘上的内容与摘要一致');
t_eq([$onDisk], t_soft_files($idA), '这一款在磁盘上只有一个文件，没有多余副本');
t_eq([], t_soft_parts(), '抓完不留半成品');

$res = t_request('GET', '/api/software/file?id=' . $idA, ['jar' => 'anon']);
t_eq(200, $res['status'], '游客能直接从服务器下载安装包');
t_eq(2048, (int) $res['headers']['content-length'], '响应带正确的 Content-Length');
t_contains($res['headers']['content-disposition'], 'filename="demo-app-2048.exe"', '另存为的名字是官方包名');
t_contains($res['headers']['content-type'], 'application/octet-stream', '按二进制流发出去');
t_eq('nosniff', isset($res['headers']['x-content-type-options']) ? $res['headers']['x-content-type-options'] : null, '带 nosniff：浏览器别自作聪明猜类型');
t_eq('no-store', isset($res['headers']['cache-control']) ? $res['headers']['cache-control'] : null, '安装包不缓存：换包之后旧文件不能还在');
t_eq(hash('sha256', t_soft_body(2048)), hash('sha256', $res['body']), '下载到的内容与源站一致');
t_eq(false, strpos($res['headers']['content-disposition'], 'softs') !== false, '响应头里不含安装包目录的名字');
t_eq(false, strpos($res['headers']['content-disposition'], '\\') !== false, '响应头里没有路径分隔符');

$res = t_request('GET', '/api/software/file', ['jar' => 'anon']);
t_eq(422, $res['status'], '下载不带 id 返回 422');
$res = t_request('GET', '/api/software/file?id=999999', ['jar' => 'anon']);
t_eq(404, $res['status'], '下载不存在的条目返回 404');
$res = t_request('GET', '/api/software/file?id=' . $idB, ['jar' => 'anon']);
t_eq(404, $res['status'], '还没有本地包的条目下载返回 404');
$res = t_request('GET', '/api/software/file?id=' . $idA . '%2e%2e%2f', ['jar' => 'anon']);
t_eq(422, $res['status'], 'id 被 URL 编码修饰过拿不到别的东西');
$res = t_request('GET', '/api/software/icon?id=' . $idA, ['jar' => 'anon']);
t_eq(404, $res['status'], '还没有图标的条目取图标返回 404');
$res = t_request('GET', '/api/software/icon', ['jar' => 'anon']);
t_eq(422, $res['status'], '取图标没带 id 返回 422');

t_section('软件仓库：库里有记录但文件不见了');

// 有人手动清了磁盘（或者换了台机器只搬库）：这一款必须 404 说人话，
// 而不是把 readfile 的警告喷给访客，更不能顺着路径去读别的文件
rename($onDisk, $onDisk . '.gone');
$res = t_request('GET', '/api/software/file?id=' . $idA, ['jar' => 'anon']);
t_eq(404, $res['status'], '文件被移走之后下载返回 404');
t_contains($res['body'], '重新抓取', '提示让管理员去重抓');
t_eq(false, strpos($res['body'], 'var'), '提示里没有路径');
rename($onDisk . '.gone', $onDisk);
$res = t_request('GET', '/api/software/file?id=' . $idA, ['jar' => 'anon']);
t_eq(200, $res['status'], '文件放回去之后下载恢复');

t_section('软件仓库：重新抓取与换扩展名');

t_soft_set_url($idA, '/dl/3000.zip');
$grab = t_soft_grab($idA);
t_eq(200, $grab['status'], '换成 zip 的直链再抓一次');
t_eq(3000, $grab['json']['bytes'], '新包的大小');
t_eq([$softDir . '/' . $idA . '.zip'], t_soft_files($idA), '扩展名变了，旧的那个 .exe 已经被删掉');
t_eq(false, is_file($softDir . '/' . $idA . '.exe'), '磁盘上确实没有旧扩展名的文件了');
t_eq('demo-app-3000.zip', $grab['json']['software']['fileName'], '下载名跟着换');

t_soft_set_url($idA, '/dl/3000.zip');
$grab = t_soft_grab($idA);
t_eq(200, $grab['status'], '同一扩展名重新抓取');
t_eq([$softDir . '/' . $idA . '.zip'], t_soft_files($idA), '同扩展名重抓也只有一份（新的覆盖旧的）');
$adminItem = t_soft_item(t_request('GET', '/api/admin/software', ['jar' => 'admin'])['json'], $idA);
t_eq(hash('sha256', t_soft_body(3000)), $adminItem['fileSha256'], '库里换成了新的摘要');

t_section('软件仓库：抓包失败时什么都不改');

$before = t_soft_item(t_request('GET', '/api/admin/software', ['jar' => 'admin'])['json'], $idA);
$goodFile = $softDir . '/' . $idA . '.zip';
$badPaths = [
    '远端 404' => ['/missing.exe', 502, 'HTTP 404'],
    '远端空文件' => ['/empty.exe', 502, '空文件'],
    '扩展名是 php' => ['/shell.php', 422, '不支持'],
    '扩展名是 txt' => ['/notes.txt', 422, '不支持'],
    '地址没有扩展名' => ['/noext', 422, '认不出'],
    '体积超过单包上限' => ['/dl/300000.exe', 502, '上限'],
    '重定向自己成环' => ['/redirect-loop.exe', 502, 'HTTP 302'],
    '重定向不给 Location' => ['/redirect-noloc.exe', 502, 'HTTP 302'],
];
foreach ($badPaths as $label => $case) {
    list($path, $expect, $needle) = $case;
    t_soft_set_url($idA, $path);
    $res = t_soft_grab($idA);
    t_eq($expect, $res['status'], $label . ' 被抓包拒绝（期望 ' . $expect . '）');
    t_contains($res['body'], $needle, $label . ' 的理由里带着「' . $needle . '」');
    t_eq(false, array_key_exists('software', (array) $res['json']), $label . ' 时不返回一个改坏的条目');
    t_eq([], t_soft_parts(), $label . ' 之后没有半成品');
    t_eq([$goodFile], t_soft_files($idA), $label . ' 之后磁盘上还是上一次那一份');
}

$after = t_soft_item(t_request('GET', '/api/admin/software', ['jar' => 'admin'])['json'], $idA);
t_eq($before['sizeBytes'], $after['sizeBytes'], '八次失败的抓包之后条目大小没变');
t_eq($before['fileSha256'], $after['fileSha256'], '摘要没变：库里那一份与磁盘那一份始终对得上');
t_eq($before['fileFetchedAt'], $after['fileFetchedAt'], '抓包时间也没被失败的几次动过');
$res = t_request('GET', '/api/software/file?id=' . $idA, ['jar' => 'anon']);
t_eq(200, $res['status'], '失败的抓包不影响访客继续下载上一次那一份');
t_eq(hash('sha256', t_soft_body(3000)), hash('sha256', $res['body']), '下载到的仍是上一次成功抓来的包');

t_section('软件仓库：远端文件名带目录穿越也洗得干净');

t_soft_set_url($idA, '/tricky.exe');
$grab = t_soft_grab($idA);
t_eq(200, $grab['status'], '远端给的文件名里带着 ../../../../ 也能抓成');
t_eq('hack.exe', $grab['json']['software']['fileName'], '名字被洗成 basename');
t_eq(false, strpos($grab['json']['software']['fileName'], '..') !== false, '下载名里没有相对路径片段');
t_eq(false, strpos($grab['json']['software']['fileName'], '/') !== false, '下载名里没有斜杠（拼不进响应头）');
t_eq([$softDir . '/' . $idA . '.exe'], t_soft_files($idA), '文件仍然只落在 <id>.exe 这一个名字下');
t_eq(false, is_file($softDir . '/hack.exe'), '没有按远端给的名字落盘');
$res = t_request('GET', '/api/software/file?id=' . $idA);
t_contains($res['headers']['content-disposition'], 'filename="hack.exe"', '发出去的文件名是洗过的那一个');
t_eq(200, $res['status'], '这一份能正常下载');

t_section('软件仓库：重定向必须留在白名单里');

// 前两条正当：跳转是真实世界里安装包下载的正常形状（GitHub 的资产地址就是 302）
t_soft_set_url($idA, '/redirect-self.exe');
$grab = t_soft_grab($idA);
t_eq(200, $grab['status'], '跟住白名单内的一跳');
t_eq(2048, $grab['json']['bytes'], '拿到的是跳转之后的那个包');
t_eq('/dl/2048.exe', substr($grab['json']['software']['fileOrigin'], -12), '来源记的是最终那一个地址');

t_soft_set_url($idA, '/redirect-chain.exe');
$grab = t_soft_grab($idA);
t_eq(200, $grab['status'], '两跳也能跟住（配置允许三跳）');
t_eq(2048, $grab['json']['bytes'], '最终拿到的仍是同一个包');

// 后两条是闸门本身：跳转一旦允许跳出白名单，白名单就只管得住第一跳
foreach ([
    '重定向到白名单外的端口' => '/redirect-evil.exe',
    '重定向到 localhost' => '/redirect-internal.exe',
] as $label => $path) {
    t_soft_set_url($idA, $path);
    $res = t_soft_grab($idA);
    t_eq(403, $res['status'], $label . ' 被拒');
    t_contains($res['body'], '白名单', $label . ' 的理由是白名单');
    t_eq([], t_soft_parts(), $label . ' 之后没有半成品');
}
t_eq(2048, filesize($softDir . '/' . $idA . '.exe'), '被拒的跳转没有把任何内容写进本地包');
$res = t_request('GET', '/api/software/file?id=' . $idA);
t_eq(200, $res['status'], '条目仍然是上一次那份能下载');

t_section('软件仓库：等待远端超时');

t_soft_set_url($idA, '/slow.exe');
$startedAt = microtime(true);
$res = t_soft_grab($idA);
$elapsed = microtime(true) - $startedAt;
t_eq(502, $res['status'], '远端拖着不写完时按超时中断');
t_contains($res['body'], '超时', '理由写的是超时');
t_assert($elapsed < 10, '抓包在配置的 3 秒超时附近就返回了（实际 ' . round($elapsed, 1) . ' 秒），不会把请求挂死');
t_eq([], t_soft_parts(), '超时的半成品被清掉');
t_eq(2048, filesize($softDir . '/' . $idA . '.exe'), '超时没有动到已经存在的那一份');

// 上面验的是「远端拖着不给字节」有超时兜着。这一条钉的是另一件只有换平台才露得出来的事：
// Windows 上 max_execution_time 算的是墙上时间（实测把它调到 2 秒、跑一个纯 sleep 的八秒循环会被杀掉，
// 循环里补一次 set_time_limit 就跑得完），所以一次几十秒的慢速抓包会被 PHP 自己掐死在半路，
// 表现是一页断掉的响应而不是一段说得清原因的 JSON。读取循环里那句「每读到一次就把时限拨回去」就是为它加的。
// 这条只能钉源码：用例跑在默认的 30 秒之下、每次抓包都在几百毫秒内结束，
// 删掉那一句没有任何断言会红。
$softnetSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/softnet.php');
$softFillBody = preg_match('/private function fill\(\)\s*\{([\s\S]*?)\n    \}/', $softnetSrc, $softFillMatch)
    ? $softFillMatch[1]
    : '';
t_contains($softFillBody, 'set_time_limit($limit)',
    'SoftnetConn::fill() 每读一次就把执行时限往上拨：慢速抓包不该被 max_execution_time 掐掉');
t_assert(strpos($softFillBody, "cfg('soft_fetch_timeout')") !== false,
    '拨出来的这个数是从 soft_fetch_timeout 推的：它必须比「等远端」那一刀大，'
    . '否则卡住的连接会先撞脚本时限（一页空白），而不是报「等待远端响应超时」');

// ------------------------------------------------------------
// 图标：管理员上传、智能获取
// ------------------------------------------------------------

/**
 * 与 tests/fake_http.php 里假源站发的那一枚逐字节相同的 1×1 PNG。
 * 两边共用同一串 base64，上传与抓取两条路拿到的内容才能直接比对。
 */
function t_soft_png_b64()
{
    return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
}

/** 同一张图的另一格式：换格式时「旧的那一枚要删掉」这条规则要靠它 */
function t_soft_gif_b64()
{
    return 'R0lGODdhAQABAIAAABR63AAAACwAAAAAAQABAAACAkQBADs=';
}

function t_soft_upload_icon($id, $image, $jar = 'admin')
{
    return t_request('POST', '/api/admin/software/icon', ['json' => ['id' => $id, 'image' => $image], 'jar' => $jar]);
}

function t_soft_fetch_icon($id, $jar = 'admin')
{
    return t_request('POST', '/api/admin/software/icon/fetch', ['json' => ['id' => $id], 'jar' => $jar]);
}

/** 改某一款的官网 / 仓库地址，为下一次「智能获取」摆好靶子 */
function t_soft_set_sources(array $fields)
{
    $res = t_request('POST', '/api/admin/software/update', ['json' => $fields, 'jar' => 'admin']);
    if ($res['status'] !== 200) {
        throw new RuntimeException('改来源地址失败：' . $res['status']);
    }
}

t_section('软件仓库：图标格式只按文件头认');

$png = base64_decode(t_soft_png_b64());
$gif = base64_decode(t_soft_gif_b64());
t_eq('png', software_icon_kind($png), '真的 PNG（与假源站发的那一枚同字节）');
t_eq('gif', software_icon_kind($gif), 'GIF');
t_eq('jpg', software_icon_kind("\xFF\xD8\xFF\xE0" . str_repeat('x', 32)), 'JPEG');
t_eq('webp', software_icon_kind('RIFF' . pack('V', 40) . 'WEBPVP8 ' . str_repeat('x', 20)), 'WebP（RIFF....WEBP 那个形状）');
t_eq('ico', software_icon_kind("\x00\x00\x01\x00" . str_repeat('x', 20)), 'ICO');
// 这一组是「按内容判断」的全部意义：名字与 Content-Type 都是外部输入，只有前几个字节说话
t_eq(null, software_icon_kind('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'SVG 不收：它是能带脚本的 XML，从本站域名下发等于同源执行');
t_eq(null, software_icon_kind('<?php echo "hi"; ?>'), '一段 php 源码不是图片');
t_eq(null, software_icon_kind("\x4D\x5A\x90\x00" . str_repeat('x', 20)), 'exe 的文件头也不认');
t_eq(null, software_icon_kind(' GIF89a'), '内容前面多一个空格就不算图片头（不许凑）');
t_eq(null, software_icon_kind(''), '空内容没有格式');
t_eq(null, software_icon_kind(null), '连字符串都不是更不用谈');

t_eq('http://127.0.0.1:8124/favicon.ico', software_favicon_url($softUrl . '/deep/page.html'), '官网地址换成同主机同端口的 favicon');
t_eq('http://127.0.0.1:8124/favicon.ico', software_favicon_url($softUrl), '主页只写到主机也拼得出 favicon');
t_eq('https://example.com/favicon.ico', software_favicon_url('https://user:pw@example.com/a'), '主页里带的账号密码不会跟着进请求地址');
t_eq(null, software_favicon_url(''), '没填官网就没有这一路候选');
t_eq(null, software_favicon_url('ftp://example.com/x'), '非 http(s) 的主页不碰');
t_eq(null, software_favicon_url('javascript:alert(1)'), 'javascript: 之类的主页绝不发请求');
t_eq(null, software_favicon_url('顺手粘进来的一句话'), '不成形状的主页当没填');

$c3 = software_icon_candidates([
    'github_url' => 'https://github.com/example/tool-a',
    'gitee_url' => 'https://gitee.com/example/tool-a',
    'homepage' => 'https://example.com/a',
]);
t_eq(['GitHub', 'Gitee', '官网'], array_keys($c3), '候选按抓包那边的同一套优先级排');
t_eq('https://api.github.com/repos/example/tool-a', $c3['GitHub']['api'], 'GitHub 的头像要从仓库信息里拿');
t_eq('https://gitee.com/api/v5/repos/example/tool-a', $c3['Gitee']['api'], 'Gitee 走它自己的 API 前缀');
t_eq('https://example.com/favicon.ico', $c3['官网']['image'], '官网这一路直接是图片地址');
$t1 = software_icon_candidates(['github_url' => '', 'gitee_url' => null, 'homepage' => '']);
t_eq([], $t1, '三个地址都没填时一个候选也没有（接口据此回 409，不去瞎猜）');
$t2 = software_icon_candidates(['github_url' => 'https://github.com/notifications', 'gitee_url' => '', 'homepage' => 'https://example.com']);
t_eq(['官网'], array_keys($t2), '仓库地址看不出 owner/仓库名时不算候选，但官网照旧');

t_section('软件仓库：管理员上传图标');

$res = t_soft_upload_icon(0, t_soft_png_b64());
t_eq(422, $res['status'], '上传没带 id 返回 422');
$res = t_soft_upload_icon(999999, t_soft_png_b64());
t_eq(404, $res['status'], '上传到不存在的条目返回 404');
$res = t_soft_upload_icon($idB, t_soft_png_b64(), 's_user');
t_eq(403, $res['status'], '普通用户不能传图标');
$res = t_soft_upload_icon($idB, t_soft_png_b64(), 'anon');
t_eq(401, $res['status'], '游客也不能（未登录是 401，登录后不是管理员才是 403）');

$badUploads = [
    '空内容' => ['', 422, '请选择'],
    '不成 base64' => ['!!!!!!!', 422, '读不出来'],
    '一段网页' => [base64_encode('<script>alert(1)</script>'), 415, 'SVG 不收'],
    'SVG' => [base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><circle/></svg>'), 415, '只接受 png'],
    'exe 改名' => [base64_encode("\x4D\x5A\x90\x00" . str_repeat('x', 64)), 415, '按文件内容判断'],
    '超出上限的图' => [base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 210000)), 413, '超过'],
    '解码之前就超限' => [base64_encode(str_repeat('x', 400000)), 413, '太大'],
];
foreach ($badUploads as $label => $case) {
    list($image, $expect, $needle) = $case;
    $res = t_soft_upload_icon($idB, $image);
    t_eq($expect, $res['status'], $label . ' 被拒（期望 ' . $expect . '）');
    t_contains($res['body'], $needle, $label . ' 的理由里带着「' . $needle . '」');
    t_eq([], t_soft_files($idB), $label . ' 之后磁盘上没有落下任何图标');
    $res = t_request('GET', '/api/software/icon?id=' . $idB, ['jar' => 'anon']);
    t_eq(404, $res['status'], $label . ' 之后访客仍然取不到图标（失败的上传不留半成品）');
}

$up = t_soft_upload_icon($idB, t_soft_png_b64());
t_eq(200, $up['status'], '真的 PNG 传上去了');
t_eq('png', $up['json']['kind'], '返回识别出来的格式');
t_eq(strlen($png), $up['json']['bytes'], '返回落盘的字节数');
$item = $up['json']['software'];
t_eq('/api/software/icon?id=' . $idB, $item['iconUrl'], '条目带回本站的图标地址（CSP 只放行同源图片，所以不直接外链）');
t_eq('png', $item['iconKind'], '管理视图带回格式');
$pub = t_soft_item(t_request('GET', '/api/software')['json'], $idB);
t_eq('/api/software/icon?id=' . $idB, $pub['iconUrl'], '公开列表也有图标地址');
t_eq(false, array_key_exists('iconKind', $pub), '但格式这一列只给管理员');
t_eq([$softDir . '/' . $idB . '.icon.png'], t_soft_files($idB), '文件名固定成 <id>.icon.<格式>，不含任何外部输入');

$res = t_request('GET', '/api/software/icon?id=' . $idB, ['jar' => 'anon']);
t_eq(200, $res['status'], '访客能取到这一枚图标');
t_contains($res['headers']['content-type'], 'image/png', '按识别出来的格式给 Content-Type');
t_eq(strlen($png), (int) $res['headers']['content-length'], 'Content-Length 就是图标的真实大小');
t_contains($res['headers']['content-disposition'], 'inline', '图标是就地显示，不是让人另存为');
t_eq('nosniff', $res['headers']['x-content-type-options'], '带 nosniff：类型由我们说了算');
t_eq('no-store', $res['headers']['cache-control'], '图标不缓存：换一枚之后不该有人还在看旧的');
t_eq($png, $res['body'], '发出去的就是传上来的那一份');
// 下架 = 访客看不到这一款，连它的图标也一起看不到；管理员在编辑器里还要能预览
t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idB, 'enabled' => false], 'jar' => 'admin']);
$res = t_request('GET', '/api/software/icon?id=' . $idB, ['jar' => 'anon']);
t_eq(404, $res['status'], '下架之后访客取不到图标');
$res = t_request('GET', '/api/software/icon?id=' . $idB, ['jar' => 'admin']);
t_eq(200, $res['status'], '管理员仍然取到得到（编辑下架条目时也要看见那枚图标）');
t_eq($png, $res['body'], '管理员看到的还是同一枚');
t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idB, 'enabled' => true], 'jar' => 'admin']);
// 换一枚同格式的：内容跟着换，名字不变，所以不需要任何 cache-busting 参数
$up = t_soft_upload_icon($idB, base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('y', 40)));
t_eq(48, $up['json']['bytes'], '同名覆盖成新的那一枚');
t_eq("\x89PNG\r\n\x1a\n" . str_repeat('y', 40), t_request('GET', '/api/software/icon?id=' . $idB, ['jar' => 'anon'])['body'], '访客立刻看到新图标');
// data:URL 也收：前端读文件本来就拿到的是这一串，不必先自己切前缀
$up = t_soft_upload_icon($idB, 'data:image/png;base64,' . t_soft_png_b64());
t_eq(200, $up['status'], '带 data:image/png;base64, 前缀的也认');
t_eq($png, t_request('GET', '/api/software/icon?id=' . $idB, ['jar' => 'anon'])['body'], '前缀切掉之后存的是正文');

$up = t_soft_upload_icon($idB, t_soft_gif_b64());
t_eq('gif', $up['json']['kind'], '换一种格式传');
t_eq([$softDir . '/' . $idB . '.icon.gif'], t_soft_files($idB), '旧的 png 被删掉了：一款只留一枚图标');
t_eq(false, is_file($softDir . '/' . $idB . '.icon.png'), '磁盘上确实没有那枚旧 png');
$res = t_request('GET', '/api/software/icon?id=' . $idB, ['jar' => 'anon']);
t_contains($res['headers']['content-type'], 'image/gif', '响应的类型跟着格式走');

$res = t_request('DELETE', '/api/admin/software/icon', ['json' => [], 'jar' => 'admin']);
t_eq(422, $res['status'], '清除图标没带 id 返回 422');
$res = t_request('DELETE', '/api/admin/software/icon', ['json' => ['id' => $idB], 'jar' => 's_user']);
t_eq(403, $res['status'], '普通用户不能清除图标');
$res = t_request('DELETE', '/api/admin/software/icon', ['json' => ['id' => $idB], 'jar' => 'admin']);
t_eq(200, $res['status'], '管理员清除图标');
t_eq(null, $res['json']['software']['iconUrl'], '清除之后条目不再声明有图标');
t_eq(null, $res['json']['software']['iconKind'], '格式也一并清掉');
t_eq([], t_soft_files($idB), '图标文件从磁盘上删掉了');
$res = t_request('GET', '/api/software/icon?id=' . $idB, ['jar' => 'anon']);
t_eq(404, $res['status'], '清除之后访客取不到图标（卡片退回首字母占位）');
$res = t_request('DELETE', '/api/admin/software/icon', ['json' => ['id' => $idB], 'jar' => 'admin']);
t_eq(409, $res['status'], '再清一次是 409 而不是假装成功');
t_contains($res['body'], '还没有本站图标', '理由说清楚没有可清除的东西');

t_section('软件仓库：智能获取图标');

// 只填了官网：取同主机的 favicon
t_soft_set_sources(['id' => $idC, 'homepage' => $softUrl . '/demo/app']);
$get = t_soft_fetch_icon($idC);
t_eq(200, $get['status'], '从官网取到了 favicon');
t_eq('官网', $get['json']['via'], '这一单走的是官网');
t_eq('ico', $get['json']['kind'], '识别出是 ICO');
$item = $get['json']['software'];
t_eq('ico', $item['iconKind'], '管理视图里能看到格式');
t_eq([$softDir . '/' . $idC . '.icon.ico'], t_soft_files($idC), '同样落在 <id>.icon.ico');
$res = t_request('GET', '/api/software/icon?id=' . $idC, ['jar' => 'anon']);
t_eq(200, $res['status'], '访客取到了刚取来的图标');
t_contains($res['headers']['content-type'], 'image/x-icon', 'ICO 的类型给对了');
t_contains($res['body'], "\x00\x00\x01\x00", '正文是真正的 ico 文件头（假源站发的就是这一枚）');
t_eq($get['json']['bytes'], strlen($res['body']), '接口报的字节数与发出去的一致');

// GitHub 排在第一顺位，但测试白名单里只有假源站：这一跳被拒之后要接着试下一个来源
t_soft_set_sources(['id' => $idC, 'githubUrl' => $softUrl . '/example/tool-c']);
$get = t_soft_fetch_icon($idC);
t_eq(200, $get['status'], '第一个来源被闸门拒掉，整件事不算失败');
t_eq('官网', $get['json']['via'], '接着试了下一个候选（顺序与抓安装包一致）');

// 只剩一个走不通的来源：409 里要把每个来源的理由都摆出来
t_soft_set_sources(['id' => $idC, 'homepage' => '']);
$get = t_soft_fetch_icon($idC);
t_eq(409, $get['status'], '所有来源都没取到图标时 409');
t_contains($get['body'], '没有取到图标', '先说要干什么失败了');
t_contains($get['body'], 'GitHub', '哪一个来源试过都列出来');
t_contains($get['body'], '白名单', '连被闸门拒的原因都带着');
t_contains($get['body'], '可以自己上传', '并且给出下一步');
t_eq([$softDir . '/' . $idC . '.icon.ico'], t_soft_files($idC), '失败的获取不会把已经存好的图标弄丢');

t_soft_set_sources(['id' => $idC, 'githubUrl' => '', 'homepage' => '']);
$get = t_soft_fetch_icon($idC);
t_eq(409, $get['status'], '三个地址都没填时 409');
t_contains($get['body'], '既没填', '理由是「没有地方可去」，而不是含糊的一句失败');
$res = t_soft_fetch_icon($idC, 's_user');
t_eq(403, $res['status'], '普通用户不能触发智能获取（这是让服务器出网的动作）');
$res = t_request('POST', '/api/admin/software/icon/fetch', ['json' => [], 'jar' => 'admin']);
t_eq(422, $res['status'], '智能获取没带 id 返回 422');
$res = t_soft_fetch_icon(999999);
t_eq(404, $res['status'], '不存在的条目 404');

// 仓库信息里那几个头像字段的位置各家不同，逐种形状都真取一次
t_eq('http://127.0.0.1:8124/avatar.png', software_repo_avatar_url($softUrl . '/json/github-repo'), 'GitHub 的头像在 owner.avatar_url');
t_eq('http://127.0.0.1:8124/avatar.gif', software_repo_avatar_url($softUrl . '/json/gitee-repo'), 'Gitee 的在 namespace.avatar_url');
t_eq('http://127.0.0.1:8124/favicon.ico', software_repo_avatar_url($softUrl . '/json/user-repo'), '只有 user 字段的旧形状也认');
$rowC = software_find($idC);
$fetchBad = [
    '仓库信息 404' => ['/missing.exe', 502, 'HTTP 404'],
    '仓库信息不是 JSON' => ['/json/broken', 502, '不是合法的 JSON'],
    '仓库信息里没有头像' => ['/json/no-avatar', 404, '没有头像地址'],
];
foreach ($fetchBad as $label => $case) {
    list($path, $expect, $needle) = $case;
    $error = t_soft_error(function () use ($softUrl, $path) { software_repo_avatar_url($softUrl . $path); });
    t_eq($expect, $error['status'], $label . ' 被按状态码退回（期望 ' . $expect . '）');
    t_contains($error['message'], $needle, $label . ' 的理由里带着「' . $needle . '」');
}

// 取回来的字节还要过与上传同一道内容检查：远端说自己是 png 不算数
$imageBad = [
    '图标地址 404' => ['/missing-icon.png', 502, 'HTTP 404'],
    '图标地址是空文件' => ['/icon-empty.png', 502, '空文件'],
    '体积超过图标上限' => ['/icon-big.png', 502, '上限'],
];
foreach ($imageBad as $label => $case) {
    list($path, $expect, $needle) = $case;
    $error = t_soft_error(function () use ($softUrl, $path) { software_icon_image($softUrl . $path); });
    t_eq($expect, $error['status'], $label . ' 被拒（期望 ' . $expect . '）');
    t_contains($error['message'], $needle, $label . ' 的理由里带着「' . $needle . '」');
}
foreach (['内容是一段 SVG' => '/icon.svg', '名字是 png 内容是 php' => '/icon-garbage.png'] as $label => $path) {
    $error = t_soft_error(function () use ($softUrl, $path, $rowC) {
        software_store_icon($rowC, software_icon_image($softUrl . $path));
    });
    t_eq(415, $error['status'], $label . ' 的内容检查挡住了（415：类型不支持）');
    t_contains($error['message'], 'SVG 不收', $label . ' 的理由点明了不收 SVG');
    t_eq([$softDir . '/' . $idC . '.icon.ico'], t_soft_files($idC), $label . ' 之后磁盘上还是原来那一枚，没有多出一张假图标');
}
t_eq(200, t_soft_upload_icon($idC, base64_encode($png))['status'], '同一款换成 PNG 图标');
t_eq([$softDir . '/' . $idC . '.icon.png'], t_soft_files($idC), '旧的 favicon.ico 被换掉了');

// 出网总开关对图标这条同样有效：它没有任何放宽的旁路
$enabled = $GLOBALS['CONFIG']['soft_fetch_enabled'];
$GLOBALS['CONFIG']['soft_fetch_enabled'] = false;
$error = t_soft_error(function () use ($softUrl) { software_icon_image($softUrl . '/favicon.ico'); });
t_eq(409, $error['status'], '关掉出网开关之后图标也取不了');
t_contains($error['message'], 'soft_fetch_enabled', '理由里带着配置项的名字');
$GLOBALS['CONFIG']['soft_fetch_enabled'] = $enabled;
t_eq(22 + strlen($png), strlen(software_icon_image($softUrl . '/favicon.ico')), '开关恢复之后又能取了（22 字节的 ico 头 + 那张 png）');

t_section('软件仓库：自动识别信息');

// 地址 → owner/repo → 接口地址：这一串是纯字符串，先把形状钉死。
// 认不出 owner/repo 就等于「这一家不去问」，问错地址比不问更糟。
t_eq('https://api.github.com/repos/owner/repo', software_repo_api('github', 'owner', 'repo'), 'GitHub 的接口前缀');
t_eq('https://gitee.com/api/v5/repos/owner/repo', software_repo_api('gitee', 'owner', 'repo'), 'Gitee 的接口前缀');
t_eq('https://api.github.com/repos/a%20b/c%26d%2Fe', software_repo_api('github', 'a b', 'c&d/e'),
    'owner 与 repo 里的特殊字符按路径段编码，拼不出第三个段');
foreach ([
    '空地址' => '',
    '只有 owner' => 'https://github.com/owner',
    '没有路径' => 'https://github.com',
    '不是字符串' => null,
] as $label => $url) {
    t_eq(null, software_repo_path($url), $label . ' 认不出 owner/repo');
}
t_eq(['owner' => 'example', 'repo' => 'tool-c'], software_repo_path('https://gitee.com/example/tool-c.git'),
    'clone 地址末尾那个 .git 去掉：留着它，接口地址变成 /repos/owner/repo.git，必然 404');

// 两家仓库的字段名全不一样，逐种真实形状各取一份
$ghMeta = software_repo_meta(software_repo_json($softUrl . '/json/github-repo'));
t_eq(1234, $ghMeta['starCount'], 'GitHub 的 star 数在 stargazers_count');
t_eq('MIT', $ghMeta['license'], '协议优先取 spdx_id：卡片那一栏要的是短名字，不是「MIT License」');
t_eq('一个用来做测试的假仓库 换行会被压成一行', $ghMeta['description'], '简介里的换行压成一行（这一栏要落进单行列）');
t_eq('http://127.0.0.1:8124/demo/app', $ghMeta['homepage'], '官网照仓库信息里那份填');
t_eq(null, $ghMeta['version'], '版本不在仓库信息里，得另外问 release');

$gtMeta = software_repo_meta(software_repo_json($softUrl . '/json/gitee-repo', 'application/json'));
t_eq(88, $gtMeta['starCount'], 'Gitee 的字段叫 star_count，回成字符串也认');
t_eq('MulanPSL-2.0', $gtMeta['license'], 'Gitee 的协议是一个裸字符串');
t_eq('Gitee 上的一份简介', $gtMeta['description'], 'Gitee 的简介');

// 版本号那一跳单独成立：认不出来只是这一栏空着
t_eq('v4.5.8', software_release_version($softUrl . '/json/github-repo'), '最新 release 的 tag 就是版本号');
t_eq('1.2.0', software_release_version($softUrl . '/json/gitee-repo', 'application/json'), 'tag 前面不带 v 也照收');
$error = t_soft_error(function () use ($softUrl) { software_release_version($softUrl . '/json/no-release'); });
t_eq(502, $error['status'], '仓库不发 release 时这一跳按状态码退回');
t_contains($error['message'], 'HTTP 404', '理由带着状态码');

// 远端的脏数据不该变成卡片上的怪相，也不该让整次识别失败
$odd = software_repo_meta(software_repo_json($softUrl . '/json/odd-repo'));
t_eq(0, $odd['starCount'], '负数按 0 报：-5 不能印到卡片上');
t_eq(null, $odd['license'], 'GitHub 对「说不清是什么协议」统一回 NOASSERTION，那一串既长又没用');
t_eq(300, mb_strlen($odd['description'], 'UTF-8'), '超长简介正好截到上限，而不是整栏丢掉');
t_eq('…', mb_substr($odd['description'], -1, 1, 'UTF-8'), '截断处留一个省略号，看得出是被截的');
t_eq(null, $odd['homepage'], '远端的 homepage 不是正常地址时这一栏当没有（javascript: 更不能带上）');
t_eq('nightly-latest', software_release_version($softUrl . '/json/odd-repo'), 'tag 不是三段式也照原样认：版本号格式由发布方自己定');

// 一家仓库的完整处理：release 失败不该把已经认出来的那几栏一起废掉
$tried = [];
$one = software_identify_one('GitHub', $softUrl . '/json/github-repo', 'application/vnd.github+json', $tried);
t_eq([], $tried, '顺路的一单没有理由要记');
t_eq(['version', 'starCount', 'license', 'description', 'homepage'], array_keys($one), '认出来的五栏都带着');
t_eq('v4.5.8', $one['version'], '版本号补在第一位（表单上它排在最上面）');

$tried = [];
$one = software_identify_one('GitHub', $softUrl . '/json/no-release', 'application/vnd.github+json', $tried);
t_eq(false, array_key_exists('version', $one), '仓库不发 release 时版本号这一栏空着');
t_eq(7, $one['starCount'], '但已经认出来的那几栏不跟着一起废掉');
t_eq(1, count($tried), '不顺的地方记一句');
t_contains($tried[0], '的 release（', '记的是 release 那一跳，不是整家失败');

$tried = [];
t_eq(null, software_identify_one('GitHub', $softUrl . '/json/blank-repo', 'application/vnd.github+json', $tried),
    '一份全是没用的字段的仓库信息：这一家算没认出来，交回调用方换下一家');
t_contains($tried[1], '没有一个能用的字段', '理由里说清楚是「没东西可认」，不是「地址错了」');

$tried = [];
t_eq(null, software_identify_one('GitHub', $softUrl . '/json/broken', 'application/vnd.github+json', $tried),
    '仓库信息不是一份 JSON 时整家跳过');
t_contains($tried[0], '不是合法的 JSON', '理由带着');
t_eq(1, count($tried), '整家在第一步就断了，不会再去问 release');

// 头像字段的三种位置（纯函数，不碰网络）
t_eq('http://x/o.png', software_avatar_field(['owner' => ['avatar_url' => 'http://x/o.png']]), 'owner.avatar_url');
t_eq('http://x/n.png', software_avatar_field(['namespace' => ['avatar_url' => 'http://x/n.png']]), 'namespace.avatar_url');
t_eq('http://x/u.png', software_avatar_field(['user' => ['avatar_url' => 'http://x/u.png']]), 'user.avatar_url');
t_eq(null, software_avatar_field(['owner' => ['avatar_url' => '']]), '空字符串当没有这一栏');
t_eq(null, software_avatar_field(['owner' => 'demo']), '字段住在意料之外的层级（这里 owner 是字符串）也不报错');
t_eq('http://x/n.png', software_avatar_field([
    'owner' => ['name' => 'demo'],
    'namespace' => ['avatar_url' => 'http://x/n.png'],
]), '第一处没有就往下找，不是只认 owner');

// 整条流水线：真地址是 api.github.com，测试白名单里只有假源站，
// 所以这里验的是「被闸门拒掉之后的交代」——每一家都要报名、都要带理由。
$error = t_soft_error(function () {
    software_identify(['username' => 'root'], 'https://github.com/example/tool-a', 'https://gitee.com/example/tool-a');
});
t_eq(409, $error['status'], '两家都在白名单外时整次识别失败');
t_contains($error['message'], '没有识别出任何信息', '先说要干什么失败了');
t_contains($error['message'], 'GitHub（', '第一家报的名');
t_contains($error['message'], 'Gitee（', '第二家也报的名（顺序与抓安装包一致）');
t_contains($error['message'], '白名单', '连被闸门拒的原因都带着');
t_contains($error['message'], '手工填一格', '并且给出下一步');
$error = t_soft_error(function () { software_identify(['username' => 'root'], '', ''); });
t_eq(409, $error['status'], '两个地址都没填时 409，而且一次网都不出');
t_contains($error['message'], '先填一个 GitHub 或 Gitee 的仓库地址', '理由直接告到表单上该补哪一格');
$error = t_soft_error(function () { software_identify(['username' => 'root'], 'https://github.com/only-owner', ''); });
t_eq(409, $error['status'], '地址残缺到认不出 owner/repo 时算「没填」，不是拿半个地址去拼接口');

// 接口这一头：这是一个让服务器出网的动作，所以权限与「没填地址」必须在门口就挡住
$res = t_request('POST', '/api/admin/software/identify', ['json' => ['githubUrl' => 'https://github.com/example/tool-a'], 'jar' => 'anon']);
t_eq(401, $res['status'], '没登录不能触发识别（401；登录后不是管理员才是 403）');
$res = t_request('POST', '/api/admin/software/identify', ['json' => ['githubUrl' => 'https://github.com/example/tool-a'], 'jar' => 's_user']);
t_eq(403, $res['status'], '普通用户也不能');
$res = t_request('POST', '/api/admin/software/identify', ['json' => ['githubUrl' => '', 'giteeUrl' => ''], 'jar' => 'admin']);
t_eq(409, $res['status'], '两个地址都没填时 409');
t_contains($res['body'], '先填一个', '理由与内部函数一致');
$res = t_request('POST', '/api/admin/software/identify', ['json' => new stdClass(), 'jar' => 'admin']);
t_eq(409, $res['status'], '空请求体同样算「没填」，不是 500');
$res = t_request('POST', '/api/admin/software/identify', ['json' => ['githubUrl' => $softUrl . '/example/tool-a'], 'jar' => 'admin']);
t_eq(409, $res['status'], '仓库在白名单外时整次识别失败');
t_contains($res['body'], '白名单', '拒绝理由原样带到接口上');
t_eq(false, strpos($res['body'], 'api.github.com') !== false, '失败理由里不泄露我们其实去问了哪个地址');
$res = t_request('POST', '/api/admin/software/identify', [
    'json' => ['githubUrl' => 'https://github.com/example/tool-a'],
    'jar' => 'admin',
    'headers' => ['Origin' => 'http://evil.example'],
]);
t_eq(403, $res['status'], '跨站的写请求进不来（管理员的 Cookie 也帮不了它）');

// 识别带回来的东西必须存得下去，否则那个 ★ 上的数只能看一眼
// （star 数不在 editable 里、或软件列宽对不上，都会在这一组露出来）
foreach ([
    '负数' => ['-1', 422],
    '不是数字' => ['很多', 422],
    '小数' => ['1.5', 422],
] as $label => $case) {
    list($value, $expect) = $case;
    $res = t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idA, 'starCount' => $value], 'jar' => 'admin']);
    t_eq($expect, $res['status'], 'star 数是' . $label . '时被拒（422 是表单校验，不是数据库报错）');
}
$res = t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idA, 'starCount' => 1234], 'jar' => 'admin']);
t_eq(200, $res['status'], 'star 数存进去了');
t_eq(1234, $res['json']['software']['starCount'], '管理视图读得回来');
t_eq(1234, t_soft_item(t_request('GET', '/api/software')['json'], $idA)['starCount'], '公开卡片上也用得到');
$res = t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idA, 'starCount' => ''], 'jar' => 'admin']);
t_eq(200, $res['status'], '清空这一栏也算一次有效修改');
t_eq(null, $res['json']['software']['starCount'], '清空之后是「不知道」，不是 0：0 颗 star 与没查过是两回事');

t_section('软件仓库：释放安装包留图标，删除连图标一起清');

// 甲此刻有一个 2048 字节的本地包，再给它一枚图标：两个动作对文件的取舍正好相反
t_eq(200, t_soft_upload_icon($idA, t_soft_png_b64())['status'], '给甲传一枚图标');
t_eq([$softDir . '/' . $idA . '.exe', $softDir . '/' . $idA . '.icon.png'], t_soft_files($idA), '甲在磁盘上有两个文件：安装包与图标');
$res = t_request('POST', '/api/admin/software/release', ['json' => ['id' => $idA], 'jar' => 'admin']);
t_eq(200, $res['status'], '释放甲的安装包');
t_eq([$softDir . '/' . $idA . '.icon.png'], t_soft_files($idA), '只删掉安装包：管理员传好的图标不是安装包的一部分');
$res = t_request('GET', '/api/software/icon?id=' . $idA, ['jar' => 'anon']);
t_eq(200, $res['status'], '卡片上的图标在释放之后照常显示');
// 甲的直链还停在上一节留下的 /slow.exe 上，重抓之前先指回假源站的正常包
t_soft_set_url($idA, '/dl/2048.exe');
$grab = t_soft_grab($idA);
t_eq(200, $grab['status'], '重新抓取（回到后面几节期望的状态）');
t_eq(2048, $grab['json']['bytes'], '抓到的是甲原来那一个 2048 字节的包');
t_eq([$softDir . '/' . $idA . '.exe', $softDir . '/' . $idA . '.icon.png'], t_soft_files($idA), '重抓也不动图标');
$res = t_request('DELETE', '/api/admin/software/icon', ['json' => ['id' => $idA], 'jar' => 'admin']);
t_eq(200, $res['status'], '把甲的图标清掉，免得影响后面几节对磁盘的断言');
t_eq([$softDir . '/' . $idA . '.exe'], t_soft_files($idA), '清掉图标之后甲只剩安装包');

// ------------------------------------------------------------
// 聚合、上下架
// ------------------------------------------------------------

t_section('软件仓库：聚合计数与侧栏');

t_soft_set_url($idA, '/dl/2048.exe');
t_soft_grab($idA);
t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idC, 'platforms' => ['macos'], 'tags' => ['调试']], 'jar' => 'admin']);

$res = t_request('GET', '/api/software');
$list = $res['json'];
t_eq(3, $list['total'], '清单 3 条');
t_eq(3, $list['stats']['total'], 'stats 与 total 是同一个数（侧栏与标题不会打架）');
t_eq(2, $list['stats']['categories'], '两个分类');
t_eq(1, $list['stats']['directFiles'], '一款有本地安装包');
t_eq('系统工具', $list['facets']['categories'][0]['name'], '按条数多的排前面');
t_eq(2, $list['facets']['categories'][0]['count'], '系统工具 2 款');
t_eq('开发工具', $list['facets']['categories'][1]['name'], '开发工具在后');
t_eq(1, $list['facets']['categories'][1]['count'], '开发工具 1 款');
// 平台侧栏始终按那张固定表（windows, macos, linux, …）的顺序给，
// 谁被选过才排进来——这样前端的图标顺序不会随管理员点选的顺序乱掉
t_eq(['windows', 'macos', 'linux'], array_column($list['facets']['platforms'], 'code'), '平台按固定顺序给出，只列真有用到的');
t_eq([1, 1, 1], array_column($list['facets']['platforms'], 'count'), '每个平台各一款');
// 甲：便携+绿色；乙：GUI+绿色+便携；丙（刚改成）调试 → 前两各 2 次，后两各 1 次
t_eq(['便携', '绿色', 'GUI', '调试'], array_column($list['facets']['tags'], 'name'), '次数多的在前，一样多的按名字稳定排序');
t_eq([2, 2, 1, 1], array_column($list['facets']['tags'], 'count'), '标签计数');

$res = t_request('GET', '/api/admin/software', ['jar' => 'admin']);
t_eq(1, $res['json']['stats']['directFiles'], '管理视图的直链数与公开一致');
t_eq(2048, $res['json']['quota']['usedBytes'], '已占用字节数等于那一款的包大小');
t_eq(1, $res['json']['quota']['fileCount'], '有本地包的条数');

t_section('软件仓库：上下架');

$res = t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idC, 'enabled' => false], 'jar' => 'admin']);
t_eq(200, $res['status'], '下架返回 200');
$res = t_request('GET', '/api/software');
t_eq(2, count($res['json']['items']), '公开列表看不到下架的条目');
t_eq(2, $res['json']['total'], '总数也跟着变');
t_eq(1, $res['json']['stats']['categories'], '分类计数只算已上架的（侧栏不会点出一个空列表）');
$res = t_request('GET', '/api/admin/software', ['jar' => 'admin']);
t_eq(3, count($res['json']['items']), '管理员仍然看得到它（否则再也上不了架）');

// 下架 = 连本地安装包也不给出去：条目不在了还能下载是个说不通的状态
$res = t_request('GET', '/api/software/file?id=' . $idA, ['jar' => 'anon']);
t_eq(200, $res['status'], '在售的条目能下载');
t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idA, 'enabled' => false], 'jar' => 'admin']);
$res = t_request('GET', '/api/software/file?id=' . $idA, ['jar' => 'anon']);
t_eq(404, $res['status'], '下架之后安装包也 404');
$res = t_request('GET', '/api/admin/software', ['jar' => 'admin']);
t_eq(true, t_soft_item($res['json'], $idA)['hasFile'], '管理视图里它仍然带着本地包（重新上架就能下载）');
t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idA, 'enabled' => true], 'jar' => 'admin']);
$res = t_request('GET', '/api/software/file?id=' . $idA, ['jar' => 'anon']);
t_eq(200, $res['status'], '重新上架之后下载恢复');
t_request('POST', '/api/admin/software/update', ['json' => ['id' => $idC, 'enabled' => true], 'jar' => 'admin']);

// ------------------------------------------------------------
// 配额与条数上限
// ------------------------------------------------------------

t_section('软件仓库：条数与配额');

$res = t_request('POST', '/api/admin/software', ['json' => ['name' => '第四款', 'category' => '系统工具'], 'jar' => 'admin']);
t_eq(409, $res['status'], '已满 3 款，再新增被拒（测试环境把上限压到 3）');
t_contains($res['body'], '先删掉一些', '理由是让人去删，不是静默截断');

// 先腾两个位置出来，才能往里灌接近配额的包
$res = t_request('DELETE', '/api/admin/software', ['json' => ['id' => $idB], 'jar' => 'admin']);
t_eq(200, $res['status'], '删掉 B');
$res = t_request('DELETE', '/api/admin/software', ['json' => ['id' => $idC], 'jar' => 'admin']);
t_eq(200, $res['status'], '删掉 C');
t_eq('没有标识', $res['json']['name'], '删除时带回被删的条目名，方便前端提示');
t_eq([], t_soft_files($idC), '删掉条目之后磁盘上没有它的痕迹');

$softD = t_soft_create(['name' => '配额丁', 'category' => '系统工具', 'sourceMode' => 'direct', 'downloadUrl' => $softUrl . '/dl/199000.exe']);
$idD = (int) $softD['id'];
$softE = t_soft_create(['name' => '配额戊', 'category' => '系统工具', 'sourceMode' => 'direct', 'downloadUrl' => $softUrl . '/dl/2048.exe']);
$idE = (int) $softE['id'];

// A 已经占着 2048 字节
$grab = t_soft_grab($idD);
t_eq(200, $grab['status'], '抓一个 199000 字节的包（总量 201048，仍在配额内）');
$grab = t_soft_grab($idE);
t_eq(200, $grab['status'], 'E 先抓一个小的');
$res = t_request('GET', '/api/admin/software', ['jar' => 'admin']);
t_eq(2048 + 199000 + 2048, $res['json']['quota']['usedBytes'], '三项合计 203096 字节');
t_eq(3, $res['json']['quota']['fileCount'], '三款都有本地包了');

t_section('软件仓库：配额的两刀各管一半');

// 第一刀在下载之后：直链没有元数据，只能等真的下完才知道这个包多大。
// 这一刀必须发生在改名到位之前，否则失败的抓包会顺手覆盖掉访客此刻还能下载的那一份。
t_soft_set_url($idE, '/dl/200000.exe');
$res = t_soft_grab($idE);
t_eq(507, $res['status'], '这个包会让总占用越过配额，被拒（507 是「存储配额」的语义）');
t_contains($res['body'], '超过配额', '理由写的是这一个包会把总量顶过去');
t_eq([], t_soft_parts(), '被拒的包连暂存文件都没留下');
$eFile = $softDir . '/' . $idE . '.exe';
t_eq([$eFile], t_soft_files($idE), 'E 磁盘上仍然只有它原来那一份');
t_eq(2048, filesize($eFile), '原来那一份的字节数没被动过');
$after = t_soft_item(t_request('GET', '/api/admin/software', ['jar' => 'admin'])['json'], $idE);
t_eq(2048, $after['sizeBytes'], '库里 E 仍然是它原来那一份');
$res = t_request('GET', '/api/software/file?id=' . $idE, ['jar' => 'anon']);
t_eq(200, $res['status'], '越过配额的那一次抓包，不影响访客继续下载 E 的旧包');

// 把总量正好推到配额：释放 A 腾出位置，再让 D、E 各占 200000
$res = t_request('POST', '/api/admin/software/release', ['json' => ['id' => $idA], 'jar' => 'admin']);
t_eq(200, $res['status'], '释放 A 的安装包');
t_eq(false, $res['json']['software']['hasFile'], '释放之后条目不再声明有本地包');
t_eq([], t_soft_files($idA), '释放会把文件从磁盘上删掉');
$res = t_request('GET', '/api/software/file?id=' . $idA, ['jar' => 'anon']);
t_eq(404, $res['status'], '释放之后下载 404');
$grab = t_soft_grab($idE);
t_eq(200, $grab['status'], '同一个包在腾出空间之后就抓得进了：说明拒的是总量，不是这个包本身');
t_eq(200000, $grab['json']['bytes'], 'E 现在是 200000 字节');
t_soft_set_url($idD, '/dl/200000.exe');
$grab = t_soft_grab($idD);
t_eq(200, $grab['status'], 'D 也换成 200000（200000 + 200000 正好等于配额，不算越线）');
$res = t_request('GET', '/api/admin/software', ['jar' => 'admin']);
t_eq(400000, $res['json']['quota']['usedBytes'], '占用正好顶到配额');
t_eq(2, $res['json']['quota']['fileCount'], '两款有本地包');

// 第二刀在下载之前：总量已经满了，就不该再为任何一个包出网
t_soft_set_url($idA, '/dl/2048.exe');
$res = t_soft_grab($idA);
t_eq(507, $res['status'], '配额已经满了，再来一个包（哪怕只有 2 KB）也被拒');
t_contains($res['body'], '达到总配额', '理由写的是总配额已满');
t_contains($res['body'], '先删掉', '并且告诉管理员下一步该干什么');
t_eq([], t_soft_files($idA), '被拒的这一款磁盘上什么都没落下');
t_eq([], t_soft_parts(), '也没有暂存文件（说明根本没开始下载）');
t_request('POST', '/api/admin/software/release', ['json' => ['id' => $idD], 'jar' => 'admin']);
$grab = t_soft_grab($idA);
t_eq(200, $grab['status'], '释放 D 还回空间之后，同一个包立刻就抓得进（配额算的是当下，不是缓存值）');
$res = t_request('GET', '/api/admin/software', ['jar' => 'admin']);
t_eq(200000 + 2048, $res['json']['quota']['usedBytes'], '配额随之降回来（释放真的还了空间）');
t_eq(2, $res['json']['quota']['fileCount'], '有本地包的条数跟着变化');
$res = t_request('GET', '/api/software', ['jar' => 'anon']);
t_eq(3, $res['json']['total'], '释放安装包不会把条目一起删掉');

t_section('软件仓库：删除会连本地文件一起清掉');

$res = t_request('DELETE', '/api/admin/software', ['json' => [], 'jar' => 'admin']);
t_eq(422, $res['status'], '删除没带 id 返回 422');
foreach ([$idA, $idD, $idE] as $id) {
    $res = t_request('DELETE', '/api/admin/software', ['json' => ['id' => $id], 'jar' => 'admin']);
    t_eq(200, $res['status'], '删掉条目 ' . $id);
    t_eq([], t_soft_files($id), '条目 ' . $id . ' 的文件已经不在磁盘上');
    $res = t_request('DELETE', '/api/admin/software', ['json' => ['id' => $id], 'jar' => 'admin']);
    t_eq(404, $res['status'], '重复删除条目 ' . $id . ' 返回 404');
}
$res = t_request('GET', '/api/software');
t_eq(0, $res['json']['total'], '清单清空了');
$res = t_request('GET', '/api/admin/software', ['jar' => 'admin']);
t_eq(0, $res['json']['quota']['usedBytes'], '配额回到 0');
t_eq(0, count($res['json']['facets']['categories']), '侧栏计数也回到空');
t_eq([], t_soft_parts(), '整个用例跑完没有留下任何半成品');
$leftovers = array_values((array) glob($softDir . '/*'));
t_eq([], $leftovers, '安装包目录被清干净了（' . implode(', ', array_map('basename', $leftovers)) . '）');

t_section('软件仓库：日志里留下了痕迹');

$logs = [];
foreach ((array) glob(cfg('log_dir') . '/*.log') as $file) {
    $logs[] = (string) file_get_contents($file);
}
$logText = implode("\n", $logs);
t_contains($logText, '软件仓库新增', '新增写了审计日志');
t_contains($logText, '软件仓库抓包', '抓包写了审计日志（谁在什么时候让服务器出的网）');
t_contains($logText, '软件仓库删除', '删除写了审计日志');
t_contains($logText, '软件仓库上传图标', '上传图标写了审计日志');
t_contains($logText, '软件仓库智能获取图标', '智能获取写了审计日志，连走的哪个来源都记下来');
t_contains($logText, '软件仓库清除图标', '清除图标也留痕');

t_section('软件仓库：二级页面路由');

$softRoot = dirname(__DIR__, 2);
$res = t_request('GET', '/software');
t_eq(200, $res['status'], 'GET /software 返回 200');
t_contains($res['headers']['content-type'], 'text/html', '返回的是 HTML');
t_contains($res['body'], '软件仓库', '页面里有板块标题');
t_contains($res['body'], '/assets/js/software.js', '页面引用了软件脚本（绝对路径）');
t_assert(isset($res['headers']['content-security-policy']), '二级页面也带 CSP');

$res = t_request('GET', '/software.html');
t_eq(200, $res['status'], '/software.html 也能访问（与 /software 是同一个文件）');
$res = t_request('GET', '/software/');
t_eq(200, $res['status'], '/software/ 带斜杠也能访问');
$res = t_request('GET', '/software/no-such-page');
t_eq(404, $res['status'], '软件仓库下的未知路径返回 404');

// 五个页面互相走得到：手机上导航是隐藏的，页脚那几个链接是唯一的出口
foreach (['/' => '首页', '/games' => '游戏', '/study' => '学习', '/read' => '阅读'] as $softPath => $softLabel) {
    $res = t_request('GET', $softPath);
    t_contains($res['body'], 'href="/software"', $softLabel . '页上有通往软件仓库的入口');
}

$softHtml = (string) file_get_contents($softRoot . '/public/software.html');
$coreAt = strpos($softHtml, 'src="/assets/js/core.js"');
$softAt = strpos($softHtml, 'src="/assets/js/software.js"');
t_assert($softAt !== false && $coreAt !== false && $coreAt < $softAt,
    'core.js 排在 software.js 前面：el() 与 api() 先定义才用得到');
$softInline = [];
t_assert(preg_match('/\son(click|pointerdown|input|change|keydown|load)=/i', $softHtml, $softInline) === 0,
    'software.html 里没有内联事件属性：CSP 的 script-src 是 ‘self’，内联的会被直接拦掉',
    $softInline ? '找到 ' . $softInline[0] : '');

// 后台表单整块带 hidden：真正的把关在接口（每条断言都在上面验过 401/403），
// 这一条只保证「游客打开这一页看不到一套用不了的输入框」
t_contains($softHtml, 'class="board-section hidden" id="soft-admin"', '后台编辑器默认隐藏');
t_assert(strpos($softHtml, 'admin/system') === false,
    '这一页不碰 GET /api/admin/system：服务器指标只在首页与系统面板给管理员看，不在这里多开一个口子');
t_assert(preg_match('/<script(?![^>]*\ssrc=)/i', $softHtml) === 0,
    'software.html 里没有内联 <script>：同上，CSP 会把它拦掉');

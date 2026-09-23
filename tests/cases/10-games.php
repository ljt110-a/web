<?php
/**
 * 游戏板块用例。
 *
 * 三件事最要紧：
 *   1. 读公开、增删改要管理员——越权尝试必须真的改不动数据（这里会再查一次列表确认）
 *   2. 跳转地址只允许 http/https：javascript: / data: 这类伪协议会被写进 <a href>，
 *      放行等于给了 XSS 的入口
 *   3. 「预留的添加接口」是真的能用：新增之后前台立刻可见，并且按 sort_order 排序
 */

t_section('游戏板块：公开列表');

$res = t_request('GET', '/api/games');
t_eq(200, $res['status'], '未登录也能读游戏列表');
t_eq(false, isset($res['json']['manageable']) ? $res['json']['manageable'] : null, '游客拿到的 manageable 是 false');
t_assert(isset($res['json']['maxName']), '返回字段长度上限，前端才能设 maxlength');

$items = $res['json']['items'];
t_eq(2, count($items), '测试库里有 2 条初始内容（王者荣耀 / 原神）');
t_eq('王者荣耀', $items[0]['name'], 'sort_order 小的排在前面：第一条是王者荣耀');
t_eq('原神', $items[1]['name'], '第二条是原神');

$first = $items[0];
t_eq('honor-of-kings', $first['slug'], '带英文标识');
t_eq('game-honor-of-kings', $first['anchor'], '锚点由 slug 生成');
t_eq('https://pvp.qq.com/', $first['url'], '跳转地址正确');
t_eq('⚔️', $first['icon'], 'emoji 图标被完整保存（utf8mb4 起作用）');
t_eq(false, $first['canManage'], '游客的 canManage 是 false');
t_eq('腾讯', $first['publisher'], '厂商字段');
t_eq('MOBA 竞技', $first['genre'], '类型字段');

$second = $items[1];
t_eq('genshin-impact', $second['slug'], '原神的英文标识');
t_eq('米哈游', $second['publisher'], '原神的厂商');
t_eq('https://ys.mihoyo.com/', $second['url'], '原神的跳转地址');

$allHttps = true;
foreach ($items as $item) {
    if (strpos($item['url'], 'https://') !== 0) {
        $allHttps = false;
    }
}
t_eq(true, $allHttps, '初始内容的地址都是 https');

t_section('游戏板块：权限');

$res = t_request('GET', '/api/admin/games', ['jar' => 'anon']);
t_eq(401, $res['status'], '未登录读管理列表返回 401');

$res = t_request('POST', '/api/admin/games', ['json' => ['name' => '游客尝试', 'url' => 'https://a.com/'], 'jar' => 'anon']);
t_eq(401, $res['status'], '未登录新增游戏返回 401');

t_clear_jar('g_user');
t_request('POST', '/api/register', ['json' => ['username' => 'g_user', 'password' => 'gamepw123'], 'jar' => 'g_user']);

$res = t_request('GET', '/api/admin/games', ['jar' => 'g_user']);
t_eq(403, $res['status'], '普通用户读管理列表返回 403');
$res = t_request('POST', '/api/admin/games', ['json' => ['name' => '越权新增', 'url' => 'https://a.com/'], 'jar' => 'g_user']);
t_eq(403, $res['status'], '普通用户新增游戏返回 403');
$res = t_request('POST', '/api/admin/games/update', ['json' => ['id' => 1, 'enabled' => false], 'jar' => 'g_user']);
t_eq(403, $res['status'], '普通用户下架游戏返回 403');
$res = t_request('DELETE', '/api/admin/games', ['json' => ['id' => 1], 'jar' => 'g_user']);
t_eq(403, $res['status'], '普通用户删除游戏返回 403');

// 关键：上面四次越权尝试一次都不能生效
$res = t_request('GET', '/api/games');
t_eq(2, count($res['json']['items']), '越权尝试之后列表仍然是 2 条，没有任何改动');
t_eq('王者荣耀', $res['json']['items'][0]['name'], '王者荣耀仍然在');

t_section('游戏板块：管理员新增（预留的添加接口）');

$res = t_request('GET', '/api/admin/games', ['jar' => 'admin']);
t_eq(200, $res['status'], '管理员读管理列表成功');
t_eq(true, isset($res['json']['manageable']) ? $res['json']['manageable'] : null, '管理员看到 manageable = true');
t_eq(2, count($res['json']['items']), '管理视图当前 2 条');

$res = t_request('POST', '/api/admin/games', [
    'json' => [
        'name' => '塞尔达传说',
        'url' => 'https://www.nintendo.com/',
        'icon' => '🗡️',
        'publisher' => '任天堂',
        'genre' => '开放世界',
        'slug' => 'zelda',
        'description' => "第一行简介\n第二行简介",
        'sortOrder' => 5,
    ],
    'jar' => 'admin',
]);
t_eq(201, $res['status'], '管理员新增成功返回 201');
$zeldaId = (int) $res['json']['game']['id'];
t_eq('塞尔达传说', $res['json']['game']['name'], '返回新建的游戏');
t_eq(5, $res['json']['game']['sortOrder'], '排序值被保存');
t_eq('game-zelda', $res['json']['game']['anchor'], '锚点用的是英文标识');
t_eq(true, isset($res['json']['game']['canManage']) ? $res['json']['game']['canManage'] : null, '管理员拿到 canManage = true');
t_assert(isset($res['json']['list']), '新增后顺带返回最新列表，前端不用再拉一次');

$res = t_request('GET', '/api/games');
t_eq(3, count($res['json']['items']), '公开列表里也能看到新增的游戏');
t_eq('塞尔达传说', $res['json']['items'][0]['name'], 'sort_order = 5 比 10 小，排到了最前面');
t_eq("第一行简介\n第二行简介", $res['json']['items'][0]['description'], '简介里的换行原样保存');

t_section('游戏板块：新增的字段校验');

$cases = [
    ['name' => '', 'url' => 'https://a.com/', 'expect' => 422, 'label' => '游戏名为空被拒绝'],
    ['name' => str_repeat('名', 41), 'url' => 'https://a.com/', 'expect' => 422, 'label' => '游戏名超过 40 字被拒绝'],
    ['name' => '没填地址', 'url' => '', 'expect' => 422, 'label' => '跳转地址为空被拒绝'],
    ['name' => '伪协议', 'url' => 'javascript:alert(1)', 'expect' => 422, 'label' => 'javascript: 伪协议被拒绝'],
    ['name' => '数据协议', 'url' => 'data:text/html,<b>x</b>', 'expect' => 422, 'label' => 'data: 伪协议被拒绝'],
    ['name' => '相对地址', 'url' => '/relative/path', 'expect' => 422, 'label' => '不是 http/https 的地址被拒绝'],
    ['name' => '原型污染', 'url' => 'vbscript:msgbox(1)', 'expect' => 422, 'label' => 'vbscript: 伪协议被拒绝'],
    ['name' => '连字符开头', 'url' => 'https://a.com/', 'slug' => '-leading-dash', 'expect' => 422, 'label' => '以连字符开头的标识被拒绝'],
    ['name' => '中文标识', 'url' => 'https://a.com/', 'slug' => '中文不能当标识', 'expect' => 422, 'label' => '非 ASCII 的标识被拒绝'],
    ['name' => '太短的标识', 'url' => 'https://a.com/', 'slug' => 'a', 'expect' => 422, 'label' => '只有一个字符的标识被拒绝'],
    ['name' => '坏图标', 'url' => 'https://a.com/', 'icon' => '这个图标太长了放不下', 'expect' => 422, 'label' => '图标过长被拒绝'],
    ['name' => '重复标识', 'url' => 'https://a.com/', 'slug' => 'zelda', 'expect' => 409, 'label' => '英文标识重复返回 409'],
];

foreach ($cases as $case) {
    $payload = ['name' => $case['name'], 'url' => $case['url']];
    if (isset($case['slug'])) {
        $payload['slug'] = $case['slug'];
    }
    if (isset($case['icon'])) {
        $payload['icon'] = $case['icon'];
    }
    $res = t_request('POST', '/api/admin/games', ['json' => $payload, 'jar' => 'admin']);
    t_eq($case['expect'], $res['status'], $case['label']);
}

$res = t_request('GET', '/api/games');
t_eq(3, count($res['json']['items']), '被拒绝的 12 次尝试一条都没写进库里');

// 英文标识大小写不敏感：填 MyGame 会被规范成 mygame 存起来。
// 这是有意的——管理员不必记住「只能小写」，存进去的永远是唯一的那种写法。
$res = t_request('POST', '/api/admin/games', [
    'json' => ['name' => '大小写测试', 'url' => 'https://example.com/case', 'slug' => 'MyGame-AbC'],
    'jar' => 'admin',
]);
t_eq(201, $res['status'], '英文标识里有大写也能新增（会被规范化）');
$caseId = (int) $res['json']['game']['id'];
t_eq('mygame-abc', $res['json']['game']['slug'], '英文标识统一转成小写保存');
t_eq('game-mygame-abc', $res['json']['game']['anchor'], '锚点用的是规范化之后的标识');

$res = t_request('DELETE', '/api/admin/games', ['json' => ['id' => $caseId], 'jar' => 'admin']);
t_eq(200, $res['status'], '清理：删掉这条规范化测试数据');

// 注意用 array_key_exists 而不是 isset：isset 对「键存在但值为 null」会返回 false，
// 而这里正好要验证 slug 就是 null，用 isset 会拿不到真实值
$res = t_request('POST', '/api/admin/games', [
    'json' => ['name' => '没有英文标识', 'url' => 'https://example.com/game'],
    'jar' => 'admin',
]);
t_eq(201, $res['status'], '英文标识留空也能新增');
$noSlugId = (int) $res['json']['game']['id'];
t_eq(null, array_key_exists('slug', $res['json']['game']) ? $res['json']['game']['slug'] : 'missing', 'slug 为 null');
t_eq('game-' . $noSlugId, $res['json']['game']['anchor'], '没有 slug 时锚点退回用 id');
t_eq('🎮', $res['json']['game']['icon'], '没填图标时给一个默认值');

t_section('游戏板块：上下架');

$res = t_request('GET', '/api/games');
$totalBefore = count($res['json']['items']);

$res = t_request('POST', '/api/admin/games/update', ['json' => ['id' => $zeldaId, 'enabled' => false], 'jar' => 'admin']);
t_eq(200, $res['status'], '下架返回 200');
t_eq(false, isset($res['json']['game']['enabled']) ? $res['json']['game']['enabled'] : null, '返回的 enabled 是 false');
t_eq('塞尔达传说', $res['json']['game']['name'], '下架时没传 name，原值保持不变');

$res = t_request('GET', '/api/games');
t_eq($totalBefore - 1, count($res['json']['items']), '下架之后公开列表里就看不到了');

// 同一个公开地址，带上管理员 Cookie 也必须只看得到上架内容：
// 公开接口的结果不该随调用者身份变化，否则缓存、排障和测试都不可预期
$res = t_request('GET', '/api/games', ['jar' => 'admin']);
t_eq($totalBefore - 1, count($res['json']['items']), '管理员带 Cookie 访问公开接口，结果与游客一致');
$res = t_request('GET', '/api/games', ['jar' => 'admin']);
t_eq(false, isset($res['json']['manageable']) ? $res['json']['manageable'] : null, '公开接口里 manageable 恒为 false');
$res = t_request('GET', '/api/games', ['jar' => 'anon']);
t_eq($totalBefore - 1, count($res['json']['items']), '未登录访问同样看不到下架内容');

$res = t_request('GET', '/api/admin/games', ['jar' => 'admin']);
$stillThere = false;
foreach ($res['json']['items'] as $item) {
    if ($item['id'] === $zeldaId && $item['enabled'] === false) {
        $stillThere = true;
    }
}
t_eq(true, $stillThere, '管理员视图里仍然能看到它（否则就再也上不了架）');

$res = t_request('POST', '/api/admin/games/update', ['json' => ['id' => $zeldaId, 'enabled' => true], 'jar' => 'admin']);
t_eq(200, $res['status'], '重新上架返回 200');
$res = t_request('GET', '/api/games');
t_eq($totalBefore, count($res['json']['items']), '重新上架后公开列表恢复');

$res = t_request('POST', '/api/admin/games/update', ['json' => ['id' => $zeldaId, 'sortOrder' => 1, 'genre' => '动作冒险'], 'jar' => 'admin']);
t_eq(200, $res['status'], '同时改排序与类型（部分更新）');
t_eq(1, $res['json']['game']['sortOrder'], '排序已更新');
t_eq('动作冒险', $res['json']['game']['genre'], '类型已更新');
t_eq('塞尔达传说', $res['json']['game']['name'], '没传的字段保持原值');

$res = t_request('POST', '/api/admin/games/update', ['json' => ['id' => $zeldaId, 'unknown' => 'x'], 'jar' => 'admin']);
t_eq(422, $res['status'], '只传不认识的字段返回 422');

$res = t_request('POST', '/api/admin/games/update', ['json' => ['enabled' => false], 'jar' => 'admin']);
t_eq(422, $res['status'], '没带 id 返回 422');

$res = t_request('POST', '/api/admin/games/update', ['json' => ['id' => 999999, 'enabled' => false], 'jar' => 'admin']);
t_eq(404, $res['status'], '更新不存在的游戏返回 404');

t_section('游戏板块：删除');

$res = t_request('DELETE', '/api/admin/games', ['json' => ['id' => $noSlugId], 'jar' => 'admin']);
t_eq(200, $res['status'], '删除返回 200');
t_eq($noSlugId, isset($res['json']['deleted']) ? $res['json']['deleted'] : null, '返回被删除的 id');
t_eq('没有英文标识', $res['json']['name'], '返回被删除的游戏名，方便前端提示');

$res = t_request('DELETE', '/api/admin/games', ['json' => ['id' => $noSlugId], 'jar' => 'admin']);
t_eq(404, $res['status'], '重复删除返回 404');

$res = t_request('DELETE', '/api/admin/games', ['json' => [], 'jar' => 'admin']);
t_eq(422, $res['status'], '没带 id 返回 422');

$res = t_request('GET', '/api/admin/games', ['jar' => 'admin']);
t_eq(3, count($res['json']['items']), '最终剩 3 条（两条初始内容 + 塞尔达传说）');

t_section('游戏板块：二级页面路由');

$res = t_request('GET', '/games');
t_eq(200, $res['status'], 'GET /games 返回 200');
t_contains($res['headers']['content-type'], 'text/html', '返回的是 HTML');
t_contains($res['body'], '游戏板块', '页面里有板块标题');
t_contains($res['body'], '/assets/js/games.js', '页面引用了游戏板块的脚本（绝对路径，换 URL 也不会 404）');
t_contains($res['body'], 'href="/"', '页面里有回首页的入口');
t_contains($res['body'], 'id="games-grid"', '有承载卡片的容器');
t_contains($res['body'], 'id="games-admin"', '预留了管理区容器');
// 小游戏这一节：只断言「结构在、而且对游客可见」。
// 各个 id 是否和 arcade.js 对得上，由前端烟测负责（它会真的拿 HTML 扫出的假 DOM 跑一遍脚本）。
t_contains($res['body'], '/assets/js/arcade.js', '页面引用了小游戏脚本');
t_contains($res['body'], 'id="arcade"', '有小游戏区块');
t_contains($res['body'], 'id="game-2048"', '有 2048 棋盘容器');
t_contains($res['body'], 'id="game-snake-canvas"', '有贪吃蛇画布');
t_assert(
    strpos($res['body'], 'class="board-section" id="arcade"') !== false,
    '小游戏区块不带 hidden：没登录、后端没起来也照样能玩'
);
t_assert(isset($res['headers']['x-content-type-options']), '二级页面也带安全响应头');
t_assert(isset($res['headers']['content-security-policy']), '二级页面也带 CSP');

$res = t_request('GET', '/games.html');
t_eq(200, $res['status'], '/games.html 也能访问（与 /games 是同一个文件）');
$res = t_request('GET', '/games/');
t_eq(200, $res['status'], '/games/ 带斜杠也能访问');
$res = t_request('GET', '/games/not-exist');
t_eq(404, $res['status'], '游戏板块下的未知路径返回 404');

$res = t_request('GET', '/');
t_contains($res['body'], 'href="/games"', '首页里有通往游戏板块的链接');
t_contains($res['body'], 'id="nav-home-submenu"', '首页导航里有个下拉子菜单容器');
t_contains($res['body'], 'href="#messages"', '下拉菜单里有留言板入口');
t_contains($res['body'], 'href="#memos"', '下拉菜单里有备忘录入口');
t_contains($res['body'], 'class="footer-links"', '页脚也有入口（窄屏下导航链接是隐藏的）');
t_assert(
    strpos($res['body'], '游戏板块 →') === false,
    '首屏不再挂「游戏板块 →」次要链接（导航下拉才是入口）'
);

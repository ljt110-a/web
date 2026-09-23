<?php
/**
 * /games 里的弹球物理沙盒（渐变标题 + 3D 翻面卡片 + canvas 物理）用例。
 *
 * 这一组不测「好不好看」——那是眼睛的事。它测的是四个会让功能直接坏掉的前提：
 *   1. 加载顺序：physics.js 用的是 core.js 的 el()，排在后面才有东西可拿
 *   2. HTML 里滑块默认值必须等于 JS 里的默认环境参数，否则屏幕上写着 70、实际按别的值在落
 *   3. 画布显示比例必须等于物理世界的宽高比，差一点球就会被拉成椭圆
 *   4. 规则自测走 core.js 的 SMOKE_TESTS 数组登记：同一页上两套规则如果都叫同一个
 *      函数名，那是静默覆盖，先写的那套断言会悄悄不再执行，而测试仍然全绿
 */

$root = dirname(__DIR__, 2);

t_section('物理沙盒：页面结构与加载顺序');

$res = t_request('GET', '/games');
t_eq(200, $res['status'], 'GET /games 返回 200');
$body = $res['body'];

$coreAt = strpos($body, 'src="/assets/js/core.js"');
$physAt = strpos($body, 'src="/assets/js/physics.js"');
t_assert($physAt !== false, '页面引用了 physics.js');
// 找的是 script 标签本身：HTML 注释里也写着 physics.js 这个名字，
// 拿裸文件名比位置会先撞上注释，得出一个假的「顺序不对」
t_assert($coreAt !== false && $physAt !== false && $coreAt < $physAt,
    'core.js 排在 physics.js 前面：el() 先定义才用得到');
// 没登录、后端没起来时这一节也必须在：它一行都不碰接口
t_assert(strpos($body, 'class="board-section" id="physics"') !== false,
    '物理沙盒区块不带 hidden：游客直接就能玩');

$physicsJs = is_file($root . '/public/assets/js/physics.js')
    ? file_get_contents($root . '/public/assets/js/physics.js') : '';
t_assert($physicsJs !== '', 'physics.js 存在');
t_assert(strpos($physicsJs, 'api(') === false,
    'physics.js 里没有 api()：这一节完全不需要后端');
t_assert(strpos($physicsJs, 'localStorage') === false,
    'physics.js 不写 localStorage：参数只活在内存里，刷新回默认值');
t_assert(strpos($physicsJs, 'addEventListener(\'keydown\'') === false
    && strpos($physicsJs, 'addEventListener("keydown"') === false,
    'physics.js 不接键盘：同一页已经有两个方向键消费者了，再来一个会互相抢');

// physics.js 按这些 id 取元素；少一个就会拿到 null 然后整块不启动。
// （前端烟测也会点名，这里是第二道，防止只改 HTML 的那次提交绕过烟测）
foreach ([
    'physics-canvas', 'physics-status', 'physics-count', 'physics-moving', 'physics-hits',
    'physics-gravity', 'physics-bounce', 'physics-gravity-out', 'physics-bounce-out',
    'physics-toggle', 'physics-add', 'physics-seed', 'physics-clear',
    'physics-card', 'physics-flip',
] as $id) {
    t_contains($body, 'id="' . $id . '"', 'HTML 里有 ' . $id);
}

// 画布只能靠指针扔球，所以 aria-label 必须把怎么玩写清楚，否则读屏软件只报「画布」
t_contains($body, 'aria-label="弹球沙盒', '画布有说明怎么操作的 aria-label');
// 注意是 === 0 而不是 === false：preg_match 没匹配到时返回 0，false 只代表正则自己出错了。
// 写成 === false 的话这条断言永远失败，而且失败信息看起来像「页面里有内联事件」——完全是误导。
$inlineHit = array();
$inlineCount = preg_match('/\son(click|pointerdown|input|change|keydown|load)=/i', $body, $inlineHit);
t_assert($inlineCount === 0,
    'games.html 里没有内联事件属性：CSP 的 script-src 是 ‘self’，内联的会被直接拦掉',
    $inlineCount === 1 ? '找到 ' . $inlineHit[0] : '');

t_section('物理沙盒：滑块默认值要和 JS 的默认参数对上');

t_assert(preg_match('/id="physics-gravity"[^>]*value="(\d+)"/', $body, $m) === 1,
    '重力滑块写了默认值');
t_assert(preg_match('/physicsEnv\((\d+(?:\.\d+)?),/', $physicsJs, $j) === 1,
    'physics.js 里有默认重力');
t_eq((float) $j[1], (float) $m[1], 'HTML 上显示的重力 = JS 的默认重力（不一致的话数字与手感对不上）');

t_assert(preg_match('/id="physics-bounce"[^>]*value="(\d+)"/', $body, $m2) === 1,
    '弹性滑块写了默认值');
t_assert(preg_match('/physicsEnv\(\d+(?:\.\d+)?,\s*(0?\.\d+|\d+)/', $physicsJs, $j2) === 1,
    'physics.js 里有默认弹性系数');
t_eq((float) $j2[1] * 100, (float) $m2[1], 'HTML 上显示的弹性 = JS 的 restitution（滑块按百分比起作用）');

// 滑块的 value 必须落在自己的 min/max 里面，否则浏览器会把它当成边界值，显示与说明又对不上
t_assert(preg_match('/id="physics-gravity"[^>]*min="0"[^>]*max="(\d+)"/', $body, $m3) === 1
    && (float) $m3[1] >= (float) $m[1],
    '重力滑块的默认值没超过自己的量程');

t_section('物理沙盒：画布比例');

// 物理算在 PHYSICS_W × PHYSICS_H 的虚拟世界里，画的时候各轴独立缩放。
// 显示比例和它不一致，圆就会被拉成椭圆——改一处忘了改另一处是最容易犯的错。
t_assert(preg_match('/const PHYSICS_W = (\d+)/', $physicsJs, $w) === 1, 'JS 里有 PHYSICS_W');
t_assert(preg_match('/const PHYSICS_H = (\d+)/', $physicsJs, $h) === 1, 'JS 里有 PHYSICS_H');
$css = file_get_contents($root . '/public/assets/css/style.css');
t_assert(preg_match('/\.physics-canvas\s*\{[^}]*aspect-ratio:\s*(\d+)\s*\/\s*(\d+)/s', $css, $ar) === 1,
    'CSS 给画布锁了 aspect-ratio');
t_assert(abs(((float) $w[1] / (float) $h[1]) - ((float) $ar[1] / (float) $ar[2])) < 0.0001,
    '画布的 CSS 比例 = 物理世界的宽高比（不一致球就是椭圆）');
t_assert(preg_match('/id="physics-canvas"[^>]*width="(\d+)"[^>]*height="(\d+)"/', $body, $attr) === 1,
    '画布的 width/height 属性也给了兜底值');
t_assert(abs(((float) $w[1] / (float) $h[1]) - ((float) $attr[1] / (float) $attr[2])) < 0.0001,
    '画布属性的兜底分辨率同样是 5:3');

// 一步最多走的距离必须小于最小的球半径，否则高速球会整个穿过另一颗球
t_assert(preg_match('/const PHYSICS_MAX_SPEED = (\d+)/', $physicsJs, $ms) === 1, 'JS 里有速度上限');
t_assert(preg_match('/const PHYSICS_STEP = 1 \/ (\d+)/', $physicsJs, $st) === 1, 'JS 里有固定步长');
t_assert(preg_match('/const PHYSICS_MIN_RADIUS = ([\d.]+)/', $physicsJs, $mr) === 1, 'JS 里有最小球半径');
t_assert(((float) $ms[1] / (float) $st[1]) < (float) $mr[1],
    '速度上限 ÷ 步长 < 最小球半径：再用力也不会穿球');

t_section('物理沙盒：3D 与渐变的样式前提');

$part16 = substr($css, (int) strpos($css, '第 16 部分'));
t_contains($part16, 'perspective:', '卡片父元素给了 perspective（3D 纵深来自父元素）');
t_contains($part16, 'transform-style: preserve-3d', '翻转容器保留 3D（少了这行背面翻不过来）');
t_contains($part16, 'backface-visibility: hidden', '两个面各自隐藏背面（不然背面会反着印在前面）');
t_contains($part16, '.physics-card.flipped .physics-inner', '翻面由一个 class 驱动（JS 只加 class，不管变换）');
t_contains($part16, 'grid-area: 1 / 1', '正反面叠在同一格：容器高度取较高那个，不用写死 min-height');
t_contains($part16, 'touch-action: none', '画布关掉浏览器手势：拖动是抓球，不能同时被当成页面滚动');
t_contains($part16, 'prefers-reduced-motion', '减少动态偏好下有降级（静止倾角与过渡都去掉，翻面照常）');
t_contains($part16, 'font-variant-numeric: tabular-nums', '跳动的读数用等宽数字，不左右抖');
// 渐变文字靠 background-clip: text 把底图裁到字上；不支持的浏览器必须退回实心色，
// 否则 color: transparent 会让整块标题消失——看不见比不好看严重得多。
t_contains($css, '@supports not (background-clip: text)', '渐变标题有 background-clip 不支持时的兜底');
t_assert(strpos($css, 'color: transparent;') !== false, '渐变标题确实把文字涂成了透明（所以兜底必须存在）');

t_section('规则自测：同一页两套规则用数组登记');

$coreJs = file_get_contents($root . '/public/assets/js/core.js');
$arcadeJs = file_get_contents($root . '/public/assets/js/arcade.js');
t_contains($coreJs, 'const SMOKE_TESTS = [];', 'core.js 声明了登记表');
t_contains($arcadeJs, 'SMOKE_TESTS.push(arcadeSelfTest);', 'arcade.js 把自己的规则登记进去');
t_contains($physicsJs, 'SMOKE_TESTS.push(physicsSelfTest);', 'physics.js 也登记了自己的');
// 同名函数声明是静默覆盖：如果谁又改回那个名字，arcade 的断言会悄悄不跑，而且别处全绿
t_assert(strpos($arcadeJs, 'function smokeSelfTest') === false
    && strpos($physicsJs, 'function smokeSelfTest') === false,
    '没有脚本再声明 smokeSelfTest()（会被同名函数静默覆盖）');

t_section('物理沙盒：没有新增二级页面');

// 这一项刻意并进 /games：加第三个二级页面要一起整理路由表、导航、页脚和测试入口
$res = t_request('GET', '/physics');
t_eq(404, $res['status'], '/physics 不存在：沙盒并进 /games，没有偷偷加第三个二级页面');
$res = t_request('GET', '/games');
t_eq(200, $res['status'], '/games 仍然是那一个页面');

$res = t_request('GET', '/assets/js/physics.js');
t_eq(200, $res['status'], 'physics.js 能取到（静态文件由 Web 服务器直接给）');
t_contains(isset($res['headers']['content-type']) ? $res['headers']['content-type'] : '',
    'javascript', 'physics.js 按脚本类型返回');

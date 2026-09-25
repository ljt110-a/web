<?php
/**
 * PWA（可安装 / 离线）用例。
 *
 * 这一组断言盯的是「装得上、离线打得开」这两件事的前提条件，而不是外观：
 *   1. 清单必须带 JSON 类型的 Content-Type——浏览器按类型决定要不要读它，
 *      这一条在 PHP 内置服务器和老 Apache 上最容易悄悄不成立（所以清单由 index.php 现生成）
 *   2. start_url / scope / 图标尺寸少一样，安装按钮就不会出现
 *   3. 离线页必须自包含：它是在「什么都拉不到」的时候才被拿出来的，
 *      引用任何 /assets/ 资源都会让它变成一张白页
 *   4. sw.js 必须放过 /api/ 和跨域请求——缓存住接口等于缓存住别人的登录态
 */

t_section('PWA：应用清单');

$root = dirname(__DIR__, 2);

$res = t_request('GET', '/manifest.webmanifest');
t_eq(200, $res['status'], 'GET /manifest.webmanifest 返回 200');
t_contains(isset($res['headers']['content-type']) ? $res['headers']['content-type'] : '',
    'application/manifest+json', '清单的 Content-Type 是 manifest+json（不是 octet-stream）');
$manifest = $res['json'];
t_assert(is_array($manifest), '清单是合法 JSON，能解析成对象');

t_assert(!empty($manifest['name']), '有 name（安装框里显示的全名）');
t_assert(!empty($manifest['short_name']), '有 short_name（桌面图标下面那行字）');
t_eq('我的空间', isset($manifest['short_name']) ? $manifest['short_name'] : '', 'short_name 用的是站内同名，不是英文代号');
t_eq('/', isset($manifest['start_url']) ? $manifest['start_url'] : null, 'start_url 是站点根：点图标打开首页');
t_eq('/', isset($manifest['scope']) ? $manifest['scope'] : null, 'scope 是站点根：四个页面都算「应用之内」');
t_eq('standalone', isset($manifest['display']) ? $manifest['display'] : null, 'display = standalone（独立窗口、没有地址栏）');
t_eq('/', isset($manifest['id']) ? $manifest['id'] : null, '有稳定的 id（改名不至于变成另一个应用）');
foreach (['background_color', 'theme_color'] as $key) {
    t_assert(!empty($manifest[$key]) && $manifest[$key][0] === '#', $key . ' 是一个颜色值');
}

$icons = isset($manifest['icons']) ? $manifest['icons'] : [];
t_assert(count($icons) >= 2, '图标至少两档（192 与 512，缺一档浏览器不给装）');
$sizes = [];
foreach ($icons as $icon) {
    $sizes[] = $icon['purpose'] . ':' . $icon['sizes'];
    t_assert(!empty($icon['src']) && $icon['src'][0] === '/',
        '图标地址是绝对路径：' . (isset($icon['src']) ? $icon['src'] : '（空）'));
    t_eq('image/png', isset($icon['type']) ? $icon['type'] : null, '图标声明了 PNG 类型：' . (isset($icon['src']) ? $icon['src'] : ''));
}
t_assert(in_array('any:192x192', $sizes, true), '有 192x192 的 any 图标');
t_assert(in_array('any:512x512', $sizes, true), '有 512x512 的 any 图标');
t_assert(in_array('maskable:512x512', $sizes, true), '有 maskable 图标（系统按自己的形状裁圆角时不会切到主体）');

// 图标不能只写在清单里，文件得真在、而且尺寸对得上
foreach (['icon-192.png' => 192, 'icon-512.png' => 512] as $file => $expect) {
    $path = $root . '/public/assets/img/' . $file;
    t_assert(is_file($path), '图标文件在：assets/img/' . $file);
    if (is_file($path)) {
        $info = getimagesize($path);
        t_eq([$expect, $expect], $info ? [(int) $info[0], (int) $info[1]] : null,
            $file . ' 的实际像素是 ' . $expect . 'x' . $expect);
    }
    $res = t_request('GET', '/assets/img/' . $file);
    t_eq(200, $res['status'], '清单里的图标能取到：/assets/img/' . $file);
    t_contains(isset($res['headers']['content-type']) ? $res['headers']['content-type'] : '',
        'image/png', $file . ' 按图片返回');
}

// 旧地址也要能用：已经装过的人，系统里存的是当初那个 URL
$res = t_request('GET', '/manifest.json');
t_eq(200, $res['status'], '旧地址 /manifest.json 也返回 200（已安装的应用不会掉清单）');
t_eq($manifest, $res['json'], '两个地址给出的是同一份清单');

$res = t_request('GET', '/manifest.webmanifest/extra');
t_eq(404, $res['status'], '清单下面再挂路径仍然 404（没有变成任意文件读取）');

t_section('PWA：五个页面都挂了入口');

foreach (['/' => '首页', '/games' => '游戏板块', '/study' => '学习板块',
        '/read' => '阅读板块', '/software' => '软件仓库'] as $path => $label) {
    $res = t_request('GET', $path);
    t_contains($res['body'], 'rel="manifest"', $label . '里有清单链接');
    t_contains($res['body'], 'name="theme-color"', $label . '里有 theme-color（手机状态栏跟着变色）');
    t_contains($res['body'], '/assets/js/pwa.js', $label . '加载了 pwa.js');
}

t_section('PWA：Service Worker');

$res = t_request('GET', '/sw.js');
t_eq(200, $res['status'], 'GET /sw.js 返回 200');
t_contains(isset($res['headers']['content-type']) ? $res['headers']['content-type'] : '',
    'javascript', 'sw.js 按脚本类型返回（类型不对浏览器会拒绝注册）');
// SW 的管辖范围由它自己的 URL 决定：放在子目录里就只能管那个子目录，
// 于是「/games 离线能开」这件事会莫名其妙地不成立。所以它必须在根目录。
t_assert(is_file($root . '/public/sw.js'), 'sw.js 就在站点根（不是 public/assets/js/ 下面）');

$sw = is_file($root . '/public/sw.js') ? file_get_contents($root . '/public/sw.js') : '';
t_contains($sw, "request.method !== 'GET'", 'SW 只接管 GET（写操作不该被拦截重放）');
t_contains($sw, "indexOf('/api/') === 0", 'SW 明确放过 /api/：接口一律走真实网络');
t_contains($sw, 'url.origin !== self.location.origin', 'SW 放过跨域请求');
t_contains($sw, "caches.delete", 'SW 激活时清掉老版本缓存（不然每改一次多留一份全量）');
t_contains($sw, 'response.status !== 200', '只有 200 才落缓存（404 也会被存住的话就再也翻不了身）');
t_assert(strpos($sw, 'eval(') === false, 'SW 里没有 eval');

$res = t_request('GET', '/offline.html');
t_eq(200, $res['status'], 'GET /offline.html 返回 200（SW 预缓存的就是它）');
t_contains(isset($res['headers']['content-type']) ? $res['headers']['content-type'] : '',
    'text/html', '离线页按 HTML 返回');
t_contains($res['body'], '连不上后端', '离线页说清了「现在是什么情况」');
// 这一条是离线页的全部意义：它是在什么都拉不到的时候才被拿出来的
t_assert(strpos($res['body'], '/assets/') === false,
    '离线页不引用任何 /assets/ 资源（引用了就会在离线时变成一张白页）');
t_assert(strpos($res['body'], '<script') === false,
    '离线页没有脚本：CSP 的 script-src 是 ‘self’，这里放内联脚本只会被拦掉');

t_section('PWA：脚本语法（需要 node，没有就跳过）');

$checked = 0;
foreach (['public/sw.js', 'public/assets/js/pwa.js'] as $file) {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(t_command(['node', '--check', $root . '/' . $file]), $descriptors, $pipes, $root);
    if (!is_resource($process)) {
        break;
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if (stripos($output, 'not recognized') !== false || stripos($output, "'node' ") !== false
        || strpos($output, 'command not found') !== false) {
        echo "  - 跳过：这台机器上没有 node，SW 脚本只做了内容检查\n";
        break;
    }
    $checked++;
    t_eq(0, $code, 'node --check ' . $file . ($output ? '：' . trim($output) : ''));
}
if ($checked === 0) {
    echo "  - 跳过：没有拿到 node 的判定结果\n";
}

<?php
/**
 * 前置控制器：整个项目唯一对外暴露的 PHP 入口。
 *
 * 两种跑法共用这一个文件：
 *  1) PHP 内置服务器（本地开发，推荐）
 *       php -S localhost:8000 -t public public/index.php
 *     这时本文件同时充当“路由脚本”：命中 /api/* 就走接口，
 *     命中真实静态文件用 return false 交回服务器原样输出。
 *  2) Apache / Nginx（正式部署）
 *     配合 public/.htaccess 把所有“不存在的文件”重写到 index.php，
 *     真实静态文件（css/js/图片）由 Web 服务器自己返回，性能更好。
 *
 * 注意：src/ 与 database/ 都在本目录之外，浏览器无法直接访问，
 * 配置文件和后端源码因此不会被下载走。
 */

require_once __DIR__ . '/../src/bootstrap.php';

$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
if ($path === null || $path === false || $path === '') {
    $path = '/';
}

// ---------- 1. 接口请求 ----------
if (preg_match('#^/api(/|$)#', $path)) {
    // api.php 自己会下发安全响应头并处理异常
    require __DIR__ . '/../src/api.php';
    return;
}

// ---------- 2. 静态资源 ----------
// 只有确认请求解析后仍落在 public/ 目录内，才把它当文件交出去，
// 这一步是防目录穿越（../../src/config.local.php 之类）。
$candidate = realpath(__DIR__ . '/' . ltrim($path, '/'));
$docroot = realpath(__DIR__);
if ($candidate !== false && $docroot !== false && is_file($candidate)) {
    $normalized = str_replace('\\', '/', $candidate);
    $rootNormalized = str_replace('\\', '/', $docroot);
    $inside = strpos($normalized, $rootNormalized . '/') === 0;
    $isScript = strtolower(substr($normalized, -4)) === '.php';
    if ($inside && !$isScript && $path !== '/index.php') {
        // 静态文件交给服务器自己发，这里不动响应头：
        // 让服务器按扩展名给出正确的 Content-Type 和缓存策略（Apache / Nginx 由配置补安全头）
        return false;
    }
}

// 从这里开始的都是本文件自己生成的页面，统一带上安全响应头
send_security_headers();

// ---------- 2.5 应用清单（PWA）----------
// 清单不写成 public/manifest.json，而是由这里现生成，三个原因：
//   1) 响应的 Content-Type 必须是 JSON 类型。PHP 内置服务器的扩展名表里没有
//      .webmanifest，Apache / Nginx 的默认表里也常常没有——本地能装、线上装不上，
//      是最难查的那类差异。写在这里就等于三种环境给同一个头。
//   2) .htaccess 与 nginx.conf.example 都把 public/ 下的 *.json 一律拒掉
//      （防止有人误把配置文件放进去）。真放一个 manifest.json 会被那条规则挡住。
//   3) 应用名 / 主题色只在这一处定义，不用去三个 HTML 里各改一遍。
// 两种地址都收：.webmanifest 是规范建议的后缀，/manifest.json 照顾已经装上的旧客户端。
if ($path === '/manifest.webmanifest' || $path === '/manifest.json') {
    serve_manifest();
    return;
}

// ---------- 3. 页面 ----------
// 页面用一张「路径 → 模板文件」的表来映射，而不是一个路径写一段 if：
// 以后加二级页面只要往这张表里加一行，不用再动下面的逻辑。
$pages = [
    '/' => 'index.html',
    '/index.html' => 'index.html',
    '/index.php' => 'index.html',
    '/games' => 'games.html',          // 游戏板块：二级页面
    '/games/' => 'games.html',
    '/games.html' => 'games.html',
    '/study' => 'study.html',          // 学习板块（番茄钟）：二级页面
    '/study/' => 'study.html',
    '/study.html' => 'study.html',
    '/read' => 'read.html',            // 小说阅读（粘贴正文自动分章）：二级页面
    '/read/' => 'read.html',
    '/read.html' => 'read.html',
];

if (isset($pages[$path])) {
    serve_page($pages[$path]);
    return;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>404</title></head>'
    . '<body style="font-family:sans-serif;padding:60px;text-align:center">'
    . '<h1>404</h1><p>没有找到这个地址：<code>' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '</code></p>'
    . '<p><a href="/">回到首页</a></p></body></html>';

/**
 * 输出一个页面模板。
 *
 * 页面本身是纯静态的 HTML（public/index.html、public/games.html），
 * 由这里统一发出去，好处是：
 *   · 静态资源可以直接被 Web 服务器命中（线上更快）
 *   · 以后想在服务端注入变量（比如站点标题、当前登录用户）只需改这一处
 * 注意 /games 是「没有扩展名的路径」，PHP 内置服务器不会把它当文件，
 * 所以它一定走到这里；而 /games.html 这种真实存在的文件会先被上面的静态分支
 * 直接交给服务器返回，两条路径的内容是同一个文件。
 */
function serve_page($file)
{
    $full = __DIR__ . '/' . $file;
    if (!is_file($full)) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '页面模板 public/' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . ' 不存在，请检查项目是否完整';
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');   // 开发期避免浏览器拿旧缓存，上线后可改成协商缓存
    readfile($full);
}

/**
 * 输出 PWA 应用清单（manifest）。
 *
 * 字段说明：
 *   start_url 是安装后点图标打开的地址，scope 圈定「哪些页面算这个应用之内」——
 *     都写站点根，因为几个页面本来就同一个站；写成 /games 会让首页变成外部站点。
 *   display standalone = 没有地址栏的独立窗口，这是「像个客户端」的关键一档
 *     （fullscreen 会把状态栏也盖掉，车机上容易挡住系统时间，所以不选）。
 *   图标给 192 与 512 两档：小于这个尺寸系统桌面会糊，缺一档浏览器会拒绝安装。
 *   purpose 分 any 与 maskable 两份：maskable 要求主体落在中央安全区里，
 *     系统会按自己的形状（圆、方、水滴）裁一刀，所以那张图的边框不能贴着画布边缘。
 */
function serve_manifest()
{
    $manifest = [
        'name' => '我的空间',
        'short_name' => '我的空间',
        'description' => '自己的一个小站：留言板、备忘录、游戏板块、学习板块与番茄钟。',
        'id' => '/',
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'any',
        'background_color' => '#0a0d1c',
        'theme_color' => '#0a0d1c',
        'icons' => [
            ['src' => '/assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ];

    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-store');
    // 不转义斜杠与中文：这份文件是给人对着 DevTools 读的，\" 和 \u6211 都只会添乱
    echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

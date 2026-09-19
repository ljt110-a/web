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

$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
if ($path === null || $path === false || $path === '') {
    $path = '/';
}

// ---------- 1. 接口请求 ----------
if (preg_match('#^/api(/|$)#', $path)) {
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
        return false;      // PHP 内置服务器：原样返回该文件
    }
}

// ---------- 3. 页面 ----------
if ($path === '/' || $path === '/index.html' || $path === '/index.php') {
    serve_home_page();
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
 * 输出首页。页面本身是纯静态的 public/index.html，
 * 由这里统一发出去，好处是以后想在服务端注入变量（如站点标题）只需改这一处。
 */
function serve_home_page()
{
    $file = __DIR__ . '/index.html';
    if (!is_file($file)) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '首页文件 public/index.html 不存在，请检查项目是否完整';
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');   // 开发期避免浏览器拿旧缓存，上线后可改成协商缓存
    readfile($file);
}

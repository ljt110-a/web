<?php
/**
 * 测试用的假源站：一个只监听 127.0.0.1 的最小 HTTP 服务器。
 *
 *   php -S 127.0.0.1:8124 tests/fake_http.php
 *
 * 为什么要有这么一个东西：软件仓库的「服务器代下载」整条链路都要出网，
 * 而测试不能依赖外网——CI 上可能没网、GitHub 会限流、真实安装包有几百 MB。
 * 有了它，「大小超限」「分块传输」「重定向跳出白名单」「文件名带穿越」这些分支
 * 都能在几秒钟内真的跑一遍，跑的是 src/softnet.php 里那套真实的 socket 客户端，
 * 而不是把某个函数替换成桩之后自欺欺人。
 *
 * 和 tests/fake_smtp.php 是同一个思路：被测代码走的是真协议，
 * 只是对面那台「服务器」是我们自己起的。
 *
 * 一个命名约定：凡是「会被当成安装包来抓」的路径都带扩展名（/dl/2048.exe）。
 * 因为被测代码在建立连接之前要先从地址认出包类型——真实世界里
 * 一个没有扩展名的地址确实抓不了，所以这里必须还原出那个形状，
 * 而不是随手写个 /size/2048 让用例在错误的原因上通过。
 * /noext 是唯一故意不带扩展名的路径，它验的正是「认不出类型就先拒」。
 */

$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

/** 一段可预测的正文：测试靠它校验字节数与 sha256，不用真的准备一个安装包 */
function fake_body($bytes)
{
    $unit = 'web-one-fake-installer-payload-0123456789ABCDEF';
    return substr(str_repeat($unit, (int) ceil($bytes / strlen($unit)) + 1), 0, $bytes);
}

/** 发一个正常的文件响应 */
function fake_send($bytes, $name)
{
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . $bytes);
    echo fake_body($bytes);
}

if ($method !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain');
    echo 'only GET';
    return;
}

// /dl/2048.exe —— 声明并真的发这么多字节，包名按「官方安装包」的样子给
if (preg_match('#^/dl/(\d+)(?:\.([a-z0-9]+))?$#', $path, $m)) {
    $ext = isset($m[2]) && $m[2] !== '' ? $m[2] : '';
    fake_send((int) $m[1], 'demo-app-' . $m[1] . ($ext === '' ? '' : '.' . $ext));
    return;
}

// /chunked.exe —— 分块传输，没有 Content-Length
if ($path === '/chunked.exe') {
    header('Content-Type: application/octet-stream');
    // 刻意不设 Content-Length，也不手写 Transfer-Encoding：
    // 内置服务器会自己把响应切成分块发出去，正好还原 GitHub 资产下载那种形状。
    echo fake_body(4096);
    return;
}

// /redirect-self.exe —— 跳到同一台假源站上的正常文件（这一跳应当被跟住）
if ($path === '/redirect-self.exe') {
    header('Location: /dl/2048.exe', true, 302);
    return;
}

// /redirect-chain.exe —— 两跳，验证「最多跟几跳」不是摆设
if ($path === '/redirect-chain.exe') {
    header('Location: /redirect-self.exe', true, 302);
    return;
}

// /redirect-evil.exe —— 跳到白名单之外的端口（这一跳必须被拒）
if ($path === '/redirect-evil.exe') {
    header('Location: http://127.0.0.1:19/nope.exe', true, 302);
    return;
}

// /redirect-internal.exe —— 跳到一个「主机名写着 localhost」的地址。
// 它和上面那条走的是不同的拒绝理由：主机名根本不在白名单里，连解析都不用做
if ($path === '/redirect-internal.exe') {
    header('Location: http://localhost:19/nope.exe', true, 302);
    return;
}

// /redirect-loop.exe —— 自己跳自己：跳数上限必须真的把循环掐断
if ($path === '/redirect-loop.exe') {
    header('Location: /redirect-loop.exe', true, 302);
    return;
}

// /redirect-noloc.exe —— 3xx 但不给 Location：不能无限转，也不能当正文
if ($path === '/redirect-noloc.exe') {
    http_response_code(302);
    header('Content-Type: text/plain');
    echo 'where?';
    return;
}

// /empty.exe —— 200 但一个字节都没有：远端返回空文件也要被拒
if ($path === '/empty.exe') {
    header('Content-Type: application/octet-stream');
    header('Content-Length: 0');
    return;
}

// /missing.exe —— 404，抓包要报「远端返回 HTTP 404」
if ($path === '/missing.exe') {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'not here';
    return;
}

// /slow.exe —— 先回头部再拖住正文，用来验证「等待远端响应超时」这条分支。
// 内置服务器是单线程的，这里睡多久就会把后面的用例挡多久，所以只睡到
// 「比客户端超时明显长一点」就够（测试里 soft_fetch_timeout 压到 3 秒）。
if ($path === '/slow.exe') {
    set_time_limit(0);
    header('Content-Type: application/octet-stream');
    header('Content-Length: 4096');
    echo fake_body(16);
    flush();
    sleep(6);
    return;
}

// /tricky.exe —— 文件名里带目录穿越与引号：落盘名必须被洗干净
if ($path === '/tricky.exe') {
    header('Content-Type: application/octet-stream');
    header('Content-Length: 1024');
    header('Content-Disposition: attachment; filename="../../../../windows/system32/hack.exe"');
    echo fake_body(1024);
    return;
}

// /shell.php —— 一个「看起来像源码」的地址：扩展名不在白名单，抓之前就该被拒
if ($path === '/shell.php') {
    fake_send(16, 'shell.php');
    return;
}

// /notes.txt —— 一个纯文本：同样是被拒的扩展名（访客点了打不开）
if ($path === '/notes.txt') {
    fake_send(64, 'notes.txt');
    return;
}

// /noext —— 地址里没有扩展名，且响应头也不给文件名：认不出类型就该在连接之前拒
if ($path === '/noext') {
    header('Content-Type: application/octet-stream');
    header('Content-Length: 1024');
    echo fake_body(1024);
    return;
}

// /json/release —— 一份假的 GitHub release JSON，给元数据解析路径用
if ($path === '/json/release') {
    header('Content-Type: application/json');
    echo json_encode([
        'tag_name' => 'v2.3.4',
        'assets' => [
            // SHA256SUMS 没有扩展名：不是安装包，必须被跳过
            ['name' => 'SHA256SUMS', 'size' => 128, 'browser_download_url' => 'http://127.0.0.1:8124/noext'],
            ['name' => 'demo-source.tar.gz', 'size' => 900, 'browser_download_url' => 'http://127.0.0.1:8124/dl/900.gz'],
            ['name' => 'demo-app-win-x64-setup.exe', 'size' => 2048, 'browser_download_url' => 'http://127.0.0.1:8124/dl/2048.exe'],
            // 只有 URL 没有 name 的资产：名字要从 URL 末段取
            ['size' => 512, 'browser_download_url' => 'http://127.0.0.1:8124/dl/512.msi'],
        ],
    ]);
    return;
}

// /json/gitee —— Gitee 的另一种形状：资产挂在 attachables 下，字段叫 url
if ($path === '/json/gitee') {
    header('Content-Type: application/json');
    echo json_encode([
        'tag_name' => 'v1.0.0-beta 测试版',   // 带空格与中文：版本号该被宽松地丢掉，而不是让抓包失败
        'attachables' => [
            ['name' => 'demo-app-linux.deb', 'size' => 1500, 'url' => 'http://127.0.0.1:8124/dl/1500.deb'],
        ],
    ]);
    return;
}

// /json/nope —— 一个没有可用资产的 release
if ($path === '/json/nope') {
    header('Content-Type: application/json');
    echo json_encode(['tag_name' => 'v9', 'assets' => [['name' => 'notes.txt', 'browser_download_url' => 'http://127.0.0.1:8124/notes.txt']]]);
    return;
}

// ------------------------------------------------------------
// 图标这一路的假响应
//
// 「智能获取」取的是真图片，所以这里发的必须是货真价实的文件头：
// 被测代码只认前几个字节，随手写一串 'pngpng' 会让用例在错误的原因上通过。
// 下面这些字节由 base64 还原，等价于用编辑器真导出过一张 1×1 的图。
// ------------------------------------------------------------

/** 一张 1×1 的透明 PNG（68 字节）——与真文件头逐字节一致 */
function fake_png()
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

/** 一张 1×1 的 GIF */
function fake_gif()
{
    return base64_decode('R0lGODdhAQABAIAAABR63AAAACwAAAAAAQABAAACAkQBADs=');
}

/** 一个把 PNG 塞进去的 .ico：文件头是 00 00 01 00，与 GitHub 上常见的fav图标同一种形状 */
function fake_ico()
{
    $png = fake_png();
    return pack('v3', 0, 1, 1) . pack('C4v2V2', 1, 1, 0, 0, 1, 32, strlen($png), 22) . $png;
}

/** 发一张图片：只有文件头要紧，Content-Type 故意给得含糊（被测代码不看它） */
function fake_image($binary, $type = 'application/octet-stream')
{
    header('Content-Type: ' . $type);
    header('Content-Length: ' . strlen($binary));
    echo $binary;
}

// /avatar.png —— 仓库头像那种位图
if ($path === '/avatar.png') {
    fake_image(fake_png(), 'image/png');
    return;
}

// /avatar.gif —— 另一种格式：换格式覆盖旧图标这条规则要靠它
if ($path === '/avatar.gif') {
    fake_image(fake_gif(), 'image/gif');
    return;
}

// /favicon.ico —— 官网 favicon 的形状
if ($path === '/favicon.ico') {
    fake_image(fake_ico(), 'image/vnd.microsoft.icon');
    return;
}

// /icon.svg —— 可以带脚本的 XML：从远端取回来也必须被拒（等于在自己域名下发一段 HTML）
if ($path === '/icon.svg') {
    fake_image('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml');
    return;
}

// /icon-big.png —— 文件头是对的，但体量超过单个图标的上限：中途就该掐断
if ($path === '/icon-big.png') {
    fake_image(fake_png() . str_repeat('x', 400000), 'image/png');
    return;
}

// /icon-empty.png —— 200 但零字节：不能当图标存下来
if ($path === '/icon-empty.png') {
    header('Content-Type: image/png');
    header('Content-Length: 0');
    return;
}

// /icon-garbage.png —— 名字叫 png，内容是一段文本：按内容判断就该拒
if ($path === '/icon-garbage.png') {
    fake_image('<?php echo "hi"; ?>' . str_repeat(' ', 64), 'image/png');
    return;
}

// /json/github-repo —— GitHub 的仓库信息：头像在 owner.avatar_url
// 顺带把「自动识别信息」要读的那几栏也摆在这里：真实接口就是这一份形状
if ($path === '/json/github-repo') {
    header('Content-Type: application/json');
    echo json_encode([
        'full_name' => 'demo/app',
        'owner' => ['login' => 'demo', 'avatar_url' => 'http://127.0.0.1:8124/avatar.png'],
        'description' => "一个用来做测试的假仓库\r\n换行会被压成一行",
        'homepage' => 'http://127.0.0.1:8124/demo/app',
        'stargazers_count' => 1234,
        'license' => ['key' => 'mit', 'spdx_id' => 'MIT', 'name' => 'MIT License'],
    ]);
    return;
}

// /json/github-repo/releases/latest —— 最新 release 的 tag
if ($path === '/json/github-repo/releases/latest') {
    header('Content-Type: application/json');
    echo json_encode(['tag_name' => 'v4.5.8', 'name' => '4.5.8']);
    return;
}

// /json/gitee-repo —— Gitee 的形状：头像挂在 namespace 下，
// 计数字段叫 star_count、协议直接是一个字符串，字段名与 GitHub 全不一样
if ($path === '/json/gitee-repo') {
    header('Content-Type: application/json');
    echo json_encode([
        'full_name' => 'demo/app',
        'namespace' => ['name' => 'demo', 'avatar_url' => 'http://127.0.0.1:8124/avatar.gif'],
        'description' => 'Gitee 上的一份简介',
        'homepage' => 'http://127.0.0.1:8124/demo/gitee',
        'star_count' => '88',
        'license' => 'MulanPSL-2.0',
    ]);
    return;
}

// /json/gitee-repo/releases/latest —— 版本号前面不带 v
if ($path === '/json/gitee-repo/releases/latest') {
    header('Content-Type: application/json');
    echo json_encode(['tag_name' => '1.2.0']);
    return;
}

// /json/no-release —— 只发 tag、不发 release 的仓库：仓库信息是好的，那一跳 404
if ($path === '/json/no-release') {
    header('Content-Type: application/json');
    echo json_encode([
        'full_name' => 'demo/app',
        'description' => '只发 tag 不发 release',
        'stargazers_count' => 7,
    ]);
    return;
}

// /json/no-release/releases/latest —— 不发 release 的仓库是多数，这一跳 404
if ($path === '/json/no-release/releases/latest') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Not Found']);
    return;
}

// /json/blank-repo —— 200、是 JSON，但一栏都认不出来
if ($path === '/json/blank-repo') {
    header('Content-Type: application/json');
    echo json_encode(['full_name' => 'demo/app', 'fork' => false]);
    return;
}

// /json/blank-repo/releases/latest
if ($path === '/json/blank-repo/releases/latest') {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'no releases';
    return;
}

// /json/odd-repo —— 远端脏数据：协议说不清、官网不是地址、简介超长、tag 不是版本号
if ($path === '/json/odd-repo') {
    header('Content-Type: application/json');
    echo json_encode([
        'description' => str_repeat('很长的简介', 80),
        'homepage' => 'javascript:alert(1)',
        'stargazers_count' => -5,
        'license' => ['key' => 'other', 'spdx_id' => 'NOASSERTION', 'name' => 'Other'],
    ]);
    return;
}

// /json/odd-repo/releases/latest
if ($path === '/json/odd-repo/releases/latest') {
    header('Content-Type: application/json');
    echo json_encode(['tag_name' => 'nightly-latest']);
    return;
}


// /json/user-repo —— 只有 user 字段的另一种形状（老 API 版本会这样返回）
if ($path === '/json/user-repo') {
    header('Content-Type: application/json');
    echo json_encode(['full_name' => 'demo/app', 'user' => ['avatar_url' => 'http://127.0.0.1:8124/favicon.ico']]);
    return;
}

// /json/no-avatar —— 一个不带任何头像字段的仓库信息
if ($path === '/json/no-avatar') {
    header('Content-Type: application/json');
    echo json_encode(['full_name' => 'demo/app', 'description' => '这里没有头像']);
    return;
}

// /json/broken —— 200 但不是一份 JSON
if ($path === '/json/broken') {
    header('Content-Type: application/json');
    echo '<html>抱歉，请先登录</html>';
    return;
}

// /missing-icon.png —— 404：某个来源没有图标是常事，不该把整件事报错
if ($path === '/missing-icon.png') {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'no icon here';
    return;
}

http_response_code(418);
header('Content-Type: text/plain');
echo "unknown path: {$path}\n";

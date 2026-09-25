<?php
/**
 * 受控出网：软件仓库要替管理员把远端的安装包、图标、仓库元数据抓到本机来，
 * 这一层是整个项目唯一的出口。
 *
 * 为什么用 stream_socket_client 自己拼 HTTP，而不用 curl_* 或 file_get_contents：
 *   1) 不新增扩展依赖。本项目的安装检查只认 pdo_mysql，curl 在不少 phpstudy 环境里是关着的。
 *   2) 更要紧的是：curl 与 allow_url_fopen 都是「按域名解析并连接」的，
 *      而这里要先自己解析、看过解析出来的 IP，再按 IP 建连。
 *      否则主机白名单形同虚设——只要某一次解析返回 127.0.0.1（DNS 重绑定，
 *      或域名本身就把 A 记录指向内网），服务器就会替攻击者去敲内网的门。
 *      Host 头由这里写死成白名单里的那个域名，TLS 的 SNI 与证书校验也按域名做，
 *      两件事并不冲突：连接按 IP，身份按域名。
 *
 * 四条闸门，每一条都在 tests/cases/17-software.php 里有对应用例：
 *   · 只允许 http / https，且主机必须在 soft_fetch_hosts 白名单里（精确匹配，不做后缀比对）
 *   · 解析出来的 IP 不能是回环、私网、链路本地、组播一类的内网地址
 *   · 每一跳重定向都重新过一遍上面两条（否则 302 就是白名单的旁路）
 *   · 字节数边读边数，超过上限立刻中断并删掉半成品
 */

/**
 * 一条带缓冲的响应读取通道。
 *
 * 为什么要专门有这么一个类而不是 fread 一把梭：读响应头时必然会把后面的正文
 * 一起读进缓冲区（fread 一次 1024 字节，哪会正好停在 \r\n\r\n）。
 * 那些字节一旦丢掉，正文开头就缺了一截——安装包会变成一个校验不过的坏文件，
 * 而且只在「头部和正文挤在同一个 TCP 包里」时出现，最难复现。
 * 所以所有读取都走这一个缓冲区。
 */
class SoftnetConn
{
    /** @var resource */
    private $socket;
    /** @var string */
    private $buffer = '';
    /** @var bool */
    private $eof = false;

    public function __construct($socket)
    {
        $this->socket = $socket;
    }

    /** 往下读一次，填充缓冲区；返回是否还有数据可拿 */
    private function fill()
    {
        if ($this->eof) {
            return false;
        }
        // 只要还在从远端读到字节，就把 PHP 自己的执行时限往前拨一次。
        // 原因分平台：Windows 上 max_execution_time 算的是墙上时间，一次几十秒的抓包
        // 会被 PHP 在 30 秒处腰斩（Linux 只算 CPU 时间，等网络不计时，所以这条在 Windows 上才看得出来）。
        // 拨的这个数刻意比 soft_fetch_timeout 大一倍：卡住的连接该由 stream_set_timeout
        // 判成「等待远端响应超时」，而不是让脚本先超时——前者是一句人话，后者是一页空白 500。
        static $limit = null;
        if ($limit === null) {
            $limit = max(30, 2 * (int) cfg('soft_fetch_timeout'));
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit($limit);
        }
        $chunk = fread($this->socket, 8192);
        if ($chunk === false || $chunk === '') {
            if (stream_get_meta_data($this->socket)['timed_out']) {
                throw new ApiException('等待远端响应超时', 502);
            }
            $this->eof = true;
            return false;
        }
        $this->buffer .= $chunk;
        return true;
    }

    /** 读到行尾（不含换行符）。超过 $maxLength 视为协议错误 */
    public function readLine($maxLength = 8192)
    {
        while (true) {
            $at = strpos($this->buffer, "\n");
            if ($at !== false) {
                $line = substr($this->buffer, 0, $at);
                $this->buffer = substr($this->buffer, $at + 1);
                return rtrim($line, "\r");
            }
            if (strlen($this->buffer) > $maxLength) {
                throw new ApiException('远端返回了一行过长的数据，已中止', 502);
            }
            if (!$this->fill()) {
                // 连接在换行之前就断了：缓冲区里剩多少算多少，交给调用方判断完整性
                $line = $this->buffer;
                $this->buffer = '';
                return $line === '' ? null : $line;
            }
        }
    }

    /** 取恰好 $n 个字节；不够就返回实际拿到的（调用方据此判断「被截断」） */
    public function readBytes($n)
    {
        $out = '';
        while (strlen($out) < $n) {
            if ($this->buffer !== '') {
                $take = min($n - strlen($out), strlen($this->buffer));
                $out .= substr($this->buffer, 0, $take);
                $this->buffer = substr($this->buffer, $take);
                continue;
            }
            if (!$this->fill()) {
                return $out;
            }
        }
        return $out;
    }

    /** 读到连接关闭为止，每次最多 $chunkSize 字节；返回 null 表示没有更多数据 */
    public function readSome($chunkSize = 8192)
    {
        if ($this->buffer !== '') {
            $out = substr($this->buffer, 0, $chunkSize);
            $this->buffer = substr($this->buffer, strlen($out));
            return $out;
        }
        if ($this->eof) {
            return null;
        }
        $this->fill();
        if ($this->buffer === '') {
            return null;
        }
        $out = substr($this->buffer, 0, $chunkSize);
        $this->buffer = substr($this->buffer, strlen($out));
        return $out;
    }

    /** 发请求正文（本项目只发请求头，没有请求体） */
    public function write($data)
    {
        if (@fwrite($this->socket, $data) === false) {
            throw new ApiException('向远端发送请求失败', 502);
        }
    }

    public function close()
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}

// ------------------------------------------------------------
// 闸门：地址 → 可连接的目的地
// ------------------------------------------------------------

/** 白名单：小写主机名，条目可以带 :端口（测试用的本机假源站就靠这个） */
function softnet_host_list()
{
    $raw = strtolower((string) cfg('soft_fetch_hosts'));
    $list = [];
    foreach (explode(',', $raw) as $item) {
        $item = trim($item);
        if ($item !== '') {
            $list[] = $item;
        }
    }
    return $list;
}

/** 这个主机 + 端口在白名单里吗 */
function softnet_host_allowed($host, $port, $scheme)
{
    foreach (softnet_host_list() as $entry) {
        if (strpos($entry, ':') !== false) {
            // 带端口的条目要求精确命中，这是给测试和本机假源站留的口子
            if ($entry === $host . ':' . $port) {
                return true;
            }
            continue;
        }
        // 不带端口的条目只放行该协议的默认端口，
        // 免得「白名单里有 github.com」变成「github.com 的任何端口都能连」
        if ($entry === $host && $port === ($scheme === 'https' ? 443 : 80)) {
            return true;
        }
    }
    return false;
}

/** 内网 / 保留地址：一律不许连，除非配置里显式放行（只有测试需要那样做） */
function softnet_is_internal_ip($ip)
{
    // filter_var 的这两个标志合起来覆盖 127/8、10/8、172.16/12、192.168/16、
    // 169.254/16、0/8、240/4 与 IPv6 的环回、链路本地段，不必自己拼一张表。
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }
    // 剩下这几段 filter_var 不管，但对「服务器替你访问一个地址」来说是同一类：
    // 组播、CGNAT（一些云的内网服务与元数据接口落在这里）、基准测试段、IPv6 唯一本地地址。
    foreach (['224.0.0.0/4', '100.64.0.0/10', '198.18.0.0/15', 'fc00::/7'] as $block) {
        if (softnet_ip_in_block($ip, $block)) {
            return true;
        }
    }
    return false;
}

/** IP 是否落在某个 CIDR 段里（v4/v6 都认；家族不同直接算不匹配） */
function softnet_ip_in_block($ip, $block)
{
    list($network, $bits) = explode('/', $block, 2);
    $a = @inet_pton($ip);
    $b = @inet_pton($network);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bits = (int) $bits;
    $whole = (int) ($bits / 8);
    if ($whole > 0 && substr($a, 0, $whole) !== substr($b, 0, $whole)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($a[$whole]) & $mask) === (ord($b[$whole]) & $mask);
}

/**
 * 把一个地址解析成「能直接建连」的目的地，任何一步不合格就抛 ApiException。
 *
 * @return array ['scheme','host','port','path','ip']
 */
function softnet_target($url)
{
    if (!cfg('soft_fetch_enabled')) {
        throw new ApiException('抓取远端的功能已经关闭（配置项 soft_fetch_enabled）', 409);
    }

    $url = trim((string) $url);
    if ($url === '' || mb_strlen($url, 'UTF-8') > 500) {
        throw new ApiException('地址为空或过长', 422);
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $url)) {
        throw new ApiException('地址包含非法字符', 422);
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        throw new ApiException('地址格式不正确，要写成 http:// 或 https:// 开头', 422);
    }

    $scheme = strtolower(isset($parts['scheme']) ? $parts['scheme'] : '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new ApiException('只允许 http 或 https 地址', 422);
    }
    // userinfo（http://内网地址@github.com/）是经典的解析分歧：
    // 不同实现取主机的规则不一样，留空子就等于留歧义。
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new ApiException('地址里不允许带账号密码', 422);
    }

    $host = strtolower($parts['host']);
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

    if (!softnet_host_allowed($host, $port, $scheme)) {
        throw new ApiException('这个主机不在允许抓取的白名单里（配置项 soft_fetch_hosts）', 403);
    }

    $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
    if (isset($parts['query']) && $parts['query'] !== '') {
        $path .= '?' . $parts['query'];
    }

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        // 白名单条目本身就写着 IP（测试用）时不必再解析，直接照用
        $ip = $host;
    } else {
        $resolved = gethostbyname($host);
        if ($resolved === $host) {
            throw new ApiException('无法解析该主机的地址', 502);
        }
        $ip = $resolved;
    }
    if (softnet_is_internal_ip($ip) && !cfg('soft_fetch_allow_private')) {
        throw new ApiException('该主机解析到了内网地址，已拒绝连接', 403);
    }

    return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => $path, 'ip' => $ip];
}

/** 建一条到「已校验过的目的地」的连接，HTTPS 在这里升级成 TLS */
function softnet_connect(array $target)
{
    $timeout = max(1, (int) cfg('soft_fetch_timeout'));
    $errno = 0;
    $errstr = '';

    $options = ['http' => ['ignore_errors' => true]];
    if ($target['scheme'] === 'https') {
        $options['ssl'] = [
            // 校验必须开着：抓下来的是要发给访客的安装包，中间人换包等于投毒。
            // 连的是 IP，所以把要比对的名字显式交给 peer_name，
            // 不校验主机名的话这道 TLS 就等于白开了。
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $target['host'],
            'SNI_enabled' => true,
            'capture_peer_cert' => false,
            'timeout' => $timeout,
        ];
    }
    $context = stream_context_create($options);

    $socket = @stream_socket_client(
        'tcp://' . $target['ip'] . ':' . $target['port'],
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if ($socket === false) {
        throw new ApiException('连接远端失败：' . ($errstr !== '' ? $errstr : ('错误码 ' . $errno)), 502);
    }
    stream_set_timeout($socket, $timeout);

    if ($target['scheme'] === 'https') {
        error_clear_last();
        $upgraded = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($upgraded !== true) {
            fclose($socket);
            // 把底层那句警告带出来：这一条要能分清「证书不对」和「根本连不上」。
            // 只说「证书校验未通过」，管理员会去查 CA，而真正的原因常常是网络不通。
            // 警告里偶尔夹着 tcp://IP:443，那是解析出来的地址，不该回显到界面上。
            $last = error_get_last();
            $detail = isset($last['message']) ? (string) $last['message'] : '';
            $detail = trim((string) preg_replace('/\s+/u', ' ', $detail));
            $detail = (string) preg_replace('/^stream_socket_enable_crypto\(\):\s*/u', '', $detail);
            $detail = (string) preg_replace('#tcp://\S+#u', '远端', $detail);
            if (strlen($detail) > 120) {
                // 按字切而不是按字节：警告里可能夹着非 ASCII，substr 会切出半个字
                $detail = (string) preg_replace('/^(.{0,120}).*$/us', '$1', $detail);
            }
            throw new ApiException('与远端建立加密连接失败：'
                . ($detail !== '' ? $detail : '证书校验未通过或对方不支持 TLS'), 502);
        }
    }

    return new SoftnetConn($socket);
}

/** 请求行 + 响应头 */
function softnet_read_head(SoftnetConn $conn)
{
    $statusLine = $conn->readLine();
    if ($statusLine === null) {
        throw new ApiException('远端没有返回响应', 502);
    }

    $status = 0;
    if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $statusLine, $m)) {
        $status = (int) $m[1];
    }

    $headers = [];
    while (true) {
        $line = $conn->readLine();
        if ($line === null || $line === '') {
            break;
        }
        $at = strpos($line, ':');
        if ($at === false) {
            continue;
        }
        // 同名头只留最后一个：本项目要读的 Content-Length / Location / Content-Type 都是单值头
        $headers[strtolower(trim(substr($line, 0, $at)))] = trim(substr($line, $at + 1));
    }

    return ['status' => $status, 'headers' => $headers];
}

/**
 * 读正文，边读边交给 $sink，超过 $maxBytes 立刻停。
 *
 * @return int 实际读到的字节数
 */
function softnet_read_body(SoftnetConn $conn, array $headers, $maxBytes, callable $sink)
{
    $bytes = 0;
    $feed = function ($chunk) use ($sink, &$bytes, $maxBytes) {
        if ($chunk === '') {
            return;
        }
        $bytes += strlen($chunk);
        if ($bytes > $maxBytes) {
            throw new ApiException('内容超过 ' . $maxBytes . ' 字节的上限，已中止', 502);
        }
        $sink($chunk);
    };

    if (strpos(strtolower(isset($headers['transfer-encoding']) ? $headers['transfer-encoding'] : ''), 'chunked') !== false) {
        while (true) {
            $sizeLine = $conn->readLine(128);
            if ($sizeLine === null) {
                throw new ApiException('分块响应在结束前断开了', 502);
            }
            $at = strpos($sizeLine, ';');
            if ($at !== false) {
                $sizeLine = substr($sizeLine, 0, $at);
            }
            $size = hexdec(trim($sizeLine));
            if ($size === 0) {
                // 最后一块之后可能还有几个扩展头，读到空行为止；读不到就算了，正文已经完整
                while (true) {
                    $trailer = $conn->readLine(512);
                    if ($trailer === null || $trailer === '') {
                        break;
                    }
                }
                return $bytes;
            }
            $remaining = (int) $size;
            while ($remaining > 0) {
                $chunk = $conn->readBytes(min(8192, $remaining));
                if ($chunk === '') {
                    throw new ApiException('分块响应被截断了', 502);
                }
                $feed($chunk);
                $remaining -= strlen($chunk);
            }
            $conn->readBytes(2);   // 每块结尾的 CRLF，丢掉
        }
    }

    if (isset($headers['content-length'])) {
        $length = (int) $headers['content-length'];
        if ($length > $maxBytes) {
            throw new ApiException('内容超过 ' . $maxBytes . ' 字节的上限，已中止', 502);
        }
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = $conn->readBytes(min(8192, $remaining));
            if ($chunk === '') {
                throw new ApiException('响应在声明的长度之前断开了', 502);
            }
            $feed($chunk);
            $remaining -= strlen($chunk);
        }
        return $bytes;
    }

    // 既没有 Content-Length 也不是分块：读到连接关闭为止
    while (true) {
        $chunk = $conn->readSome();
        if ($chunk === null) {
            return $bytes;
        }
        $feed($chunk);
    }
}

/** 拼一个最简 GET 请求。Host 用域名而不是 IP，端口非默认时才带上 */
function softnet_send_request(SoftnetConn $conn, array $target, array $options)
{
    $authority = $target['host'];
    $defaultPort = $target['scheme'] === 'https' ? 443 : 80;
    if ($target['port'] !== $defaultPort) {
        $authority .= ':' . $target['port'];
    }
    $request = 'GET ' . $target['path'] . " HTTP/1.1\r\n"
        . 'Host: ' . $authority . "\r\n"
        . 'User-Agent: ' . (isset($options['agent']) ? $options['agent'] : 'web-one-softnet/1.0') . "\r\n"
        . 'Accept: ' . (isset($options['accept']) ? $options['accept'] : '*/*') . "\r\n"
        . "Connection: close\r\n\r\n";
    $conn->write($request);
}

/** 3xx 的跳转目标解析成绝对地址（相对 Location 也要能跟，但会重新过一遍白名单） */
function softnet_redirect_url(array $target, $location)
{
    $location = trim((string) $location);
    if ($location === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $location)) {
        return $location;
    }
    if (strpos($location, '//') === 0) {
        return $target['scheme'] . ':' . $location;
    }

    $authority = $target['host'];
    if ($target['port'] !== ($target['scheme'] === 'https' ? 443 : 80)) {
        $authority .= ':' . $target['port'];
    }
    $base = $target['scheme'] . '://' . $authority;
    if ($location[0] === '/') {
        return $base . $location;
    }
    $at = strrpos($target['path'], '/');
    $dir = $at === false ? '' : substr($target['path'], 0, $at);
    return $base . $dir . '/' . $location;
}

/** 这个状态码是不是「带 Location 的跳转」 */
function softnet_is_redirect($status)
{
    return in_array($status, [301, 302, 303, 307, 308], true);
}

/**
 * 取一个小响应（仓库元数据），自动跟随重定向，正文留在内存里。
 *
 * @return array ['status'=>int, 'body'=>string, 'headers'=>array, 'finalUrl'=>string]
 */
function softnet_request($url, array $options = [])
{
    $maxBytes = isset($options['maxBytes']) ? (int) $options['maxBytes'] : (int) cfg('soft_fetch_meta_bytes');
    $limit = max(0, (int) cfg('soft_fetch_max_redirect'));
    $current = (string) $url;
    $status = 0;
    $headers = [];
    $body = '';

    for ($hops = 0; ; $hops++) {
        $target = softnet_target($current);
        $conn = softnet_connect($target);
        try {
            softnet_send_request($conn, $target, $options);
            $head = softnet_read_head($conn);
            $status = $head['status'];
            $headers = $head['headers'];

            // 跳转的响应体（通常是一句「正在跳转」）不能混进正文里
            $body = '';
            if (!softnet_is_redirect($status)) {
                softnet_read_body($conn, $headers, $maxBytes, function ($chunk) use (&$body) {
                    $body .= $chunk;
                });
            }
        } finally {
            $conn->close();
        }

        if (!softnet_is_redirect($status) || $hops >= $limit) {
            break;
        }
        $next = softnet_redirect_url($target, isset($headers['location']) ? $headers['location'] : '');
        if ($next === null) {
            break;
        }
        $current = $next;
    }

    return ['status' => $status, 'body' => $body, 'headers' => $headers, 'finalUrl' => $current];
}

// ------------------------------------------------------------
// 落盘：安装包与图标
// ------------------------------------------------------------

/** 安装包允许的扩展名。挡掉 .php / .html 这类「传回来反而像个页面」的东西 */
function softnet_allowed_ext($ext)
{
    static $allow = [
        'exe', 'msi', 'msix', 'appx', 'cab', 'msp', 'zip', '7z', 'rar', 'gz', 'tgz', 'bz2', 'xz', 'zst',
        'tar', 'dmg', 'pkg', 'apk', 'deb', 'rpm', 'snap', 'appimage', 'flatpak', 'iso', 'img', 'jar', 'wasm',
    ];
    return in_array(strtolower((string) $ext), $allow, true);
}

/**
 * 把任意字符串洗成一个安全的文件名：只留 [A-Za-z0-9._-]，压掉连续的点，限长。
 *
 * 这一步不是洁癖。远端给的 Content-Disposition 文件名、URL 的最后一段都是外部输入：
 * 原样拼进路径就是目录穿越，原样拼进响应头就是响应头注入。
 * 所以先洗再存，而且落盘时的文件名永远由服务端自己拼（带数据库 id），不用这个名字。
 */
function softnet_safe_name($value, $maxLength = 120)
{
    $value = (string) $value;
    $value = basename(str_replace('\\', '/', $value));
    $value = preg_replace('/[^\w.\-]+/u', '', $value);
    $value = preg_replace('/\.{2,}/', '.', $value);
    $value = trim($value, '.-');
    if ($value === '') {
        return '';
    }
    return mb_substr($value, 0, $maxLength, 'UTF-8');
}

/** 安装包目录：不存在就建，返回绝对路径。所有落盘都只认这一个函数 */
function softnet_dir()
{
    $dir = (string) cfg('soft_dir');
    ensure_dir($dir);
    return rtrim($dir, '/\\');
}

/**
 * 把远端地址流式下载到一个本地文件里。
 *
 * 写的是 $destPath . '.part'，全部校验通过之后才 rename 成正式名：
 * 中途超时、超过大小上限、管理员关掉页面，留下的都是一个 .part，
 * 不会被当成「已经抓好了」的那一份。
 *
 * @param string $url      远端地址（会重新过一遍闸门）
 * @param string $destPath 最终落盘的绝对路径，扩展名必须在白名单里
 * @param int    $maxBytes 单包上限
 * @return array ['bytes','sha256','finalUrl','remoteName','contentType']
 */
function softnet_download_to_file($url, $destPath, $maxBytes, array $options = [])
{
    $ext = strtolower((string) pathinfo($destPath, PATHINFO_EXTENSION));
    if (!softnet_allowed_ext($ext)) {
        throw new ApiException('不支持的安装包类型：.' . $ext, 422);
    }
    if ($maxBytes < 1) {
        throw new ApiException('安装包大小上限配置不正确', 500);
    }

    $partPath = $destPath . '.part';
    @unlink($partPath);

    $hash = hash_init('sha256');
    $bytes = 0;
    $written = 0;
    $handle = @fopen($partPath, 'wb');
    if ($handle === false) {
        throw new ApiException('安装包目录不可写，请检查 var 目录的权限', 500);
    }

    $status = 0;
    $headers = [];
    $finalUrl = (string) $url;

    try {
        $sink = function ($chunk) use ($handle, &$bytes, &$written, $maxBytes, $hash) {
            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                throw new ApiException('安装包超过 ' . $maxBytes . ' 字节的上限，已中止', 502);
            }
            $written += (int) fwrite($handle, $chunk);
            hash_update($hash, $chunk);
        };

        $limit = max(0, (int) cfg('soft_fetch_max_redirect'));
        $current = (string) $url;

        for ($hops = 0; ; $hops++) {
            $target = softnet_target($current);
            $finalUrl = $current;
            $conn = softnet_connect($target);
            try {
                softnet_send_request($conn, $target, $options + ['accept' => $options['accept'] ?? 'application/octet-stream']);
                $head = softnet_read_head($conn);
                $status = $head['status'];
                $headers = $head['headers'];
                if (!softnet_is_redirect($status)) {
                    softnet_read_body($conn, $headers, $maxBytes, $sink);
                }
            } finally {
                $conn->close();
            }

            if (!softnet_is_redirect($status) || $hops >= $limit) {
                break;
            }
            $next = softnet_redirect_url($target, isset($headers['location']) ? $headers['location'] : '');
            if ($next === null) {
                break;
            }
            $current = $next;
        }

        if ($status < 200 || $status >= 300) {
            throw new ApiException('远端返回 HTTP ' . $status . '，没有拿到文件', 502);
        }
        if ($bytes === 0) {
            throw new ApiException('远端返回的是空文件', 502);
        }
        if ($written !== $bytes) {
            throw new ApiException('写入本地文件不完整，请重试', 502);
        }
        fclose($handle);
        $handle = null;

        if (!@rename($partPath, $destPath)) {
            throw new ApiException('安装包落盘失败，请检查目录权限', 500);
        }

        return [
            'bytes' => $bytes,
            'sha256' => hash_final($hash),
            'finalUrl' => $finalUrl,
            'remoteName' => softnet_remote_filename($headers, $finalUrl),
            'contentType' => strtolower((string) preg_replace('/;.*$/', '', isset($headers['content-type']) ? $headers['content-type'] : '')),
        ];
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        // 成功路径上 .part 已经被 rename 走了，这里只会清掉失败留下的半成品
        if (is_file($partPath)) {
            @unlink($partPath);
        }
    }
}

/** 从响应头或最终 URL 里挑一个文件名（已经洗过，调用方还要再拼上自己的命名） */
function softnet_remote_filename(array $headers, $finalUrl)
{
    $raw = '';
    $disposition = isset($headers['content-disposition']) ? $headers['content-disposition'] : '';
    if (preg_match('/filename\*\s*=\s*[^\'"]*\'\'([^;]+)/i', $disposition, $m)) {
        $raw = rawurldecode(trim($m[1]));
    } elseif (preg_match('/filename\s*=\s*"?([^";]+)"?/i', $disposition, $m)) {
        $raw = trim($m[1]);
    }
    if ($raw === '' && is_string($finalUrl)) {
        $path = parse_url($finalUrl, PHP_URL_PATH);
        $raw = $path === null ? '' : basename((string) $path);
    }
    return softnet_safe_name($raw);
}

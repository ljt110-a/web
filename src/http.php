<?php
/**
 * HTTP 层工具：JSON 收发、业务异常、请求参数解析、客户端信息。
 * 后端所有响应都是 JSON，前端所有写操作都走 JSON，一一对应，便于排查。
 */

/**
 * 业务异常：带一个 HTTP 状态码，路由层捕获后原样吐给前端。
 * 前端拿到的永远是 { "error": "给用户看的一句话" } 这种结构。
 */
class ApiException extends Exception
{
    /** @var int */
    private $status;

    public function __construct($message, $status = 400)
    {
        parent::__construct($message);
        $this->status = (int) $status;
    }

    public function status()
    {
        return $this->status;
    }
}

/** 输出 JSON 并结束（正常响应） */
function json_out($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // JSON_UNESCAPED_UNICODE：让中文按中文返回，而不是 \uXXXX，方便在浏览器 Network 里读
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

/** 读取 JSON 请求体；不是合法 JSON 时抛 400 */
function body_json()
{
    // 要求 JSON 提交，同时也是本项目的一道 CSRF 防线：
    // 跨站的 <form> 无法把 Content-Type 设成 application/json，
    // 再配合 Cookie 的 SameSite=Lax，第三方站点就没法冒用你的登录态发写请求。
    $ctype = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($ctype, 'application/json') === false) {
        throw new ApiException('请求必须使用 application/json 提交', 415);
    }
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new ApiException('请求体不是合法的 JSON', 400);
    }
    return $data;
}

/** 从已解析的请求体里取一个字符串字段（自动去首尾空格） */
function body_string(array $body, $key)
{
    $value = isset($body[$key]) ? $body[$key] : '';
    return is_scalar($value) ? trim((string) $value) : '';
}

/**
 * 取密码字段：刻意不去空格。
 * 用户名首尾空格是无意义的，但密码里的空格是密码本身，trim 掉会让人登不进去。
 */
function body_password(array $body, $key)
{
    $value = isset($body[$key]) ? $body[$key] : '';
    return is_scalar($value) ? (string) $value : '';
}

/**
 * 取邮箱字段：统一小写并校验格式。
 * 大小写不敏感是有意的——Alice@Example.com 和 alice@example.com 是同一个邮箱，
 * 存成一模一样的字符串才能让唯一索引真正起作用。
 */
function body_email(array $body, $key, $required = true)
{
    $value = mb_strtolower(body_string($body, $key), 'UTF-8');
    if ($value === '') {
        if ($required) {
            throw new ApiException('请填写邮箱', 422);
        }
        return null;
    }
    if (!filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value, 'UTF-8') > 120) {
        throw new ApiException('邮箱格式不正确', 422);
    }
    return $value;
}

/** 取一个整数请求体字段，并夹在 [min, max] 之间 */
function body_int(array $body, $key, $default, $min, $max)
{
    if (!isset($body[$key]) || !is_numeric($body[$key])) {
        return $default;
    }
    return max($min, min($max, (int) $body[$key]));
}

/** 取一个整数查询参数，并夹在 [min, max] 之间 */
function query_int($key, $default, $min, $max)
{
    if (!isset($_GET[$key]) || !is_numeric($_GET[$key])) {
        return $default;
    }
    return max($min, min($max, (int) $_GET[$key]));
}

/** 取一个查询字符串参数（去空格，限长，避免超长输入拖慢搜索） */
function query_string($key, $maxLength = 60)
{
    if (!isset($_GET[$key]) || !is_scalar($_GET[$key])) {
        return '';
    }
    $value = trim((string) $_GET[$key]);
    return mb_substr($value, 0, $maxLength, 'UTF-8');
}

/** 客户端 IP：本地开发时通常是 ::1 或 127.0.0.1 */
function client_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    // REMOTE_ADDR 由服务器写入，不接受 X-Forwarded-For 之类可伪造的请求头
    return substr($ip, 0, 45);
}

/**
 * 客户端 IP 的摘要。
 * UV 去重用它而不是 IP 原文：统计要的是「是不是同一个人」，
 * 不需要长期保存对方的具体地址，少存一份可识别的个人信息。
 */
function client_ip_hash()
{
    return hash('sha256', 'web-one|' . client_ip());
}

/** 当前请求的路径，去掉查询字符串 */
function request_path()
{
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $path = parse_url($uri, PHP_URL_PATH);
    return $path === null || $path === false ? '/' : $path;
}

/**
 * 数据库时间 → “2026-09-19 22:14” 这种面板上直接可显示的格式；空值给占位横线。
 * 放在这一层是因为公开接口（/api/me）和后台接口都要用它，
 * 谁都不该去依赖对方的内部函数。
 */
function format_datetime($value)
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date('Y-m-d H:i', $ts);
}

/** 请求方法，统一大写 */
function request_method()
{
    return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
}

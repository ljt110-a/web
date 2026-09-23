<?php
/**
 * 引导文件：读取配置、设置时区、加载其余模块。
 * 所有需要后端的入口（接口、安装脚本、体检脚本）都只 require 这一个文件。
 *
 * 加载顺序即依赖顺序：
 *   db 是地基 → orm 只依赖 db → http 是 JSON 收发 → security 提供日志与响应头
 *   → throttle / tokens 依赖前几者 → mailer 依赖 security 的日志 → auth 依赖上面全部
 *   → visits / messages / memos / games / study / novels 是业务 → admin / system 读业务的表
 *
 * 带幂等守卫：入口文件可能有多个（index.php 处理页面、api.php 处理接口、
 * 命令行脚本处理安装），谁先加载都不要紧，重复加载直接返回。
 * 否则第二次加载会因为函数重复声明而致命错误——而那个错误是 500，
 * 排查起来完全看不出是「被加载了两次」。
 */

if (defined('WEB_ONE_BOOTSTRAPPED')) {
    return;
}
define('WEB_ONE_BOOTSTRAPPED', true);

$CONFIG = require __DIR__ . '/config.php';

date_default_timezone_set($CONFIG['timezone']);

require __DIR__ . '/db.php';
require __DIR__ . '/orm.php';
require __DIR__ . '/http.php';
require __DIR__ . '/security.php';
require __DIR__ . '/throttle.php';
require __DIR__ . '/tokens.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/visits.php';
require __DIR__ . '/messages.php';
require __DIR__ . '/memos.php';
require __DIR__ . '/games.php';
require __DIR__ . '/study.php';
require __DIR__ . '/novels.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/system.php';

/**
 * 读取配置项。
 * @param string|null $key 传 null 时返回整个配置数组
 * @return mixed
 */
function cfg($key = null)
{
    global $CONFIG;
    if ($key === null) {
        return $CONFIG;
    }
    if (!array_key_exists($key, $CONFIG)) {
        throw new RuntimeException("配置项不存在：{$key}");
    }
    return $CONFIG[$key];
}

/** 是否生产环境（决定要不要打生产专用的安全告警） */
function is_production()
{
    return cfg('env') === 'production';
}

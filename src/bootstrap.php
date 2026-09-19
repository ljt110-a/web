<?php
/**
 * 引导文件：读取配置、设置时区、加载其余模块。
 * 所有需要后端的入口（接口、安装脚本）都只 require 这一个文件。
 */

$CONFIG = require __DIR__ . '/config.php';

date_default_timezone_set($CONFIG['timezone']);

require __DIR__ . '/db.php';
require __DIR__ . '/http.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/visits.php';
require __DIR__ . '/admin.php';

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

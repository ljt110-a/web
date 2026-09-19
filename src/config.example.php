<?php
/**
 * 配置模板：把它复制成 config.local.php，再填入你本机的真实口令。
 *   copy src\config.example.php src\config.local.php   (Windows)
 *   cp src/config.example.php src/config.local.php     (macOS / Linux)
 *
 * config.local.php 不会进入 git，密码可以放心写。
 */

return [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'web_one',
    'db_user' => 'root',
    'db_pass' => '在这里填你的 MySQL 密码',

    // 初始管理员账号，只有 database/install.php 第一次建号时会用到
    'admin_user' => 'root',
    'admin_pass' => 'root',
];

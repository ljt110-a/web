<?php
/**
 * 后端配置：数据库连接、管理员初始账号、会话有效期等。
 *
 * 真实口令不要写在本文件里（本文件会提交到 git）。
 * 请写在同目录的 config.local.php —— 它已被 .gitignore 排除，
 * 从 config.example.php 复制一份再改即可。
 */

$defaults = [
    // ---------- MySQL 连接 ----------
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'web_one',
    'db_user' => 'root',
    'db_pass' => '',           // 请在 config.local.php 里覆盖

    // ---------- 初始管理员（由 database/install.php 写入数据库）----------
    'admin_user' => 'root',
    'admin_pass' => 'root',    // 演示用弱口令，正式使用前必须改

    // ---------- 登录会话 ----------
    'session_ttl_days' => 7,   // 记住登录的天数
    'cookie_name' => 'webone_session',
    'cookie_secure' => false,  // 部署到 HTTPS 后改成 true

    // ---------- 业务规则（与前端表单校验保持一致）----------
    'username_min' => 2,
    'username_max' => 20,
    'password_min' => 6,

    // ---------- 其它 ----------
    'timezone' => 'Asia/Shanghai',
    // 出错时是否把内部错误详情返回给前端。只在本地开发打开，线上必须为 false。
    'debug' => false,
];

$localFile = __DIR__ . '/config.local.php';
$local = is_file($localFile) ? require $localFile : [];

return array_merge($defaults, is_array($local) ? $local : []);

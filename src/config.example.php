<?php
/**
 * 配置模板：把它复制成 config.local.php，再填入你本机的真实口令。
 *   copy src\config.example.php src\config.local.php   (Windows)
 *   cp src/config.example.php src/config.local.php     (macOS / Linux)
 *
 * config.local.php 不会进入 git，密码可以放心写。
 *
 * 这个文件只需要写「与默认值不同」的项；没写的项自动沿用 src/config.php 的默认值。
 * 全部可配置项见 src/config.php；也可以用 WEB_ONE_XXX 环境变量覆盖本文件。
 */

return [
    // ---------- 运行环境 ----------
    // 本地开发用 local；正式上线改成 production
    // （production 会强制把 debug 关掉、把 Cookie 的 Secure 打开）
    'env' => 'local',

    // ---------- 数据库 ----------
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'web_one',
    'db_user' => 'root',
    'db_pass' => '在这里填你的 MySQL 密码',

    // ---------- 初始管理员 ----------
    // 只有 database/install.php 第一次建号时会用到；建完就改数据库里的哈希
    'admin_user' => 'root',
    'admin_pass' => 'root',

    // ---------- 站点地址 ----------
    // 邮件里的验证 / 重置链接靠它拼绝对地址，上线后必须改成真实域名
    // 'app_url' => 'https://example.com',

    // ---------- 邮件 ----------
    // 本地开发保持 log：邮件内容写进 var/mail/YYYY-MM-DD.log，直接能复制出链接
    // 'mail_driver' => 'log',
    //
    // 换成真实 SMTP 时这样写：
    // 'mail_driver' => 'smtp',
    // 'mail_from' => 'no-reply@example.com',
    // 'mail_from_name' => 'web-one',
    // 'smtp_host' => 'smtp.example.com',
    // 'smtp_port' => 587,
    // 'smtp_user' => '你的账号',
    // 'smtp_pass' => '你的授权码',
    // 'smtp_encryption' => 'starttls',   // none | starttls | ssl

    // ---------- 调试 ----------
    // 打开后接口会把内部错误详情返回给前端，只允许本地开发开
    'debug' => true,
];

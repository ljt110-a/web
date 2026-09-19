-- ============================================================
-- web-one 全栈项目：MySQL 表结构
--
-- 这个文件只做“建表”，不建库、不写业务数据。
-- 正常安装不需要手动执行它：
--   php database/install.php        ← 会自动建库、执行本文件、写入管理员
-- 如果你想手动执行，先进入数据库再导入：
--   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS web_one DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   mysql -u root -p web_one < database/schema.sql
-- ============================================================

-- ------------------------------------------------------------
-- 第 1 张表：users（用户表）—— 存账号、密码哈希与角色
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户 ID，主键，自增',
  `username`      VARCHAR(20)  NOT NULL COMMENT '用户名，2~20 个字符，全表唯一',
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'bcrypt 密码哈希，绝不保存明文密码',
  `role`          ENUM('user','admin') NOT NULL DEFAULT 'user' COMMENT '角色：普通用户 / 管理员',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '注册时间',
  `last_login_at` DATETIME NULL DEFAULT NULL COMMENT '最后登录时间，从未登录时为 NULL',
  `login_count`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计登录次数',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- ------------------------------------------------------------
-- 第 2 张表：sessions（登录会话表）—— “记住登录状态”的服务器端依据
-- 浏览器 Cookie 里放的是随机令牌原文，这里只存令牌的 SHA-256 摘要：
-- 即使数据库整张表泄露，攻击者也拿不到可用的登录令牌。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
  `token`        CHAR(64) NOT NULL COMMENT '登录令牌的 SHA-256 摘要（不保存令牌原文）',
  `user_id`      INT UNSIGNED NOT NULL COMMENT '所属用户 ID',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '签发时间',
  `expires_at`   DATETIME NOT NULL COMMENT '过期时间，过期后后端会删除该会话',
  `ip`           VARCHAR(45) NULL DEFAULT NULL COMMENT '签发时的客户端 IP（兼容 IPv6 长度）',
  PRIMARY KEY (`token`),
  KEY `idx_sessions_user` (`user_id`),
  KEY `idx_sessions_expires` (`expires_at`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录会话表';
-- ON DELETE CASCADE：管理员删除某个用户时，MySQL 会自动删掉该用户所有会话，
-- 相当于“删除即踢下线”，后端不需要再写额外的清理代码。

-- ------------------------------------------------------------
-- 第 3 张表：visits（访问记录表）—— 页脚的“累计访问”和后台的“今日访问”
-- 一次访问插一行（明细表），统计时用 COUNT(*)。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `visits` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '访问记录 ID',
  `day`        CHAR(10) NOT NULL COMMENT '访问日期 YYYY-MM-DD，用于按天统计',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '访问时间',
  `ip`         VARCHAR(45) NULL DEFAULT NULL COMMENT '客户端 IP',
  PRIMARY KEY (`id`),
  KEY `idx_visits_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='页面访问记录表';
-- day 上加索引的原因：后台每次打开都要执行 WHERE day = 今天 的统计，
-- 没有索引就得全表扫描，访问记录多了会明显变慢。

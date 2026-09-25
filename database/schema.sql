-- ============================================================
-- web-one 全栈项目：MySQL 表结构（v7）
--
-- 这个文件只做“建表”，不建库、不写业务数据。
-- 正常安装不需要手动执行它：
--   php database/install.php        ← 建库 → 执行本文件 → 升级旧表 → 写入管理员
--   php database/migrate.php        ← 只把「已有库」升级到最新结构
-- 想手动执行，先进入数据库再导入：
--   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS web_one DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   mysql -u root -p web_one < database/schema.sql
--
-- 注意：CREATE TABLE IF NOT EXISTS 只会让「全新的库」拿到下面这些列。
-- 已经建过表的库请用 migrate.php 补列，它才是幂等升级的入口。
-- ============================================================

-- ------------------------------------------------------------
-- 第 1 张表：users（用户表）—— 账号、密码哈希、角色、邮箱与验证状态
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户 ID，主键，自增',
  `username`            VARCHAR(20)  NOT NULL COMMENT '用户名，2~20 个字符，全表唯一',
  `password_hash`       VARCHAR(255) NOT NULL COMMENT 'bcrypt 密码哈希，绝不保存明文密码',
  `role`                ENUM('user','admin') NOT NULL DEFAULT 'user' COMMENT '角色：普通用户 / 管理员',
  `email`               VARCHAR(120) NULL DEFAULT NULL COMMENT '邮箱，可空；填了就不能重复（唯一索引允许多个 NULL）',
  `email_verified_at`   DATETIME NULL DEFAULT NULL COMMENT '邮箱验证通过时间，未验证为 NULL',
  `password_changed_at` DATETIME NULL DEFAULT NULL COMMENT '最后一次改密时间，用于提醒与审计',
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '注册时间',
  `last_login_at`       DATETIME NULL DEFAULT NULL COMMENT '最后登录时间，从未登录时为 NULL',
  `login_count`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计登录次数',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_role_id` (`role`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';
-- idx_users_role_id：后台列表固定按「管理员优先、再按注册先后」排序，
-- 这个索引让 ORDER BY (role = 'admin') DESC, id ASC 也能走索引。

-- ------------------------------------------------------------
-- 第 2 张表：sessions（登录会话表）—— “记住登录状态”的服务器端依据
-- 浏览器 Cookie 里放的是随机令牌原文，这里只存令牌的 SHA-256 摘要：
-- 即使数据库整张表泄露，攻击者也拿不到可用的登录令牌。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键：唯一作用是确定登录先后顺序',
  `token`      CHAR(64) NOT NULL COMMENT '登录令牌的 SHA-256 摘要（不保存令牌原文）',
  `user_id`    INT UNSIGNED NOT NULL COMMENT '所属用户 ID',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '签发时间',
  `expires_at` DATETIME NOT NULL COMMENT '过期时间，过期后后端会删除该会话',
  `ip`         VARCHAR(45) NULL DEFAULT NULL COMMENT '签发时的客户端 IP（兼容 IPv6 长度）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sessions_token` (`token`),
  KEY `idx_sessions_user` (`user_id`),
  KEY `idx_sessions_expires` (`expires_at`),
  KEY `idx_sessions_user_expires` (`user_id`, `expires_at`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录会话表';
-- 为什么用自增 id 做主键、而不是直接用 token：
-- 「每个账号最多保留 N 个会话，超出踢掉最旧的」需要真正可靠的先后顺序，
-- 而 created_at 只精确到秒——同一秒内的几次登录排不出先后，
-- 结果是「最旧的那个」变成随机挑一个。自增 id 天然给出确定的插入顺序。
-- 顺带的好处：InnoDB 按主键聚簇存储，用自增整数比用 64 字节的随机字符串
-- 做主键更省空间，页分裂也少得多。
-- ON DELETE CASCADE：管理员删除某个用户时，MySQL 会自动删掉该用户所有会话，
-- 相当于“删除即踢下线”，后端不需要再写额外的清理代码。
-- idx_sessions_user_expires：后台的「在线状态」判断是
--   EXISTS(SELECT 1 FROM sessions WHERE user_id=? AND expires_at > NOW())
-- 加上「每用户会话数上限」也要按这两列一起筛，单列索引不够用。

-- ------------------------------------------------------------
-- 第 3 张表：visits（访问记录表）—— 页脚的“累计访问”和后台的“今日访问”
-- 一次访问插一行（明细表）：COUNT(*) 是 PV，COUNT(DISTINCT ip_hash) 是 UV。
-- 只把 IP 的 SHA-256 摘要用于去重，ip 原文保留仅为排障，可按需清空。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `visits` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '访问记录 ID',
  `day`        CHAR(10) NOT NULL COMMENT '访问日期 YYYY-MM-DD，用于按天统计',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '访问时间',
  `ip`         VARCHAR(45) NULL DEFAULT NULL COMMENT '客户端 IP',
  `ip_hash`    CHAR(64) NULL DEFAULT NULL COMMENT 'IP 的 SHA-256 摘要，用于 UV 去重',
  `user_id`    INT UNSIGNED NULL DEFAULT NULL COMMENT '已登录时的用户 ID，未登录为 NULL',
  PRIMARY KEY (`id`),
  KEY `idx_visits_day` (`day`),
  KEY `idx_visits_day_ip` (`day`, `ip_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='页面访问记录表';
-- day 上加索引的原因：后台每次打开都要执行 WHERE day = 今天 的统计，
-- 没有索引就得全表扫描，访问记录多了会明显变慢。
-- idx_visits_day_ip：COUNT(DISTINCT ip_hash) 在 (day, ip_hash) 复合索引上
-- 可以只扫索引不读数据行，UV 统计因此和 PV 一样便宜。
-- 这里刻意「不」加唯一约束：同一访客当天多次访问要如实记成多条 PV。

-- ------------------------------------------------------------
-- 第 4 张表：auth_attempts（认证尝试表）—— 登录 / 注册 / 找回密码的失败限流依据
-- 只记「尝试」本身，不记密码。规则见 src/throttle.php：
--   窗口期内同一账号失败次数超阈值 → 锁该账号
--   窗口期内同一 IP  失败次数超阈值 → 锁该 IP
-- 登录成功会清掉该账号的失败记录，过期记录由 purge_expired_attempts() 顺手清理。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auth_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '尝试记录 ID',
  `action`     VARCHAR(16)  NOT NULL COMMENT '动作：login / register / forgot',
  `identifier` VARCHAR(190) NOT NULL COMMENT '被尝试的账号标识（用户名小写化，或邮箱）',
  `ip`         VARCHAR(45)  NOT NULL COMMENT '来源 IP',
  `succeeded`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '本次尝试是否成功：1 成功 / 0 失败',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '尝试时间',
  PRIMARY KEY (`id`),
  KEY `idx_attempts_identifier` (`action`, `identifier`, `created_at`),
  KEY `idx_attempts_ip` (`action`, `ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='认证尝试记录表（限流用）';

-- ------------------------------------------------------------
-- 第 5 张表：one_time_tokens（一次性令牌表）—— 邮箱验证与密码重置共用
-- 与 sessions 同样的思路：库里只存令牌的 SHA-256 摘要，邮件里发的是原文。
-- 一封邮件对应一行；用掉则写 used_at；同一用户同一用途只保留一条有效记录
-- （发新邮件时把旧的作废，避免用户点了旧链接反而失败）。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `one_time_tokens` (
  `token`      CHAR(64) NOT NULL COMMENT '令牌的 SHA-256 摘要（不保存原文）',
  `user_id`    INT UNSIGNED NOT NULL COMMENT '所属用户 ID',
  `purpose`    ENUM('email_verify','password_reset') NOT NULL COMMENT '用途：邮箱验证 / 密码重置',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '签发时间',
  `expires_at` DATETIME NOT NULL COMMENT '过期时间',
  `used_at`    DATETIME NULL DEFAULT NULL COMMENT '使用时间，NULL 表示还没用过',
  PRIMARY KEY (`token`),
  KEY `idx_tokens_user_purpose` (`user_id`, `purpose`),
  KEY `idx_tokens_expires` (`expires_at`),
  CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='一次性令牌表（邮箱验证 / 密码重置）';

-- ------------------------------------------------------------
-- 第 6 张表：messages（留言板）
-- 发帖需要登录（作者外键非空），但读取是公开的——游客也能看留言，
-- 这样留言板才对未注册的访客有意义。
-- 删除策略随外键级联：账号被管理员删掉时，他发的留言一并消失，
-- 不留下没有作者的孤儿数据。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '留言 ID',
  `user_id`    INT UNSIGNED NOT NULL COMMENT '发布者',
  `body`       VARCHAR(500) NOT NULL COMMENT '留言正文，1~500 字',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '发布时间',
  PRIMARY KEY (`id`),
  KEY `idx_messages_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_messages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言板';
-- 列表按主键倒序取就够（自增 id 本身就代表发布时间先后），
-- 而且 ORDER BY id DESC LIMIT n 在 InnoDB 上是沿主键倒着扫，代价最低，
-- 所以刻意不额外为 created_at 建索引——少一个索引就少一份写入开销。
-- idx_messages_user_created 一个索引管两件事：
--   1) 列表页 JOIN 作者时要按 user_id 找
--   2) 发帖限流要数「这个账号最近 N 秒发了几条」——
--      直接用这张表自己的近期行数当限流依据，不必再额外维护一张计数表，
--      也就不存在「计数表和真实数据对不上」的问题。
-- 这里没有存作者名的快照：用户名在本项目里不可修改，
-- 而且账号删除时留言会级联删掉，快照永远不会用到。

-- ------------------------------------------------------------
-- 第 7 张表：memos（备忘录）—— 私有数据，每个账号只能看到自己的
-- 所有查询都必须带 user_id 条件（见 src/memos.php），
-- 是「在 SQL 的 WHERE 里限定归属」而不是「先查出来再判断是不是自己的」：
-- 后者一旦哪天忘记判断就是越权漏洞，前者忘了加条件只会查不到数据。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `memos` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '备忘录 ID',
  `user_id`    INT UNSIGNED NOT NULL COMMENT '所属用户，查询时必须作为条件',
  `title`      VARCHAR(80)  NOT NULL COMMENT '标题，1~80 字',
  `body`       VARCHAR(2000) NOT NULL DEFAULT '' COMMENT '正文，可空，最多 2000 字',
  `done`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否已完成：1 完成 / 0 未完成',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后修改时间，由 MySQL 自动维护',
  PRIMARY KEY (`id`),
  KEY `idx_memos_user_updated` (`user_id`, `done`, `updated_at`),
  CONSTRAINT `fk_memos_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='备忘录（按账号隔离）';
-- updated_at 交给 MySQL 的 ON UPDATE CURRENT_TIMESTAMP 维护，
-- 而不是让 PHP 每次记得去写——少一处「忘记更新」的可能。
-- idx_memos_user_updated：列表固定按「未完成在前、最近修改在前」排，
-- 条件里始终有 user_id，所以这是覆盖前缀索引。

-- ------------------------------------------------------------
-- 第 8 张表：games（游戏板块）
-- 这是一张「内容表」：前台只读，内容是管理员通过接口维护的。
-- 因此它没有 user_id 之类的归属字段，只有排序与上下架开关。
-- 初始内容（王者荣耀 / 原神）由 database/install.php 在表为空时写入，
-- 之后一律由接口维护，安装脚本不会去覆盖你改过的数据。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `games` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '游戏 ID',
  `slug`        VARCHAR(40)  NULL DEFAULT NULL COMMENT '英文标识，用于 /games#game-xxx 锚点；可空，可空则前端退回用 id',
  `name`        VARCHAR(40)  NOT NULL COMMENT '游戏名',
  `publisher`   VARCHAR(40)  NULL DEFAULT NULL COMMENT '厂商',
  `genre`       VARCHAR(30)  NULL DEFAULT NULL COMMENT '类型，如 MOBA / 开放世界',
  `description` VARCHAR(300) NOT NULL DEFAULT '' COMMENT '一句话简介',
  `url`         VARCHAR(300) NOT NULL COMMENT '点击卡片跳转的地址（官网）',
  `icon`        VARCHAR(8)   NULL DEFAULT NULL COMMENT '卡片上的 emoji 图标',
  `sort_order`  INT          NOT NULL DEFAULT 100 COMMENT '排序，数字小的排在前面',
  `enabled`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否上架：0 表示只在管理员视图里可见',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '添加时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_games_slug` (`slug`),
  KEY `idx_games_listing` (`enabled`, `sort_order`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='游戏板块内容表';
-- slug 允许为 NULL 是有意的：MySQL 的唯一索引允许多个 NULL，
-- 管理员懒得想英文标识时就不填，前端锚点退回用自增 id，不必为此拦一道。
-- idx_games_listing：前台查询固定是 `WHERE enabled = 1 ORDER BY sort_order, id`，
-- 这三列合成一个索引，取列表时可以直接沿索引顺序读，不用再排序。

-- ------------------------------------------------------------
-- 第 9 张表：pomodoros（番茄钟记录）—— 学习板块用，按账号隔离
-- 和 memos 一样：每条 SQL 的 WHERE 里都带 user_id，归属判断写在 SQL 里而不是 PHP 里。
-- 存「计划时长 + 实际走秒 + 是否跑完」三个数而不是只存一个数：
-- 中途放弃是番茄钟最常见的情况，留着 elapsed 才能算出「完成度」这种有用的数。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pomodoros` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录 ID',
  `user_id`    INT UNSIGNED NOT NULL COMMENT '所属用户，查询时必须作为条件',
  `subject`    VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '这一轮在学什么，可空',
  `minutes`    SMALLINT UNSIGNED NOT NULL COMMENT '计划时长（分钟）',
  `elapsed`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '实际走了多少秒，中途放弃时小于 minutes*60',
  `finished`   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否完整跑完：1 跑完 / 0 中途放弃',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '结束（或放弃）的时间',
  PRIMARY KEY (`id`),
  KEY `idx_pomodoros_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_pomodoros_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='番茄钟记录（按账号隔离）';
-- idx_pomodoros_user_created：列表和「今天完成了几个」都是按 (user_id, created_at) 取，
-- 一个索引同时服务这两条查询；按天统计用 created_at 的范围条件，不走函数，索引仍然有效。

-- ------------------------------------------------------------
-- 第 10 张表：novels（小说书架）—— 按账号隔离的私有内容
-- 存的是「分好章的一本书」的元信息 + 阅读进度，正文在 novel_chapters 里。
-- 归属判断和 memos / pomodoros 一样写在 SQL 的 WHERE 里（见 src/novels.php）。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `novels` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '书 ID',
  `user_id`            INT UNSIGNED NOT NULL COMMENT '所属用户，查询时必须作为条件',
  `title`              VARCHAR(120) NOT NULL COMMENT '书名，粘贴时可留空由正文推断',
  `chapter_count`      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '章节数，导入时算好存着：列表页要为每本书显示「共 N 章」，现算就要把整本书读一遍',
  `char_count`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '全书正文字数（不含章节标题）',
  `progress_chapter`   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '读到第几章，从 0 开始；书架上「读到 37%」就是拿它除以 chapter_count',
  `progress_paragraph` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '那一章里读到第几个自然段，从 0 开始',
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '导入时间',
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后阅读（或改名）时间，书架按它倒序',
  PRIMARY KEY (`id`),
  KEY `idx_novels_user_updated` (`user_id`, `updated_at`),
  CONSTRAINT `fk_novels_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='小说书架（按账号隔离）';
-- updated_at 靠 ON UPDATE 自动维护：保存进度就是一次 UPDATE，
-- 于是「最近在读」的排序依据不需要 PHP 记得去写时间戳。
-- 这里刻意不存粘贴的原文：分章之后的 novel_chapters 就是唯一的一份正文。
-- 存原文等于把同一本书放两份（一本 50 万字约 1.5 MB），而重新分章并不常用——
-- 分错了就在书架上删掉这一本，改改原文再粘一次。

-- ------------------------------------------------------------
-- 第 11 张表：novel_chapters（章节正文）—— 每章一行，只经由所属的书访问
-- 为什么一章一行而不是一整本存一个字段：阅读器一次只显示一章，
-- 按行取就是「一章的体积」，整本存则每次都要把几 MB 读进 PHP 再切，
-- 而且接口返回里会带上用户根本没在看的内容。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `novel_chapters` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '章节行 ID',
  `novel_id`   BIGINT UNSIGNED NOT NULL COMMENT '所属的书',
  `seq`        SMALLINT UNSIGNED NOT NULL COMMENT '章序号，从 0 开始且连续（翻页就是 seq±1，不需要按 id 猜）',
  `title`      VARCHAR(200) NOT NULL COMMENT '章节标题，识别不出来时是「第 N 章」',
  `content`    MEDIUMTEXT NOT NULL COMMENT '本章正文，段落之间用空行分隔，不含标题行',
  `char_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '本章正文字数',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chapters_novel_seq` (`novel_id`, `seq`),
  CONSTRAINT `fk_chapters_novel` FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='小说章节正文（随书级联删除）';
-- uk_chapters_novel_seq 一个索引管三件事：按 (novel_id, seq) 直接定位「第几章」，
-- 顺带给出目录所需的顺序读取，并且从结构上挡住同一本书出现两个相同的序号。
-- ON DELETE CASCADE：删一本书就是把它的章节一起删掉，不留孤儿正文占空间。
-- seq 从 0 开始且连续，所以「全书读完」就是 seq === chapter_count - 1，
-- 前端不必为了找下一章多发一次请求。

-- ------------------------------------------------------------
-- 第 12 张表：softs（软件仓库）—— 全站共享的内容表，不按账号隔离
-- 和 games 同类：前台只读，增删改一律走管理员接口，所以没有 user_id 与外键。
-- 比 games 多的是两组字段：一组是「去哪儿找这个软件」（GitHub / Gitee / 官网 / 直链），
-- 一组是「服务器已经替大家把安装包抓下来了吗」。后者只存服务端生成的文件名与摘要，
-- 绝不存管理员输入的路径——落盘位置由 src/softnet.php 一处决定（见那里的说明）。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `softs` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '软件 ID',
  `slug`            VARCHAR(60)  NULL DEFAULT NULL COMMENT '英文标识，用于 /software#soft-xxx 锚点与下载文件名，可空',
  `name`            VARCHAR(80)  NOT NULL COMMENT '软件名，卡片标题',
  `category`        VARCHAR(30)  NOT NULL DEFAULT '其它' COMMENT '分类，左侧侧栏按它分组并计数',
  `platforms`       VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '支持的平台，逗号分隔的小写代号：windows,macos,linux,android,ios,web',
  `tags`            VARCHAR(300) NOT NULL DEFAULT '' COMMENT '标签，逗号分隔，前端渲染成 #标签',
  `description`     VARCHAR(300) NOT NULL DEFAULT '' COMMENT '一句话简介',
  `homepage`        VARCHAR(300) NULL DEFAULT NULL COMMENT '官网地址，卡片上的「官网」按钮',
  `github_url`      VARCHAR(300) NULL DEFAULT NULL COMMENT 'GitHub 仓库地址，卡片上的「GitHub」按钮',
  `gitee_url`       VARCHAR(300) NULL DEFAULT NULL COMMENT 'Gitee 仓库地址（可选），自动抓包时的第二顺位',
  `download_url`    VARCHAR(500) NULL DEFAULT NULL COMMENT '安装包直链。填了它就以它为准，不再去 GitHub 挑',
  `source_mode`     VARCHAR(12)  NOT NULL DEFAULT 'auto' COMMENT '取包方式：auto 自动判断 / github / gitee / direct 只用直链 / none 不抓',
  `version`         VARCHAR(40)  NULL DEFAULT NULL COMMENT '当前版本号，卡片上 v4.5.8 那一行',
  `license`         VARCHAR(40)  NULL DEFAULT NULL COMMENT '协议，如 MIT / GPL-3.0，自动识别信息时带回来',
  `icon_file`       VARCHAR(40)  NULL DEFAULT NULL COMMENT '本站图标文件名（管理员上传或智能取包后落盘的），NULL 表示没有本地图标',
  `icon_kind`       VARCHAR(12)  NULL DEFAULT NULL COMMENT '本地图标格式，按文件头判定：png / gif / jpg / webp / ico，决定文件名与响应头；不收 svg',
  `star_count`      INT UNSIGNED NULL DEFAULT NULL COMMENT 'GitHub star 数，自动识别时带回来；NULL 表示还没抓过',
  `size_bytes`      BIGINT UNSIGNED NULL DEFAULT NULL COMMENT '安装包大小（字节）。抓到本地包就是实际字节数，否则是远端声明值',
  `file_ext`        VARCHAR(12)  NULL DEFAULT NULL COMMENT '本地安装包扩展名，只允许白名单内的值',
  `file_sha256`     CHAR(64)     NULL DEFAULT NULL COMMENT '本地安装包摘要，用于判断「远端换包了吗」',
  `file_display`    VARCHAR(160) NULL DEFAULT NULL COMMENT '浏览器另存为时看到的文件名，已脱敏成 [A-Za-z0-9._-]',
  `file_origin`     VARCHAR(500) NULL DEFAULT NULL COMMENT '实际落盘的来源地址（跟随重定向之后的最终 URL）',
  `file_fetched_at` DATETIME     NULL DEFAULT NULL COMMENT '最近一次成功抓包的时间，NULL 表示服务器还没有这一份安装包',
  `enabled`         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否上架：0 表示只在管理员视图里可见',
  `sort_order`      INT          NOT NULL DEFAULT 100 COMMENT '排序，数字小的排在前面',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '添加时间',
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后修改时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_softs_slug` (`slug`),
  KEY `idx_softs_listing` (`enabled`, `sort_order`, `id`),
  KEY `idx_softs_category` (`enabled`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='软件仓库（全站共享，含服务器代下载的安装包元信息）';
-- 侧栏要「每个分类几条」、筛选条要「每个平台几条」，这两件事都用一条 GROUP BY 解决，
-- 所以 (enabled, category) 单独给一个索引；列表本身仍走 idx_softs_listing。
-- file_fetched_at 是「有没有本地包」的唯一判据：下载按钮要不要显示、
-- 「可直链下载 N 款」这个数，都只看它是不是 NULL，不去猜 file_ext。
-- size_bytes 一列两用（远端声明值 / 本地实际值），刻意不拆成两列：
-- 卡片上只显示一个大小，两个数同时存在时谁赢需要额外解释，不如在写入那一刻就定下来。

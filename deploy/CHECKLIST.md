# 上线前检查清单

按顺序过一遍。每一条都写清了「为什么」和「怎么验」，不是走过场的打勾项。

---

## 0. 先跑体检

```bash
php database/doctor.php
```

它会逐层检查运行环境、配置、数据库连通、表结构、目录可写与生产风险。
**必须先做到「体检通过」再往下看**，否则后面的检查都会受干扰。

```bash
php tests/run.php
```

端到端测试全绿（当前 932 项）说明功能层面没问题。它在独立测试库 `web_one_test` 上跑，不会碰你的正式数据。

---

## 1. 环境与依赖

- [ ] **PHP >= 7.3**，且启用 `pdo_mysql`、`mbstring`
      （用 SMTP 发信还需要 `openssl`）
- [ ] **MySQL >= 5.7**（本项目在 8.0 上开发与验证）
- [ ] **时区一致**：`src/config.php` 的 `timezone` 与 MySQL 的 `time_zone` 要对上。
      连不上就会差 8 小时，「今日访问」会统计错。
      `doctor.php` 会打印当前会话时区，应当是 `+08:00`。
- [ ] **`src/` 与 `database/` 不在 Web 根目录内**
      站点根目录必须指向 `public/`。这是本项目最重要的一条部署约束——
      指错了整个后端源码（含数据库口令）就能被直接下载。
      验：浏览器访问 `/../src/config.local.php`，必须是 404。
- [ ] **PWA 的三个地址都通**：`/manifest.webmanifest`、`/sw.js`、`/offline.html` 各自返回 200。
      前两个少了任何一个都表现为「浏览器压根不给安装按钮」，
      少了 `offline.html` 则断网时看到的是一张浏览器错误页而不是你写的兜底页。
      注意 `/sw.js` 必须在站点根目录：Service Worker 的可控范围由它自己的 URL 决定，
      放进 `/assets/js/` 就只能管 `/assets/js/` 那一段。
      还要注意后缀：`*.json` 被 `public/.htaccess` 和 `deploy/nginx.conf.example` 明令拒绝，
      所以清单是 `public/index.php` 现生成的、后缀是 `.webmanifest`，不要为了省事改回 `manifest.json`。

---

## 2. 配置（把 env 切到 production）

在 `src/config.local.php` 里改，或用 `WEB_ONE_*` 环境变量覆盖：

- [ ] **`env` 改成 `production`**
      它会强制把 `debug` 关掉、把 Cookie 的 `Secure` 打开。这两项配错等于把用户数据送出去。
- [ ] **`debug` 必须为 false**，`php database/doctor.php` 里不该再出现这条提醒。
      开着 debug 时接口会把异常信息、类名、文件路径回给浏览器。
- [ ] **`app_url` 改成真实域名**（如 `https://example.com`）
      邮件里的验证 / 重置链接靠它拼绝对地址。留 `localhost` 的话，用户收到的链接指向他自己电脑。
- [ ] **`cookie_secure` 为 true**（`env=production` 会自动打开，确认一下）
- [ ] **数据库账号不要用 root**
      单独建一个只对 `web_one` 库有权限的账号：
      ```sql
      CREATE USER 'webone'@'127.0.0.1' IDENTIFIED BY '一个强口令';
      GRANT SELECT, INSERT, UPDATE, DELETE ON web_one.* TO 'webone'@'127.0.0.1';
      FLUSH PRIVILEGES;
      ```
      注意只给数据权限，不给 DDL——建表由 `install.php` / `migrate.php` 用另一个账号做。

---

## 3. 账号与凭据

- [ ] **改掉默认管理员密码**。`root/root` 只适合本地演示，
      而它的用户名恰恰是最容易被猜的那个。改法见下。
- [ ] **确认没有多余的测试账号**。管理员面板里逐个核对，「删除」按钮点两次即可删除。
- [ ] **确认数据库里没有明文口令**：
      ```sql
      SELECT username, LEFT(password_hash, 7) FROM users;
      ```
      `password_hash` 这一列必须全部是 `$2y$10$` 开头的 bcrypt 哈希。

改管理员密码的两种方式：

```bash
# 方式一（推荐）：登录后在页面上「个人中心 → 修改密码」，需要输入当前密码
# 方式二：命令行生成哈希再写库
php -r "echo password_hash('你的新密码', PASSWORD_DEFAULT), PHP_EOL;"
```

```sql
UPDATE users SET password_hash = '刚才那串哈希', password_changed_at = NOW() WHERE username = 'root';
```

改完立刻用新密码登录验证一次。库里只有哈希，没法「查回」旧密码，只能覆盖。

---

## 4. HTTPS

- [ ] **全站 HTTPS**，并把 HTTP 全量 301 跳到 HTTPS
      （Nginx 配置见 `deploy/nginx.conf.example`）
- [ ] **`fastcgi_param HTTPS on;`** 要让 PHP 知道自己在 HTTPS 后面，
      否则 `is_https()` 判断不出来，HSTS 头不会下发
- [ ] **证书自动续期**（certbot 的 timer 或 cron 已生效）
- [ ] **确认 HSTS 打开**。`Strict-Transport-Security` 在 Nginx 配置里默认是注释掉的，
      确认 HTTPS 一切正常后再打开。
      **警告**：HSTS 一旦下发且 max-age 较长，浏览器在有效期内会拒绝用 http 访问你的域名，
      回退要靠清浏览器缓存，很麻烦。先在短 max-age 上试。
- [ ] **HTTPS 不只是「更安全」，它还决定了 PWA 能不能装**。
      浏览器只在*安全上下文*里注册 Service Worker：`https://` 或 `http://127.0.0.1`。
      用 `http://局域网 IP`（比如车机、另一台手机上访问 `http://192.168.x.x:8000`）是注册不上的，
      控制台会直接报权限策略错误，而页面其它部分照常工作——很容易误判成「代码有问题」。
      正式上线要么真 HTTPS，要么本地测试时老实写 `127.0.0.1`。
- [ ] **装过一次的设备会留着旧缓存**：改动了 `public/assets/` 下的 CSS/JS 或 HTML 之后，
      把 `public/sw.js` 里的 `CACHE_NAME`（形如 `web-one-v1`）尾号加一再发一次。
      当前是 `web-one-v3`——加 `/read` 这一页时升过一次，预缓存列表里才有它。
      本站走的是「先问网络、失败了才读缓存」，所以正常刷新就能拿到新版；
      但不改这个号码的话，`install` 阶段那次预取不会发生，断网时能用的还是旧的一份。
      上线后用手机飞行模式打开一次 `/`，看到自制的离线页而不是浏览器错误页，就说明缓存确实是新的。
      阅读板块的离线不是靠这个缓存：读过的章节按书存进浏览器 `localStorage`，
      `sw.js` 有意完全不碰 `/api/`（那是带登录态的私有内容，缓存住等于缓存住别人的数据）。

---

## 5. 邮件

- [ ] **`mail_driver` 从 `log` 改成 `smtp`**（或 `mail`）
      `log` 只把邮件写进 `var/mail/`，用户根本收不到。
- [ ] **`smtp_*` 填对**，`smtp_encryption` 用 `starttls`（587 端口）或 `ssl`（465 端口）
- [ ] **`mail_from` 用真实存在的发件地址**，并且配好 SPF / DKIM，
      否则验证邮件会大批进垃圾箱
- [ ] **实测一次完整流程**：注册带邮箱的账号 → 收到验证邮件 → 点链接验证成功；
      再走一次「忘记密码」→ 收到重置邮件 → 重置后能用新密码登录。
      这两条链路只靠单元测试验不出「信到底有没有发出去」。

---

## 6. 限流参数

`src/config.php` 里的默认值适合小站，按你的实际流量调：

| 配置项 | 默认 | 含义 |
| --- | --- | --- |
| `throttle_window` | 900 | 统计窗口（秒） |
| `throttle_max_per_user` | 5 | 窗口内同一账号最多失败几次 |
| `throttle_max_per_ip` | 20 | 窗口内同一 IP 最多失败几次 |
| `max_sessions_per_user` | 5 | 同一账号最多保留几个登录设备 |
| `session_ttl_days` | 7 | 登录保持天数 |

注意：`throttle_max_per_ip` 是按 IP 计的，**公司或学校的出口 NAT 会让很多用户共用一个 IP**。
如果你的用户集中在少数几个出口 IP 上，把这个值调大，否则会出现「一个人输错密码，同事全被锁」。

---

## 7. 数据与备份

- [ ] **`var/` 目录不在 Web 根目录内**（它在项目根目录下，本来就在 `public/` 之外）；确认 Nginx / Apache 不会把它当静态目录暴露
- [ ] **`var/logs`、`var/stats`、`var/mail` 都对 Web 用户可写**
      分别是应用日志、资源监控的采样文件、`mail_driver=log` 时的邮件。
      写不进去不会让请求失败（都做了静默降级），但你会失去这些排查手段。
      `php database/doctor.php` 会检查前两个目录。
- [ ] **定时备份数据库**：
      ```bash
      mysqldump --single-transaction --default-character-set=utf8mb4 \
        -u webone -p web_one | gzip > web_one-$(date +%F).sql.gz
      ```
- [ ] **明确 `visits` 表的保留策略**
      它是唯一会随流量无限增长的表（每次页面访问插一行）。
      `src/visits.php` 里的 `purge_old_visits($days)` 可以直接调用，
      也可以改成定时把明细汇总成按天统计后再清理。
- [ ] **已经跑过旧版本的，先升一次结构**：
      ```bash
      php database/migrate.php
      ```
      幂等、可反复执行。这一版新增的是小说的两张表 `novels` 与 `novel_chapters`
      （后者靠 `novel_id` 外键 `ON DELETE CASCADE` 跟着书删），新装的直接 `install.php` 就带上。
- [ ] **`messages` / `memos` / `games` / `pomodoros` / `novels` / `novel_chapters` 也要纳入备份**
      留言是站内公开内容（用户删了就没有第二份），备忘录、番茄钟与小说是用户的私有数据，
      游戏板块是人工维护的名单。
      `mysqldump` 整库导出的话本来就包含，只导部分表时要记得带上。
      `messages` 有发帖限流但没有自动清理；`memos` 有每账号条数上限（默认 200），
      `pomodoros` 有每账号条数上限（默认 2000）。
- [ ] **`novel_chapters.content` 是 `MEDIUMTEXT`，是这张库最大的一块**
      存成每章一行。**单本没有字数上限**（一本连载完的长篇就是三五百万字），管磁盘的是**账号总量**：
      四道都能用 `WEB_ONE_*` 环境变量或 `config.local.php` 调小——`novel_max_total_chars`
      （一个账号书架上的总字数，默认 5000 万 ≈ 正文 150 MB）、`novel_max_count`（每人几本，默认 30）、
      `novel_max_chapters`（单本章数，默认 5000，卡在 `seq` 那列 SMALLINT 的 65535 天花板之下）、
      `novel_chapter_max_chars`（单章超过就按段落切开，默认 30000）。
      总量算的是**还留在书架上的字数**，删一本就还原额度，所以它是配额不是死水位。
      MySQL 的 `max_allowed_packet` 反倒不用为「书大」担心：插章节是**一个事务里把同一条预处理语句
      逐章 `execute`**，一次往返只带一章，包的大小由 `novel_chapter_max_chars` 封顶（3 万字 ≈ 90 KB），
      跟全书多大无关。
      真正会为「一本特别大的书」先炸的是 **PHP 的 `memory_limit`**：实测 570 万字的正文走完归一化 +
      分章全程峰值 80 MB（约 14 字节 / 字，还要算上整份 JSON 在解码时的副本），
      256M 大约容得下**一次 1800 万字**——再大的单本会先撞内存，不是先撞配额。
      书架没有分页（30 本上限就是唯一的保护），但它只返回书目不返回正文，
      正文按章取，所以流量与「书有多大」基本无关。
- [ ] **导一本整书 = 一个 JSON 请求体，别让它半路被挡掉**
      文件是在浏览器里读成文本再发出来的（服务端不接收文件、不落盘，只收到一段 JSON），
      请求体的大小约等于那个文件的 UTF-8 字节数：中文一个字 3 字节，**300 万字 ≈ 9 MB**。
      三道关都得放过它：Nginx 的 `client_max_body_size`（`nginx.conf.example` 里放到了 32m；
      默认 1m、老配置里的 2m 都会先返回一页 413，用户看不到站内的任何提示，只会以为「点了没反应」）、
      PHP 的 `post_max_size`（**出厂默认 8M 挡不住几百万字的书**，与上面那道 nginx 对齐着调）、
      以及 `max_execution_time`（几百万字解析加分章再逐章插进去不是零时间，实测 570 万字光识别就 228 ms，
      再加上千多次插入往返）。
      不想放开就把 `novel_max_total_chars` 与 `novel_chapter_max_chars` 一起调小，两头选一个。
- [ ] **`post_max_size` 挡大请求的方式很坑：一次已经成功的导入会看起来像失败**
      实测把它调成 8M、再打一个 18 MB 的 JSON 进去：`php://input` 一个字节不少地到了服务端，
      `json_decode` 照样成功（只有 `$_POST` 是空的，而本站不读它），书**确实存进去了**；
      但 PHP 先吐一条 `Warning: POST Content-Length ... exceeds the limit`，
      而 `display_errors=1` 会把它**印在 JSON 前面**——响应就此解析不了。
      用户看到的是「导入失败」，书架上多了一本，再点一次又多一本。
      所以放量大请求之前，先确认下面第 8 节里 `display_errors` 是关的，并把 `post_max_size` 一次调够。
- [ ] **要知道小说那道「只收纯文本」挡的是什么、不挡什么**
      接口看的是文件的**形状**（`MZ` / `PK\x03\x04` / gzip / 7z / rar / ELF / 老 Office / `%PDF` 这些头，
      加上有没有 `\0` 字节、不可打印字符是否超过一成），改名成 `.txt` 的 exe 会在**选文件那一步**就被本地拦下，
      连请求都不发。省下的是带宽和库里的死重，这一点值得在部署说明里写清楚。
      但它**不是杀毒**：没有病毒库、不查宏、不看内容，真带毒的一份 txt 它一句都看不出来。
      真正让「上传物变成可执行代码」不成立的是结构：正文只能作为 JSON 里的一个字符串进来，
      服务端**从不落盘、从不 exec、从不 include**，回到浏览器只走 `textContent`，
      CSP 的 `script-src 'self'` 兜底（`tests/cases/16-novel.php` 里钉着「带 `<img onerror>` 的正文原样存、原样回，
      但就是跑不了」）。所以别在 WAF / 杀软清单里把它当成已有能力。
      如果确实要做恶意文件识别，那是外挂 `clamav` 一类的独立工程，本站的代码里没有这一层。
- [ ] **`var/stats/samples.ndjson` 可以不备份**
      它只是资源监控的趋势采样（每行一个 JSON），丢了顶多趋势图重新积累。
- [ ] **确认 `one_time_tokens` 与 `auth_attempts` 会被清理**
      这两个表由应用在登录、打开后台时自动清理过期数据（见 `purge_expired_*`），
      流量很低的站点如果长期没人登录，可以加个每日 cron：
      ```bash
      php -r "require 'src/bootstrap.php'; purge_expired_sessions(); purge_expired_attempts(); purge_expired_tokens();"
      ```

---

## 8. 运行与监控

- [ ] **PHP-FPM + Nginx**，不要用 `php -S`（那是单进程的开发服务器）
- [ ] **`var/logs/` 有写入权限**，且日志会被轮转（`logrotate`）
      日志里是带 `X-Request-Id` 的结构化行，排查时用这个 ID 和前端报错对上。
- [ ] **`log_requests` 按需打开**。默认关闭；排障时打开，事后记得关，否则日志量很大。
- [ ] **生产环境把 `display_errors` 关掉**（`php.ini`），
      让错误只进日志、不吐给浏览器。
- [ ] **确认接口不泄露内部信息**：随便触发一个 500（例如临时改错数据库口令），
      前端应该只看到「服务器内部错误，请稍后再试」，而不是 SQL 语句或文件路径。

---

## 9. 上线后立刻做的事

- [ ] 用真实邮箱注册一个账号，走完「注册 → 验证 → 登录 → 改密 → 退出 → 忘记密码 → 重置」全流程
- [ ] 在 `/read` 选一个真文件试试（**最好再拿一份 GBK 编码的老 txt**）：页面上写的「按 xxx 读的」
      对不对、认出的章数对不对、翻两章后刷新能不能「接着读」回到那段、开飞行模式再点进去
      读过的章节还在不在。这几件事只有真在浏览器里做一遍才算验过——解码是 `TextDecoder` 的活，
      PHP 侧的用例拿到的是已经解好的字符串，看不出解错过没错过
- [ ] 同一页上再试两份文件：一份**改名成 .txt 的 exe 或 zip**（应当在选完的当下就出红字、
      网络面板里一个请求都不该发出去），红字之后**紧接着**再选一份正常小说（那行红字必须消失，
      不然用户以为正常文件也被拒了）。顺手拿一本几百万字的整书导一次，
      这一条验的是 nginx 的 `client_max_body_size` 与 `post_max_size` 放不放行——
      如果界面报「失败」而书架上多了一本书，那是 `display_errors` 把 PHP 的 Warning 印进了 JSON（见第 7 节）
- [ ] 手机上把导入再走一遍：手机的文件选择器进的是「文件」App / 系统下载目录，
      从微信或浏览器里存下去的 txt 翻得到翻不到，只有真点一次才知道
- [ ] 用手机浏览器打开一次，确认窄屏布局正常（尤其首屏大标题与副标题不会溢出、背景光束不压住正文）
- [ ] 打开管理员面板，确认统计数字、搜索、分页、重置密码都正常
- [ ] 首页的「我的用量」换两种身份各看一次：游客状态下它只剩两张耗时卡、没有「重新读取」按钮，
      网络面板里这一节一个请求都不发；换一个**普通账号**登录，卡上应当是他自己的条数
      （和管理员的数字对不上才对），且下面不会出现「运行状态」一节。
      再用这个普通账号直接请求 `/api/admin/system`，必须是 403——
      放开的是各人自己那一份，服务器那一份仍然只有管理员看得到
- [ ] 把 `php tests/run.php` 接入 CI，每次改代码都跑一遍
      CI 镜像里装了 node 的话，前端启动烟测（`tests/js-smoke.js`）会跟着一起跑，
      能挡住「页面脚本抛错但 HTTP 断言全绿」这一类问题；没有 node 时它自己跳过，不算失败

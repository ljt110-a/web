# web-one · 全栈用户认证示例

原来的 `index.html` 是单个前端文件：账号存在浏览器的 `localStorage` 里，密码在浏览器里算 SHA-256，
"后台"按钮只要改一下本地数据就能打开。那一版适合看动画和布局，但它不是后端——数据只属于你这一台电脑，
而且所有校验都能在控制台里绕过。

这一版把它改成了真正的前端 + 后端 + 数据库：

| 层 | 位置 | 负责什么 |
| --- | --- | --- |
| 前端 | `public/` | 页面、动画、表单与交互；只发请求，不存数据 |
| 后端 | `src/` | 注册 / 登录 / 退出 / 后台接口，密码哈希，会话与权限判断 |
| 数据库 | MySQL `web_one` 库 | `users` / `sessions` / `visits` 三张表 |

```
web-one/
├── public/              Web 根目录（只有这个目录对外可访问）
│   ├── index.php        前置控制器：/api/* 转后端，其它返回页面
│   ├── index.html       页面结构
│   ├── .htaccess        Apache 重写规则（用 phpStudy / Apache 部署时生效）
│   └── assets/          css/style.css、js/app.js
├── src/                 后端代码，浏览器访问不到
│   ├── api.php          接口路由：每个接口对应一个函数
│   ├── auth.php         注册、登录、会话、require_login / require_admin
│   ├── admin.php        后台：用户列表、统计、删除用户
│   ├── visits.php       访问计数
│   ├── db.php           PDO 连接（预处理、异常、时区）
│   ├── http.php         JSON 收发、ApiException、客户端 IP
│   ├── bootstrap.php    加载配置与其余模块
│   ├── config.php       配置默认值（可提交）
│   ├── config.example.php  配置模板
│   └── config.local.php    本机真实口令（已在 .gitignore 里，不会提交）
├── database/
│   ├── schema.sql       建表语句
│   └── install.php      一键安装：建库 → 建表 → 写入管理员
└── start.bat            双击启动（跑安装 + 起内置服务器）
```

## 环境要求

- PHP 7.3 及以上，需要 `pdo_mysql` 与 `mbstring` 扩展（phpStudy 自带的 PHP 已包含）
- MySQL 5.7 / 8.0，本仓库按 `127.0.0.1:3306` 配置

本机自检（先确认命令行里有 `php`）：

```bat
php -v
php -m | findstr /I "pdo_mysql mbstring"
```

> **`php` 不在 PATH 里怎么办？** 本文后续所有 `php xxx` 命令，都可以把 `php` 换成完整路径，
> 例如本机 phpStudy 自带的那一个：
> `E:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe database\install.php`
> 或者把上面那个目录加进系统 PATH。**最省事的办法是直接双击 `start.bat`**，它已经包含了这个回退逻辑。

## 三步跑起来

**1. 配置数据库口令**（只需一次）

```bat
copy src\config.example.php src\config.local.php
```

然后编辑 `src/config.local.php`，把 `db_pass` 改成你本机 MySQL 的密码。

**2. 安装数据库**（幂等，重复执行不会覆盖已有管理员密码）

```bat
php database\install.php
```

**3. 启动**

```bat
php -S localhost:8000 -t public public\index.php
```

浏览器打开 <http://localhost:8000>。也可以直接双击 `start.bat`（它会先跑一遍安装）。

> 必须通过 `http://localhost:8000` 访问。用 `file://` 直接双击 `public/index.html` 是打不通接口的：
> 没有服务器就没有 `/api/*`，页面顶部会显示一条红色提示告诉你后端没启动。

## 默认账号

| 用户名 | 密码 | 角色 | 来源 |
| --- | --- | --- | --- |
| `root` | `root` | 管理员 | `src/config.local.php` 的 `admin_user` / `admin_pass`，由安装脚本写入数据库 |

`root` 登录后导航栏才会出现"后台"按钮。**这对口令只适合本地演示**，往外部署前一定要改掉：

```bat
php -r "echo password_hash('你的新密码', PASSWORD_DEFAULT);"
```

把输出的一串（60 个字符）填进 SQL：

```sql
UPDATE users SET password_hash = '刚才那串哈希' WHERE username = 'root';
```

改完立刻用新密码登录验证一次。数据库里从来只有哈希，没有明文，所以没法"查回"旧密码，只能这样覆盖。

## 接口一览

前缀都是 `/api`，请求与响应一律是 JSON。

| 方法 | 路径 | 权限 | 作用 |
| --- | --- | --- | --- |
| `GET` | `/api/health` | 公开 | 自检：PHP 与数据库是否连通 |
| `POST` | `/api/register` | 公开 | 注册并直接登录，返回 `{logged, username, role}` |
| `POST` | `/api/login` | 公开 | 登录成功才签发会话 Cookie |
| `POST` | `/api/logout` | 登录 | 删除当前这一条会话 |
| `GET` | `/api/me` | 公开 | 我是谁：未登录返回 `{logged:false}` |
| `POST` | `/api/visit` | 公开 | 记一次访问，返回 `{totalVisits, todayVisits}` |
| `GET` | `/api/admin/users` | 管理员 | 统计 + 用户列表（含是否在线） |
| `DELETE` | `/api/admin/users` | 管理员 | 删除用户，请求体 `{username}` |

失败时状态码是 4xx/5xx，响应体固定是 `{"error": "能直接显示给用户的一句话"}`。
所有写操作（`POST` / `DELETE`）都必须用 `application/json` 提交，否则回 `415`。

用 curl 手测两个：

```bash
curl -s localhost:8000/api/health
curl -s -X POST -H "Content-Type: application/json" \
     -d '{"username":"root","password":"root"}' localhost:8000/api/login
```

## 数据库表

- `users` — 账号、bcrypt 密码哈希、角色、注册时间、最后登录、登录次数
- `sessions` — 登录令牌摘要、所属用户、过期时间、签发 IP；外键 `ON DELETE CASCADE`
- `visits` — 一行一次访问，`day` 字段带索引，用于"今日访问"

想直接看数据：

```sql
USE web_one;
SELECT username, role, created_at, last_login_at, login_count FROM users;
```

## 一次登录发生了什么

1. 前端 `POST /api/login`，密码走 HTTPS 到达后端（本地 http 下是明文，上线必须配 HTTPS）
2. 后端按用户名查库，用 `password_verify()` 与库里的 bcrypt 哈希比对
3. 命中后生成 32 字节随机令牌，**库里只存它的 SHA-256 摘要**，原文写进 `HttpOnly` Cookie
4. 之后每个请求浏览器自动带上 Cookie，后端查 `sessions` 判断是谁、有没有过期
5. 剩余寿命不足一半时自动续期，所以常用的人不会半路被踢下线

## 这一版解决了什么，又没解决什么

已经做到：

- 密码不在前端源码里，也不在页面里 —— `root/root` 现在只存在于你本机的数据库和配置文件
- 改本地存储不再是管理员：权限只由 `src/auth.php` 的 `require_admin()` 决定
- 全站 SQL 都用预处理绑定，用户名写成 `' OR 1=1 --` 也只是一段字符串
- 后台表格用 `textContent` 渲染，注册成 `<b>x</b>` 的用户名也只会显示成字面文本
- 会话 Cookie 是 `HttpOnly + SameSite=Lax`，页面脚本读不到，跨站表单也带不出去
- 错误响应不回吐 SQL 原文与内部路径（`config.local.php` 里 `debug => true` 时才回详情）

还没做，真上线前必须补：

- `root/root` 是可猜到的口令；也没有登录失败限流 / 验证码，可被暴力尝试
- 没有 HTTPS，Cookie 与密码在网络上裸奔（上线后把 `cookie_secure` 打开）
- 没有"修改密码 / 找回密码 / 邮箱验证"，忘记口令只能按上面的 SQL 覆盖
- 删除用户是硬删除，没有回收站；管理员账号也不允许删除，等于把超级账号焊死了
- 用户名依赖 MySQL 的大小写不敏感排序规则：`Alice` 与 `alice` 是同一个账号，这是有意的
- 只用一个 PHP 内置服务器跑，单进程，仅适合本地开发；正式部署请交给 Apache / Nginx + PHP-FPM

## 常见问题

**页面顶部出现红色提示"后端接口连不上"**
后端没启动或端口不对。在项目根目录执行 `php -S localhost:8000 -t public public\index.php`，
注意要用 `http://localhost:8000` 打开，而不是双击 HTML 文件。

**提示"数据库账号或密码不对"**
检查 `src/config.local.php` 的 `db_user` / `db_pass`；phpStudy 的 MySQL 默认口令通常是 `root`，
而独立安装的 MySQL 8 口令是你安装时设置的那个。

**端口 8000 被占用**
换个端口，例如 `php -S localhost:8080 -t public public\index.php`，前端不用改任何代码（接口用的是相对路径）。

**改了 css / js 没生效**
Ctrl + F5 强制刷新。页面 HTML 与接口响应都设了 `no-store`，但静态资源仍可能被浏览器缓存。

**中文用户名显示成问号**
数据库、表、连接三处都必须是 `utf8mb4`。用 `database/install.php` 建的库已经满足；
手动建库时漏了字符集就会这样，重建库即可。

**想用 phpStudy / Apache 部署**
把站点根目录指向 `public/`，`public/.htaccess` 会把 `/api/*` 交给 `public/index.php`；
`src/` 与 `database/` 留在根目录之外，这样源码不会被下载。

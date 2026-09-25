<?php
/**
 * 后端配置：数据库、会话、限流、邮件、路径。
 *
 * 三层优先级（后者覆盖前者）：
 *   1) 本文件的 $defaults
 *   2) 同目录的 config.local.php（不进 git，放真实口令）
 *   3) 环境变量 WEB_ONE_XXX（最高优先级，容器 / CI / 生产用它覆盖）
 *
 * 例：WEB_ONE_ENV=production WEB_ONE_DB_PASS=secret php -S localhost:8000 -t public public/index.php
 *
 * env=production 时有两项会被强制改写，因为配错等于把用户数据送出去：
 *   debug => false、cookie_secure => true
 */

$rootDir = dirname(__DIR__);

$defaults = [
    // ---------- 运行环境 ----------
    // local | production。production 会强制关 debug 并打开 Secure Cookie。
    'env' => 'local',

    // ---------- MySQL 连接 ----------
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'web_one',
    'db_user' => 'root',
    'db_pass' => '',           // 请在 config.local.php 里覆盖

    // ---------- 初始管理员（仅 database/install.php 首次建号时使用）----------
    'admin_user' => 'root',
    'admin_pass' => 'root',    // 演示用弱口令，正式使用前必须改

    // ---------- 登录会话 ----------
    'session_ttl_days' => 7,   // 记住登录的天数
    'cookie_name' => 'webone_session',
    'cookie_secure' => false,  // 部署到 HTTPS 后改成 true（env=production 会自动打开）
    'max_sessions_per_user' => 5,  // 同一账号最多同时保留几个登录设备，超出踢掉最旧的

    // ---------- 业务规则（与前端表单校验保持一致）----------
    'username_min' => 2,
    'username_max' => 20,
    'password_min' => 6,
    'password_max_bytes' => 72,   // bcrypt 只吃前 72 字节，超了直接拒绝而不是静默截断

    // ---------- 站点地址 ----------
    // 邮件里的验证 / 重置链接要靠它拼绝对地址；本地用 http://localhost:8000
    'app_url' => 'http://localhost:8000',

    // ---------- 登录失败限流 ----------
    'throttle_enabled' => true,
    'throttle_window' => 900,        // 统计窗口（秒）
    'throttle_max_per_user' => 5,    // 同一账号在窗口内最多失败几次
    'throttle_max_per_ip' => 20,     // 同一 IP 在窗口内最多失败几次

    // ---------- 邮件 ----------
    // log  = 只把邮件写进 var/mail/*.log（本地开发用，能直接看到链接）
    // smtp = 走真实 SMTP 服务器
    // mail = 交给本机 sendmail / PHP mail()
    'mail_driver' => 'log',
    'mail_from' => 'no-reply@web-one.local',
    'mail_from_name' => 'web-one',
    'smtp_host' => '127.0.0.1',
    'smtp_port' => 587,
    'smtp_user' => '',
    'smtp_pass' => '',
    'smtp_encryption' => 'starttls',  // none | starttls | ssl
    'smtp_timeout' => 10,

    // ---------- 一次性令牌有效期 ----------
    'verify_ttl_hours' => 24,      // 邮箱验证链接
    'reset_ttl_minutes' => 30,     // 密码重置链接

    // ---------- 留言板 ----------
    'message_max_len' => 500,      // 单条留言最长字数
    'message_rate_max' => 5,       // 同一个账号在窗口内最多发几条
    'message_rate_window' => 60,   // 发帖限流窗口（秒）
    'message_page_size' => 10,     // 留言列表默认每页条数
    'message_max_page_size' => 50,

    // ---------- 备忘录 ----------
    'memo_max_title' => 80,
    'memo_max_body' => 2000,
    'memo_max_count' => 200,       // 每个账号最多存几条，防止无限堆积

    // ---------- 番茄钟（学习板块）----------
    'pomodoro_default_minutes' => 25,  // 默认一轮多长
    'pomodoro_min_minutes' => 1,
    'pomodoro_max_minutes' => 180,     // 上限：超过三小时的「一轮」只是把放弃的风险推迟
    'pomodoro_max_subject' => 40,
    'pomodoro_max_count' => 2000,      // 每个账号最多存多少条记录

    // ---------- 小说阅读（选中本地 txt → 自动分章）----------
    'novel_max_title' => 120,          // 与 novels.title 列宽一致
    'novel_max_chapter_title' => 200,  // 与 novel_chapters.title 列宽一致
    'novel_min_chars' => 200,          // 短于这个字数不像一本书，直接拒，省得误触
    // 每个账号存在书架上的总字数上限，约 5000 万字（正文约 150 MB）。
    // 这里刻意**不再限单次导入**：一本连载完的长篇就是三五百万字，按「一次能粘多少」来设上限，
    // 等于把真实需求挡在门外。改成按账号总量算，既留得住磁盘的边界，又永远不会碰到正常的书。
    'novel_max_total_chars' => 50000000,
    // 单本章节数上限。这个数字同时在 seq 与 chapter_count 两列的天花板之下（SMALLINT 最大 65535），
    // 超了是一次说清楚的 422，而不是数据库报「out of range」。它挡的是「每行都像个标题」的病态输入
    'novel_max_chapters' => 5000,
    'novel_max_count' => 30,           // 每个账号最多存几本
    'novel_chapter_max_chars' => 30000,// 单章超过这个字数就再切开，一次翻页不该传三万字

    // ---------- 软件仓库（含服务器代下载）----------
    // 安装包与图标落盘的位置。刻意放在 public/ 之外：浏览器不能直连这个目录，
    // 每一次下载都要经过 /api/software/file 那道判断（是否上架、是否真的有包）。
    'soft_dir' => $rootDir . '/var/softs',
    'soft_max_count' => 300,               // 最多收录多少款，防止这张表无限长
    // 出网总开关。关掉之后「自动识别信息」「抓安装包」「抓图标」三个动作一律拒绝，
    // 列表和已经落盘的安装包照常可用——内网部署或没有外网的机器上就把它关掉。
    'soft_fetch_enabled' => true,
    // 允许出网的主机白名单（逗号分隔，只比对主机名，不比对外壳）。
    // 默认这份是「GitHub 的 API 与它自己的重定向目标 + Gitee 的 API + 它的头像 CDN」，
    // 想加源站就在这里加一行，不需要改代码。
    // avatars.githubusercontent.com 只在「智能获取图标」时会用到：仓库 API 给的
    // owner.avatar_url 就落在这个域名上，不加它等于智能获取永远取不到头像。
    'soft_fetch_hosts' => 'api.github.com,github.com,codeload.github.com,'
        . 'raw.githubusercontent.com,objects.githubusercontent.com,release-assets.githubusercontent.com,'
        . 'avatars.githubusercontent.com,gitee.com',
    'soft_fetch_timeout' => 20,            // 单次建连与两次读之间的超时（秒）
    'soft_fetch_max_redirect' => 3,        // 最多跟几跳重定向，每一跳都重新过一遍白名单
    'soft_fetch_meta_bytes' => 300000,     // 「自动识别信息」的响应体上限：仓库元数据用不了 300 KB
    'soft_icon_max_bytes' => 200000,       // 单个图标上限，超过就当没抓到
    'soft_max_file_bytes' => 209715200,    // 单个安装包上限 200 MB，边下边计数，超了立刻中断并删掉半成品
    'soft_quota_bytes' => 2147483648,      // 全部安装包合计占用上限 2 GB，撞上了要先删旧的再抓新的
    // 关掉「不许访问内网地址」这一条。唯一用途是让测试用一台本机假源站跑完整下载链路
    // （见 tests/fake_http.php）；生产环境必须保持 false，doctor 与 security_warnings 会盯着它。
    'soft_fetch_allow_private' => false,

    // ---------- 资源监控 ----------
    'stats_dir' => $rootDir . '/var/stats',
    'stats_sample_interval' => 60, // 两次采样之间至少间隔多少秒（避免每个请求都写文件）
    'stats_max_samples' => 120,    // 保留最近多少条采样用于画趋势

    // ---------- 其它 ----------
    'timezone' => 'Asia/Shanghai',
    // 出错时是否把内部错误详情返回给前端。只在本地开发打开，线上必须为 false。
    'debug' => false,
    // 日志与邮件落盘位置，都在 public/ 之外，浏览器访问不到
    'log_dir' => $rootDir . '/var/logs',
    'mail_dir' => $rootDir . '/var/mail',
    'log_requests' => false,       // 是否记录每个请求（排障时打开）
];

// ---------- 2) 本机文件覆盖 ----------
$localFile = __DIR__ . '/config.local.php';
$local = is_file($localFile) ? require $localFile : [];
$config = array_merge($defaults, is_array($local) ? $local : []);

// ---------- 3) 环境变量覆盖 ----------
foreach ($defaults as $key => $defaultValue) {
    $envName = 'WEB_ONE_' . strtoupper($key);
    $raw = getenv($envName);
    if ($raw === false && isset($_SERVER[$envName])) {
        $raw = $_SERVER[$envName];
    }
    if ($raw === false || $raw === null || $raw === '') {
        continue;
    }
    if (is_bool($defaultValue)) {
        $config[$key] = in_array(strtolower((string) $raw), ['1', 'true', 'on', 'yes'], true);
    } elseif (is_int($defaultValue)) {
        $config[$key] = (int) $raw;
    } else {
        $config[$key] = (string) $raw;
    }
}

// ---------- 4) 生产环境的兜底 ----------
if ($config['env'] === 'production') {
    $config['debug'] = false;
    $config['cookie_secure'] = true;
}

return $config;

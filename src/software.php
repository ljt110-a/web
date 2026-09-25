<?php
/**
 * 软件仓库：一张全站共享的「内容表」，前台只读，增删改与抓包一律走管理员接口。
 *
 * 和 games 是同一类表（没有 user_id、没有外键），多出来的两件事都在这份文件里：
 *   1. 一条软件可以从四个地方来（GitHub / Gitee / 官网 / 安装包直链），
 *      取哪一个由 source_mode 决定，规则见 software_resolve_source()
 *   2. 「服务器代下载」：管理员点一次抓包，服务器替访客把安装包取回来落在 var/softs/，
 *      之后所有人下载的都是这一份。落盘、命名、大小与配额的把关全在这里，
 *      出网那一半在 src/softnet.php（闸门），发文件那一半在 src/http.php（流式响应）
 *
 * 权限：GET /api/software 公开且不随身份变化；其余全部 require_admin。
 * 对外一律不暴露绝对路径、目录结构、磁盘余量——浏览器能看到的只有 id 与洗过的文件名。
 */

// ------------------------------------------------------------
// 常量
// ------------------------------------------------------------

/** 平台代号 → 页面上显示的字。代号进数据库，显示名只在这一处定义 */
function software_platform_labels()
{
    return [
        'windows' => 'Windows',
        'macos' => 'macOS',
        'linux' => 'Linux',
        'android' => 'Android',
        'ios' => 'iOS',
        'web' => '浏览器',
    ];
}

/** 取包方式。none 表示「就不该由服务器去抓」，是管理员的显式声明 */
function software_source_modes()
{
    return ['auto', 'github', 'gitee', 'direct', 'none'];
}

/** 各字段的长度上限，与 schema.sql 里 softs 的列宽一一对应 */
function software_limits()
{
    return [
        'name' => 80,
        'category' => 30,
        'description' => 300,
        'tag' => 20,
        'tags' => 300,
        'platforms' => 60,
        'version' => 40,
        'license' => 40,
        'url' => 300,
        'downloadUrl' => 500,
        'slug' => 60,
    ];
}

/** softs 的列清单。写 SQL 时只从这里取，避免两处列表不同步 */
function software_columns()
{
    return 'id, slug, name, category, platforms, tags, description, homepage, github_url, gitee_url,
            download_url, source_mode, version, license, icon_file, icon_kind, star_count, size_bytes,
            file_ext, file_sha256, file_display, file_origin, file_fetched_at, enabled, sort_order,
            created_at, updated_at';
}

// ------------------------------------------------------------
// 校验
// ------------------------------------------------------------

/** 单行短文本：去首尾空白、压掉换行、限长、挡控制字符 */
function software_text($value, $maxLen, $label, $required = false)
{
    $value = trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string) $value));
    if ($value === '') {
        if ($required) {
            throw new ApiException('请填写' . $label, 422);
        }
        return null;
    }
    if (mb_strlen($value, 'UTF-8') > $maxLen) {
        throw new ApiException($label . '最多 ' . $maxLen . ' 个字（当前 ' . mb_strlen($value, 'UTF-8') . ' 个）', 422);
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new ApiException($label . '包含非法字符', 422);
    }
    return $value;
}

/** 简介：允许换行，前端用 pre-wrap 原样显示 */
function software_description($value)
{
    $value = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    if ($value === '') {
        return '';
    }
    $max = software_limits()['description'];
    if (mb_strlen($value, 'UTF-8') > $max) {
        throw new ApiException('简介最多 ' . $max . ' 个字', 422);
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
        throw new ApiException('简介包含非法字符', 422);
    }
    return $value;
}

/**
 * 网址校验：只允许 http / https。
 *
 * 这些值会被渲染成 <a href>，容许 javascript: 之类的伪协议写进来，
 * 点这张卡片就等于在别人浏览器里执行脚本——和 games 那条是同一个道理。
 * 另外它同时是「服务器要不要拿这个地址去出网」的依据，所以宁严不许松：
 * 带账号密码的地址、控制字符、超长的地址一律不收。
 */
function software_url($value, $label, $maxLen = 300)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value, 'UTF-8') > $maxLen) {
        throw new ApiException($label . '最长 ' . $maxLen . ' 个字符', 422);
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new ApiException($label . '包含非法字符', 422);
    }
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new ApiException($label . '必须以 http:// 或 https:// 开头', 422);
    }
    if (parse_url($value, PHP_URL_HOST) === null) {
        throw new ApiException($label . '里缺少域名', 422);
    }
    if (preg_match('#/@#', (string) parse_url($value, PHP_URL_PATH))) {
        // 路径里出现 @ 说明这个地址的解析结果会有歧义，不伺候
        throw new ApiException($label . '格式不正确', 422);
    }
    return $value;
}

/** 英文标识：留空返回 null（唯一索引允许多个 null，不必强迫管理员起名） */
function software_slug($value)
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,59}$/', $value)) {
        throw new ApiException('英文标识只能用 2~60 个小写字母、数字与连字符，且不能以连字符开头', 422);
    }
    return $value;
}

/**
 * 平台多选：只认白名单里的代号，去重后按固定顺序存成逗号串。
 * 存成「规范化的固定顺序」而不是照抄提交顺序，是为了让「筛选条上每个平台几条」
 * 这类统计在 PHP 侧用一句 LIKE 就能算对，不必为每个平台写一遍数组判断。
 */
function software_platforms($value)
{
    if (!is_array($value)) {
        $value = $value === null || $value === '' ? [] : explode(',', (string) $value);
    }
    $known = software_platform_labels();
    $picked = [];
    foreach ($value as $item) {
        $code = strtolower(trim((string) $item));
        if ($code === '') {
            continue;
        }
        if (!isset($known[$code])) {
            throw new ApiException('不认识的平台：' . $code, 422);
        }
        $picked[$code] = true;
    }
    $ordered = [];
    foreach (array_keys($known) as $code) {
        if (isset($picked[$code])) {
            $ordered[] = $code;
        }
    }
    $csv = implode(',', $ordered);
    if (mb_strlen($csv, 'UTF-8') > software_limits()['platforms']) {
        throw new ApiException('选中的平台太多了', 422);
    }
    return $csv;
}

/**
 * 标签：逗号或空格分隔的输入统一洗成一个数组，去重、限长、限量。
 * 页面上的「#标签」和「展开全清单」都直接吃这个数组，不在前端再切一遍。
 */
function software_tags($value, $maxTags = 12)
{
    if (!is_array($value)) {
        $value = $value === null || $value === '' ? [] : preg_split('/[,，\s]+/u', (string) $value);
    }
    $max = software_limits()['tag'];
    $list = [];
    foreach ($value as $item) {
        $tag = trim(str_replace('#', '', (string) $item));
        $tag = preg_replace('/[\x00-\x1F\x7F]/u', '', $tag);
        if ($tag === '') {
            continue;
        }
        if (mb_strlen($tag, 'UTF-8') > $max) {
            throw new ApiException('每个标签最多 ' . $max . ' 个字（「' . mb_substr($tag, 0, 12, 'UTF-8') . '…」超了）', 422);
        }
        $key = mb_strtolower($tag, 'UTF-8');
        if (!isset($list[$key])) {
            $list[$key] = $tag;
        }
        if (count($list) > $maxTags) {
            throw new ApiException('一款软件最多打 ' . $maxTags . ' 个标签', 422);
        }
    }
    $joined = implode(',', array_values($list));
    if (mb_strlen($joined, 'UTF-8') > software_limits()['tags']) {
        throw new ApiException('标签合计太长了，删几个再存', 422);
    }
    return $joined;
}

function software_source_mode($value)
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return 'auto';
    }
    if (!in_array($value, software_source_modes(), true)) {
        throw new ApiException('不认识的取包方式：' . $value, 422);
    }
    return $value;
}

function software_sort_order($value)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return 100;
    }
    return max(-9999, min(9999, (int) $value));
}

/**
 * star 数：只认非负整数，空值算「还不知道」。
 *
 * 这一栏由「自动识别信息」带回来，也是卡片上那个 ★ 后面的数——
 * 所以它必须可写，否则识别出来的 star 数就只能看一眼又不能存。
 * 上限按列宽 INT UNSIGNED 收着，不给数据库塞一个存不进去的值。
 */
function software_star_count($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    $count = filter_var($value, FILTER_VALIDATE_INT);
    if ($count === false || $count < 0) {
        throw new ApiException('star 数只能是不小于 0 的整数', 422);
    }
    return min(4294967295, $count);
}

/** 版本号：可空，但必须是「一眼看去像版本号」的形状，挡掉把说明文字塞进这一列 */
function software_version($value)
{
    $value = software_text($value, software_limits()['version'], '版本号');
    if ($value === null) {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-+]{0,39}$/', $value)) {
        throw new ApiException('版本号只能用字母、数字与 . - _ +', 422);
    }
    return $value;
}

// ------------------------------------------------------------
// 读取
// ------------------------------------------------------------

function software_find($id)
{
    $stmt = db()->prepare('SELECT ' . software_columns() . ' FROM softs WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** 逗号串 → 显示用的数组 */
function software_explode($csv)
{
    $out = [];
    foreach (explode(',', (string) $csv) as $item) {
        $item = trim($item);
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return $out;
}

/**
 * 数据库行 → 给前端的视图。
 *
 * $isAdmin 只多给三类字段：上下架与排序、抓包的技术细节（摘要、来源、时间）、
 * 以及「这次抓包用的地址」。绝对路径任何时候都不出去——浏览器要下载，
 * 只需要 /api/software/file?id= 这一个地址，剩下的由服务端决定。
 */
function software_to_item(array $row, $isAdmin = false)
{
    $labels = software_platform_labels();
    $codes = software_explode($row['platforms']);
    $platforms = [];
    foreach ($codes as $code) {
        $platforms[] = ['code' => $code, 'label' => isset($labels[$code]) ? $labels[$code] : $code];
    }

    $hasFile = $row['file_fetched_at'] !== null && $row['file_ext'] !== null && $row['file_ext'] !== '';
    $slug = $row['slug'] === null ? '' : (string) $row['slug'];

    $item = [
        'id' => (int) $row['id'],
        'anchor' => $slug !== '' ? 'soft-' . $slug : 'soft-' . (int) $row['id'],
        'slug' => $slug === '' ? null : $slug,
        'name' => $row['name'],
        'category' => $row['category'],
        'platforms' => $platforms,
        'tags' => software_explode($row['tags']),
        'description' => $row['description'],
        'homepage' => $row['homepage'],
        'githubUrl' => $row['github_url'],
        'giteeUrl' => $row['gitee_url'],
        'downloadUrl' => $row['download_url'],
        'version' => $row['version'],
        'license' => $row['license'],
        'starCount' => $row['star_count'] === null ? null : (int) $row['star_count'],
        'sizeBytes' => $row['size_bytes'] === null ? null : (int) $row['size_bytes'],
        // 有没有本地图标 / 本地安装包，决定页面上画不画那一枚图标和那个「下载」按钮
        'iconUrl' => $row['icon_file'] === null || $row['icon_file'] === ''
            ? null
            : '/api/software/icon?id=' . (int) $row['id'],
        'hasFile' => $hasFile,
        'fileUrl' => $hasFile ? '/api/software/file?id=' . (int) $row['id'] : null,
        'fileName' => $hasFile ? (string) $row['file_display'] : null,
        'fileBytes' => $hasFile && $row['size_bytes'] !== null ? (int) $row['size_bytes'] : null,
    ];

    if ($isAdmin) {
        $item['sourceMode'] = $row['source_mode'];
        $item['enabled'] = (int) $row['enabled'] === 1;
        $item['sortOrder'] = (int) $row['sort_order'];
        $item['createdAt'] = format_datetime($row['created_at']);
        $item['updatedAt'] = format_datetime($row['updated_at']);
        $item['fileFetchedAt'] = format_datetime($row['file_fetched_at']);
        $item['fileSha256'] = $hasFile ? (string) $row['file_sha256'] : null;
        $item['fileOrigin'] = $hasFile ? (string) $row['file_origin'] : null;
        // 编辑器里要显示「现在是哪一枚图标、什么格式」，格式换掉时旧文件会被删，
        // 所以这一列只用来给管理员看一眼，不参与任何路径拼接
        $item['iconKind'] = $row['icon_kind'] === null || $row['icon_kind'] === '' ? null : (string) $row['icon_kind'];
    }

    return $item;
}

/**
 * 列表 + 侧栏与筛选条要用的聚合数。
 *
 * 一次把整张表读进 PHP 再算，而不是发三条 GROUP BY：
 * 这张表的上限是 soft_max_count（默认 300）条，
 * 一次读满比三条聚合查询更简单，而且分类 / 平台 / 标签三套计数共用同一份行，
 * 不会出现「侧栏说 8 款、点进去只有 7 款」这种对不上的数。
 *
 * @param array|null $viewer null 表示游客视角（只含已上架的）
 */
function software_list(array $viewer = null)
{
    $isAdmin = $viewer !== null && $viewer['role'] === 'admin';

    $sql = 'SELECT ' . software_columns() . ' FROM softs'
        . ($isAdmin ? '' : ' WHERE enabled = 1')
        . ' ORDER BY sort_order ASC, id ASC';
    $rows = db()->query($sql)->fetchAll();

    $items = [];
    $categories = [];
    $platforms = [];
    $tags = [];
    $withFile = 0;
    foreach ($rows as $row) {
        $items[] = software_to_item($row, $isAdmin);

        $category = (string) $row['category'];
        $categories[$category] = isset($categories[$category]) ? $categories[$category] + 1 : 1;
        foreach (software_explode($row['platforms']) as $code) {
            $platforms[$code] = isset($platforms[$code]) ? $platforms[$code] + 1 : 1;
        }
        foreach (software_explode($row['tags']) as $tag) {
            $key = mb_strtolower($tag, 'UTF-8');
            $tags[$key] = isset($tags[$key])
                ? ['name' => $tags[$key]['name'], 'count' => $tags[$key]['count'] + 1]
                : ['name' => $tag, 'count' => 1];
        }
        if ($row['file_fetched_at'] !== null && $row['file_ext'] !== null && $row['file_ext'] !== '') {
            $withFile++;
        }
    }

    arsort($categories);
    $labels = software_platform_labels();
    $platformFacet = [];
    foreach ($labels as $code => $label) {
        if (isset($platforms[$code])) {
            $platformFacet[] = ['code' => $code, 'label' => $label, 'count' => $platforms[$code]];
        }
    }
    uasort($tags, function ($a, $b) {
        // 出现次数多的排前面，一样多时按名字，保证每次刷新顺序稳定
        if ($a['count'] === $b['count']) {
            return strcmp($a['name'], $b['name']);
        }
        return $a['count'] < $b['count'] ? 1 : -1;
    });

    $out = [
        'items' => $items,
        'total' => count($items),
        'manageable' => $isAdmin,
        'facets' => [
            'categories' => array_map(
                function ($name, $count) {
                    return ['name' => $name, 'count' => $count];
                },
                array_keys($categories),
                array_values($categories)
            ),
            'platforms' => $platformFacet,
            'tags' => array_values($tags),
        ],
        'stats' => [
            'total' => count($items),
            'categories' => count($categories),
            'directFiles' => $withFile,
        ],
        'limits' => software_limits(),
        'platformLabels' => $labels,
        // 这两个是「产品规则」而不是「服务器现状」：写死的配置值，给谁看都不泄露什么
        'maxFileBytes' => (int) cfg('soft_max_file_bytes'),
        'quotaBytes' => (int) cfg('soft_quota_bytes'),
        'fetchEnabled' => cfg('soft_fetch_enabled') === true,
    ];

    // 已经占了多少字节是一项测量值，不是配置：只给管理员。
    // 前台要显示的是「这一款有多大」，那是 size_bytes，每条自己带。
    if ($isAdmin) {
        $out['quota'] = [
            'usedBytes' => software_used_bytes(),
            'limitBytes' => (int) cfg('soft_quota_bytes'),
            'fileCount' => $withFile,
        ];
    }

    return $out;
}

// ------------------------------------------------------------
// 写入（都是管理员操作）
// ------------------------------------------------------------

/** 从 GitHub / Gitee 仓库地址里抠出 owner/repo */
function software_repo_path($url)
{
    if (!is_string($url) || $url === '') {
        return null;
    }
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === null) {
        return null;
    }
    $parts = array_values(array_filter(explode('/', trim((string) $path, '/'))));
    if (count($parts) < 2) {
        return null;
    }
    // 管理员常直接粘 clone 地址，末尾那个 .git 会跟着落进第二段：
    // 留着它，接口地址就变成 /repos/owner/repo.git，必然 404
    return ['owner' => $parts[0], 'repo' => (string) preg_replace('/\.git$/u', '', $parts[1])];
}

/**
 * 按 source_mode 决定「这次抓包该去哪个地址拿」。
 *
 * auto 就是图片里那句「自动判断（GitHub → Gitee → 直链，推荐）」：
 * 先看 GitHub 的最新 release，拿不到再看 Gitee，再拿不到才退回直链。
 * 之所以把直链排在最后：填了仓库地址就说明包在仓库里，
 * 而直链往往是镜像站，版本会滞后——但它是唯一的兜底，不能不试。
 *
 * @return array ['url'=>string, 'version'=>string|null, 'size'=>int|null, 'name'=>string|null]
 */
function software_resolve_source(array $row)
{
    $mode = (string) $row['source_mode'];
    if ($mode === 'none') {
        throw new ApiException('这一款被设成了「不让服务器抓包」，要改请先去编辑里换取包方式', 409);
    }

    $order = [];
    if ($mode === 'auto') {
        $order = ['github', 'gitee', 'direct'];
    } elseif ($mode === 'github' || $mode === 'gitee') {
        $order = [$mode];
    } else {
        $order = ['direct'];
    }

    $tried = [];
    foreach ($order as $kind) {
        if ($kind === 'direct') {
            if ($row['download_url'] !== null && $row['download_url'] !== '') {
                return ['url' => $row['download_url'], 'version' => null, 'size' => null, 'name' => null, 'via' => 'direct'];
            }
            $tried[] = '安装包直链（没填）';
            continue;
        }

        $url = $kind === 'github' ? $row['github_url'] : $row['gitee_url'];
        if ($url === null || $url === '') {
            $tried[] = ($kind === 'github' ? 'GitHub' : 'Gitee') . '（没填仓库地址）';
            continue;
        }
        $repo = software_repo_path($url);
        if ($repo === null) {
            $tried[] = ($kind === 'github' ? 'GitHub' : 'Gitee') . '（仓库地址看不出 owner/仓库名）';
            continue;
        }
        $label = $kind === 'github' ? 'GitHub' : 'Gitee';
        try {
            $release = $kind === 'github'
                ? software_latest_github_release($repo['owner'], $repo['repo'])
                : software_latest_gitee_release($repo['owner'], $repo['repo']);
        } catch (ApiException $e) {
            // 查询仓库失败不该让整次抓包报错，记一句原因接着试下一个来源。
            // 最典型的是 GitHub 限流或主机不在白名单：这时该退到 Gitee / 直链，
            // 而不是把「403 不在白名单」这种服务端配置细节甩到页面上。
            $tried[] = $label . '（' . $e->getMessage() . '）';
            continue;
        }
        if ($release !== null) {
            return $release + ['via' => $kind];
        }
        $tried[] = $label . '（没找到可用的安装包资产）';
    }

    throw new ApiException('没有可用的下载地址：' . implode('、', $tried), 409);
}

/**
 * 宽松版版本号：远端仓库的 tag 五花八门（带中文、带空格、超长），
 * 认不出来就当没有，而不是让整次抓包因为一个 tag 的形状失败。
 * 管理员手填的那一条才走严格的 software_version()。
 */
function software_soft_version($value)
{
    try {
        return software_version($value);
    } catch (ApiException $e) {
        return null;
    }
}

/**
 * GitHub 最新 release 里挑一个资产。
 *
 * 用 /releases/latest 而不是 /releases：前者按发布时间的语义给「最新那一个」，
 * 后者要把整个列表拉回来（老仓库能有几百个 release）。
 * 挑资产的规则见 software_pick_asset()。
 */
function software_latest_github_release($owner, $repo)
{
    $api = software_repo_api('github', $owner, $repo) . '/releases/latest';
    $response = softnet_request($api, [
        'accept' => 'application/vnd.github+json',
        'maxBytes' => (int) cfg('soft_fetch_meta_bytes'),
    ]);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        return null;
    }
    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        return null;
    }
    $picked = software_pick_asset(software_release_assets($data));
    if ($picked === null) {
        return null;
    }

    return software_asset_result($data, $picked);
}

/** Gitee 最新 release。它的字段名和 GitHub 不一致，这里统一洗成同一份形状 */
function software_latest_gitee_release($owner, $repo)
{
    $api = software_repo_api('gitee', $owner, $repo) . '/releases/latest';
    $response = softnet_request($api, [
        'accept' => 'application/json',
        'maxBytes' => (int) cfg('soft_fetch_meta_bytes'),
    ]);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        return null;
    }
    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        return null;
    }
    $picked = software_pick_asset(software_release_assets($data));
    if ($picked === null) {
        return null;
    }

    return software_asset_result($data, $picked);
}

/**
 * release 的 JSON → 统一的候选资产列表。
 *
 * GitHub 用 assets[].browser_download_url；Gitee 两种形状都见过
 * （assets[].browser_download_url 与 attachables[].url），所以两处都扫一遍。
 * 认不出的条目跳过而不是报错：字段是远端说了算的，它改一天我们就漏一天，
 * 但「因为多了一个没见过的字段就整次抓包失败」是最难向管理员解释的。
 */
function software_release_assets(array $data)
{
    $assets = [];
    foreach ([['assets', 'browser_download_url'], ['attachables', 'url']] as $pair) {
        if (empty($data[$pair[0]]) || !is_array($data[$pair[0]])) {
            continue;
        }
        foreach ($data[$pair[0]] as $asset) {
            if (!is_array($asset) || empty($asset[$pair[1]])) {
                continue;
            }
            $url = (string) $asset[$pair[1]];
            $name = isset($asset['name']) ? (string) $asset['name'] : '';
            if ($name === '') {
                $path = parse_url($url, PHP_URL_PATH);
                $name = basename((string) $path);
            }
            $assets[] = [
                'name' => $name,
                'url' => $url,
                'size' => isset($asset['size']) ? (int) $asset['size'] : null,
            ];
        }
    }
    return $assets;
}

/** 挑中的资产 + release 自带的 tag → software_resolve_source 期望的那份形状 */
function software_asset_result(array $data, array $picked)
{
    return [
        'url' => $picked['url'],
        // tag_name 是仓库自己标的版本，比让管理员再抄一遍准
        'version' => isset($data['tag_name']) ? software_soft_version($data['tag_name']) : null,
        'size' => $picked['size'],
        'name' => $picked['name'],
    ];
}

/**
 * 一堆候选资产里挑一个。
 *
 * 规则按「像不像安装包」打分，而不是取第一个：
 * release 列表里常混着 SHA256SUMS、.asc 签名、源码包这些不是安装包的东西，
 * 抓回来给访客是一个打不开的文本文件，比抓失败更难解释。
 */
function software_pick_asset(array $assets)
{
    $best = null;
    $bestScore = -1;
    foreach ($assets as $asset) {
        $name = (string) $asset['name'];
        if ($name === '') {
            continue;
        }
        // 认类型用与下载时同一个函数：资产名优先、URL 兜底。
        // 两边规则不一致会出现「挑的时候算它不合格，抓的时候却认得」这种怪事。
        if (software_guess_ext($name, (string) $asset['url']) === null) {
            continue;
        }
        $lower = strtolower($name);
        $score = 1;
        // 明确不是安装包的名字：签名、校验和、源码包
        if (preg_match('/\.(asc|sig|sha1|sha256|sha512|md5)$/u', $lower)) {
            continue;
        }
        if (preg_match('/(source[_\-.]?tar|source[_\-.]?zip|-src\.|sources\.)/u', $lower)) {
            $score -= 3;
        }
        // 常见的平台标记：命中了就说明这个包是给普通用户点的那一个
        if (preg_match('/(win|windows|setup|installer|x64|x86|\.exe|\.msi)/u', $lower)) {
            $score += 4;
        }
        if (preg_match('/(mac|osx|darwin|\.dmg)/u', $lower)) {
            $score += 2;
        }
        if (preg_match('/(linux|\.deb|\.rpm|\.AppImage)/ui', $lower)) {
            $score += 2;
        }
        if (preg_match('/(portable|standalone)/u', $lower)) {
            $score += 1;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $asset;
        }
    }
    return $best;
}

/**
 * 仓库信息接口（不含 /releases 那一段）。
 *
 * 只有两个前缀是允许的写法：调用方给的是 owner/repo，不是地址，
 * 所以「识别信息」和「取头像」用的是同一份地址，不会各写一遍再各错一遍。
 */
function software_repo_api($kind, $owner, $repo)
{
    $path = rawurlencode((string) $owner) . '/' . rawurlencode((string) $repo);
    return $kind === 'gitee'
        ? 'https://gitee.com/api/v5/repos/' . $path
        : 'https://api.github.com/repos/' . $path;
}

/** 取一份仓库信息的 JSON。非 2xx 与不成形的返回都算失败，理由里带着状态码 */
function software_repo_json($api, $accept = 'application/vnd.github+json')
{
    $response = softnet_request($api, ['accept' => $accept]);
    if ($response['status'] !== 200) {
        throw new ApiException('仓库信息返回 HTTP ' . $response['status'], 502);
    }
    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        throw new ApiException('仓库信息不是合法的 JSON', 502);
    }
    return $data;
}

/** 宽松版单行文本：远端给的内容太长就截断，含控制字符之类的脏数据就当没有这一栏 */
function software_soft_text($value, $maxLen)
{
    $one = trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string) $value));
    if ($one === '') {
        return null;
    }
    if (mb_strlen($one, 'UTF-8') > $maxLen) {
        $one = rtrim(mb_substr($one, 0, $maxLen - 1, 'UTF-8')) . '…';
    }
    try {
        return software_text($one, $maxLen, '识别结果');
    } catch (ApiException $e) {
        return null;    // 认不出的脏数据不该让整次识别失败，只是这一栏空着
    }
}

/** 宽松版地址：远端填的 homepage 未必是个合法 URL，认不出就当没有这一栏 */
function software_soft_url($value)
{
    try {
        return software_url($value, '官网', software_limits()['url']);
    } catch (ApiException $e) {
        return null;
    }
}

/**
 * 仓库信息 → 卡片上那几栏（star 数、协议、简介、官网）。
 *
 * GitHub 与 Gitee 的字段名不一致，这里一次认完：
 * 远端的字段是它说了算的，我们只挑能认出来的，认不出就当没有这一栏。
 */
function software_repo_meta(array $data)
{
    $limits = software_limits();

    $stars = null;
    foreach (['stargazers_count', 'star_count'] as $key) {
        if (isset($data[$key]) && is_numeric($data[$key])) {
            $stars = max(0, (int) $data[$key]);
            break;
        }
    }

    $license = null;
    if (isset($data['license'])) {
        if (is_array($data['license'])) {
            // GitHub 给的是 {"key":"mit","spdx_id":"MIT","name":"MIT License"}
            foreach (['spdx_id', 'name', 'key'] as $key) {
                if (isset($data['license'][$key]) && is_scalar($data['license'][$key])) {
                    $license = (string) $data['license'][$key];
                    break;
                }
            }
        } elseif (is_scalar($data['license'])) {
            $license = (string) $data['license'];    // Gitee 直接给一个协议名
        }
    }
    // GitHub 对「说不清是什么协议」统一回 NOASSERTION，那一串既长又没用
    if ($license !== null && in_array(strtolower($license), ['noassertion', 'other', 'unknown'], true)) {
        $license = null;
    }

    return [
        'version' => null,    // 版本来自最新 release，不在这一份里，占个位让形状稳定
        'starCount' => $stars,
        'license' => software_soft_text($license, $limits['license']),
        'description' => software_soft_text(isset($data['description']) ? $data['description'] : '', $limits['description']),
        'homepage' => software_soft_url(isset($data['homepage']) ? $data['homepage'] : ''),
    ];
}

/** 最新 release 的 tag → 版本号。这一栏认不出来只是空着，不算失败 */
function software_release_version($api, $accept = 'application/vnd.github+json')
{
    $data = software_repo_json($api . '/releases/latest', $accept);
    return isset($data['tag_name']) ? software_soft_version($data['tag_name']) : null;
}

/**
 * 一家仓库的识别结果。
 *
 * 版本号住在另一个接口上，而很多仓库根本不发 release——那一跳失败只是记一句，
 * 不该把已经认出来的那几栏一起废掉，所以 $tried 按引用累积、由调用方拼进理由。
 *
 * @param array $tried  「这一路哪里不顺」的清单
 * @return array|null   一栏都没认出来时返回 null，调用方换下一家
 */
function software_identify_one($label, $api, $accept, array &$tried)
{
    try {
        $detected = software_repo_meta(software_repo_json($api, $accept));
    } catch (ApiException $e) {
        // 这一家连仓库信息都没给回来，换下一家（与抓安装包那边同一个脾气）
        $tried[] = $label . '（' . $e->getMessage() . '）';
        return null;
    }
    try {
        $detected['version'] = software_release_version($api, $accept);
    } catch (ApiException $e) {
        $tried[] = $label . ' 的 release（' . $e->getMessage() . '）';
    }

    $useful = array_filter($detected, function ($value) {
        return $value !== null && $value !== '';
    });
    if ($useful === []) {
        $tried[] = $label . '（仓库信息里没有一个能用的字段）';
        return null;
    }
    return $useful;
}

/**
 * 「自动识别信息」：只读仓库的元数据，一个字节都不往磁盘上写。
 *
 * 刻意不回写数据库，只把认出来的东西交回前端填进表单：
 * 远端的 description 与 homepage 未必是管理员想挂的那一份，
 * 让他在「保存此软件」之前先看一眼，比服务器替他改完更合适。
 *
 * @return array ['source'=>string, 'detected'=>array, 'tried'=>array]
 */
function software_identify(array $actor, $githubUrl, $giteeUrl)
{
    $sources = [];
    $github = software_repo_path($githubUrl);
    if ($github !== null) {
        $sources['GitHub'] = ['kind' => 'github', 'repo' => $github];
    }
    $gitee = software_repo_path($giteeUrl);
    if ($gitee !== null) {
        $sources['Gitee'] = ['kind' => 'gitee', 'repo' => $gitee];
    }
    if ($sources === []) {
        throw new ApiException('先填一个 GitHub 或 Gitee 的仓库地址（形如 https://github.com/owner/repo），才有信息可识别', 409);
    }

    $tried = [];
    foreach ($sources as $label => $source) {
        $api = software_repo_api($source['kind'], $source['repo']['owner'], $source['repo']['repo']);
        $accept = $source['kind'] === 'gitee' ? 'application/json' : 'application/vnd.github+json';
        $useful = software_identify_one($label, $api, $accept, $tried);
        if ($useful === null) {
            continue;
        }

        app_log('info', '软件仓库识别信息', [
            'actor' => $actor['username'],
            'via' => $label,
            'fields' => array_keys($useful),
        ]);

        return ['source' => $label, 'detected' => $useful, 'tried' => $tried];
    }

    throw new ApiException(
        '没有识别出任何信息：' . implode('；', $tried) . '。可以照抄官网上的版本信息手工填一格，或者用直链抓包。',
        409
    );
}

/** 本地安装包的落盘路径：只由 id 与白名单扩展名决定，不接受任何外部输入 */
function software_file_path($id, $ext)
{
    return softnet_dir() . '/' . (int) $id . '.' . strtolower((string) $ext);
}

function software_icon_path($id, $kind)
{
    return softnet_dir() . '/' . (int) $id . '.icon.' . strtolower((string) $kind);
}

/** 已经落在磁盘上的安装包合计占了多少字节（不含正在重抓的这一款） */
function software_used_bytes($exceptId = 0)
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(size_bytes), 0) FROM softs
          WHERE file_fetched_at IS NOT NULL AND id <> ?'
    );
    $stmt->execute([(int) $exceptId]);
    return (int) $stmt->fetchColumn();
}

/**
 * 服务器代下载：把这一款的安装包抓到本机来。
 *
 * 顺序刻意是「先落盘、后写库」：抓的过程最长能到几十秒，
 * 中途失败就什么都不改，页面上仍然是上一次那一份；
 * 只有文件真的完整落地了，才把 file_* 与 size_bytes 一次性更新掉，
 * 并删掉这一款的上一个包。不会出现「库里说有包、磁盘上没有」的中间态。
 */
function software_grab_file(array $actor, $id)
{
    $row = software_find($id);
    if ($row === null) {
        throw new ApiException('这个软件不存在，可能已被删除', 404);
    }

    $maxBytes = (int) cfg('soft_max_file_bytes');
    $source = software_resolve_source($row);

    // 先看远端声明的大小，能提前拒掉一个明显超标的包，不必下满 200 MB 才报错
    if ($source['size'] !== null && $source['size'] > $maxBytes) {
        throw new ApiException(
            '安装包有 ' . system_format_bytes($source['size']) . '，超过单个 ' . system_format_bytes($maxBytes, 0) . ' 的上限',
            413
        );
    }
    $quota = (int) cfg('soft_quota_bytes');
    $used = software_used_bytes((int) $row['id']);
    if ($used >= $quota) {
        throw new ApiException('安装包已经占了 ' . system_format_bytes($used) . '，达到总配额，先删掉几个旧的', 507);
    }

    // 扩展名先定下来：直链看 URL 末段，仓库资产看资产名，都不认识就拒
    $ext = software_guess_ext(isset($source['name']) ? $source['name'] : '', $source['url']);
    if ($ext === null) {
        // 分成两种说法，因为管理员下一步要做的事不一样：
        // 地址里带着一个不收的扩展名（.txt / .php），换一个地址就行；
        // 根本没有扩展名（下载接口那种 /download?id=1），得去填一个明确的文件地址。
        $raw = software_raw_ext(isset($source['name']) ? $source['name'] : '', $source['url']);
        if ($raw !== '') {
            throw new ApiException('这个地址指向的是 .' . $raw . ' 文件，不支持：安装包不能是文本、脚本或网页', 422);
        }
        throw new ApiException('认不出这个地址指向的安装包类型，请在「安装包直链」里填一个明确的文件地址', 422);
    }

    $destPath = software_file_path((int) $row['id'], $ext);
    // 先下到 grab- 前缀的暂存名，等大小与配额这一关全过了才改名到位：
    // 直接往正式名上写的话，一次失败的抓包会顺手覆盖掉访客此刻还能下载的那一份，
    // 库里却仍记着旧的大小与摘要，变成「说有包、磁盘上那份却不是它」。
    $stagePath = softnet_dir() . '/grab-' . (int) $row['id'] . '.' . $ext;
    $startedAt = microtime(true);
    $result = softnet_download_to_file($source['url'], $stagePath, $maxBytes);

    // 远端可能又跳了一次，最终大小以本地实际落盘的字节为准
    $bytes = (int) $result['bytes'];
    if ($used + $bytes > $quota) {
        @unlink($stagePath);
        throw new ApiException('这个包会让总占用超过配额，已放弃', 507);
    }
    if (!@rename($stagePath, $destPath)) {
        @unlink($stagePath);
        throw new ApiException('安装包落盘失败，请检查目录权限', 500);
    }

    $display = software_download_name($row, $result['remoteName'], $ext);
    $version = !empty($source['version']) ? $source['version'] : $row['version'];

    $stmt = db()->prepare(
        'UPDATE softs
            SET file_ext = ?, size_bytes = ?, file_sha256 = ?, file_display = ?,
                file_origin = ?, file_fetched_at = NOW(), version = ?
          WHERE id = ?'
    );
    $stmt->execute([
        $ext,
        $bytes,
        $result['sha256'],
        $display,
        mb_substr($result['finalUrl'], 0, 500, 'UTF-8'),
        $version,
        (int) $row['id'],
    ]);

    // 扩展名变了（比如上一版是 zip 这一版是 exe）就要把旧文件清掉，否则磁盘上留两份
    software_prune_old_file((int) $row['id'], $ext);

    app_log('info', '软件仓库抓包', [
        'actor' => $actor['username'],
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'bytes' => $bytes,
        'seconds' => round(microtime(true) - $startedAt, 1),
        'via' => isset($source['via']) ? $source['via'] : 'direct',
    ]);

    return [
        'software' => software_to_item(software_find((int) $row['id']), true),
        'bytes' => $bytes,
        'sha256' => $result['sha256'],
        'seconds' => round(microtime(true) - $startedAt, 1),
        'via' => isset($source['via']) ? $source['via'] : 'direct',
    ];
}

/** 从资产名或 URL 末段猜扩展名；猜不出来返回 null，让调用方去拒 */
function software_guess_ext($remoteName, $url)
{
    foreach ([$remoteName, (string) parse_url($url, PHP_URL_PATH)] as $candidate) {
        if ($candidate === null || $candidate === '') {
            continue;
        }
        $ext = strtolower((string) pathinfo((string) $candidate, PATHINFO_EXTENSION));
        // 双扩展名：appimage 之类是一个词，但 .tar.gz 的 pathinfo 只会给 gz，够用
        if (softnet_allowed_ext($ext)) {
            return $ext;
        }
    }
    return null;
}

/** 只看「地址里有没有写扩展名」，不管它在不在白名单：为的是一句更准的拒绝理由 */
function software_raw_ext($remoteName, $url)
{
    foreach ([$remoteName, (string) parse_url($url, PHP_URL_PATH)] as $candidate) {
        $ext = strtolower((string) pathinfo((string) $candidate, PATHINFO_EXTENSION));
        if ($ext !== '') {
            return $ext;
        }
    }
    return '';
}

/**
 * 浏览器「另存为」看到的文件名。
 *
 * 以远端给的名字为主（那是官方包名，用户认得），洗过之后兜一个自己拼的：
 * 名称 + 版本 + 扩展名。两个来源都已经过 softnet_safe_name，
 * 所以这个值可以直接进 Content-Disposition，不会再拼出换行或引号。
 */
function software_download_name(array $row, $remoteName, $ext)
{
    $name = softnet_safe_name($remoteName);
    if ($name === '') {
        $base = softnet_safe_name($row['slug'] !== null && $row['slug'] !== '' ? $row['slug'] : $row['name']);
        $version = softnet_safe_name((string) $row['version']);
        $name = ($base !== '' ? $base : 'soft-' . (int) $row['id'])
            . ($version !== '' ? '-' . ltrim($version, 'vV') : '') . '.' . $ext;
    }
    // 扩展名不对就换掉：用户拿到一个没有类型、或类型和实际内容不符的文件最难解释
    if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== strtolower($ext)) {
        $stem = softnet_safe_name(preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $name));
        $name = ($stem !== '' ? $stem : 'soft-' . (int) $row['id']) . '.' . $ext;
    }
    return mb_substr($name, 0, 160, 'UTF-8');
}

/** 换过扩展名之后，把这一款剩下的旧安装包删掉（图标不是安装包，不能顺手删） */
function software_prune_old_file($id, $keepExt)
{
    $keep = basename(software_file_path($id, $keepExt));
    foreach ((array) glob(softnet_dir() . '/' . (int) $id . '.*') as $file) {
        $name = basename(str_replace('\\', '/', $file));
        // 图标叫 <id>.icon.png，它不属于「安装包」这一类
        if (strpos($name, '.icon.') !== false) {
            continue;
        }
        if ($name !== $keep) {
            @unlink($file);
        }
    }
}

/**
 * 删掉一款软件在磁盘上的文件。
 *
 * 两种名字都要扫：正式包与它的 .part 半成品叫 <id>.*，抓包中途的暂存叫 grab-<id>.*。
 * $keepIcon 分开是因为「释放安装包」不该顺手把管理员传好的图标删掉——
 * 那是另一件东西，下一次抓包之后还要用。
 */
function software_drop_files($id, $keepIcon = false)
{
    $id = (int) $id;
    foreach ([$id . '.*', 'grab-' . $id . '.*'] as $pattern) {
        foreach ((array) glob(softnet_dir() . '/' . $pattern) as $file) {
            $name = basename(str_replace('\\', '/', $file));
            if ($keepIcon && strpos($name, '.icon.') !== false) {
                continue;
            }
            @unlink($file);
        }
    }
}

/**
 * 新增一款软件。抓包不在这里发生——先存下条目，再由管理员点「抓取安装包」，
 * 因为抓一次可能几十秒，不该塞在一个「保存」里让人以为页面卡住了。
 */
function software_create(array $actor, array $input)
{
    if (software_count() >= (int) cfg('soft_max_count')) {
        throw new ApiException('仓库里已经有 ' . (int) cfg('soft_max_count') . ' 款，先删掉一些再加', 409);
    }

    $limits = software_limits();
    $row = [
        'slug' => software_slug(isset($input['slug']) ? $input['slug'] : ''),
        'name' => software_text(isset($input['name']) ? $input['name'] : '', $limits['name'], '软件名', true),
        'category' => software_text(isset($input['category']) ? $input['category'] : '', $limits['category'], '分类', true),
        'platforms' => software_platforms(isset($input['platforms']) ? $input['platforms'] : []),
        'tags' => software_tags(isset($input['tags']) ? $input['tags'] : []),
        'description' => software_description(isset($input['description']) ? $input['description'] : ''),
        'homepage' => software_url(isset($input['homepage']) ? $input['homepage'] : '', '官网', $limits['url']),
        'github_url' => software_url(isset($input['githubUrl']) ? $input['githubUrl'] : '', 'GitHub 仓库地址', $limits['url']),
        'gitee_url' => software_url(isset($input['giteeUrl']) ? $input['giteeUrl'] : '', 'Gitee 仓库地址', $limits['url']),
        'download_url' => software_url(isset($input['downloadUrl']) ? $input['downloadUrl'] : '', '安装包直链', $limits['downloadUrl']),
        'source_mode' => software_source_mode(isset($input['sourceMode']) ? $input['sourceMode'] : 'auto'),
        'version' => software_version(isset($input['version']) ? $input['version'] : ''),
        'license' => software_text(isset($input['license']) ? $input['license'] : '', $limits['license'], '协议'),
        'star_count' => software_star_count(isset($input['starCount']) ? $input['starCount'] : null),
        'sort_order' => software_sort_order(isset($input['sortOrder']) ? $input['sortOrder'] : 100),
        'enabled' => array_key_exists('enabled', $input) ? ($input['enabled'] ? 1 : 0) : 1,
    ];

    $stmt = db()->prepare(
        'INSERT INTO softs (slug, name, category, platforms, tags, description, homepage,
                            github_url, gitee_url, download_url, source_mode, version, license,
                            star_count, sort_order, enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    try {
        $stmt->execute([
            $row['slug'], $row['name'], $row['category'], $row['platforms'], $row['tags'],
            $row['description'], $row['homepage'], $row['github_url'], $row['gitee_url'],
            $row['download_url'], $row['source_mode'], $row['version'], $row['license'],
            $row['star_count'], $row['sort_order'], $row['enabled'],
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new ApiException('这个英文标识已经被占用了，换一个或留空', 409);
        }
        throw $e;
    }

    $id = (int) db()->lastInsertId();
    app_log('info', '软件仓库新增', ['actor' => $actor['username'], 'id' => $id, 'name' => $row['name']]);

    return software_to_item(software_find($id), true);
}

/** 部分更新：只改传进来的字段。上下架与排序也走这里，前端一个按钮就够 */
function software_update(array $actor, $id, array $fields)
{
    $row = software_find($id);
    if ($row === null) {
        throw new ApiException('这个软件不存在，可能已被删除', 404);
    }

    $limits = software_limits();
    $map = [
        'name' => ['name', function ($v) use ($limits) { return software_text($v, $limits['name'], '软件名', true); }],
        'slug' => ['slug', 'software_slug'],
        'category' => ['category', function ($v) use ($limits) { return software_text($v, $limits['category'], '分类', true); }],
        'platforms' => ['platforms', 'software_platforms'],
        'tags' => ['tags', 'software_tags'],
        'description' => ['description', 'software_description'],
        'homepage' => ['homepage', function ($v) use ($limits) { return software_url($v, '官网', $limits['url']); }],
        'githubUrl' => ['github_url', function ($v) use ($limits) { return software_url($v, 'GitHub 仓库地址', $limits['url']); }],
        'giteeUrl' => ['gitee_url', function ($v) use ($limits) { return software_url($v, 'Gitee 仓库地址', $limits['url']); }],
        'downloadUrl' => ['download_url', function ($v) use ($limits) { return software_url($v, '安装包直链', $limits['downloadUrl']); }],
        'sourceMode' => ['source_mode', 'software_source_mode'],
        'version' => ['version', 'software_version'],
        'license' => ['license', function ($v) use ($limits) { return software_text($v, $limits['license'], '协议'); }],
        'starCount' => ['star_count', 'software_star_count'],
        'sortOrder' => ['sort_order', 'software_sort_order'],
        'enabled' => ['enabled', function ($v) { return $v ? 1 : 0; }],
    ];

    $sets = [];
    $params = [];
    foreach ($fields as $key => $value) {
        if (!isset($map[$key])) {
            continue;
        }
        $sets[] = $map[$key][0] . ' = ?';
        $params[] = $map[$key][1]($value);
    }
    if ($sets === []) {
        throw new ApiException('没有需要修改的内容', 422);
    }

    $params[] = (int) $id;
    try {
        db()->prepare('UPDATE softs SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new ApiException('这个英文标识已经被占用了，换一个或留空', 409);
        }
        throw $e;
    }

    app_log('info', '软件仓库修改', [
        'actor' => $actor['username'],
        'id' => (int) $id,
        'fields' => implode(',', array_keys($fields)),
    ]);

    return software_to_item(software_find($id), true);
}

/**
 * 删除一款软件，连本地安装包一起删。
 *
 * 先删库再删文件：反过来的话，文件删了但数据库行没删掉，
 * 页面上会留下一个永远下载失败的按钮；现在的顺序最坏只是磁盘上多一份孤儿文件，
 * 而那是能被「重新抓包」覆盖掉的。
 */
function software_delete(array $actor, $id)
{
    $row = software_find($id);
    if ($row === null) {
        throw new ApiException('这个软件不存在，可能已被删除', 404);
    }

    db()->prepare('DELETE FROM softs WHERE id = ?')->execute([(int) $row['id']]);
    software_drop_files((int) $row['id']);

    app_log('warning', '软件仓库删除', ['actor' => $actor['username'], 'id' => (int) $row['id'], 'name' => $row['name']]);

    return ['deleted' => (int) $row['id'], 'name' => $row['name']];
}

/** 丢掉这一款已经抓下来的安装包（软件还在，只是不再由服务器代下载） */
function software_release_file(array $actor, $id)
{
    $row = software_find($id);
    if ($row === null) {
        throw new ApiException('这个软件不存在，可能已被删除', 404);
    }
    if ($row['file_fetched_at'] === null) {
        throw new ApiException('这一款还没有本地安装包', 409);
    }

    db()->prepare(
        'UPDATE softs SET file_ext = NULL, size_bytes = NULL, file_sha256 = NULL,
                file_display = NULL, file_origin = NULL, file_fetched_at = NULL
          WHERE id = ?'
    )->execute([(int) $row['id']]);
    // 只放开安装包：管理员传好的图标不是安装包的一部分，下一次抓包之后还要用
    software_drop_files((int) $row['id'], true);

    app_log('warning', '软件仓库释放安装包', ['actor' => $actor['username'], 'id' => (int) $row['id'], 'name' => $row['name']]);

    return software_to_item(software_find((int) $row['id']), true);
}

// ------------------------------------------------------------
// 图标：管理员上传、智能获取、清除
// ------------------------------------------------------------

/**
 * 按文件头判断一枚图标是什么格式，只认这五种。
 *
 * 不看文件名，也不看对方给的 Content-Type——这两个都是外部输入，
 * 而图标会被浏览器当图片渲染在我们自己的页面上。
 * SVG 刻意不收：它是可以带脚本的 XML，从本站域名下发等于给自己开一个同源执行的口子。
 */
function software_icon_kind($binary)
{
    if (!is_string($binary) || $binary === '') {
        return null;
    }
    if (strncmp($binary, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return 'png';
    }
    if (strncmp($binary, 'GIF87a', 6) === 0 || strncmp($binary, 'GIF89a', 6) === 0) {
        return 'gif';
    }
    if (strncmp($binary, "\xFF\xD8\xFF", 3) === 0) {
        return 'jpg';
    }
    if (strlen($binary) >= 12 && strncmp($binary, 'RIFF', 4) === 0 && substr($binary, 8, 4) === 'WEBP') {
        return 'webp';
    }
    if (strncmp($binary, "\x00\x00\x01\x00", 4) === 0) {
        return 'ico';
    }
    return null;
}

/** 把 data:URL 或纯 base64 解成正文。前端不必先把前缀切掉，但也绝不把警告喷给前端 */
function software_icon_from_base64($image)
{
    $text = trim((string) $image);
    if ($text === '') {
        throw new ApiException('请选择一个图标文件', 422);
    }
    if (strpos($text, 'data:') === 0) {
        $comma = strpos($text, ',');
        if ($comma === false) {
            throw new ApiException('图标内容读不出来，请重新选一个文件', 422);
        }
        $text = substr($text, $comma + 1);
    }
    // 先按长度拦一道：解码会把内存翻一倍，不该让一个几 MB 的字符串走进 base64_decode
    $max = (int) cfg('soft_icon_max_bytes');
    if (strlen($text) > $max * 2) {
        throw new ApiException('图标太大了，最多 ' . system_format_bytes($max), 413);
    }
    $binary = base64_decode(preg_replace('/\s+/', '', $text), true);
    if ($binary === false || $binary === '') {
        throw new ApiException('图标内容读不出来，请重新选一个文件', 422);
    }
    return $binary;
}

/**
 * 收下这一枚图标：写到 <id>.icon.<格式>，再把文件名与格式写回库。
 *
 * 上传与智能获取共用这一步——两条路来的字节都要过同一道内容与大小检查，
 * 否则「远端给的东西」会比「管理员亲手选的」少一层把关。
 */
function software_store_icon(array $row, $binary)
{
    $max = (int) cfg('soft_icon_max_bytes');
    if ($binary === '') {
        throw new ApiException('图标是空的，重新选一个文件', 422);
    }
    if (strlen($binary) > $max) {
        throw new ApiException('图标有 ' . system_format_bytes(strlen($binary)) . '，超过 ' . system_format_bytes($max) . ' 的上限', 413);
    }
    $kind = software_icon_kind($binary);
    if ($kind === null) {
        throw new ApiException('只接受 png / gif / jpg / webp / ico，而且是按文件内容判断（SVG 不收）', 415);
    }

    $id = (int) $row['id'];
    if (!ensure_dir(softnet_dir())) {
        throw new ApiException('图标目录不可写，请检查 var 目录的权限', 500);
    }
    if (@file_put_contents(software_icon_path($id, $kind), $binary) === false) {
        throw new ApiException('图标写入失败，请检查 var 目录的权限', 500);
    }
    // 格式换了（原来那枚是 ico，这次传的是 png）就把旧的删掉：
    // 一款软件在磁盘上留两枚图标，将来没人知道页面上画的是哪一枚
    $old = strtolower((string) $row['icon_kind']);
    if ($old !== '' && $old !== $kind) {
        @unlink(software_icon_path($id, $old));
    }

    db()->prepare('UPDATE softs SET icon_file = ?, icon_kind = ? WHERE id = ?')
        ->execute([$id . '.icon.' . $kind, $kind, $id]);

    return ['kind' => $kind, 'bytes' => strlen($binary)];
}

/** POST /api/admin/software/icon —— 管理员自己传一枚图标（base64 走 JSON，与全站「写操作都是 JSON」一致） */
function software_upload_icon(array $actor, $id, $image)
{
    $row = software_find($id);
    if ($row === null) {
        throw new ApiException('这个软件不存在，可能已被删除', 404);
    }

    $saved = software_store_icon($row, software_icon_from_base64($image));
    app_log('info', '软件仓库上传图标', [
        'actor' => $actor['username'],
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'kind' => $saved['kind'],
        'bytes' => $saved['bytes'],
    ]);

    return ['software' => software_to_item(software_find((int) $row['id']), true)] + $saved;
}

/**
 * POST /api/admin/software/icon/fetch —— 「智能获取」：
 * 从已经填好的 GitHub / Gitee 仓库或官网顺手取一枚图标，不必管理员再去找一张。
 *
 * 走的是与抓安装包同一道闸门（白名单、解析后按 IP 建连、逐跳复查、字节上限）。
 * 图标这条没有任何理由比安装包松：它同样是「让服务器替你访问一个地址」。
 */
function software_fetch_icon(array $actor, $id)
{
    $row = software_find($id);
    if ($row === null) {
        throw new ApiException('这个软件不存在，可能已被删除', 404);
    }

    $candidates = software_icon_candidates($row);
    if ($candidates === []) {
        throw new ApiException(
            '这一款既没填 GitHub、Gitee 仓库地址，也没填官网，智能获取没有地方可去。先填一个地址，或者直接上传图标',
            409
        );
    }

    $tried = [];
    foreach ($candidates as $label => $candidate) {
        try {
            $url = isset($candidate['api']) ? software_repo_avatar_url($candidate['api']) : $candidate['image'];
            $saved = software_store_icon($row, software_icon_image($url));
            app_log('info', '软件仓库智能获取图标', [
                'actor' => $actor['username'],
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'via' => $label,
                'kind' => $saved['kind'],
            ]);
            return ['software' => software_to_item(software_find((int) $row['id']), true), 'via' => $label] + $saved;
        } catch (ApiException $e) {
            // 一个来源取不到不该让整件事报错，记一句原因接着试下一个（与挑安装包那边同一个脾气）
            $tried[] = $label . '（' . $e->getMessage() . '）';
        }
    }

    throw new ApiException('没有取到图标：' . implode('；', $tried) . '。可以自己上传一张。', 409);
}

/** 智能获取的候选与顺序：GitHub → Gitee → 官网 favicon（与抓包那边的优先级保持一致） */
function software_icon_candidates(array $row)
{
    $out = [];
    $github = software_repo_path((string) $row['github_url']);
    if ($github !== null) {
        $out['GitHub'] = ['api' => software_repo_api('github', $github['owner'], $github['repo'])];
    }
    $gitee = software_repo_path((string) $row['gitee_url']);
    if ($gitee !== null) {
        $out['Gitee'] = ['api' => software_repo_api('gitee', $gitee['owner'], $gitee['repo'])];
    }
    $favicon = software_favicon_url((string) $row['homepage']);
    if ($favicon !== null) {
        $out['官网'] = ['image' => $favicon];
    }
    return $out;
}

/** 官网地址 → 同协议同主机的 favicon 地址；主页没填或不是 http(s) 就返回 null */
function software_favicon_url($homepage)
{
    $parts = parse_url(trim((string) $homepage));
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }
    $authority = $parts['host'];
    if (isset($parts['port'])) {
        $authority .= ':' . (int) $parts['port'];
    }
    return $scheme . '://' . $authority . '/favicon.ico';
}

/** 仓库信息里头像地址住的位置：GitHub 在 owner.avatar_url，Gitee 在 namespace / user 下 */
function software_avatar_field(array $data)
{
    foreach ([['owner', 'avatar_url'], ['namespace', 'avatar_url'], ['user', 'avatar_url']] as $path) {
        $node = $data;
        foreach ($path as $key) {
            if (!is_array($node) || !isset($node[$key])) {
                $node = null;
                break;
            }
            $node = $node[$key];
        }
        if (is_string($node) && $node !== '') {
            return $node;
        }
    }
    return null;
}

function software_repo_avatar_url($api, $accept = 'application/vnd.github+json')
{
    $url = software_avatar_field(software_repo_json($api, $accept));
    if ($url === null) {
        throw new ApiException('仓库信息里没有头像地址', 404);
    }
    return $url;
}

/** 取一个地址指向的图片正文：图标很小，用留在内存里的那份响应就够，不必走流式落盘那条路 */
function software_icon_image($url)
{
    $res = softnet_request($url, [
        'accept' => 'image/*',
        'maxBytes' => (int) cfg('soft_icon_max_bytes'),
    ]);
    if ($res['status'] !== 200) {
        throw new ApiException('图标地址返回 HTTP ' . $res['status'], 502);
    }
    if ($res['body'] === '') {
        throw new ApiException('图标地址给的是一个空文件', 502);
    }
    return $res['body'];
}

/** 清除图标：软件留着，卡片退回首字母占位。没有图标时是 409 而不是静默成功 */
function software_clear_icon(array $actor, $id)
{
    $row = software_find($id);
    if ($row === null) {
        throw new ApiException('这个软件不存在，可能已被删除', 404);
    }
    if ($row['icon_file'] === null || $row['icon_file'] === '') {
        throw new ApiException('这一款还没有本站图标', 409);
    }

    db()->prepare('UPDATE softs SET icon_file = NULL, icon_kind = NULL WHERE id = ?')
        ->execute([(int) $row['id']]);
    foreach ((array) glob(softnet_dir() . '/' . (int) $row['id'] . '.icon.*') as $file) {
        @unlink($file);
    }

    app_log('info', '软件仓库清除图标', ['actor' => $actor['username'], 'id' => (int) $row['id'], 'name' => $row['name']]);

    return software_to_item(software_find((int) $row['id']), true);
}

/**
 * 取出一个要发给浏览器的本地文件（安装包或图标）。
 *
 * 这里把「路径」这件事收在唯一一处：调用方只给 id 与类型，
 * 文件名由数据库里的 file_ext / icon_kind 现算，再 realpath 确认一次仍在 soft_dir 内。
 * 数据库被改坏、或者有人塞进来一个奇怪的扩展名，都到不了 readfile。
 *
 * $isAdmin 只影响一件事：下架的条目对访客不存在，但编辑器里还要画得出那枚图标、
 * 也还要能确认已经抓下来的包，所以管理员这一路放行。
 *
 * @return array ['path','name','mime','inline']
 */
function software_serve_target($id, $kind = 'file', $isAdmin = false)
{
    $row = software_find($id);
    if ($row === null || ((int) $row['enabled'] !== 1 && !$isAdmin)) {
        throw new ApiException('没有这个文件', 404);
    }
    $inline = false;

    if ($kind === 'icon') {
        if ($row['icon_file'] === null || $row['icon_file'] === '') {
            throw new ApiException('这一款还没有图标', 404);
        }
        $kindExt = strtolower((string) $row['icon_kind']);
        $path = software_icon_path((int) $row['id'], $kindExt);
        $name = softnet_safe_name($row['slug'] !== null && $row['slug'] !== '' ? $row['slug'] : $row['name']) . '.' . $kindExt;
        $mime = ['png' => 'image/png', 'ico' => 'image/x-icon', 'gif' => 'image/gif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg'][$kindExt] ?? 'application/octet-stream';
        // 只可能收到这五种位图（SVG 在 software_icon_kind 那一关就被拒了），
        // 所以从本站域名就地显示是安全的：它不会是能执行的脚本
        $inline = $mime !== 'application/octet-stream';
    } else {
        if ($row['file_fetched_at'] === null || $row['file_ext'] === null || $row['file_ext'] === '') {
            throw new ApiException('这一款还没有服务器代下载的安装包', 404);
        }
        $ext = strtolower((string) $row['file_ext']);
        if (!softnet_allowed_ext($ext)) {
            throw new ApiException('安装包扩展名不合法', 404);
        }
        $path = software_file_path((int) $row['id'], $ext);
        $name = (string) $row['file_display'];
        // 兜底：库里这一列被改坏过也要有一个能下载的名字，绝不把外部输入直接拼进响应头
        if (softnet_safe_name($name) !== $name || $name === '') {
            $name = software_download_name($row, '', $ext);
        }
        $mime = 'application/octet-stream';
    }

    $real = realpath($path);
    $root = realpath(softnet_dir());
    if ($real === false || $root === false || strpos(str_replace('\\', '/', $real), str_replace('\\', '/', $root) . '/') !== 0) {
        throw new ApiException('文件已经不在服务器上了，请让管理员重新抓取', 404);
    }

    return ['path' => $real, 'name' => $name, 'mime' => $mime, 'inline' => $inline];
}

/** 软件条数（后台面板与系统状态用） */
function software_count()
{
    return (int) db()->query('SELECT COUNT(*) FROM softs')->fetchColumn();
}

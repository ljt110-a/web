<?php
/**
 * 游戏板块：一张「内容表」，前台只读，内容由管理员通过接口维护。
 *
 * 「预留添加接口」具体落到了哪些地方：
 *   1. 表结构里把以后会用到的字段一次留好——英文标识（锚点用）、厂商、类型、简介、
 *      图标、排序、上下架开关。加一个游戏只是加一条记录，不用再改表
 *   2. 新增接口 POST /api/admin/games 的校验是完整的，页面上的表单、以后写的别的客户端
 *      （脚本、App、导入工具）都能直接拿来用
 *   3. 初始内容只在表为空时写入，之后一切由接口维护，安装脚本不会覆盖你改过的数据
 *
 * 权限：读公开（未登录也能看游戏列表），增删改一律要管理员。
 * 地址只允许 http / https —— 这一条是安全要求，见 validate_game_url()。
 */

function game_max_name()
{
    return 40;
}

function game_max_description()
{
    return 300;
}

// ------------------------------------------------------------
// 校验
// ------------------------------------------------------------

function validate_game_name($name)
{
    $name = trim((string) $name);
    $len = mb_strlen($name, 'UTF-8');
    if ($len === 0) {
        throw new ApiException('请填写游戏名', 422);
    }
    if ($len > game_max_name()) {
        throw new ApiException('游戏名最多 ' . game_max_name() . ' 个字（当前 ' . $len . ' 个）', 422);
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $name)) {
        throw new ApiException('游戏名包含非法字符', 422);
    }
    return $name;
}

/**
 * 跳转地址校验。
 * 只允许 http / https：这是安全要求——这个值会被渲染成 <a href>，
 * 一旦容许 javascript: 之类的伪协议写进来，点这张卡片就等于在别人浏览器里执行脚本。
 * 前端那边也只管拼 DOM，真正的把关在这里。
 */
function validate_game_url($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        throw new ApiException('请填写跳转地址', 422);
    }
    if (mb_strlen($url, 'UTF-8') > 300) {
        throw new ApiException('跳转地址最长 300 个字符', 422);
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $url)) {
        throw new ApiException('跳转地址包含非法字符', 422);
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new ApiException('跳转地址必须以 http:// 或 https:// 开头', 422);
    }
    if (parse_url($url, PHP_URL_HOST) === null) {
        throw new ApiException('跳转地址里缺少域名', 422);
    }
    return $url;
}

/** 厂商 / 类型这类「可空、单行」的短文本 */
function validate_game_short_text($value, $maxLen, $label)
{
    $value = trim(str_replace(["\r\n", "\r"], ' ', (string) $value));
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value, 'UTF-8') > $maxLen) {
        throw new ApiException($label . '最多 ' . $maxLen . ' 个字', 422);
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new ApiException($label . '包含非法字符', 422);
    }
    return $value;
}

/** 简介：允许换行，会由前端用 pre-wrap 原样显示 */
function validate_game_description($value)
{
    $value = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    if ($value === '') {
        return '';
    }
    if (mb_strlen($value, 'UTF-8') > game_max_description()) {
        throw new ApiException('简介最多 ' . game_max_description() . ' 个字', 422);
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
        throw new ApiException('简介包含非法字符', 422);
    }
    return $value;
}

/** 英文标识：留空返回 null（唯一索引允许多个 null，不必强迫管理员起名） */
function validate_game_slug($value)
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $value)) {
        throw new ApiException('英文标识只能用 2~40 个小写字母、数字与连字符，且不能以连字符开头', 422);
    }
    return $value;
}

/** 图标：期望是一个 emoji，这里只限制长度与非法字符 */
function validate_game_icon($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value, 'UTF-8') > 4) {
        throw new ApiException('图标最多 4 个字符（建议只填一个 emoji）', 422);
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new ApiException('图标包含非法字符', 422);
    }
    return $value;
}

/** 排序值：非数字就当默认值，数字则夹在合理范围内 */
function validate_game_sort($value)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return 100;
    }
    return max(-9999, min(9999, (int) $value));
}

// ------------------------------------------------------------
// 读取
// ------------------------------------------------------------

/**
 * 数据库行 → 给前端的视图。
 * $viewer 传管理员时才会带上 canManage，前台拿到的一律是 false。
 */
function game_to_item(array $row, array $viewer = null)
{
    $isAdmin = $viewer !== null && $viewer['role'] === 'admin';
    $slug = isset($row['slug']) ? (string) $row['slug'] : '';

    return [
        'id' => (int) $row['id'],
        // 锚点：没有 slug 就退回用自增 id，保证每张卡片都有稳定的定位点
        'anchor' => $slug !== '' ? 'game-' . $slug : 'game-' . (int) $row['id'],
        'slug' => $slug === '' ? null : $slug,
        'name' => $row['name'],
        'publisher' => $row['publisher'],
        'genre' => $row['genre'],
        'description' => $row['description'],
        'url' => $row['url'],
        'icon' => ($row['icon'] === null || $row['icon'] === '') ? '🎮' : $row['icon'],
        'sortOrder' => (int) $row['sort_order'],
        'enabled' => (int) $row['enabled'] === 1,
        'createdAt' => format_datetime($row['created_at']),
        'canManage' => $isAdmin,
    ];
}

function game_find($id)
{
    $stmt = db()->prepare(
        'SELECT id, slug, name, publisher, genre, description, url, icon,
                sort_order, enabled, created_at
           FROM games WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * 游戏列表。
 * 前台只看到上架的；管理员看得到全部（含已下架的），否则下架之后就没法再上架了。
 */
function game_list(array $viewer = null)
{
    $isAdmin = $viewer !== null && $viewer['role'] === 'admin';

    $sql = 'SELECT id, slug, name, publisher, genre, description, url, icon,
                   sort_order, enabled, created_at
              FROM games'
        . ($isAdmin ? '' : ' WHERE enabled = 1')
        . ' ORDER BY sort_order ASC, id ASC';

    $items = [];
    foreach (db()->query($sql)->fetchAll() as $row) {
        $items[] = game_to_item($row, $viewer);
    }

    return [
        'items' => $items,
        'total' => count($items),
        // 前端靠这个决定要不要显示管理区，真正的权限判断仍然在后端
        'manageable' => $isAdmin,
        'maxName' => game_max_name(),
        'maxDescription' => game_max_description(),
    ];
}

// ------------------------------------------------------------
// 写入（都是管理员操作）
// ------------------------------------------------------------

/** 新增一个游戏，返回刚创建的那条 */
function game_create(array $actor, array $input)
{
    $slug = validate_game_slug(isset($input['slug']) ? $input['slug'] : '');
    $name = validate_game_name(isset($input['name']) ? $input['name'] : '');
    $url = validate_game_url(isset($input['url']) ? $input['url'] : '');
    $publisher = validate_game_short_text(isset($input['publisher']) ? $input['publisher'] : '', 40, '厂商');
    $genre = validate_game_short_text(isset($input['genre']) ? $input['genre'] : '', 30, '类型');
    $description = validate_game_description(isset($input['description']) ? $input['description'] : '');
    $icon = validate_game_icon(isset($input['icon']) ? $input['icon'] : '');
    $sortOrder = validate_game_sort(isset($input['sortOrder']) ? $input['sortOrder'] : 100);

    $stmt = db()->prepare(
        'INSERT INTO games (slug, name, publisher, genre, description, url, icon, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    try {
        $stmt->execute([$slug, $name, $publisher, $genre, $description, $url, $icon, $sortOrder]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new ApiException('这个英文标识已经被占用了，换一个或留空', 409);
        }
        throw $e;
    }

    $row = game_find((int) db()->lastInsertId());
    if ($row === null) {
        throw new ApiException('保存失败，请重试', 500);
    }
    app_log('info', '新增游戏', ['actor' => $actor['username'], 'name' => $name]);
    return game_to_item($row, $actor);
}

/**
 * 部分更新：只改传进来的字段，列名走白名单。
 * 上下架也走这里（传 enabled），所以前端一个按钮就能切换。
 */
function game_update(array $actor, $id, array $fields)
{
    $row = game_find($id);
    if ($row === null) {
        throw new ApiException('这个游戏不存在，可能已被删除', 404);
    }

    $sets = [];
    $params = [];

    if (array_key_exists('name', $fields)) {
        $sets[] = 'name = ?';
        $params[] = validate_game_name($fields['name']);
    }
    if (array_key_exists('slug', $fields)) {
        $sets[] = 'slug = ?';
        $params[] = validate_game_slug($fields['slug']);
    }
    if (array_key_exists('publisher', $fields)) {
        $sets[] = 'publisher = ?';
        $params[] = validate_game_short_text($fields['publisher'], 40, '厂商');
    }
    if (array_key_exists('genre', $fields)) {
        $sets[] = 'genre = ?';
        $params[] = validate_game_short_text($fields['genre'], 30, '类型');
    }
    if (array_key_exists('description', $fields)) {
        $sets[] = 'description = ?';
        $params[] = validate_game_description($fields['description']);
    }
    if (array_key_exists('url', $fields)) {
        $sets[] = 'url = ?';
        $params[] = validate_game_url($fields['url']);
    }
    if (array_key_exists('icon', $fields)) {
        $sets[] = 'icon = ?';
        $params[] = validate_game_icon($fields['icon']);
    }
    if (array_key_exists('sortOrder', $fields)) {
        $sets[] = 'sort_order = ?';
        $params[] = validate_game_sort($fields['sortOrder']);
    }
    if (array_key_exists('enabled', $fields)) {
        $sets[] = 'enabled = ?';
        $params[] = $fields['enabled'] ? 1 : 0;
    }

    if ($sets === []) {
        throw new ApiException('没有需要修改的内容', 422);
    }

    $params[] = (int) $id;
    try {
        db()->prepare('UPDATE games SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new ApiException('这个英文标识已经被占用了，换一个或留空', 409);
        }
        throw $e;
    }

    app_log('info', '修改游戏', [
        'actor' => $actor['username'],
        'id' => (int) $id,
        'fields' => implode(',', array_keys($fields)),
    ]);

    return game_to_item(game_find($id), $actor);
}

function game_delete(array $actor, $id)
{
    $stmt = db()->prepare('SELECT id, name FROM games WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch();
    if ($row === false) {
        throw new ApiException('这个游戏不存在，可能已被删除', 404);
    }

    db()->prepare('DELETE FROM games WHERE id = ?')->execute([(int) $row['id']]);
    app_log('warning', '删除游戏', ['actor' => $actor['username'], 'name' => $row['name']]);

    return ['deleted' => (int) $row['id'], 'name' => $row['name']];
}

/** 游戏总数（后台与系统状态面板展示用） */
function game_count()
{
    return (int) db()->query('SELECT COUNT(*) FROM games')->fetchColumn();
}

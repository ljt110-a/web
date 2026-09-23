<?php
/**
 * 留言板。
 *
 * 权限设计：**读公开，写要登录**。
 *   - 游客也能看留言，留言板对未注册的访客才有意义
 *   - 发帖必须是登录用户，这样每条留言都有明确作者，不必处理匿名刷屏
 *   - 删除限「作者本人」或「管理员」
 *
 * 发帖限流直接用 messages 表自己的近期行数当依据（见 message_rate_guard），
 * 不额外维护计数表——少一张表就少一处「计数和真实数据对不上」的可能。
 */

/** 单条留言的最大字数 */
function message_max_len()
{
    return (int) cfg('message_max_len');
}

/** 留言列表的每页条数范围 */
function message_page_bounds()
{
    return [1, max(1, (int) cfg('message_max_page_size'))];
}

/** 正文规范化 + 校验 */
function validate_message_body($body)
{
    // 先把所有换行统一成 \n：前端 textarea 提交的是 \r\n，
    // 统一之后存进库里的内容在任何平台上显示都一致
    $body = str_replace(["\r\n", "\r"], "\n", (string) $body);
    $body = trim($body);

    $len = mb_strlen($body, 'UTF-8');
    if ($len === 0) {
        throw new ApiException('留言内容不能为空', 422);
    }
    if ($len > message_max_len()) {
        throw new ApiException('留言最多 ' . message_max_len() . ' 个字（当前 ' . $len . ' 个）', 422);
    }
    // 控制字符会让页面排版和日志都乱掉；换行(\n)与制表符(\t)是有意义的，放行
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $body)) {
        throw new ApiException('留言包含非法字符', 422);
    }
    return $body;
}

/**
 * 发帖限流：数这个账号在窗口内已经发了几条。
 * 解锁自然是靠最早那条留言滑出窗口，不需要额外状态。
 */
function message_rate_guard($userId)
{
    $max = (int) cfg('message_rate_max');
    $window = (int) cfg('message_rate_window');
    if ($max <= 0 || $window <= 0) {
        return;
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*) AS sent, MIN(created_at) AS oldest
           FROM messages
          WHERE user_id = ? AND created_at >= NOW() - INTERVAL ? SECOND'
    );
    $stmt->execute([(int) $userId, $window]);
    $row = $stmt->fetch();
    if ((int) $row['sent'] < $max) {
        return;
    }

    $retryAfter = max(1, (int) strtotime($row['oldest']) + $window - time());
    header('Retry-After: ' . $retryAfter);
    throw new ApiException(sprintf('发得太快了，请 %d 秒后再试', $retryAfter), 429);
}

/**
 * 数据库行 → 给前端的视图。
 * mine / canDelete 由后端算好，前端就不用自己去比对用户身份——
 * 前端的判断永远只是体验，能不能删最终由 message_delete() 决定。
 */
function message_to_item(array $row, array $viewer = null)
{
    $isAuthor = $viewer !== null && (int) $row['user_id'] === (int) $viewer['id'];
    $isAdmin = $viewer !== null && $viewer['role'] === 'admin';

    return [
        'id' => (int) $row['id'],
        'author' => $row['username'],
        'role' => $row['role'],
        'body' => $row['body'],
        'createdAt' => format_datetime($row['created_at']),
        'mine' => $isAuthor,
        'canDelete' => $isAuthor || $isAdmin,
    ];
}

/** 取一条留言（内部用，带作者信息） */
function message_find($id)
{
    $stmt = db()->prepare(
        'SELECT m.id, m.user_id, m.body, m.created_at, u.username, u.role
           FROM messages m JOIN users u ON u.id = m.user_id
          WHERE m.id = ? LIMIT 1'
    );
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * 留言列表，最新的在前。
 * @param array|null $viewer 当前访客（未登录传 null），只影响 mine / canDelete
 */
function message_list($page, $perPage, array $viewer = null)
{
    list($minPerPage, $maxPerPage) = message_page_bounds();
    $perPage = max($minPerPage, min($maxPerPage, (int) $perPage));
    $page = max(1, (int) $page);

    $total = (int) db()->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $rows = db()->query(
        'SELECT m.id, m.user_id, m.body, m.created_at, u.username, u.role
           FROM messages m JOIN users u ON u.id = m.user_id
          ORDER BY m.id DESC
          LIMIT ' . $perPage . ' OFFSET ' . $offset
    )->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $items[] = message_to_item($row, $viewer);
    }

    return [
        'items' => $items,
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages,
        'rateLimit' => [
            'max' => (int) cfg('message_rate_max'),
            'window' => (int) cfg('message_rate_window'),
        ],
        'maxLen' => message_max_len(),
    ];
}

/** 发一条留言，返回刚创建的那条 */
function message_create(array $user, $body)
{
    $body = validate_message_body($body);
    message_rate_guard((int) $user['id']);

    db()->prepare('INSERT INTO messages (user_id, body) VALUES (?, ?)')
        ->execute([(int) $user['id'], $body]);

    $row = message_find((int) db()->lastInsertId());
    if ($row === null) {
        // 理论上到不了这里；真到了说明插入后立刻被删了，报错好过返回空对象
        throw new ApiException('留言发布失败，请重试', 500);
    }
    return message_to_item($row, $user);
}

/**
 * 删除留言。作者本人或管理员。
 * 权限判断放在服务端一次做完，前端那个置灰的按钮只是提示。
 */
function message_delete($id, array $actor)
{
    $stmt = db()->prepare('SELECT id, user_id FROM messages WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch();
    if ($row === false) {
        throw new ApiException('这条留言不存在，可能已被删除', 404);
    }

    $isAuthor = (int) $row['user_id'] === (int) $actor['id'];
    if (!$isAuthor && $actor['role'] !== 'admin') {
        throw new ApiException('只能删除自己发的留言', 403);
    }

    db()->prepare('DELETE FROM messages WHERE id = ?')->execute([(int) $row['id']]);

    if (!$isAuthor) {
        // 管理员删别人的留言属于「代管行为」，留一条日志方便事后追溯
        app_log('warning', '管理员删除了他人留言', [
            'actor' => $actor['username'],
            'message_id' => (int) $row['id'],
            'author_id' => (int) $row['user_id'],
        ]);
    }
    return ['deleted' => (int) $row['id']];
}

/** 留言总数（后台统计用） */
function message_count()
{
    return (int) db()->query('SELECT COUNT(*) FROM messages')->fetchColumn();
}

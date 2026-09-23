<?php
/**
 * 后台管理：概览数据（统计 + 用户列表）与账号操作。
 * 所有函数都假定调用方已经通过 require_admin() 这一道门槛。
 *
 * 列表是分页 + 可搜索的：用户表迟早会长到「一次全查出来」不合适的规模，
 * 现在就把 API 形状定成带分页元数据的结构，将来不用改前端契约。
 */

/**
 * 每页条数的允许范围（后端夹紧，不信前端传什么就取什么）。
 * 下限放在 1 而不是 5：调试和写测试时需要「一次只看一条」的能力，
 * 真正防的是上限——不设上限的话 ?perPage=100000 就等于让任何管理员一键把全表拉出来。
 */
function admin_per_page_bounds()
{
    return [1, 100];
}

/**
 * 后台面板需要的全部数据。
 * @return array ['stats'=>..., 'users'=>[...], 'paging'=>..., 'search'=>...]
 */
function admin_overview($page = 1, $perPage = 10, $search = '')
{
    // 顺手清理过期数据：后台是管理员最常来的地方，放在这里不需要额外的定时任务
    purge_expired_sessions();
    purge_expired_attempts();
    purge_expired_tokens();

    $stats = visit_stats();
    $stats['daily'] = visit_daily_trend(7);

    $list = admin_list_users($page, $perPage, $search);
    // userCount 是「全表总数」，不是当前这一页的条数——分页之后这两者很容易被写混
    $stats['userCount'] = $list['total'];
    $stats['onlineCount'] = admin_online_count();
    $stats['maxSessionsPerUser'] = (int) cfg('max_sessions_per_user');

    return [
        'stats' => $stats,
        'users' => $list['items'],
        'paging' => [
            'page' => $list['page'],
            'perPage' => $list['perPage'],
            'total' => $list['total'],
            'totalPages' => $list['totalPages'],
        ],
        'search' => $search,
    ];
}

/** 当前在线人数：有未过期会话的账号数 */
function admin_online_count()
{
    return (int) db()->query(
        'SELECT COUNT(DISTINCT user_id) FROM sessions WHERE expires_at > NOW()'
    )->fetchColumn();
}

/**
 * 用户列表：管理员永远排在最前，其余按注册先后。
 * activeSessions 是「该账号当前有效的登录设备数」，为 0 表示不在线。
 *
 * @return array ['items'=>[], 'page'=>int, 'perPage'=>int, 'total'=>int, 'totalPages'=>int]
 */
function admin_list_users($page = 1, $perPage = 10, $search = '')
{
    list($minPerPage, $maxPerPage) = admin_per_page_bounds();
    $perPage = max($minPerPage, min($maxPerPage, (int) $perPage));
    $page = max(1, (int) $page);
    $search = trim((string) $search);

    $where = '';
    $params = [];
    if ($search !== '') {
        // 用户名和邮箱都能搜；LIKE 里的通配符要被转义，
        // 否则用户搜一个 "%" 就会匹配到所有人
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where = ' WHERE u.username LIKE ? OR u.email LIKE ?';
        $params = [$like, $like];
    }

    // 总数要先算，才能知道总页数并把越界的页码拉回最后一页
    $countStmt = db()->prepare('SELECT COUNT(*) FROM users u' . $where);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT u.id, u.username, u.role, u.email, u.email_verified_at,
                   u.created_at, u.last_login_at, u.password_changed_at, u.login_count,
                   (SELECT COUNT(*) FROM sessions s
                     WHERE s.user_id = u.id AND s.expires_at > NOW()) AS active_sessions'
         . ' FROM users u'
         . $where
         . ' ORDER BY (u.role = \'admin\') DESC, u.id ASC'
         . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'username' => $row['username'],
            'role' => $row['role'],
            'email' => $row['email'],
            'emailVerified' => $row['email_verified_at'] !== null,
            'createdAt' => format_datetime($row['created_at']),
            'lastLoginAt' => format_datetime($row['last_login_at']),
            'passwordChangedAt' => format_datetime($row['password_changed_at']),
            'loginCount' => (int) $row['login_count'],
            'activeSessions' => (int) $row['active_sessions'],
            'online' => (int) $row['active_sessions'] > 0,
        ];
    }

    return [
        'items' => $items,
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages,
    ];
}

/**
 * 删除用户。两条护栏：管理员账号不可删，也不能删掉正在登录的自己。
 * 会话表的外键是 ON DELETE CASCADE，所以这个人所有登录令牌会被 MySQL 一并清掉。
 * @return array ['deleted'=>string,'remaining'=>int]
 */
function admin_delete_user($username, array $actor)
{
    $user = find_user($username);
    if ($user === null) {
        throw new ApiException('该用户不存在，可能已被删除', 404);
    }
    if ($user['role'] === 'admin') {
        throw new ApiException('管理员账号不可删除', 403);
    }
    if ((int) $user['id'] === (int) $actor['id']) {
        throw new ApiException('不能删除当前登录的自己', 400);
    }

    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
    app_log('warning', '管理员删除了用户', ['actor' => $actor['username'], 'target' => $user['username']]);

    return [
        'deleted' => $user['username'],
        'remaining' => (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    ];
}

/**
 * 管理员为普通用户重置密码（用户忘了密码又收不到邮件时的兜底手段）。
 *
 * 明确不允许重置另一个管理员的密码：否则任何一个管理员都能悄悄接管
 * 另一个管理员的账号，而系统里目前所有管理员权限是等价的，
 * 这种「横向移动」不该由后台一键提供。管理员要改自己的密码，走 /api/password。
 */
function admin_reset_password($username, $newPassword, array $actor)
{
    $user = find_user($username);
    if ($user === null) {
        throw new ApiException('该用户不存在，可能已被删除', 404);
    }
    if ((int) $user['id'] === (int) $actor['id']) {
        throw new ApiException('这是你自己的账号，请用「修改密码」并输入当前密码', 400);
    }
    if ($user['role'] === 'admin') {
        throw new ApiException('管理员账号的密码不能由后台重置', 403);
    }

    validate_password($newPassword, $user['username']);
    db()->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]);

    // 密码变了，这个人所有设备上的登录都必须失效
    $revoked = revoke_all_sessions((int) $user['id']);
    throttle_clear('login', $user['username']);
    app_log('warning', '管理员重置了用户密码', [
        'actor' => $actor['username'],
        'target' => $user['username'],
        'revoked_sessions' => $revoked,
    ]);

    return ['reset' => $user['username'], 'revokedSessions' => $revoked];
}

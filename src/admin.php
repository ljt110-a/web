<?php
/**
 * 后台管理：概览数据（统计 + 用户列表）与删除用户。
 * 所有函数都假定调用方已经通过 require_admin() 这一道门槛。
 */

/**
 * 后台面板需要的全部数据。
 * @return array ['stats'=>..., 'users'=>[...]]
 */
function admin_overview()
{
    purge_expired_sessions();

    $stats = visit_stats();
    $users = admin_list_users();
    $stats['userCount'] = count($users);

    return ['stats' => $stats, 'users' => $users];
}

/**
 * 用户列表：管理员永远排在最前，其余按注册先后。
 * online 表示“此用户还有未过期的登录会话”，用来显示在线状态。
 */
function admin_list_users()
{
    $sql = 'SELECT u.username, u.role, u.created_at, u.last_login_at, u.login_count,
                   EXISTS(
                     SELECT 1 FROM sessions s
                      WHERE s.user_id = u.id AND s.expires_at > NOW()
                   ) AS online
              FROM users u
             ORDER BY (u.role = \'admin\') DESC, u.id ASC';
    $rows = db()->query($sql)->fetchAll();

    $list = [];
    foreach ($rows as $row) {
        $list[] = [
            'username' => $row['username'],
            'role' => $row['role'],
            'createdAt' => format_datetime($row['created_at']),
            'lastLoginAt' => format_datetime($row['last_login_at']),
            'loginCount' => (int) $row['login_count'],
            'online' => (int) $row['online'] === 1,
        ];
    }
    return $list;
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

    return ['deleted' => $user['username'], 'remaining' => (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn()];
}

/** 数据库时间 → 面板上直接可显示的“2026-09-19 22:14”；空值显示占位横线 */
function format_datetime($value)
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date('Y-m-d H:i', $ts);
}

<?php
/**
 * 学习板块的番茄钟记录。
 *
 * 数据是私有的（和备忘录一样按账号隔离），但这一张表只由前端在「一轮结束」时写一条，
 * 没有编辑：番茄钟记的是发生过的事实，改一条记录等于伪造历史，不如删掉重来。
 *
 * 归属判断依旧写在 SQL 的 WHERE 里（id + user_id 一起当条件），
 * 不是查出来再用 PHP 判断是不是自己的——理由见 memos.php 顶部。
 *
 * 这一张表的读写都走 src/orm.php 的查询构造器，是它的参考用法。
 */

/** 这张表要交给前端的列：写在一处，免得每个查询各列一份、漏一列就在视图里报索引不存在 */
const POMODORO_COLUMNS = ['id', 'subject', 'minutes', 'elapsed', 'finished', 'created_at'];

function pomodoro_max_minutes()
{
    return (int) cfg('pomodoro_max_minutes');
}

function pomodoro_max_subject()
{
    return (int) cfg('pomodoro_max_subject');
}

/** 计划时长：只收 1~上限 的整数分钟，其它一律拒绝而不是悄悄夹到范围内 */
function validate_pomodoro_minutes($minutes)
{
    $minutes = (int) $minutes;
    $min = (int) cfg('pomodoro_min_minutes');
    if ($minutes < $min || $minutes > pomodoro_max_minutes()) {
        throw new ApiException(
            '一轮时长要在 ' . $min . '~' . pomodoro_max_minutes() . ' 分钟之间（当前 ' . $minutes . '）',
            422
        );
    }
    return $minutes;
}

/** 学科 / 任务名：可空，非空时清洗与限长 */
function validate_pomodoro_subject($subject)
{
    $subject = trim(str_replace(["\r\n", "\r"], "\n", (string) $subject));
    // 控制字符换成空格而不是直接删掉：删掉会把「第一行\n第二行」粘成「第一行第二行」。
    // 之后统一压掉连续空白——它只是一个标签，不该在列表里占两行。
    $subject = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject);
    $subject = preg_replace('/\s+/u', ' ', $subject);
    $subject = trim($subject);
    if (mb_strlen($subject, 'UTF-8') > pomodoro_max_subject()) {
        throw new ApiException('名称最多 ' . pomodoro_max_subject() . ' 个字', 422);
    }
    return $subject;
}

/**
 * 实际走秒：允许比计划多出一小段，因为计时器是按秒跳动、跑满那一轮时很容易多算一两秒；
 * 但差得离谱就是前端出问题了，不能记进库。
 */
function validate_pomodoro_elapsed($elapsed, $minutes)
{
    $elapsed = (int) $elapsed;
    $planned = $minutes * 60;
    $slack = 30;
    if ($elapsed < 0 || $elapsed > $planned + $slack) {
        throw new ApiException('实际时长不合理（计划 ' . $planned . ' 秒，收到 ' . $elapsed . ' 秒）', 422);
    }
    return $elapsed;
}

/** 数据库行 → 给前端的视图 */
function pomodoro_to_item(array $row)
{
    $minutes = (int) $row['minutes'];
    $elapsed = (int) $row['elapsed'];
    return [
        'id' => (int) $row['id'],
        'subject' => $row['subject'],
        'minutes' => $minutes,
        'elapsed' => $elapsed,
        'finished' => (int) $row['finished'] === 1,
        // 完成度：中途放弃时给个百分比，比只显示「放弃了」有用
        'percent' => $minutes > 0 ? min(100, (int) round($elapsed / ($minutes * 60) * 100)) : 0,
        'createdAt' => format_datetime($row['created_at']),
    ];
}

/** 取一条（只在属于该用户时才有结果） */
function pomodoro_find($id, $userId)
{
    return db_table('pomodoros')
        ->select(POMODORO_COLUMNS)
        ->where('id', (int) $id)
        ->where('user_id', (int) $userId)
        ->first();
}

/**
 * 统计：总数 / 今天数 / 累计专注分钟。
 * 「今天」用 created_at >= CURDATE() 的范围条件而不是 DATE(created_at) = 今天：
 * 套了函数就用不上 idx_pomodoros_user_created 这个索引了。
 * 四个聚合同时要读，所以走 selectRaw 拼成一条语句，而不是发四次查询。
 */
function pomodoro_stats($userId)
{
    $row = db_table('pomodoros')
        ->selectRaw('COUNT(*)', 'total')
        ->selectRaw('COALESCE(SUM(created_at >= CURDATE()), 0)', 'today')
        ->selectRaw('COALESCE(SUM(finished = 1), 0)', 'finished')
        ->selectRaw('COALESCE(SUM(ROUND(elapsed / 60)), 0)', 'minutes')
        ->where('user_id', (int) $userId)
        ->first();
    return [
        'total' => (int) $row['total'],
        'today' => (int) $row['today'],
        'finished' => (int) $row['finished'],
        'minutes' => (int) $row['minutes'],
        'limit' => (int) cfg('pomodoro_max_count'),
    ];
}

/** 最近若干条记录，新的在前 */
function pomodoro_list($userId, $limit = 30)
{
    $rows = db_table('pomodoros')
        ->select(POMODORO_COLUMNS)
        ->where('user_id', (int) $userId)
        ->orderBy('id', 'desc')
        ->limit(max(1, min(200, (int) $limit)))
        ->get();

    $items = [];
    foreach ($rows as $row) {
        $items[] = pomodoro_to_item($row);
    }
    return $items;
}

/** 记一轮。跑完和中途放弃都记，用 finished 区分。 */
function pomodoro_create($userId, $subject, $minutes, $elapsed, $finished)
{
    $minutes = validate_pomodoro_minutes($minutes);
    $subject = validate_pomodoro_subject($subject);
    $elapsed = validate_pomodoro_elapsed($elapsed, $minutes);

    $stats = pomodoro_stats($userId);
    $cap = (int) cfg('pomodoro_max_count');
    if ($cap > 0 && $stats['total'] >= $cap) {
        throw new ApiException(
            '番茄钟记录已达上限（' . $cap . ' 条），请先删掉一些旧的',
            409
        );
    }

    $id = db_table('pomodoros')->insert([
        'user_id' => (int) $userId,
        'subject' => $subject,
        'minutes' => $minutes,
        'elapsed' => $elapsed,
        'finished' => $finished ? 1 : 0,
    ]);

    $row = pomodoro_find($id, $userId);
    if ($row === null) {
        throw new ApiException('保存失败，请重试', 500);
    }
    return pomodoro_to_item($row);
}

/** 删一条。id + user_id 一起做条件。 */
function pomodoro_delete($userId, $id)
{
    $affected = db_table('pomodoros')
        ->where('id', (int) $id)
        ->where('user_id', (int) $userId)
        ->delete();
    if ($affected === 0) {
        throw new ApiException('这条记录不存在', 404);
    }
    return ['deleted' => (int) $id];
}

/**
 * 一次取回学习板块要显示的全部数据。
 * 未登录时不查库，只把「默认时长」等常量给前端——计时器本身不需要账号也能用。
 */
function pomodoro_view($userId)
{
    if ($userId === null) {
        return [
            'logged' => false,
            'items' => [],
            'stats' => ['total' => 0, 'today' => 0, 'finished' => 0, 'minutes' => 0, 'limit' => (int) cfg('pomodoro_max_count')],
            'defaults' => pomodoro_defaults(),
        ];
    }
    return [
        'logged' => true,
        'items' => pomodoro_list($userId),
        'stats' => pomodoro_stats($userId),
        'defaults' => pomodoro_defaults(),
    ];
}

/** 前端表单要用到的几个边界，统一由后端下发，避免两边各写一份数字 */
function pomodoro_defaults()
{
    return [
        'minutes' => (int) cfg('pomodoro_default_minutes'),
        'minMinutes' => (int) cfg('pomodoro_min_minutes'),
        'maxMinutes' => pomodoro_max_minutes(),
        'maxSubject' => pomodoro_max_subject(),
    ];
}

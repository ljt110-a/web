<?php
/**
 * 备忘录：纯私有数据，每个账号只能看到、修改自己的。
 *
 * 隔离方式值得单独说明：**每一条 SQL 的 WHERE 里都带着 user_id**，
 * 而不是「先按 id 查出来，再判断 user_id 是不是自己的」。
 * 原因很实际：
 *   - 前者如果哪天忘了加条件，结果是查不到数据（功能坏掉，立刻会被发现）
 *   - 后者如果哪天忘了判断，结果是能改别人的数据（越权漏洞，往往很久都没人发现）
 * 所以所有读写都只经由下面这几个函数，且 id 与 user_id 永远一起出现在 WHERE 里。
 *
 * 数据访问走 src/orm.php 的查询构造器（用法见 study.php，这里带上它独有的部分：
 * 按筛选条件加 WHERE、多列 ORDER BY、只改传进来字段的 UPDATE）。
 */

/** 这张表交给前端的列 */
const MEMO_COLUMNS = ['id', 'title', 'body', 'done', 'created_at', 'updated_at'];

function memo_max_title()
{
    return (int) cfg('memo_max_title');
}

function memo_max_body()
{
    return (int) cfg('memo_max_body');
}

/** 正文规范化：统一换行、去掉首尾空白 */
function memo_normalize_text($text)
{
    return trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
}

function validate_memo_title($title)
{
    $title = memo_normalize_text($title);
    $len = mb_strlen($title, 'UTF-8');
    if ($len === 0) {
        throw new ApiException('标题不能为空', 422);
    }
    if ($len > memo_max_title()) {
        throw new ApiException('标题最多 ' . memo_max_title() . ' 个字（当前 ' . $len . ' 个）', 422);
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $title)) {
        throw new ApiException('标题包含非法字符', 422);
    }
    return $title;
}

function validate_memo_body($body)
{
    $body = memo_normalize_text($body);
    if (mb_strlen($body, 'UTF-8') > memo_max_body()) {
        throw new ApiException('正文最多 ' . memo_max_body() . ' 个字', 422);
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $body)) {
        throw new ApiException('正文包含非法字符', 422);
    }
    return $body;
}

/** 数据库行 → 给前端的视图 */
function memo_to_item(array $row)
{
    return [
        'id' => (int) $row['id'],
        'title' => $row['title'],
        'body' => $row['body'],
        'done' => (int) $row['done'] === 1,
        'createdAt' => format_datetime($row['created_at']),
        'updatedAt' => format_datetime($row['updated_at']),
    ];
}

/** 取一条（只在属于该用户时才有结果，这是隔离的关键） */
function memo_find($id, $userId)
{
    return db_table('memos')
        ->select(MEMO_COLUMNS)
        ->where('id', (int) $id)
        ->where('user_id', (int) $userId)
        ->first();
}

/** 数量统计：总数 / 未完成 / 已完成 */
function memo_stats($userId)
{
    $row = db_table('memos')
        ->selectRaw('COUNT(*)', 'total')
        ->selectRaw('COALESCE(SUM(done = 0), 0)', 'open')
        ->selectRaw('COALESCE(SUM(done = 1), 0)', 'done')
        ->where('user_id', (int) $userId)
        ->first();
    return [
        'total' => (int) $row['total'],
        'open' => (int) $row['open'],
        'done' => (int) $row['done'],
        'limit' => (int) cfg('memo_max_count'),
    ];
}

/**
 * 列表。排序：未完成在前，然后按最近修改在前。
 * @param string $filter all | open | done
 */
function memo_list($userId, $filter = 'all')
{
    $query = db_table('memos')->select(MEMO_COLUMNS)->where('user_id', (int) $userId);
    if ($filter === 'open') {
        $query->where('done', 0);
    } elseif ($filter === 'done') {
        $query->where('done', 1);
    } else {
        $filter = 'all';
    }

    $items = [];
    foreach ($query->orderBy('done')->orderBy('updated_at', 'desc')->orderBy('id', 'desc')->get() as $row) {
        $items[] = memo_to_item($row);
    }

    return [
        'items' => $items,
        'filter' => $filter,
        'stats' => memo_stats($userId),
        'maxTitle' => memo_max_title(),
        'maxBody' => memo_max_body(),
    ];
}

/** 新建。每个账号有条数上限，防止无限堆积把表撑大 */
function memo_create($userId, $title, $body)
{
    $title = validate_memo_title($title);
    $body = validate_memo_body($body);

    $stats = memo_stats($userId);
    $limit = (int) cfg('memo_max_count');
    if ($limit > 0 && $stats['total'] >= $limit) {
        throw new ApiException(
            '备忘录数量已达上限（' . $limit . ' 条），请先清理一些再添加',
            409
        );
    }

    $id = db_table('memos')->insert([
        'user_id' => (int) $userId,
        'title' => $title,
        'body' => $body,
    ]);

    $row = memo_find($id, $userId);
    if ($row === null) {
        throw new ApiException('保存失败，请重试', 500);
    }
    return memo_to_item($row);
}

/**
 * 部分更新：只改传进来的字段。
 *
 * 列名走白名单而不是直接拼前端传来的键——拼键名等于把 SQL 结构交给客户端。
 * 这里传进去的键是代码里点名的三个，前端键名要经过一次映射才到这里，
 * 构造器又会拿 information_schema 的列名比一遍，所以是两道白名单。
 * updated_at 由 MySQL 的 ON UPDATE 自动维护，这里不用管。
 *
 * @param array $fields 只认识 title / body / done 三个键
 */
function memo_update($userId, $id, array $fields)
{
    $data = [];

    if (array_key_exists('title', $fields)) {
        $data['title'] = validate_memo_title($fields['title']);
    }
    if (array_key_exists('body', $fields)) {
        $data['body'] = validate_memo_body($fields['body']);
    }
    if (array_key_exists('done', $fields)) {
        $data['done'] = $fields['done'] ? 1 : 0;
    }

    if ($data === []) {
        throw new ApiException('没有需要修改的内容', 422);
    }

    db_table('memos')->where('id', (int) $id)->where('user_id', (int) $userId)->update($data);

    // update 影响的行数不能当「不存在」的依据（MySQL 只算内容真的变了的行），
    // 所以判存在要靠再查一次。
    $row = memo_find($id, $userId);
    if ($row === null) {
        throw new ApiException('这条备忘录不存在', 404);
    }
    return memo_to_item($row);
}

/** 删除。同样是 id + user_id 一起做条件 */
function memo_delete($userId, $id)
{
    $affected = db_table('memos')
        ->where('id', (int) $id)
        ->where('user_id', (int) $userId)
        ->delete();
    if ($affected === 0) {
        throw new ApiException('这条备忘录不存在', 404);
    }
    return ['deleted' => (int) $id];
}

/** 备忘录总数（后台统计用） */
function memo_count()
{
    return db_table('memos')->count();
}

<?php
/**
 * 查询构造器（src/orm.php）用例。
 *
 * 这个文件不通过 HTTP，而是在测试进程里直接调构造器——因为它要验证的东西
 * 有一大半是「拼出来的语句长什么样」「什么输入必须被拒绝」，
 * 这些都够不到接口，等到接口回报错才发现就已经晚了。
 *
 * 跑在 web_one_test 上（run.php 已经把环境变量指过去），结束前把自己插的行删干净。
 */

t_section('构造器：拼出来的 SQL');

/** 断言一段代码抛出异常，且异常信息里有指定字样 */
function t_throws($callback, $needle, $label)
{
    try {
        $callback();
    } catch (Throwable $e) {
        t_assert(
            strpos($e->getMessage(), $needle) !== false,
            $label,
            '要找不到「' . $needle . '」，实际是「' . get_class($e) . ': ' . $e->getMessage() . '」'
        );
        return;
    }
    t_assert(false, $label, '根本没抛异常');
}

$sql = db_table('pomodoros')
    ->select('id', 'minutes')
    ->where('user_id', 7)
    ->orderBy('id', 'desc')
    ->limit(5)
    ->toSql();
t_eq(
    'SELECT `id`, `minutes` FROM `pomodoros` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 5',
    $sql,
    'select / where / orderBy / limit 拼成一条语句，值留成 ? 占位符'
);
t_assert(strpos($sql, '7') === false, '传进来的值不会出现在 SQL 文本里');

t_eq(
    'SELECT * FROM `pomodoros`',
    db_table('pomodoros')->toSql(),
    '不指定列就是 SELECT *'
);
t_eq(
    'DELETE FROM `pomodoros` WHERE `id` = ? AND `user_id` = ?',
    db_table('pomodoros')->where('id', 1)->where('user_id', 2)->toSql('DELETE'),
    '删除语句里条件用 AND 串起来'
);
t_section('构造器：标识符只认表里真实存在的列');

// 值能绑参数，列名不能——所以列名这一侧唯一的防线就是白名单。
// 这里逐个确认「越界的写法会被挡在拼 SQL 之前」。
t_throws(function () {
    db_table('pomodoros')->where('user_id = 1', 1);
}, '非法列名', '想把条件当列名传进来：拒绝');

t_throws(function () {
    db_table('pomodoros')->where('no_such_column', 1);
}, '没有列', '不存在的列名：拒绝（拼错列名不会变成对外的 500）');

t_throws(function () {
    db_table('not_a_table_at_all');
}, '不存在或不可见', '不存在的表：构造时就报出来');

t_throws(function () {
    db_table('pomodoros')->where('minutes', 'DROP', 1);
}, '不支持的比较符', '比较符走白名单，不是任意字符串');

t_throws(function () {
    db_table('pomodoros')->selectRaw('COUNT(*)', 'a; b');
}, '非法的列别名', '别名也要过标识符检查');

t_throws(function () {
    db_table('pomodoros')->selectRaw('SUM(?)', 's');
}, '不接受占位符', 'selectRaw 不接受占位符：表达式只能来自源码');

t_throws(function () {
    db_table('pomodoros')->selectRaw('COUNT(*); DROP TABLE memos', 's');
}, '不允许出现 ;', 'selectRaw 挡掉第二条语句');

t_eq(
    'SELECT * FROM `pomodoros` ORDER BY `id` ASC',
    db_table('pomodoros')->orderBy('id', 'desc; --')->toSql(),
    '排序方向只认 asc / desc，其它一律按 ASC 处理'
);
t_eq(
    'SELECT * FROM `pomodoros` LIMIT 5',
    db_table('pomodoros')->limit('5; DROP TABLE memos')->toSql(),
    'limit 强制转整数之后才拼进语句，非数字尾巴自然消失'
);

t_section('构造器：不许有无条件的写操作');

t_throws(function () {
    db_table('pomodoros')->update(['minutes' => 1]);
}, '必须带条件', '没有 WHERE 的 UPDATE 直接拒绝：那会把全表改成同一个值');

t_throws(function () {
    db_table('pomodoros')->delete();
}, '必须带条件', '没有 WHERE 的 DELETE 直接拒绝');

t_throws(function () {
    db_table('pomodoros')->where('user_id', 1)->update([]);
}, '至少要给一个字段', 'UPDATE 不给任何字段没有意义');

t_throws(function () {
    db_table('pomodoros')->insert([]);
}, '至少要给一个字段', 'INSERT 空数据没有意义');

t_section('构造器：真的读写一遍');

// 用一个新注册的账号，保证表里没有别人留下的行，计数才是确定的
t_clear_jar('orm_a');
t_request('POST', '/api/register', ['json' => ['username' => 'orm_a', 'password' => 'ormpw123'], 'jar' => 'orm_a']);
$user = db_table('users')->select('id')->where('username', 'orm_a')->first();
t_assert(is_array($user) && (int) $user['id'] > 0, '能从 users 里按用户名取到自己的 id');
$uid = (int) $user['id'];

$ids = [];
foreach ([['英语单词', 15, 900, 1], ['高数习题', 25, 1500, 1], ['线性代数', 25, 300, 0]] as $row) {
    $ids[] = db_table('pomodoros')->insert([
        'user_id' => $uid,
        'subject' => $row[0],
        'minutes' => $row[1],
        'elapsed' => $row[2],
        'finished' => $row[3],
    ]);
}
t_assert(count($ids) === 3 && min($ids) > 0, 'insert() 返回自增 id');
t_assert($ids[2] > $ids[0], '后插的 id 更大（自增主键）');

$mine = function () use ($uid) {
    return db_table('pomodoros')->where('user_id', $uid);
};

t_eq(3, $mine()->count(), 'count() 数的是带条件的行数');
t_eq(0, db_table('pomodoros')->where('user_id', $uid + 999999)->count(), '换一个 user_id 就一条都数不到');

// 带引号的字符串要原样进、原样出：这既验证了「值是绑进去的」，也验证了没有二次转义
$quoted = "it's \"a\" \\ test";
$quotedId = db_table('pomodoros')->insert([
    'user_id' => $uid, 'subject' => $quoted, 'minutes' => 5, 'elapsed' => 60, 'finished' => 1,
]);
$got = db_table('pomodoros')->select('subject')->where('id', $quotedId)->first();
t_eq($quoted, $got['subject'], '引号与反斜杠原样存、原样取（值是绑参数的，不靠转义拼接）');
t_eq(0, $mine()->where('subject', "x' OR '1'='1")->count(), "字符串里带 OR '1'='1' 也不会变成条件");

$rows = $mine()->select('id', 'subject')->orderBy('id', 'desc')->limit(2)->get();
t_eq(2, count($rows), 'limit 生效');
t_eq($quotedId, (int) $rows[0]['id'], '按 id 倒序，最新的在前');
$page2 = $mine()->select('id')->orderBy('id', 'desc')->limit(1)->offset(1)->get();
t_eq($ids[2], (int) $page2[0]['id'], 'limit + offset 取到第二条（分页可用）');
t_eq(
    'SELECT * FROM `pomodoros` WHERE `user_id` = ? LIMIT 18446744073709551615 OFFSET 1',
    $mine()->offset(1)->toSql(),
    '只给 offset 时补一个「不限制」的 LIMIT：MySQL 要求 OFFSET 前面必须有 LIMIT'
);

$in = $mine()->whereIn('minutes', [15, 25])->count();
t_eq(3, $in, 'whereIn 命中两条时长里的记录（15 与 25 分钟共 3 条）');
t_eq(0, $mine()->whereIn('minutes', [])->count(), 'whereIn 传空数组是「一个都不命中」，不是报错也不是全命中');
t_eq(0, $mine()->whereNull('subject')->count(), 'whereNull：这一列 NOT NULL，所以一条都没有');

t_eq('英语单词', $mine()->where('minutes', 15)->value('subject'), 'value() 取单列的值');
t_eq('兜底', $mine()->where('minutes', 15)->value('nope', '兜底'), 'value() 取不到列时给默认值');
t_assert($mine()->exists(), 'exists() 在有记录时返回真');
t_assert(!$mine()->where('minutes', 9999)->exists(), 'exists() 在没记录时返回假');

$agg = db_table('pomodoros')
    ->selectRaw('COUNT(*)', 'total')
    ->selectRaw('COALESCE(SUM(finished = 1), 0)', 'finished')
    ->selectRaw('COALESCE(SUM(ROUND(elapsed / 60)), 0)', 'minutes')
    ->where('user_id', $uid)
    ->first();
t_eq(4, (int) $agg['total'], 'selectRaw 的聚合成形');
t_eq(3, (int) $agg['finished'], '按条件求和（finished = 1 在 MySQL 里就是 0/1）');
t_eq(46, (int) $agg['minutes'], '累计分钟数：900+1500+300+60 秒各自折算成 15+25+5+1');

// 走一遍真正的业务函数，确认 study.php 换到构造器之后行为没变
$stats = pomodoro_stats($uid);
t_eq(4, $stats['total'], '业务函数 pomodoro_stats 与直查结果一致');
t_eq(4, $stats['today'], '今天这一档也算进去了');
t_eq(3, $stats['finished'], '跑完的三条算 finished，中途放弃那条不算');
t_eq(46, $stats['minutes'], '业务函数里的累计分钟数一致');
t_eq(4, count(pomodoro_list($uid)), 'pomodoro_list 返回这个账号的全部四条');
t_eq('高数习题', pomodoro_find($ids[1], $uid)['subject'], 'pomodoro_find 按 id + user_id 取得到');
t_assert(pomodoro_find($ids[1], $uid + 1) === null, '换一个 user_id 就查不到（隔离写在 WHERE 里）');

$affected = db_table('pomodoros')->where('id', $ids[0])->where('user_id', $uid)->update(['subject' => '英语阅读']);
t_eq(1, $affected, 'update 改了内容，影响 1 行');
$again = db_table('pomodoros')->where('id', $ids[0])->where('user_id', $uid)->update(['subject' => '英语阅读']);
t_eq(0, $again, '再更新成同一个值 rowCount 是 0——所以不能用 rowCount 判断记录存不存在');
t_eq('英语阅读', pomodoro_find($ids[0], $uid)['subject'], '值确实落在库里');
$minutes = db_table('pomodoros')->where('id', $ids[0])->value('minutes');
t_eq(15, (int) $minutes, 'update 只改了一个字段，别的字段没被顺手改掉');

foreach ($ids as $id) {
    db_table('pomodoros')->where('id', $id)->where('user_id', $uid)->delete();
}
db_table('pomodoros')->where('id', $quotedId)->where('user_id', $uid)->delete();
t_eq(0, $mine()->count(), 'delete 之后自己插的行都没了（用例不留脏数据）');

<?php
/**
 * 一层很薄的查询构造器：把「拼 SQL + 绑参数」这件事收口到一个地方。
 *
 * 先说清楚它**不是**什么：不是完整的 ORM，没有实体类、没有关联关系、没有变更集跟踪，
 * 也不替换 src/db.php 的 PDO 连接。需要 JOIN、需要复杂报表的地方仍然直接写 SQL——
 * 那种语句用链式 API 表达出来只会更难读。
 *
 * 它存在的理由有三个，都是手写字符串拼 SQL 时真正会出事的点：
 *
 * 1. **列名不能来自客户端。** 值可以绑参数，标识符（表名、列名）不行——MySQL 不接受
 *    「? 当列名」。所以这里的列名要先过白名单：拿 information_schema 里这张表**真实存在的列**
 *    比一遍，不在就直接抛异常。附带的好处是拼错列名会立刻得到一句人话，
 *    而不是等 MySQL 报错变成对外的 500。
 * 2. **LIMIT 不能绑参数。** PDO 关闭模拟预处理后，用 execute([...]) 传进去的值都是字符串，
 *    MySQL 会对 `LIMIT ?` 报 «Incorrect arguments to mysqld_stmt_execute»。
 *    所以这里把 limit/offset 强制 (int) 之后写进语句——是整数，就不可能有注入面。
 * 3. **UPDATE / DELETE 少写 WHERE 会毁掉整张表。** 这类事故只要发生一次就很贵，
 *    所以构造器直接拒绝没有条件的更新和删除。
 *
 * 另外，按账号隔离的写法（每条 SQL 的 WHERE 里都带 user_id）在这里仍然是调用方的责任，
 * 构造器不会替你加。它只是让「加条件」这件事不再需要操心逗号、? 的个数和参数顺序。
 */

/** 入口：取一张表开头的查询构造器 */
function db_table($table)
{
    return new DbQuery($table);
}

/** 标识符只允许「字母、数字、下划线」，且不能以数字开头——这是拼进 SQL 的前提 */
function orm_is_identifier($name)
{
    return is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
}

/** 给标识符加反引号；调用前必须已经过 orm_is_identifier 检查 */
function orm_quote_id($name)
{
    return '`' . $name . '`';
}

/**
 * 一张表真实存在的列名（小写键，值保留原始大小写）。
 * 一次请求内缓存：information_schema 的查询不便宜，而列结构不会在一次请求里变。
 */
function orm_columns($table)
{
    static $cache = [];
    $key = strtolower($table);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $stmt = db()->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([cfg('db_name'), $table]);
    $columns = [];
    foreach ($stmt->fetchAll() as $row) {
        $columns[strtolower($row['COLUMN_NAME'])] = $row['COLUMN_NAME'];
    }
    if ($columns === []) {
        // 表不存在与「当前账号看不见它」是同一种表现，给同一句提示就够排障了
        throw new RuntimeException('数据表 ' . $table . ' 不存在或不可见，请执行 php database/migrate.php');
    }
    $cache[$key] = $columns;
    return $columns;
}

/** 校验一个列名：合法且确实在这张表里，否则抛异常 */
function orm_check_column($table, $column)
{
    if (!orm_is_identifier($column)) {
        throw new RuntimeException('非法列名：' . $column);
    }
    $columns = orm_columns($table);
    if (!isset($columns[strtolower($column)])) {
        throw new RuntimeException('表 ' . $table . ' 里没有列 ' . $column);
    }
    return $columns[strtolower($column)];
}

final class DbQuery
{
    /** 允许的比较符白名单。IN / IS NULL 有各自的方法，不走这里 */
    private static $operators = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'];

    private $table;
    private $columns = [];
    private $expressions = [];
    private $wheres = [];
    private $params = [];
    private $orders = [];
    private $limit = null;
    private $offset = null;

    public function __construct($table)
    {
        if (!orm_is_identifier($table)) {
            throw new RuntimeException('非法表名：' . $table);
        }
        $this->table = $table;
        // 现在就确认这张表存在：晚到执行时才报错，排查成本更高
        orm_columns($table);
    }

    /* ---------- 拼装 ---------- */

    /** 指定要查的列；不调用就是 SELECT * */
    public function select()
    {
        foreach (func_get_args() as $column) {
            if (is_array($column)) {
                foreach ($column as $one) {
                    $this->columns[] = orm_check_column($this->table, $one);
                }
                continue;
            }
            $this->columns[] = orm_check_column($this->table, $column);
        }
        return $this;
    }

    /**
     * 加一个聚合 / 计算列，例如 selectRaw('COALESCE(SUM(finished = 1), 0)', 'finished')。
     *
     * 表达式没有参数可绑，所以它**只能来自源码里的字面量**，不能拼任何外部输入。
     * 这里再挡两道：出现 ? 说明想绑东西（不该走这条路），出现 ; 说明想塞第二条语句。
     */
    public function selectRaw($expression, $alias)
    {
        if (strpos($expression, '?') !== false) {
            throw new RuntimeException('selectRaw 不接受占位符 ?：表达式只能写死在源码里');
        }
        if (strpos($expression, ';') !== false) {
            throw new RuntimeException('selectRaw 的表达式里不允许出现 ;（第二条语句）：' . $expression);
        }
        if (!orm_is_identifier($alias)) {
            throw new RuntimeException('非法的列别名：' . $alias);
        }
        $this->expressions[] = $expression . ' AS ' . orm_quote_id($alias);
        return $this;
    }

    /**
     * 加一个 WHERE 条件，多个条件之间是 AND。
     *
     * 两种写法：where('user_id', 3)（默认等于）与 where('elapsed', '>=', 60)。
     * 值永远进参数数组，绝不拼进语句。
     */
    public function where($column, $operatorOrValue, $value = null)
    {
        $args = func_get_args();
        if (count($args) === 2) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = strtoupper(trim((string) $operatorOrValue));
            if (!in_array($operator, self::$operators, true)) {
                throw new RuntimeException('不支持的比较符：' . $operator);
            }
        }
        $column = orm_check_column($this->table, $column);
        $this->wheres[] = orm_quote_id($column) . ' ' . $operator . ' ?';
        $this->params[] = $value;
        return $this;
    }

    /** 值为 NULL 的列只能用 IS NULL 比，所以单独开一个方法而不是让 where() 里传 null */
    public function whereNull($column)
    {
        $column = orm_check_column($this->table, $column);
        $this->wheres[] = orm_quote_id($column) . ' IS NULL';
        return $this;
    }

    /**
     * WHERE 列 IN (...)。
     * 空数组意味着「一个都不该命中」，所以拼成恒假条件而不是 IN ()（MySQL 不认后者）。
     */
    public function whereIn($column, array $values)
    {
        $column = orm_check_column($this->table, $column);
        if ($values === []) {
            $this->wheres[] = '0 = 1';
            return $this;
        }
        $this->wheres[] = orm_quote_id($column) . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        foreach ($values as $value) {
            $this->params[] = $value;
        }
        return $this;
    }

    public function orderBy($column, $direction = 'asc')
    {
        $column = orm_check_column($this->table, $column);
        $direction = strtolower((string) $direction) === 'desc' ? 'DESC' : 'ASC';
        $this->orders[] = orm_quote_id($column) . ' ' . $direction;
        return $this;
    }

    /** 见文件顶部第 2 条：这里是 (int) 之后直接拼进去的 */
    public function limit($limit)
    {
        $this->limit = max(1, (int) $limit);
        return $this;
    }

    public function offset($offset)
    {
        $this->offset = max(0, (int) $offset);
        return $this;
    }

    /* ---------- 出语句 ---------- */

    /** 只含 ? 占位符的 SQL，不含任何值——所以可以放心打进日志或断言里 */
    public function toSql($verb = 'SELECT')
    {
        $verb = strtoupper($verb);
        $select = '`' . $this->table . '`';

        if ($verb === 'SELECT') {
            $parts = [];
            foreach ($this->expressions as $expression) {
                $parts[] = $expression;
            }
            if ($parts !== []) {
                $fields = implode(', ', $parts);
            } else {
                $fields = $this->columns === [] ? '*' : implode(', ', array_map('orm_quote_id', $this->columns));
            }
            $sql = 'SELECT ' . $fields . ' FROM ' . $select;
        } elseif ($verb === 'DELETE') {
            $sql = 'DELETE FROM ' . $select;
        } else {
            throw new RuntimeException('toSql 只用于查询与删除：' . $verb);
        }

        $sql .= $this->whereClause();
        if ($verb === 'SELECT' && $this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($verb === 'SELECT') {
            $sql .= $this->limitClause();
        }
        return $sql;
    }

    private function whereClause()
    {
        return $this->wheres === [] ? '' : ' WHERE ' . implode(' AND ', $this->wheres);
    }

    private function limitClause()
    {
        if ($this->limit === null && $this->offset === null) {
            return '';
        }
        // 没给 limit 又给了 offset：MySQL 要求 OFFSET 必须跟在一个 LIMIT 后面，给个不限制的量
        $sql = ' LIMIT ' . ($this->limit === null ? '18446744073709551615' : $this->limit);
        if ($this->offset !== null && $this->offset > 0) {
            $sql .= ' OFFSET ' . $this->offset;
        }
        return $sql;
    }

    /* ---------- 执行 ---------- */

    /** 跑一条语句并返回结果集 */
    private function run($sql, array $params)
    {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function get()
    {
        return $this->run($this->toSql('SELECT'), $this->params)->fetchAll();
    }

    /** @return array|null 一行都没有时返回 null，而不是 false */
    public function first()
    {
        $saved = $this->limit;
        $this->limit = 1;
        $rows = $this->run($this->toSql('SELECT'), $this->params)->fetchAll();
        $this->limit = $saved;
        return $rows === [] ? null : $rows[0];
    }

    /** 取某一列的第一行值（配合 selectRaw 取聚合结果）；没有结果时返回 $default */
    public function value($alias, $default = null)
    {
        if (!orm_is_identifier($alias)) {
            throw new RuntimeException('非法的列别名：' . $alias);
        }
        $row = $this->first();
        if ($row === null || !array_key_exists($alias, $row)) {
            return $default;
        }
        return $row[$alias];
    }

    /** 条件是否匹配到至少一行；只取 1 行，不数全表 */
    public function exists()
    {
        $saved = $this->limit;
        $this->limit(1);
        $found = $this->first() !== null;
        $this->limit = $saved;
        return $found;
    }

    public function count($column = null)
    {
        $fields = $column === null ? '*' : orm_quote_id(orm_check_column($this->table, $column));
        $sql = 'SELECT COUNT(' . $fields . ') AS aggregate FROM `' . $this->table . '`'
             . $this->whereClause() . ' LIMIT 1';
        $row = $this->run($sql, $this->params)->fetch();
        return (int) $row['aggregate'];
    }

    /** 某一列的合计（NULL 与没有行都算 0）。配额这类「只问总量、不取内容」的场合用它 */
    public function sum($column)
    {
        $fields = orm_quote_id(orm_check_column($this->table, $column));
        $sql = 'SELECT COALESCE(SUM(' . $fields . '), 0) AS aggregate FROM `' . $this->table . '`'
             . $this->whereClause() . ' LIMIT 1';
        $row = $this->run($sql, $this->params)->fetch();
        return (int) $row['aggregate'];
    }

    /** 插入一行，返回自增 id */
    public function insert(array $data)
    {
        if ($data === []) {
            throw new RuntimeException('insert 至少要给一个字段');
        }
        $columns = [];
        $params = [];
        foreach ($data as $column => $value) {
            $column = orm_check_column($this->table, $column);
            $columns[] = orm_quote_id($column);
            $params[] = $value;
        }
        $sql = 'INSERT INTO `' . $this->table . '` (' . implode(', ', $columns) . ') VALUES ('
             . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $this->run($sql, $params);
        return (int) db()->lastInsertId();
    }

    /**
     * 更新给定的字段，返回受影响行数。
     *
     * 没有 WHERE 就拒绝：UPDATE 少一句条件是把整张表改成同一个值。
     * 注意 MySQL 只把「内容真的变了」的行算进 rowCount，
     * 所以 rowCount 为 0 不等于「目标不存在」——要判断存在就再查一次（memos.php 就是这么做的）。
     */
    public function update(array $data)
    {
        if ($data === []) {
            throw new RuntimeException('update 至少要给一个字段');
        }
        if ($this->wheres === []) {
            throw new RuntimeException('update 必须带条件：这会改动整张 ' . $this->table);
        }
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            $column = orm_check_column($this->table, $column);
            $sets[] = orm_quote_id($column) . ' = ?';
            $params[] = $value;
        }
        $sql = 'UPDATE `' . $this->table . '` SET ' . implode(', ', $sets) . $this->whereClause();
        $stmt = $this->run($sql, array_merge($params, $this->params));
        return $stmt->rowCount();
    }

    /** 删除，返回受影响行数；同样拒绝没有条件的调用 */
    public function delete()
    {
        if ($this->wheres === []) {
            throw new RuntimeException('delete 必须带条件：这会清空整张 ' . $this->table);
        }
        return $this->run($this->toSql('DELETE'), $this->params)->rowCount();
    }
}

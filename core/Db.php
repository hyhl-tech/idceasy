<?php
/**
 * idceasy - 数据库层（PDO + 轻量查询构造器）
 * 只使用预处理语句，杜绝 SQL 注入。
 */
namespace think;

use PDO;
use PDOStatement;

class Db
{
    protected static ?PDO $pdo = null;
    protected static array $config = [];
    protected static array $log = [];

    public static function init(array $config): void
    {
        self::$config = $config;
        $type = $config['type'] ?? 'mysql';
        $host = $config['hostname'] ?? '127.0.0.1';
        $port = (int)($config['hostport'] ?? 3306);
        $name = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';
        $dsn = $type === 'sqlite'
            ? 'sqlite:' . $name
            : "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        self::$pdo = new PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) throw new \RuntimeException('数据库未初始化，请先运行安装程序。');
        return self::$pdo;
    }

    public static function prefix(): string
    {
        return self::$config['prefix'] ?? 'ice_';
    }

    public static function table(string $name): Query
    {
        return new Query($name);
    }

    public static function query(string $sql, array $bindings = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($bindings);
        self::$log[] = [$sql, $bindings];
        return $st;
    }

    public static function select(string $sql, array $bindings = []): array
    {
        return self::query($sql, $bindings)->fetchAll();
    }

    public static function first(string $sql, array $bindings = [])
    {
        $row = self::query($sql, $bindings)->fetch();
        return $row === false ? null : $row;
    }

    public static function execute(string $sql, array $bindings = []): int
    {
        return self::query($sql, $bindings)->rowCount();
    }

    public static function lastId(): int
    {
        return (int)self::pdo()->lastInsertId();
    }

    public static function transaction(callable $callback)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function log(): array
    {
        return self::$log;
    }
}

class Query
{
    protected string $table;
    protected array $wheres = [];
    protected array $orders = [];
    protected array $fields = ['*'];
    protected array $joins = [];
    protected array $groups = [];
    protected ?int $limit = null;
    protected ?int $offset = null;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function table(string $table): Query
    {
        $this->table = $table;
        return $this;
    }

    public function field($fields): Query
    {
        $this->fields = is_array($fields) ? $fields : array_map('trim', explode(',', $fields));
        return $this;
    }

    public function where($field, $op = null, $value = null): Query
    {
        if (is_array($field)) {
            foreach ($field as $k => $v) $this->where($k, '=', $v);
            return $this;
        }
        if (func_num_args() === 2) {
            if ($op === null) return $this->whereNull($field);
            $value = $op;
            $op = '=';
        }
        if ($value === null) return $this->whereNull($field);
        $op = strtolower($op ?? '=');
        $allowed = ['=', '>', '<', '>=', '<=', '!=', '<>', 'like', 'not like'];
        if (!in_array($op, $allowed, true)) $op = '=';
        $this->wheres[] = ['sql' => "`{$field}` {$op} ?", 'binds' => [$value]];
        return $this;
    }

    public function whereIn(string $field, array $values): Query
    {
        if (!$values) { $this->wheres[] = ['sql' => '1 = 0', 'binds' => []]; return $this; }
        $ph = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = ['sql' => "`{$field}` IN ({$ph})", 'binds' => array_values($values)];
        return $this;
    }

    public function whereNotIn(string $field, array $values): Query
    {
        if (!$values) return $this;
        $ph = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = ['sql' => "`{$field}` NOT IN ({$ph})", 'binds' => array_values($values)];
        return $this;
    }

    public function whereNull(string $field): Query
    {
        $this->wheres[] = ['sql' => "`{$field}` IS NULL", 'binds' => []];
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): Query
    {
        $this->wheres[] = ['sql' => $sql, 'binds' => $bindings];
        return $this;
    }

    public function join(string $table, string $on, string $type = 'INNER'): Query
    {
        $this->joins[] = strtoupper($type) . ' JOIN ' . $table . ' ON ' . $on;
        return $this;
    }

    public function group(string $field): Query
    {
        $this->groups[] = $field;
        return $this;
    }

    public function order(string $field, string $dir = 'asc'): Query
    {
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = "`{$field}` {$dir}";
        return $this;
    }

    public function limit(int $limit, ?int $offset = null): Query
    {
        $this->limit = $limit;
        if ($offset !== null) $this->offset = $offset;
        return $this;
    }

    public function page(int $page, int $perPage = 20): Query
    {
        $page = max(1, $page);
        return $this->limit($perPage, ($page - 1) * $perPage);
    }

    protected function buildWhere(): array
    {
        if (!$this->wheres) return ['', []];
        $parts = array_column($this->wheres, 'sql');
        $binds = [];
        foreach ($this->wheres as $w) { foreach ($w['binds'] as $b) $binds[] = $b; }
        return [' WHERE ' . implode(' AND ', $parts), $binds];
    }

    protected function tableName(): string
    {
        return Db::prefix() . $this->table;
    }

    public function select(): array
    {
        $sql = 'SELECT ' . implode(', ', $this->fields) . ' FROM ' . $this->tableName();
        if ($this->joins) $sql .= ' ' . implode(' ', $this->joins);
        [$where, $binds] = $this->buildWhere();
        $sql .= $where;
        if ($this->groups) $sql .= ' GROUP BY ' . implode(',', $this->groups);
        if ($this->orders) $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        if ($this->limit !== null) $sql .= ' LIMIT ' . $this->limit . ($this->offset ? ' OFFSET ' . $this->offset : '');
        return Db::select($sql, $binds);
    }

    public function find(): ?array
    {
        $rows = $this->limit(1)->select();
        return $rows[0] ?? null;
    }

    public function value(string $field, $default = null)
    {
        $row = $this->field($field)->find();
        if (!$row) return $default;
        $v = array_values($row)[0] ?? $default;
        return $v === null ? $default : $v;
    }

    public function column(string $field, string $key = ''): array
    {
        $rows = $this->field($key !== '' ? "{$key},{$field}" : $field)->select();
        $out = [];
        foreach ($rows as $row) {
            if ($key !== '' && isset($row[$key])) $out[$row[$key]] = $row[$field];
            else $out[] = $row[$field];
        }
        return $out;
    }

    public function count(): int
    {
        $clone = clone $this;
        $clone->orders = [];
        $clone->limit = null;
        $clone->offset = null;
        $clone->fields = ['COUNT(*) AS c'];
        $row = $clone->select()[0] ?? ['c' => 0];
        return (int)$row['c'];
    }

    public function sum(string $field): float
    {
        $clone = clone $this;
        $clone->orders = [];
        $clone->limit = null;
        $clone->offset = null;
        $clone->fields = ["COALESCE(SUM(`{$field}`),0) AS s"];
        return (float)($clone->select()[0]['s'] ?? 0);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function insert(array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $this->tableName() . ' (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        Db::execute($sql, array_values($data));
        return Db::lastId();
    }

    public function insertAll(array $rows): int
    {
        if (!$rows) return 0;
        $cols = array_keys($rows[0]);
        $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $sql = 'INSERT INTO ' . $this->tableName() . ' (`' . implode('`,`', $cols) . '`) VALUES '
            . implode(',', array_fill(0, count($rows), $ph));
        $bind = [];
        foreach ($rows as $row) foreach ($cols as $c) $bind[] = $row[$c] ?? null;
        return Db::execute($sql, $bind);
    }

    public function update(array $data): int
    {
        if (!$data) return 0;
        $sets = [];
        $bind = [];
        foreach ($data as $k => $v) { $sets[] = "`{$k}` = ?"; $bind[] = $v; }
        [$where, $wbinds] = $this->buildWhere();
        $sql = 'UPDATE ' . $this->tableName() . ' SET ' . implode(', ', $sets) . $where;
        return Db::execute($sql, array_merge($bind, $wbinds));
    }

    public function delete(): int
    {
        [$where, $wbinds] = $this->buildWhere();
        if ($where === '') throw new \RuntimeException('拒绝执行无条件的删除操作');
        return Db::execute('DELETE FROM ' . $this->tableName() . $where, $wbinds);
    }

    public function increment(string $field, $by = 1, array $extra = []): int
    {
        $sets = ["`{$field}` = `{$field}` + ?"];
        $bind = [$by];
        foreach ($extra as $k => $v) { $sets[] = "`{$k}` = ?"; $bind[] = $v; }
        [$where, $wbinds] = $this->buildWhere();
        return Db::execute('UPDATE ' . $this->tableName() . ' SET ' . implode(',', $sets) . $where,
            array_merge($bind, $wbinds));
    }

    public function decrement(string $field, $by = 1, array $extra = []): int
    {
        return $this->increment($field, -abs($by), $extra);
    }

    public function paginate(int $perPage = 20, ?int $page = null): array
    {
        $page = max(1, (int)($page ?? ($_GET['page'] ?? 1)));
        $total = $this->count();
        $rows = $this->page($page, $perPage)->select();
        $pages = max(1, (int)ceil($total / $perPage));
        return ['data' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }
}

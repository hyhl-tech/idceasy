<?php
/**
 * idceasy - 轻量模型基类
 * 复杂查询直接用 Db::table()，模型适合插件与快速 CRUD。
 */
namespace think;

abstract class Model
{
    protected static string $table = '';
    protected static string $pk = 'id';

    public static function query(): Query
    {
        return Db::table(static::$table);
    }

    public static function find($id): ?array
    {
        return static::query()->where(static::$pk, $id)->find();
    }

    public static function all(array $where = [], string $order = '', string $dir = 'asc'): array
    {
        $q = static::query();
        if ($where) $q->where($where);
        if ($order !== '') $q->order($order, $dir);
        return $q->select();
    }

    public static function create(array $data): int
    {
        return static::query()->insert($data);
    }

    public static function updateById($id, array $data): int
    {
        return static::query()->where(static::$pk, $id)->update($data);
    }

    public static function deleteById($id): int
    {
        return static::query()->where(static::$pk, $id)->delete();
    }

    public static function count(array $where = []): int
    {
        $q = static::query();
        if ($where) $q->where($where);
        return $q->count();
    }
}

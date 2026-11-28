<?php
/**
 * idceasy - 配置容器
 * 兼容 ThinkPHP 的 Config 用法：Config::get('app.debug')
 */
namespace think;

class Config
{
    protected static array $items = [];

    public static function load(string $file): void
    {
        if (is_file($file)) {
            $data = require $file;
            if (is_array($data)) {
                self::$items = array_replace_recursive(self::$items, $data);
            }
        }
    }

    public static function get(string $key = '', $default = null)
    {
        if ($key === '') return self::$items;
        $value = self::$items;
        foreach (explode('.', $key) as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) return $default;
            $value = $value[$seg];
        }
        return $value;
    }

    public static function set(string $key, $value): void
    {
        $segments = explode('.', $key);
        $ref = &self::$items;
        foreach ($segments as $seg) {
            if (!isset($ref[$seg]) || !is_array($ref[$seg])) $ref[$seg] = [];
            $ref = &$ref[$seg];
        }
        $ref = $value;
    }

    public static function all(): array
    {
        return self::$items;
    }
}

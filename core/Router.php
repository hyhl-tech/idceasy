<?php
/**
 * idceasy - 路由
 * 支持 /product/{id} 形式的占位参数与路由分组。
 */
namespace think;

class Router
{
    protected static array $routes = [];
    protected static string $prefix = '';
    protected static array $groupOpts = [];

    public static function get(string $path, $handler, array $opts = []): void
    {
        self::add('GET', $path, $handler, $opts);
    }

    public static function post(string $path, $handler, array $opts = []): void
    {
        self::add('POST', $path, $handler, $opts);
    }

    public static function any(string $path, $handler, array $opts = []): void
    {
        self::add('ANY', $path, $handler, $opts);
    }

    protected static function add(string $method, string $path, $handler, array $opts): void
    {
        $full = '/' . trim(self::$prefix . '/' . trim($path, '/'), '/');
        if ($full !== '/') $full = rtrim($full, '/');
        self::$routes[] = [
            'method'  => $method,
            'path'    => $full,
            'pattern' => self::compile($full),
            'handler' => $handler,
            'opts'    => array_merge(self::$groupOpts, $opts),
        ];
    }

    public static function group(string $prefix, callable $callback, array $opts = []): void
    {
        $oldPrefix = self::$prefix;
        $oldOpts = self::$groupOpts;
        self::$prefix = '/' . trim($oldPrefix . '/' . trim($prefix, '/'), '/');
        self::$groupOpts = array_merge($oldOpts, $opts);
        $callback();
        self::$prefix = $oldPrefix;
        self::$groupOpts = $oldOpts;
    }

    protected static function compile(string $path): string
    {
        $regex = preg_replace_callback('#\{(\w+)\}#', function ($m) {
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $path);
        return '#^' . $regex . '$#';
    }

    public static function match(string $method, string $path): ?array
    {
        if ($path !== '/') $path = rtrim($path, '/');
        foreach (self::$routes as $route) {
            if ($route['method'] !== 'ANY' && $route['method'] !== $method) continue;
            if (preg_match($route['pattern'], $path, $m)) {
                $params = [];
                foreach ($m as $k => $v) if (!is_int($k)) $params[$k] = urldecode($v);
                return ['handler' => $route['handler'], 'params' => $params, 'opts' => $route['opts']];
            }
        }
        return null;
    }

    public static function routes(): array
    {
        return self::$routes;
    }
}

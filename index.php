<?php
/**
 * idceasy - 入口文件
 * 环境要求：PHP 8.1+ / MySQL 5.7+ 或 MariaDB 10.3+
 */
declare(strict_types=1);

define('IDCEASY_VERSION', '1.0.0');
define('BASE_PATH', __DIR__);
define('CORE_PATH', BASE_PATH . '/core');
define('APP_PATH', BASE_PATH . '/app');
define('PLUGIN_PATH', BASE_PATH . '/plugins');
define('DATA_PATH', BASE_PATH . '/data');

// PHP 内置服务器：静态文件直接返回
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, '.php')) return false;
}

spl_autoload_register(function (string $class): void {
    $map = [
        'think\\Query'         => CORE_PATH . '/Db.php',
        'think\\HttpException' => CORE_PATH . '/App.php',
    ];
    if (isset($map[$class])) { require_once $map[$class]; return; }

    $prefixes = [
        'think\\'   => CORE_PATH . '/',
        'app\\'     => APP_PATH . '/',
        'plugins\\' => PLUGIN_PATH . '/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) continue;
        $relative = substr($class, strlen($prefix));
        $file = $dir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) { require_once $file; return; }
    }
});

require_once CORE_PATH . '/App.php';

ob_start();
\think\App::boot();
\think\App::run();

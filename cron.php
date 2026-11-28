<?php
/**
 * idceasy 计划任务入口
 * 用法：
 *   php /path/to/cron.php
 *   https://your-domain/cron.php?token=后台设置的令牌
 * 建议每分钟执行一次。
 */
declare(strict_types=1);

define('IDCEASY_VERSION', '1.0.0');
define('BASE_PATH', __DIR__);
define('CORE_PATH', BASE_PATH . '/core');
define('APP_PATH', BASE_PATH . '/app');
define('PLUGIN_PATH', BASE_PATH . '/plugins');
define('DATA_PATH', BASE_PATH . '/data');

spl_autoload_register(function (string $class): void {
    $map = [
        'think\\Query'         => CORE_PATH . '/Db.php',
        'think\\HttpException' => CORE_PATH . '/App.php',
    ];
    if (isset($map[$class])) { require_once $map[$class]; return; }
    $prefixes = ['think\\' => CORE_PATH . '/', 'app\\' => APP_PATH . '/', 'plugins\\' => PLUGIN_PATH . '/'];
    foreach ($prefixes as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) continue;
        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; return; }
    }
});

require_once CORE_PATH . '/App.php';
\think\App::boot();

header('Content-Type: text/plain; charset=utf-8');
if (!\think\App::$installed) {
    echo "idceasy 尚未安装\n";
    exit(1);
}

$isCli = PHP_SAPI === 'cli';
$token = (string)($_GET['token'] ?? '');
$expected = (string)cfg('cron_token', '');
if (!$isCli) {
    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        echo "403 令牌无效，请在后台「设置 - 接口与自动化」中查看定时任务地址\n";
        exit;
    }
}

if (!empty($_GET['task'])) {
    $result = \app\service\Tasks::run((string)$_GET['task']);
    echo $result['output'] . ' (' . $result['duration'] . "s)\n";
    exit($result['ok'] ? 0 : 1);
}

echo 'idceasy cron ' . date('Y-m-d H:i:s') . "\n";
foreach (\app\service\Tasks::runAll() as $task => $result) {
    echo '[' . $task . '] ' . $result['output'] . ' (' . $result['duration'] . "s)\n";
}
echo "done\n";

<?php
/**
 * idceasy - 应用内核
 */
namespace think;

class App
{
    public static bool $installed = false;

    public static function boot(): void
    {
        require_once BASE_PATH . '/app/common.php';

        Config::load(BASE_PATH . '/config/app.php');
        $dbConfig = BASE_PATH . '/config/database.php';
        if (is_file($dbConfig)) {
            $db = require $dbConfig;
            if (is_array($db)) {
                Config::set('database', $db);
                try {
                    Db::init($db);
                    self::$installed = true;
                } catch (\Throwable $e) {
                    self::$installed = false;
                }
            }
        }
        date_default_timezone_set(Config::get('app.timezone', 'Asia/Shanghai'));
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) Session::start();
        if (self::$installed) Plugin::boot();
    }

    public static function run(): void
    {
        $request = Request::instance();
        $path = $request->path();

        if (!self::$installed) {
            if (str_starts_with($path, '/install')) {
                header('Location: ' . Request::instance()->base() . '/install.php');
                exit;
            }
            header('Location: ' . $request->base() . '/install.php');
            exit;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        if ((string)cfg('maintenance', '0') === '1'
            && !str_starts_with($path, '/admin')
            && !str_starts_with($path, '/api')
            && !str_starts_with($path, '/pay')
            && !current_admin()) {
            http_response_code(503);
            echo View::render('maintenance', [
                'siteName' => cfg('site_name', 'idceasy'), 'year' => date('Y'), 'title' => '维护中',
                'user' => current_user(), 'admin' => current_admin(), 'cartCount' => cart_count(),
                'menu' => nav_menu(), 'flashOk' => null, 'flashErr' => null, 'theme' => 'default',
            ], 'default');
            return;
        }

        require BASE_PATH . '/config/routes.php';

        try {
            $route = Router::match($request->method(), $path);
            if (!$route) {
                self::abort(404, '页面不存在');
                return;
            }

            $opts = $route['opts'];
            $auth = $opts['auth'] ?? null;

            if ($auth === 'user' && !is_logged_in()) {
                $target = url('/login') . '?redirect=' . urlencode($request->url());
                Response::redirect($target)->send();
                return;
            }
            if ($auth === 'admin' && !current_admin()) {
                Response::redirect(admin_url('/login'))->send();
                return;
            }

            if ($request->isPost() && ($opts['csrf'] ?? true) !== false) {
                if (!Session::verify($request->param('_token'))) {
                    self::abort(419, '页面已过期，请返回刷新后重试');
                    return;
                }
            }

            $handler = $route['handler'];
            $params = array_values($route['params']);
            $request->setRouteParams($route['params']);

            if ($handler instanceof \Closure) {
                $result = $handler(...$params);
            } else {
                if (is_string($handler) && str_contains($handler, '@')) {
                    [$class, $method] = explode('@', $handler, 2);
                } elseif (is_array($handler)) {
                    [$class, $method] = $handler;
                } else {
                    throw new \RuntimeException('无效的路由处理器');
                }
                if (!class_exists($class)) throw new \RuntimeException('控制器不存在: ' . $class);
                $controller = new $class();
                if (!method_exists($controller, $method)) {
                    throw new \RuntimeException('方法不存在: ' . $class . '::' . $method);
                }
                if (method_exists($controller, 'authorize')) {
                    $controller->authorize($method);
                }
                $result = $controller->$method(...$params);
            }

            if ($result instanceof Response) $result->send();
            elseif (is_array($result)) Response::json($result)->send();
            elseif (is_string($result)) echo $result;
        } catch (HttpException $e) {
            self::abort($e->getCode() ?: 500, $e->getMessage());
            return;
        } catch (\Throwable $e) {
            log_write('app', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
            if (Config::get('app.debug')) {
                self::abort(500, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            }
            self::abort(500, '系统开小差了，请稍后再试');
        }
    }

    public static function abort(int $code, string $message): void
    {
        http_response_code($code);
        $file = BASE_PATH . '/template/default/error.htm';
        if (is_file($file)) {
            try {
                $html = View::render('error', [
                    'code' => $code, 'message' => $message,
                    'siteName' => function_exists('cfg') ? cfg('site_name', 'idceasy') : 'idceasy',
                    'siteUrl' => '', 'year' => date('Y'), 'user' => function_exists('current_user') ? current_user() : null,
                    'admin' => function_exists('current_admin') ? current_admin() : null,
                    'cartCount' => function_exists('cart_count') ? cart_count() : 0,
                    'menu' => function_exists('nav_menu') ? nav_menu() : [],
                    'flashOk' => null, 'flashErr' => null, 'theme' => 'default', 'title' => '',
                ], 'default');
                echo $html;
                return;
            } catch (\Throwable $e) {
                // 降级为纯文本
            }
        }
        echo '<!doctype html><meta charset="utf-8"><title>' . $code . '</title>'
            . '<div style="font:16px/1.7 system-ui;padding:80px;text-align:center">'
            . '<h1 style="font-size:56px;margin:0;color:#2563eb">' . $code . '</h1>'
            . '<p style="color:#555">' . htmlspecialchars($message) . '</p>'
            . '<p><a href="' . htmlspecialchars(url('/')) . '">返回首页</a></p></div>';
    }

    public static function version(): string
    {
        return defined('IDCEASY_VERSION') ? IDCEASY_VERSION : '1.0.0';
    }
}

class HttpException extends \RuntimeException
{
}

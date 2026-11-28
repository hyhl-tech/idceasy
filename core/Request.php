<?php
/**
 * idceasy - 请求对象
 */
namespace think;

class Request
{
    protected static ?Request $instance = null;
    protected array $routeParams = [];

    public static function instance(): Request
    {
        return self::$instance ??= new self();
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public function path(): string
    {
        $path = '';
        if (!empty($_GET['s'])) {
            $path = $_GET['s'];
        } elseif (!empty($_SERVER['PATH_INFO'])) {
            $path = $_SERVER['PATH_INFO'];
        } else {
            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
            if ($base && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
            $uri = preg_replace('#^/index\.php#', '', $uri);
            $path = $uri;
        }
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function get(string $key = '', $default = null)
    {
        if ($key === '') return $_GET;
        return $_GET[$key] ?? $default;
    }

    public function post(string $key = '', $default = null)
    {
        if ($key === '') return $_POST;
        return $_POST[$key] ?? $default;
    }

    /** 兼容 TP 的 param()：合并 GET/POST */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key = '', $default = null)
    {
        if ($key === '') return array_merge($_GET, $_POST, $this->routeParams);
        if (array_key_exists($key, $this->routeParams)) return $this->routeParams[$key];
        if (array_key_exists($key, $_POST)) return $_POST[$key];
        return $_GET[$key] ?? $default;
    }

    public function input(string $key, $default = null)
    {
        return $this->param($key, $default);
    }

    public function all(): array
    {
        return array_merge($_GET, $_POST, $this->routeParams);
    }

    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $v = $this->param($k, '__NONE__');
            if ($v !== '__NONE__') $out[$k] = $v;
        }
        return $out;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->param($key);
        return is_numeric($v) ? (int)$v : $default;
    }

    public function float(string $key, float $default = 0): float
    {
        $v = $this->param($key);
        return is_numeric($v) ? (float)$v : $default;
    }

    public function bool(string $key): bool
    {
        return in_array($this->param($key), ['1', 1, true, 'on', 'yes', 'true'], true);
    }

    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }

    public function ua(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    }

    public function header(string $name, string $default = ''): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) return $_SERVER[$key];
        if (strcasecmp($name, 'Content-Type') === 0) return $_SERVER['CONTENT_TYPE'] ?? $default;
        return $default;
    }

    public function raw(): string
    {
        static $body = null;
        if ($body === null) $body = file_get_contents('php://input') ?: '';
        return $body;
    }

    public function json(): array
    {
        $data = json_decode($this->raw(), true);
        return is_array($data) ? $data : [];
    }

    public function host(): string
    {
        return $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    public function scheme(): string
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    public function url(): string
    {
        return $this->scheme() . '://' . $this->host() . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    public function base(): string
    {
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return $dir === '' ? '' : $dir;
    }
}

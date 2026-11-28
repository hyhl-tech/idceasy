<?php
/**
 * idceasy - 插件内核
 * 四类插件：registrar（注册商）/ gateway（支付网关）/ server（主机/服务器模块）/ addon（钩子扩展）
 * 插件文件均放在 /plugins/{type}/{id}.php，文件 return 一个对象（或 addon 返回数组）。
 */
namespace think;

class Plugin
{
    public const TYPES = ['registrar', 'gateway', 'server', 'integration', 'addon'];

    protected static array $instances = [];
    protected static array $discovered = [];
    protected static array $hooks = [];
    protected static ?array $records = null;
    protected static bool $booted = false;

    /** 启动时挂载已启用的 addon 插件钩子 */
    public static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;
        try {
            $records = self::records();
        } catch (\Throwable $e) {
            return; // 数据库尚未安装
        }
        foreach ($records as $row) {
            if ($row['type'] !== 'addon' || (int)$row['status'] !== 1) continue;
            $file = self::file('addon', $row['name']);
            if (!is_file($file)) continue;
            try {
                $def = require $file;
                if (!is_array($def)) continue;
                foreach (($def['hooks'] ?? []) as $event => $callback) {
                    if (is_callable($callback)) self::listen($event, $callback);
                }
                if (isset($def['boot']) && is_callable($def['boot'])) {
                    call_user_func($def['boot'], self::config('addon', $row['name']));
                }
            } catch (\Throwable $e) {
                log_write('plugin', '加载插件失败: ' . $row['name'] . ' - ' . $e->getMessage());
            }
        }
        foreach ($records as $row) {
            if ($row['type'] !== 'integration' || (int)$row['status'] !== 1) continue;
            try {
                $obj = self::make('integration', $row['name'], self::config('integration', $row['name']));
                if (method_exists($obj, 'boot')) $obj->boot();
            } catch (\Throwable $e) {
                log_write('plugin', '加载对接插件失败: ' . $row['name'] . ' - ' . $e->getMessage());
            }
        }
    }

    public static function file(string $type, string $id): string
    {
        $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
        return PLUGIN_PATH . '/' . $type . '/' . $id . '.php';
    }

    /** 扫描目录，返回 [id => info] */
    public static function discover(string $type, bool $refresh = false): array
    {
        if (isset(self::$discovered[$type]) && !$refresh) return self::$discovered[$type];
        $out = [];
        foreach ((array)glob(PLUGIN_PATH . '/' . $type . '/*.php') as $file) {
            $id = basename($file, '.php');
            if ($id === '' || str_starts_with($id, '_')) continue;
            try {
                if ($type === 'addon') {
                    $def = require $file;
                    if (is_array($def) && isset($def['info'])) $out[$id] = $def['info'] + ['id' => $id];
                } else {
                    $obj = require $file;
                    if (is_object($obj) && method_exists($obj, 'info')) {
                        $info = $obj->info();
                        $info['id'] = $info['id'] ?? $id;
                        $out[$id] = $info;
                    }
                }
            } catch (\Throwable $e) {
                $out[$id] = ['id' => $id, 'title' => $id, 'version' => '-', 'error' => $e->getMessage()];
            }
        }
        ksort($out);
        self::$discovered[$type] = $out;
        return $out;
    }

    /** 取得插件实例（自动注入配置） */
    public static function make(string $type, string $id, ?array $config = null)
    {
        $key = $type . ':' . $id;
        if (!isset(self::$instances[$key])) {
            $file = self::file($type, $id);
            if (!is_file($file)) throw new \RuntimeException('插件不存在: ' . $key);
            $obj = require $file;
            if (!is_object($obj)) throw new \RuntimeException('插件格式错误: ' . $key);
            self::$instances[$key] = $obj;
        }
        $obj = self::$instances[$key];
        if ($config !== null && method_exists($obj, 'setConfig')) $obj->setConfig($config);
        return $obj;
    }

    public static function records(bool $refresh = false): array
    {
        if (self::$records === null || $refresh) {
            $rows = Db::table('plugin')->order('type')->order('name')->select();
            self::$records = [];
            foreach ($rows as $r) self::$records[$r['type'] . ':' . $r['name']] = $r;
        }
        return array_values(self::$records);
    }

    public static function record(string $type, string $name): ?array
    {
        return self::$records[$type . ':' . $name] ?? null;
    }

    public static function enabled(string $type, string $name): bool
    {
        $r = self::record($type, $name);
        return $r && (int)$r['status'] === 1;
    }

    public static function config(string $type, string $name): array
    {
        $r = self::record($type, $name);
        $cfg = $r ? json_decode((string)$r['config'], true) : [];
        return is_array($cfg) ? $cfg : [];
    }

    public static function saveConfig(string $type, string $name, array $config): void
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $exists = Db::table('plugin')->where(['type' => $type, 'name' => $name])->find();
        if ($exists) {
            Db::table('plugin')->where('id', $exists['id'])->update(['config' => $json, 'updated_at' => now()]);
        } else {
            $info = self::discover($type)[$name] ?? ['title' => $name, 'version' => '1.0'];
            Db::table('plugin')->insert([
                'type' => $type, 'name' => $name, 'title' => $info['title'] ?? $name,
                'version' => $info['version'] ?? '1.0', 'status' => 0, 'config' => $json,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        self::$records = null;
    }

    public static function toggle(string $type, string $name, bool $on): void
    {
        $row = Db::table('plugin')->where(['type' => $type, 'name' => $name])->find();
        if (!$row) {
            self::saveConfig($type, $name, []);
            $row = Db::table('plugin')->where(['type' => $type, 'name' => $name])->find();
        }
        Db::table('plugin')->where('id', $row['id'])->update(['status' => $on ? 1 : 0, 'updated_at' => now()]);
        self::$records = null;
        if ($type === 'addon') {
            $file = self::file($type, $name);
            $def = is_file($file) ? require $file : null;
            $fn = $on ? ($def['install'] ?? null) : ($def['uninstall'] ?? null);
            if (is_callable($fn)) {
                try { $fn(); } catch (\Throwable $e) { log_write('plugin', '插件生命周期失败: ' . $e->getMessage()); }
            }
        }
    }

    public static function listen(string $event, callable $callback): void
    {
        self::$hooks[$event][] = $callback;
    }

    /** 触发钩子，回调返回值会被收集；传入引用型参数可原地修改 */
    public static function hook(string $event, ...$args): array
    {
        $results = [];
        foreach (self::$hooks[$event] ?? [] as $cb) {
            try {
                $results[] = $cb(...$args);
            } catch (\Throwable $e) {
                log_write('plugin', '钩子执行失败 [' . $event . ']: ' . $e->getMessage());
            }
        }
        return $results;
    }

    public static function has(string $event): bool
    {
        return !empty(self::$hooks[$event]);
    }
}

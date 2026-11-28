<?php
/**
 * 主机开通：选择服务器、调用 server 插件、状态同步
 */
namespace app\service;

use think\Db;
use think\Plugin;

class Provision
{
    public static function pickServer(array $product): ?array
    {
        $q = Db::table('server')->where('status', 1)->where('module', $product['module'] ?: 'manual');
        if ((int)$product['server_group_id'] > 0) $q->where('group_id', (int)$product['server_group_id']);
        $servers = $q->order('id')->select();
        if (!$servers) return null;
        $best = null;
        $bestLoad = PHP_INT_MAX;
        foreach ($servers as $server) {
            $count = Db::table('host')->where('server_id', $server['id'])
                ->whereIn('status', ['active', 'suspended', 'pending'])->count();
            if ((int)$server['max_accounts'] > 0 && $count >= (int)$server['max_accounts']) continue;
            if ($count < $bestLoad) {
                $bestLoad = $count;
                $best = $server;
            }
        }
        return $best;
    }

    public static function configFor(array $server): array
    {
        return [
            'host'     => $server['host'] ?? '',
            'port'     => (int)($server['port'] ?? 0),
            'username' => $server['username'] ?? '',
            'password' => decrypt_str($server['password'] ?? ''),
            'api_key'  => decrypt_str($server['api_key'] ?? ''),
            'secure'   => (int)($server['secure'] ?? 1),
            'server'   => $server['name'] ?? '',
        ];
    }

    protected static function params(int $hostId): array
    {
        $host = Db::table('host')->where('id', $hostId)->find();
        if (!$host) return ['ok' => false, 'error' => '服务不存在'];
        $product = Db::table('product')->where('id', $host['product_id'])->find();
        if (!$product) return ['ok' => false, 'error' => '产品不存在'];
        $server = $host['server_id'] ? Db::table('server')->where('id', $host['server_id'])->find() : null;
        if (!$server && $product['module'] !== 'manual') {
            $server = self::pickServer($product);
            if ($server) Db::table('host')->where('id', $hostId)->update(['server_id' => $server['id']]);
        }
        return [
            'ok' => true,
            'host' => $host, 'product' => $product, 'server' => $server,
            'params' => [
                'host_id' => $hostId, 'domain' => $host['domain'], 'username' => $host['username'],
                'password' => decrypt_str($host['password']), 'product_id' => $product['id'],
                'product_name' => $product['name'], 'cycle' => $host['cycle'],
                'server' => $server, 'config' => $server ? self::configFor($server) : [],
            ],
        ];
    }

    protected static function module(string $name, array $config)
    {
        return Plugin::make('server', $name ?: 'manual', $config);
    }

    public static function create(int $hostId): array
    {
        $ctx = self::params($hostId);
        if (!$ctx['ok']) return $ctx;
        $host = $ctx['host'];
        $module = $host['module'] ?: 'manual';
        if ($module === 'manual') {
            return ['ok' => true, 'manual' => true, 'msg' => '人工开通服务，等待管理员处理'];
        }
        if (!$ctx['server']) return ['ok' => false, 'error' => '未找到可用服务器节点'];
        try {
            $plugin = self::module($module, $ctx['params']['config']);
            $res = $plugin->create($ctx['params']);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!empty($res['ok'])) {
            Db::table('host')->where('id', $hostId)->update([
                'status' => 'active',
                'remote_id' => (string)($res['remote_id'] ?? ''),
                'ip' => (string)($res['ip'] ?? ''),
                'username' => (string)($res['username'] ?? $host['username']),
                'password' => !empty($res['password']) ? encrypt_str($res['password']) : $host['password'],
                'updated_at' => now(),
            ]);
            if (!empty($res['server_id'])) {
                Db::table('host')->where('id', $hostId)->update(['server_id' => (int)$res['server_id']]);
            }
            hook('host.provisioned', ['host_id' => $hostId, 'result' => $res]);
            self::notifyHostCreated($hostId);
        }
        return $res;
    }

    public static function suspend(int $hostId): array
    {
        return self::action($hostId, 'suspend', ['status' => 'suspended']);
    }

    public static function unsuspend(int $hostId): array
    {
        return self::action($hostId, 'unsuspend', ['status' => 'active']);
    }

    public static function terminate(int $hostId): array
    {
        return self::action($hostId, 'terminate', ['status' => 'terminated']);
    }

    protected static function action(int $hostId, string $method, array $update): array
    {
        $ctx = self::params($hostId);
        if (!$ctx['ok']) return $ctx;
        if (($ctx['host']['module'] ?: 'manual') === 'manual') {
            Db::table('host')->where('id', $hostId)->update($update + ['updated_at' => now()]);
            return ['ok' => true, 'manual' => true];
        }
        try {
            $plugin = self::module($ctx['host']['module'], $ctx['params']['config']);
            $res = $plugin->{$method}($ctx['params']);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!empty($res['ok'])) Db::table('host')->where('id', $hostId)->update($update + ['updated_at' => now()]);
        return $res;
    }

    public static function changePassword(int $hostId, string $password): array
    {
        $ctx = self::params($hostId);
        if (!$ctx['ok']) return $ctx;
        if (($ctx['host']['module'] ?: 'manual') !== 'manual') {
            try {
                $res = self::module($ctx['host']['module'], $ctx['params']['config'])
                    ->changePassword($ctx['params'], $password);
            } catch (\Throwable $e) {
                $res = ['ok' => false, 'error' => $e->getMessage()];
            }
            if (empty($res['ok'])) return $res;
        }
        Db::table('host')->where('id', $hostId)->update(['password' => encrypt_str($password), 'updated_at' => now()]);
        hook('host.password_changed', ['host_id' => $hostId]);
        return ['ok' => true];
    }

    public static function power(int $hostId, string $action): array
    {
        $ctx = self::params($hostId);
        if (!$ctx['ok']) return $ctx;
        if (!in_array($action, ['start', 'stop', 'reboot', 'hardstop'], true)) {
            return ['ok' => false, 'error' => '不支持的操作'];
        }
        if (($ctx['host']['module'] ?: 'manual') === 'manual') {
            return ['ok' => false, 'error' => '人工服务不支持电源操作'];
        }
        try {
            return self::module($ctx['host']['module'], $ctx['params']['config'])->power($ctx['params'], $action);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function changePackage(int $hostId): array
    {
        $ctx = self::params($hostId);
        if (!$ctx['ok']) return $ctx;
        if (($ctx['host']['module'] ?: 'manual') === 'manual') return ['ok' => true];
        try {
            return self::module($ctx['host']['module'], $ctx['params']['config'])->changePackage($ctx['params']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function usage(int $hostId): array
    {
        $ctx = self::params($hostId);
        if (!$ctx['ok']) return $ctx;
        if (($ctx['host']['module'] ?: 'manual') === 'manual') return ['ok' => false, 'error' => '暂无用量数据'];
        try {
            return self::module($ctx['host']['module'], $ctx['params']['config'])->usage($ctx['params']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function loginUrl(int $hostId): array
    {
        $ctx = self::params($hostId);
        if (!$ctx['ok']) return $ctx;
        if (($ctx['host']['module'] ?: 'manual') === 'manual') return ['ok' => false, 'error' => '人工服务无面板'];
        try {
            return self::module($ctx['host']['module'], $ctx['params']['config'])->loginUrl($ctx['params']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function testServer(int $serverId): array
    {
        $server = Db::table('server')->where('id', $serverId)->find();
        if (!$server) return ['ok' => false, 'error' => '服务器不存在'];
        try {
            return self::module($server['module'], self::configFor($server))->test();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function makeUsername(string $domain): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('.', $domain)[0] ?? ''));
        $base = substr($base, 0, 8);
        if (strlen($base) < 3) $base = 'host' . $base;
        return $base . random_int(100, 999);
    }

    protected static function notifyHostCreated(int $hostId): void
    {
        $host = Db::table('host')->where('id', $hostId)->find();
        if (!$host) return;
        $user = Db::table('user')->where('id', $host['user_id'])->find();
        if (!$user) return;
        $server = $host['server_id'] ? Db::table('server')->where('id', $host['server_id'])->find() : null;
        Mailer::sendTemplate('host_created', $user['email'], [
            'name' => $user['name'] ?: $user['email'], 'domain' => $host['domain'],
            'panel' => $server ? ('https://' . $server['host'] . ':' . ($server['port'] ?: 2083)) : abs_url('/user/services'),
            'username' => $host['username'], 'password' => '（请登录会员中心查看）',
        ], false);
    }
}

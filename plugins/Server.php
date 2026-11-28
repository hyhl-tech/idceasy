<?php
/**
 * 主机/服务器开通模块插件基类
 *
 * $params 结构：
 *   host_id, domain, username, password, product_id, product_name, cycle,
 *   server (host 行), config (解密后的服务器凭据)
 * create() 返回：['ok'=>true,'remote_id'=>'123','ip'=>'1.2.3.4','username'=>'','password'=>'']
 */
namespace plugins;

require_once __DIR__ . '/PluginBase.php';
require_once __DIR__ . '/Http.php';

abstract class Server extends PluginBase
{
    use Http;

    abstract public function create(array $params): array;

    public function suspend(array $params): array
    {
        return $this->fail('该模块不支持暂停');
    }

    public function unsuspend(array $params): array
    {
        return $this->fail('该模块不支持解除暂停');
    }

    public function terminate(array $params): array
    {
        return $this->fail('该模块不支持删除');
    }

    public function changePassword(array $params, string $password): array
    {
        return $this->fail('该模块不支持修改密码');
    }

    public function power(array $params, string $action): array
    {
        return $this->fail('该模块不支持电源操作');
    }

    public function changePackage(array $params): array
    {
        return $this->fail('该模块不支持升降配');
    }

    /** 返回 ['ok'=>true,'disk'=>['used'=>..,'limit'=>..],'bandwidth'=>[...],'raw'=>[]] */
    public function usage(array $params): array
    {
        return $this->fail('该模块不支持用量查询');
    }

    /** 面板单点登录地址 */
    public function loginUrl(array $params): array
    {
        return $this->fail('该模块不支持面板登录');
    }

    /** 服务器级连通性测试 */
    public function test(): array
    {
        return ['ok' => true, 'msg' => '该模块无需测试'];
    }

    protected function basicAuth(): array
    {
        return [(string)$this->config('username', ''), (string)$this->config('password', '')];
    }

    protected function baseUrl(string $scheme = 'https'): string
    {
        $host = (string)$this->config('host', '');
        $port = (int)$this->config('port', 0);
        return rtrim($scheme . '://' . $host . ($port ? ':' . $port : ''), '/');
    }
}

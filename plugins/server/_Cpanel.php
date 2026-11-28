<?php
/**
 * cPanel / WHM 通用驱动（WHM API1）
 */
require_once __DIR__ . '/../Server.php';
use plugins\Server;

abstract class CpanelServer extends Server
{
    protected function whm(string $function, array $params = []): array
    {
        $host = (string)$this->config('host', '');
        $port = (int)$this->config('port', 2087);
        $user = (string)$this->config('username', 'root');
        $pass = (string)$this->config('password', '');
        $url = 'https://' . $host . ':' . $port . '/json-api/' . $function . '?api.version=1&' . http_build_query($params);
        $res = $this->http('GET', $url, [], ['Authorization: WHM ' . $user . ':' . $pass], ['verify' => false]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $j = $res['json'];
        if (!is_array($j)) return $this->fail('WHM 返回异常：' . mb_substr($res['body'], 0, 160));
        $result = $j['metadata']['result'] ?? 0;
        if ((int)$result !== 1) {
            return $this->fail('WHM: ' . ($j['metadata']['reason'] ?? '请求失败'));
        }
        return $this->ok(['data' => $j['data'] ?? []]);
    }

    public function create(array $params): array
    {
        $res = $this->whm('createacct', [
            'username' => $params['username'], 'domain' => $params['domain'],
            'password' => $params['password'], 'plan' => (string)$this->config('plan', 'default'),
            'contactemail' => (string)$this->config('contact_email', ''),
            'cgi' => 1, 'quota' => 0,
        ]);
        if (!$res['ok']) return $res;
        return $this->ok([
            'remote_id' => (string)($res['data']['user'] ?? $params['username']),
            'ip' => (string)($res['data']['ip'] ?? $this->config('host', '')),
            'username' => (string)($res['data']['user'] ?? $params['username']),
        ]);
    }

    public function suspend(array $params): array
    {
        return $this->whm('suspendacct', ['user' => $params['username'], 'reason' => '逾期未付款']);
    }

    public function unsuspend(array $params): array
    {
        return $this->whm('unsuspendacct', ['user' => $params['username']]);
    }

    public function terminate(array $params): array
    {
        return $this->whm('removeacct', ['user' => $params['username'], 'keepdns' => 0]);
    }

    public function changePassword(array $params, string $password): array
    {
        return $this->whm('passwd', ['user' => $params['username'], 'password' => $password]);
    }

    public function usage(array $params): array
    {
        $res = $this->whm('listaccts', ['search' => $params['username'], 'searchtype' => 'user']);
        if (!$res['ok']) return $res;
        $row = $res['data']['acct'][0] ?? [];
        return $this->ok(['disk' => ['used' => ((float)($row['diskused'] ?? 0)) * 1024 * 1024,
            'limit' => ((float)($row['disklimit'] ?? 0)) * 1024 * 1024],
            'bandwidth' => ['used' => ((float)($row['bandwidthused'] ?? 0)) * 1024 * 1024,
                'limit' => ((float)($row['bandwidthlimit'] ?? 0)) * 1024 * 1024], 'raw' => $row]);
    }

    public function loginUrl(array $params): array
    {
        $res = $this->whm('create_user_session', ['user' => $params['username'], 'service' => 'cpaneld']);
        if (!$res['ok']) return $res;
        $url = (string)($res['data']['url'] ?? '');
        if ($url === '') return $this->fail('未获取到面板登录地址');
        return $this->ok(['url' => $url]);
    }

    public function test(): array
    {
        $res = $this->whm('version');
        return $res['ok'] ? ['ok' => true, 'msg' => 'WHM 连接成功，版本 ' . (string)($res['data']['version'] ?? '')] : ['ok' => false, 'error' => $res['error']];
    }
}

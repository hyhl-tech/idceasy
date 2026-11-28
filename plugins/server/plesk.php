<?php
return new class extends \plugins\Server {
    public function info(): array
    {
        return ['id' => 'plesk', 'title' => 'Plesk', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'Plesk REST API v2，需要管理员账户与目标客户 ID。',
            'fields' => [
                ['name' => 'host', 'label' => '服务器地址', 'type' => 'text', 'required' => true],
                ['name' => 'port', 'label' => '端口', 'type' => 'number', 'help' => '默认 8443'],
                ['name' => 'username', 'label' => '管理员用户名', 'type' => 'text', 'required' => true],
                ['name' => 'password', 'label' => '密码', 'type' => 'password', 'required' => true],
                ['name' => 'client_id', 'label' => '客户 ID', 'type' => 'number', 'help' => 'Plesk 中的客户 ID，默认 1'],
                ['name' => 'ip', 'label' => '默认 IP', 'type' => 'text'],
            ]];
    }
    protected function api(string $method, string $path, array $body = []): array
    {
        $base = 'https://' . $this->config('host', '') . ':' . (int)$this->config('port', 8443) . '/api/v2';
        $auth = 'Authorization: Basic ' . base64_encode($this->config('username', '') . ':' . $this->config('password', ''));
        $res = $this->http($method, $base . $path, $body, [$auth], ['json' => true, 'verify' => false]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        if ($res['code'] >= 400) {
            return $this->fail('Plesk: ' . (is_array($res['json']) ? ($res['json']['message'] ?? '请求失败') : mb_substr($res['body'], 0, 160)));
        }
        return $this->ok(['data' => $res['json'] ?? []]);
    }
    protected function domainId(string $name): ?int
    {
        $res = $this->api('GET', '/domains?name=' . urlencode($name));
        if (!$res['ok']) return null;
        foreach ((array)$res['data'] as $d) if (($d['name'] ?? '') === $name) return (int)$d['id'];
        return null;
    }
    public function create(array $params): array
    {
        $res = $this->api('POST', '/domains', [
            'name' => $params['domain'], 'hosting_type' => 'virtual',
            'hosting_settings' => ['ftp_login' => $params['username'], 'ftp_password' => $params['password']],
            'owner_client' => ['id' => (int)$this->config('client_id', 1)],
            'ip_address' => (string)$this->config('ip', ''),
        ]);
        if (!$res['ok']) return $res;
        return $this->ok(['remote_id' => (string)($res['data']['id'] ?? ''), 'username' => $params['username'],
            'ip' => (string)$this->config('ip', $this->config('host', ''))]);
    }
    public function suspend(array $params): array
    {
        $id = $params['host']['remote_id'] ?? '' ?: $this->domainId($params['domain']);
        if (!$id) return $this->fail('找不到 Plesk 域名 ID');
        return $this->api('PUT', '/domains/' . $id, ['status' => 1]);
    }
    public function unsuspend(array $params): array
    {
        $id = $params['host']['remote_id'] ?? '' ?: $this->domainId($params['domain']);
        if (!$id) return $this->fail('找不到 Plesk 域名 ID');
        return $this->api('PUT', '/domains/' . $id, ['status' => 0]);
    }
    public function terminate(array $params): array
    {
        $id = $params['host']['remote_id'] ?? '' ?: $this->domainId($params['domain']);
        if (!$id) return $this->fail('找不到 Plesk 域名 ID');
        return $this->api('DELETE', '/domains/' . $id);
    }
    public function test(): array
    {
        $res = $this->api('GET', '/server');
        return $res['ok'] ? ['ok' => true, 'msg' => 'Plesk 连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

<?php
return new class extends \plugins\Server {
    public function info(): array
    {
        return ['id' => 'directadmin', 'title' => 'DirectAdmin', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'DirectAdmin 面板，通过 CMD_API 创建、暂停与删除用户。',
            'fields' => [
                ['name' => 'host', 'label' => '服务器地址', 'type' => 'text', 'required' => true],
                ['name' => 'port', 'label' => '端口', 'type' => 'number', 'help' => '默认 2222'],
                ['name' => 'username', 'label' => '管理员用户名', 'type' => 'text', 'required' => true],
                ['name' => 'password', 'label' => '密码', 'type' => 'password', 'required' => true],
                ['name' => 'package', 'label' => '默认套餐', 'type' => 'text'],
                ['name' => 'ip', 'label' => 'IP', 'type' => 'text', 'help' => '留空使用共享 IP'],
            ]];
    }
    protected function api(string $cmd, array $params = [], string $method = 'POST'): array
    {
        $url = 'https://' . $this->config('host', '') . ':' . (int)$this->config('port', 2222) . '/CMD_API_' . $cmd;
        $res = $this->http($method, $url, $params, ['Authorization: Basic ' . base64_encode(
            $this->config('username', '') . ':' . $this->config('password', ''))], ['verify' => false]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        parse_str($res['body'], $out);
        if (isset($out['error']) && $out['error'] !== '0') {
            return $this->fail('DirectAdmin: ' . ($out['details'] ?? $out['text'] ?? '请求失败'));
        }
        return $this->ok(['data' => $out]);
    }
    public function create(array $params): array
    {
        $res = $this->api('ACCOUNT_USER', [
            'action' => 'create', 'username' => $params['username'], 'domain' => $params['domain'],
            'passwd' => $params['password'], 'passwd2' => $params['password'],
            'package' => (string)$this->config('package', 'default'),
            'ip' => (string)$this->config('ip', ''), 'notify' => 'no',
            'email' => (string)$this->config('email', ''),
        ]);
        if (!$res['ok']) return $res;
        return $this->ok(['remote_id' => $params['username'], 'username' => $params['username'],
            'ip' => (string)$this->config('host', '')]);
    }
    public function suspend(array $params): array
    {
        return $this->api('SELECT_USERS', ['action' => 'select', 'suspend' => 'Suspend', 'select0' => $params['username']]);
    }
    public function unsuspend(array $params): array
    {
        return $this->api('SELECT_USERS', ['action' => 'select', 'suspend' => 'Unsuspend', 'select0' => $params['username']]);
    }
    public function terminate(array $params): array
    {
        return $this->api('SELECT_USERS', ['action' => 'delete', 'select0' => $params['username']]);
    }
    public function changePassword(array $params, string $password): array
    {
        return $this->api('USER_PASSWD', ['username' => $params['username'], 'passwd' => $password, 'passwd2' => $password]);
    }
    public function usage(array $params): array
    {
        $res = $this->api('SHOW_USER_USAGE', ['user' => $params['username']], 'GET');
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok(['disk' => ['used' => ((float)($d['disk_usage'] ?? 0)) * 1024 * 1024, 'limit' => ((float)($d['quota'] ?? 0)) * 1024 * 1024],
            'bandwidth' => ['used' => ((float)($d['bandwidth_usage'] ?? 0)) * 1024 * 1024, 'limit' => ((float)($d['bandwidth'] ?? 0)) * 1024 * 1024],
            'raw' => $d]);
    }
    public function test(): array
    {
        $res = $this->api('SHOW_USERS', [], 'GET');
        return $res['ok'] ? ['ok' => true, 'msg' => 'DirectAdmin 连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

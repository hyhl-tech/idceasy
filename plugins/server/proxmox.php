<?php
return new class extends \plugins\Server {
    public function info(): array
    {
        return ['id' => 'proxmox', 'title' => 'Proxmox VE', 'version' => '1.0', 'author' => 'idceasy',
            'description' => '通过模板克隆创建 KVM 虚拟机，使用 API Token 认证。',
            'fields' => [
                ['name' => 'host', 'label' => 'PVE 地址', 'type' => 'text', 'required' => true],
                ['name' => 'port', 'label' => '端口', 'type' => 'number', 'help' => '默认 8006'],
                ['name' => 'user', 'label' => 'API 用户', 'type' => 'text', 'help' => '如 root@pam'],
                ['name' => 'token_id', 'label' => 'Token ID', 'type' => 'text', 'required' => true],
                ['name' => 'token_secret', 'label' => 'Token Secret', 'type' => 'password', 'required' => true],
                ['name' => 'node', 'label' => '节点', 'type' => 'text', 'required' => true],
                ['name' => 'template_id', 'label' => '模板 VMID', 'type' => 'number', 'required' => true],
                ['name' => 'storage', 'label' => '存储', 'type' => 'text', 'help' => '克隆目标存储，如 local-lvm'],
                ['name' => 'bridge', 'label' => '网桥', 'type' => 'text', 'help' => '默认 vmbr0'],
                ['name' => 'cores', 'label' => '默认核心数', 'type' => 'number'],
                ['name' => 'memory', 'label' => '默认内存 MB', 'type' => 'number'],
            ]];
    }
    protected function api(string $method, string $path, array $params = []): array
    {
        $base = 'https://' . $this->config('host', '') . ':' . (int)$this->config('port', 8006) . '/api2/json';
        $token = $this->config('user', 'root@pam') . '!' . $this->config('token_id', '') . '=' . $this->config('token_secret', '');
        $res = $this->http($method, $base . $path, $params, ['Authorization: PVEAPIToken=' . $token], ['verify' => false]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        if ($res['code'] >= 400) return $this->fail('Proxmox: ' . mb_substr($res['body'], 0, 180));
        return $this->ok(['data' => $res['json']['data'] ?? []]);
    }
    public function create(array $params): array
    {
        $next = $this->api('GET', '/cluster/nextid');
        if (!$next['ok']) return $next;
        $vmid = (int)$next['data'];
        $node = (string)$this->config('node', '');
        $clone = $this->api('POST', '/nodes/' . $node . '/qemu/' . (int)$this->config('template_id', 0) . '/clone', [
            'newid' => $vmid, 'name' => preg_replace('/[^a-z0-9\-]/i', '-', $params['domain']),
            'full' => 1, 'storage' => (string)$this->config('storage', 'local-lvm'),
        ]);
        if (!$clone['ok']) return $clone;
        $this->api('POST', '/nodes/' . $node . '/qemu/' . $vmid . '/config', [
            'cores' => (int)$this->config('cores', 2), 'memory' => (int)$this->config('memory', 2048),
            'net0' => 'virtio,bridge=' . (string)$this->config('bridge', 'vmbr0'),
        ]);
        $this->api('POST', '/nodes/' . $node . '/qemu/' . $vmid . '/status/start');
        return $this->ok(['remote_id' => (string)$vmid, 'username' => 'root']);
    }
    public function suspend(array $params): array
    {
        return $this->api('POST', '/nodes/' . $this->config('node', '') . '/qemu/' . $params['host']['remote_id'] . '/status/stop');
    }
    public function unsuspend(array $params): array
    {
        return $this->api('POST', '/nodes/' . $this->config('node', '') . '/qemu/' . $params['host']['remote_id'] . '/status/start');
    }
    public function terminate(array $params): array
    {
        return $this->api('DELETE', '/nodes/' . $this->config('node', '') . '/qemu/' . $params['host']['remote_id'] . '?purge=1');
    }
    public function power(array $params, string $action): array
    {
        $map = ['start' => 'start', 'stop' => 'shutdown', 'reboot' => 'reboot', 'hardstop' => 'stop'];
        if (!isset($map[$action])) return $this->fail('不支持的操作');
        return $this->api('POST', '/nodes/' . $this->config('node', '') . '/qemu/' . $params['host']['remote_id'] . '/status/' . $map[$action]);
    }
    public function test(): array
    {
        $res = $this->api('GET', '/version');
        return $res['ok'] ? ['ok' => true, 'msg' => 'Proxmox 连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

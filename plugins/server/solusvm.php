<?php
return new class extends \plugins\Server {
    public function info(): array
    {
        return ['id' => 'solusvm', 'title' => 'SolusVM', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'SolusVM 主控，按节点 / 方案 / 模板创建 VPS。',
            'fields' => [
                ['name' => 'host', 'label' => '主控地址', 'type' => 'text', 'required' => true],
                ['name' => 'port', 'label' => '端口', 'type' => 'number', 'help' => '默认 5656'],
                ['name' => 'api_id', 'label' => 'API ID', 'type' => 'text', 'required' => true],
                ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                ['name' => 'node', 'label' => '节点', 'type' => 'text'],
                ['name' => 'plan', 'label' => '方案', 'type' => 'text'],
                ['name' => 'template', 'label' => '系统模板', 'type' => 'text'],
                ['name' => 'virt', 'label' => '虚拟化', 'type' => 'select', 'options' => ['kvm' => 'KVM', 'openvz' => 'OpenVZ', 'xen' => 'Xen']],
            ]];
    }
    protected function api(string $action, array $params = []): array
    {
        $url = 'https://' . $this->config('host', '') . ':' . (int)$this->config('port', 5656) . '/api/admin/command.php';
        $res = $this->http('GET', $url, array_merge([
            'apiid' => $this->config('api_id', ''), 'apikey' => $this->config('api_key', ''), 'action' => $action,
        ], $params), [], ['verify' => false]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $j = $res['json'];
        if (!is_array($j)) return $this->fail('返回格式异常');
        if (($j['status'] ?? '') !== 'success') return $this->fail('SolusVM: ' . ($j['statusmsg'] ?? '请求失败'));
        return $this->ok(['data' => $j]);
    }
    public function create(array $params): array
    {
        $res = $this->api('vserver-create', [
            'type' => (string)$this->config('virt', 'kvm'), 'node' => (string)$this->config('node', ''),
            'plan' => (string)$this->config('plan', ''), 'template' => (string)$this->config('template', ''),
            'password' => $params['password'], 'username' => $params['username'],
            'hostname' => $params['domain'],
        ]);
        if (!$res['ok']) return $res;
        $info = $res['data']['info'] ?? [];
        return $this->ok(['remote_id' => (string)($info['vserverid'] ?? ''), 'ip' => (string)($info['mainipaddress'] ?? ''),
            'username' => 'root']);
    }
    public function suspend(array $params): array
    {
        return $this->api('vserver-suspend', ['vserverid' => $params['host']['remote_id'] ?? '']);
    }
    public function unsuspend(array $params): array
    {
        return $this->api('vserver-unsuspend', ['vserverid' => $params['host']['remote_id'] ?? '']);
    }
    public function terminate(array $params): array
    {
        return $this->api('vserver-terminate', ['vserverid' => $params['host']['remote_id'] ?? '']);
    }
    public function changePassword(array $params, string $password): array
    {
        return $this->api('vserver-rootpassword', ['vserverid' => $params['host']['remote_id'] ?? '', 'rootpassword' => $password]);
    }
    public function power(array $params, string $action): array
    {
        $map = ['start' => 'vserver-boot', 'stop' => 'vserver-shutdown', 'reboot' => 'vserver-reboot'];
        if (!isset($map[$action])) return $this->fail('不支持的操作');
        return $this->api($map[$action], ['vserverid' => $params['host']['remote_id'] ?? '']);
    }
    public function test(): array
    {
        $res = $this->api('node-list');
        return $res['ok'] ? ['ok' => true, 'msg' => 'SolusVM 连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

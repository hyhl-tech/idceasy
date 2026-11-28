<?php
return new class extends \plugins\Server {
    public function info(): array
    {
        return ['id' => 'virtualizor', 'title' => 'Virtualizor', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'Virtualizor KVM/OpenVZ 面板，按预设方案创建 VPS。',
            'fields' => [
                ['name' => 'host', 'label' => '面板地址', 'type' => 'text', 'required' => true],
                ['name' => 'port', 'label' => '端口', 'type' => 'number', 'help' => '默认 4085'],
                ['name' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'required' => true],
                ['name' => 'api_pass', 'label' => 'API Password', 'type' => 'password', 'required' => true],
                ['name' => 'plan_id', 'label' => '默认方案 ID', 'type' => 'number', 'help' => 'Virtualizor 中的计划 plid'],
                ['name' => 'os_id', 'label' => '默认系统 ID', 'type' => 'number'],
                ['name' => 'virt', 'label' => '虚拟化', 'type' => 'select', 'options' => ['kvm' => 'KVM', 'openvz' => 'OpenVZ', 'lxc' => 'LXC']],
            ]];
    }
    protected function api(string $act, array $params = []): array
    {
        $url = 'https://' . $this->config('host', '') . ':' . (int)$this->config('port', 4085) . '/api/';
        $res = $this->http('POST', $url, array_merge([
            'api_key' => $this->config('api_key', ''), 'api_pass' => $this->config('api_pass', ''), 'act' => $act,
        ], $params), [], ['verify' => false]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $j = $res['json'];
        if (!is_array($j)) return $this->fail('返回格式异常');
        if ((int)($j['done']['status'] ?? 0) !== 1 && (int)($j['status'] ?? 0) !== 1) {
            return $this->fail('Virtualizor: ' . ($j['done']['msg'] ?? $j['msg'] ?? '请求失败'));
        }
        return $this->ok(['data' => $j]);
    }
    public function create(array $params): array
    {
        $res = $this->api('addvs', [
            'virt' => (string)$this->config('virt', 'kvm'),
            'plid' => (int)$this->config('plan_id', 1),
            'osid' => (int)$this->config('os_id', 1),
            'hostname' => $params['domain'], 'password' => $params['password'],
            'user_email' => (string)$this->config('email', cfg('company_email', '')),
        ]);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        $vsid = $d['vsid'] ?? $d['vpsid'] ?? ($d['done']['vsid'] ?? '');
        return $this->ok(['remote_id' => (string)$vsid, 'ip' => (string)($d['info']['mainip'] ?? ''),
            'username' => 'root']);
    }
    public function suspend(array $params): array
    {
        return $this->api('suspend', ['vpsid' => $params['host']['remote_id'] ?? '']);
    }
    public function unsuspend(array $params): array
    {
        return $this->api('unsuspend', ['vpsid' => $params['host']['remote_id'] ?? '']);
    }
    public function terminate(array $params): array
    {
        return $this->api('delete', ['vpsid' => $params['host']['remote_id'] ?? '']);
    }
    public function changePassword(array $params, string $password): array
    {
        return $this->api('changepassword', ['vpsid' => $params['host']['remote_id'] ?? '', 'password' => $password]);
    }
    public function power(array $params, string $action): array
    {
        $map = ['start' => 'start', 'stop' => 'stop', 'reboot' => 'reboot', 'hardstop' => 'stop'];
        return $this->api($map[$action] ?? 'reboot', ['vpsid' => $params['host']['remote_id'] ?? '']);
    }
    public function usage(array $params): array
    {
        $res = $this->api('vs_info', ['vpsid' => $params['host']['remote_id'] ?? '']);
        return $res['ok'] ? $this->ok(['raw' => $res['data']]) : $res;
    }
    public function test(): array
    {
        $res = $this->api('listvs');
        return $res['ok'] ? ['ok' => true, 'msg' => 'Virtualizor 连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

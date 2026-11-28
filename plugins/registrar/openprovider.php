<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return ['id' => 'openprovider', 'title' => 'Openprovider', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'Openprovider 白标注册商，使用 API Token 登录，需联系人 Handle。',
            'fields' => [
                ['name' => 'username', 'label' => '用户名', 'type' => 'text', 'required' => true],
                ['name' => 'password', 'label' => 'API 密码', 'type' => 'password', 'required' => true],
                ['name' => 'owner_handle', 'label' => 'Owner Handle', 'type' => 'text', 'required' => true],
                ['name' => 'admin_handle', 'label' => 'Admin Handle', 'type' => 'text'],
                ['name' => 'tech_handle', 'label' => 'Tech Handle', 'type' => 'text'],
                ['name' => 'billing_handle', 'label' => 'Billing Handle', 'type' => 'text'],
            ]];
    }
    protected function token(): ?string
    {
        $res = $this->http('POST', 'https://api.openprovider.eu/v1beta/auth/login',
            ['username' => $this->config('username', ''), 'password' => $this->config('password', '')], [], ['json' => true]);
        return $res['json']['data']['token'] ?? null;
    }
    protected function call(string $method, string $path, array $body = []): array
    {
        $token = $this->token();
        if (!$token) return $this->fail('Openprovider 登录失败，请检查用户名与密码');
        $res = $this->http($method, 'https://api.openprovider.eu/v1beta' . $path, $body,
            ['Authorization: Bearer ' . $token], ['json' => true]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $j = $res['json'];
        if (!is_array($j)) return $this->fail('返回格式异常');
        if (($j['code'] ?? 0) >= 400) return $this->fail('Openprovider: ' . ($j['desc'] ?? '未知错误'));
        return $this->ok(['data' => $j['data'] ?? []]);
    }
    public function check(string $domain): array
    {
        $pos = strrpos($domain, '.');
        $res = $this->call('POST', '/domains/check', ['domains' => [[
            'name' => substr($domain, 0, $pos), 'extension' => substr($domain, $pos + 1),
        ]]]);
        if (!$res['ok']) return $res;
        $r = $res['data']['results'][0] ?? [];
        return $this->ok(['available' => (bool)($r['status'] === 'free' || $r['available'] ?? false),
            'premium' => false, 'price' => (float)($r['price']['product']['price'] ?? 0)]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $pos = strrpos($domain, '.');
        $h = $this->config('owner_handle');
        $res = $this->call('POST', '/domains', [
            'domain' => ['name' => substr($domain, 0, $pos), 'extension' => substr($domain, $pos + 1)],
            'period' => max(1, $years), 'owner_handle' => $h,
            'admin_handle' => $this->config('admin_handle', $h), 'tech_handle' => $this->config('tech_handle', $h),
            'billing_handle' => $this->config('billing_handle', $h),
            'nameservers' => array_map(fn($n) => ['name' => $n], $ns),
        ]);
        return $res['ok'] ? $this->ok(['id' => (string)($res['data']['id'] ?? $domain),
            'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function renew(string $domain, int $years): array
    {
        $res = $this->call('POST', '/domains/' . $domain . '/renew', ['period' => max(1, $years)]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function getInfo(string $domain): array
    {
        $res = $this->call('GET', '/domains/' . $domain);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        $ns = [];
        foreach ((array)($d['nameservers'] ?? []) as $n) $ns[] = is_array($n) ? ($n['name'] ?? '') : $n;
        return $this->ok(['status' => stripos((string)($d['status'] ?? 'ACT'), 'ACT') !== false ? 'active' : 'pending',
            'expire_date' => !empty($d['expiration_date']) ? date('Y-m-d H:i:s', strtotime((string)$d['expiration_date'])) : null,
            'ns' => array_values(array_filter($ns))]);
    }
    public function setNS(string $domain, array $ns): array
    {
        $res = $this->call('PUT', '/domains/' . $domain, ['nameservers' => array_map(fn($n) => ['name' => $n], $ns)]);
        return $res['ok'] ? $this->ok() : $res;
    }
};

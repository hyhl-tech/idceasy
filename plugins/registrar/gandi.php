<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return ['id' => 'gandi', 'title' => 'Gandi', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'Gandi v5 API，支持注册、续费、NS 与域名信息查询。',
            'fields' => [
                ['name' => 'api_key', 'label' => 'Personal Access Token', 'type' => 'password', 'required' => true],
            ]];
    }
    protected function call(string $method, string $path, array $body = []): array
    {
        $res = $this->http($method, 'https://api.gandi.net/v5' . $path, $body,
            ['Authorization: Apikey ' . $this->config('api_key', '')], ['json' => true]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $j = $res['json'];
        if ($res['code'] >= 400) return $this->fail('Gandi: ' . (is_array($j) ? ($j['message'] ?? '请求失败') : $res['body']));
        return $this->ok(['data' => is_array($j) ? $j : []]);
    }
    public function check(string $domain): array
    {
        $res = $this->call('GET', '/domain/check?name=' . urlencode($domain));
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok(['available' => (bool)($d['available'] ?? false), 'premium' => false,
            'price' => (float)($d['price'] ?? 0)]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $c = $this->contactBasic();
        $res = $this->call('POST', '/domain/domains', [
            'fqdn' => $domain, 'duration' => max(1, $years),
            'owner' => ['given' => $c['first_name'], 'family' => $c['last_name'], 'email' => $c['email'],
                'address' => $c['address1'], 'city' => $c['city'], 'zip' => $c['postcode'], 'country' => $c['country'],
                'phone' => preg_replace('/\D/', '', $c['phone']) ?: '13800000000'],
            'nameservers' => array_values($ns),
        ]);
        return $res['ok'] ? $this->ok(['id' => $domain, 'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function renew(string $domain, int $years): array
    {
        $res = $this->call('POST', '/domain/domains/' . $domain . '/renew', ['duration' => max(1, $years)]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function getInfo(string $domain): array
    {
        $res = $this->call('GET', '/domain/domains/' . $domain);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok(['status' => !empty($d['status']) ? 'active' : 'pending',
            'expire_date' => !empty($d['dates']['registry_ends_at']) ? date('Y-m-d H:i:s', strtotime((string)$d['dates']['registry_ends_at'])) : null,
            'ns' => (array)($d['nameservers'] ?? [])]);
    }
    public function setNS(string $domain, array $ns): array
    {
        $res = $this->call('PUT', '/domain/domains/' . $domain . '/nameservers', ['servers' => array_values($ns)]);
        return $res['ok'] ? $this->ok() : $res;
    }
    public function test(): array
    {
        $res = $this->call('GET', '/domain/check?name=idceasy-test.com');
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

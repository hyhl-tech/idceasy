<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return [
            'id' => 'godaddy', 'title' => 'GoDaddy / WildWest', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'GoDaddy Reseller API，WildWest 经销商需填写 Shopper ID。',
            'fields' => [
                ['name' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'required' => true],
                ['name' => 'api_secret', 'label' => 'API Secret', 'type' => 'password', 'required' => true],
                ['name' => 'shopper_id', 'label' => 'Shopper ID', 'type' => 'text', 'help' => 'WildWest 经销商必填'],
                ['name' => 'sandbox', 'label' => '使用 OTE 测试环境', 'type' => 'checkbox'],
            ],
        ];
    }
    protected function call(string $method, string $path, array $body = []): array
    {
        $base = $this->config('sandbox', '0') === '1' ? 'https://api.ote-godaddy.com' : 'https://api.godaddy.com';
        $headers = ['Authorization: sso-key ' . $this->config('api_key', '') . ':' . $this->config('api_secret', '')];
        if ($this->config('shopper_id', '') !== '') $headers[] = 'X-Shopper-Id: ' . $this->config('shopper_id');
        $opts = ['json' => true];
        $res = $this->http($method, $base . $path, $body, $headers, $opts);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        if ($res['code'] >= 400) {
            $j = $res['json'];
            $msg = is_array($j) ? ($j['message'] ?? $j['fields'][0]['message'] ?? '') : $res['body'];
            return $this->fail('GoDaddy: ' . mb_substr((string)$msg, 0, 180));
        }
        return $this->ok(['data' => $res['json'] ?? []]);
    }
    protected function contact(): array
    {
        $c = $this->contactBasic();
        return [
            'nameFirst' => $c['first_name'], 'nameLast' => $c['last_name'],
            'email' => $c['email'], 'phone' => preg_replace('/\D/', '', $c['phone']) ?: '13800000000',
            'addressMailing' => ['address1' => $c['address1'], 'city' => $c['city'], 'state' => $c['state'],
                'postalCode' => $c['postcode'], 'country' => $c['country']],
        ];
    }
    public function check(string $domain): array
    {
        $res = $this->call('GET', '/v1/domains/available?domain=' . urlencode($domain) . '&checkType=FAST');
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok(['available' => (bool)($d['available'] ?? false),
            'premium' => (bool)($d['premium'] ?? false), 'price' => (float)($d['price'] ?? 0) / 1000000]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $c = $this->contact();
        $body = [
            'domain' => $domain, 'period' => max(1, $years), 'renewAuto' => true,
            'privacy' => false, 'consent' => [
                'agreedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'agreedBy' => client_ip(), 'agreementKeys' => ['DNRA'],
            ],
            'contactAdmin' => $c, 'contactRegistrant' => $c, 'contactTech' => $c, 'contactBilling' => $c,
        ];
        if ($ns) $body['nameServers'] = array_values($ns);
        $res = $this->call('POST', '/v1/domains/purchase', $body);
        if (!$res['ok']) return $res;
        return $this->ok(['id' => (string)($res['data']['orderId'] ?? $domain),
            'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]);
    }
    public function renew(string $domain, int $years): array
    {
        $res = $this->call('POST', '/v1/domains/' . $domain . '/renew', ['period' => max(1, $years)]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function getInfo(string $domain): array
    {
        $res = $this->call('GET', '/v1/domains/' . $domain);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok(['status' => stripos((string)($d['status'] ?? 'ACTIVE'), 'ACTIVE') !== false ? 'active' : 'pending',
            'expire_date' => !empty($d['expires']) ? date('Y-m-d H:i:s', strtotime((string)$d['expires'])) : null,
            'ns' => (array)($d['nameServers'] ?? []), 'lock' => (bool)($d['locked'] ?? false)]);
    }
    public function setNS(string $domain, array $ns): array
    {
        $res = $this->call('PATCH', '/v1/domains/' . $domain, ['nameServers' => array_values($ns)]);
        return $res['ok'] ? $this->ok() : $res;
    }
    public function test(): array
    {
        $res = $this->call('GET', '/v1/domains/available?domain=idceasy-test.com');
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

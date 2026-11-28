<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return [
            'id' => 'namecom', 'title' => 'Name.com', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'Name.com v4 REST API，使用账户用户名与 API Token（Basic Auth）。',
            'fields' => [
                ['name' => 'username', 'label' => '用户名', 'type' => 'text', 'required' => true],
                ['name' => 'api_token', 'label' => 'API Token', 'type' => 'password', 'required' => true],
                ['name' => 'sandbox', 'label' => '沙箱', 'type' => 'checkbox'],
            ],
        ];
    }
    protected function call(string $method, string $path, array $body = []): array
    {
        $base = $this->config('sandbox', '0') === '1' ? 'https://api.dev.name.com' : 'https://api.name.com';
        $auth = base64_encode($this->config('username', '') . ':' . $this->config('api_token', ''));
        $opts = ['verify' => true];
        if ($method === 'GET') $res = $this->http('GET', $base . $path, [], ['Authorization: Basic ' . $auth], $opts);
        else {
            $opts['json'] = true;
            $res = $this->http($method, $base . $path, $body, ['Authorization: Basic ' . $auth], $opts);
        }
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $json = $res['json'];
        if ($res['code'] >= 400) {
            $msg = is_array($json) ? ($json['message'] ?? json_encode($json, JSON_UNESCAPED_UNICODE)) : $res['body'];
            return $this->fail('Name.com: ' . mb_substr((string)$msg, 0, 180));
        }
        return $this->ok(['data' => is_array($json) ? $json : []]);
    }
    protected function contactPayload(): array
    {
        $c = $this->contactBasic();
        return [
            'firstName' => $c['first_name'], 'lastName' => $c['last_name'],
            'companyName' => $this->contactValue('company', 'Individual'),
            'address1' => $c['address1'], 'city' => $c['city'], 'state' => $c['state'],
            'zip' => $c['postcode'], 'country' => $c['country'],
            'phone' => preg_replace('/\D/', '', $c['phone']) ?: '13800000000',
            'email' => $c['email'],
        ];
    }
    public function check(string $domain): array
    {
        $res = $this->call('POST', '/v4/domains:checkAvailability', ['domainNames' => [$domain]]);
        if (!$res['ok']) return $res;
        $r = $res['data']['results'][0] ?? [];
        return $this->ok([
            'available' => (bool)($r['available'] ?? false),
            'premium' => (bool)($r['premium'] ?? false),
            'price' => (float)($r['purchasePrice'] ?? 0),
        ]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $c = $this->contactPayload();
        $body = [
            'domainName' => $domain, 'years' => max(1, $years),
            'contacts' => ['registrant' => $c, 'admin' => $c, 'tech' => $c, 'billing' => $c],
        ];
        if ($ns) $body['nameservers'] = array_values($ns);
        $res = $this->call('POST', '/v4/domains', $body);
        if (!$res['ok']) return $res;
        return $this->ok([
            'id' => (string)($res['data']['domainName'] ?? $domain),
            'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years')),
        ]);
    }
    public function renew(string $domain, int $years): array
    {
        $res = $this->call('POST', '/v4/domains/' . $domain . ':renew', ['years' => max(1, $years)]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function getInfo(string $domain): array
    {
        $res = $this->call('GET', '/v4/domains/' . $domain);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok([
            'status' => stripos((string)($d['status'] ?? 'active'), 'active') !== false ? 'active' : 'pending',
            'expire_date' => !empty($d['expireDate']) ? date('Y-m-d H:i:s', strtotime((string)$d['expireDate'])) : null,
            'ns' => (array)($d['nameservers'] ?? []),
            'lock' => !empty($d['transferLockEnabled']),
        ]);
    }
    public function setNS(string $domain, array $ns): array
    {
        $res = $this->call('PUT', '/v4/domains/' . $domain, ['nameservers' => array_values($ns)]);
        return $res['ok'] ? $this->ok() : $res;
    }
    public function getEpp(string $domain): array
    {
        $res = $this->call('GET', '/v4/domains/' . $domain . ':getAuthCode');
        if (!$res['ok']) return $res;
        return $this->ok(['code' => (string)($res['data']['authCode'] ?? '')]);
    }
    public function test(): array
    {
        $res = $this->call('POST', '/v4/domains:checkAvailability', ['domainNames' => ['idceasy-test.com']]);
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

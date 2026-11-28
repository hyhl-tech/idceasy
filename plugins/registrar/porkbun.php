<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return [
            'id' => 'porkbun', 'title' => 'Porkbun', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'Porkbun API v3，价格透明，支持注册、续费、NS 与 EPP。',
            'fields' => [
                ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                ['name' => 'secret_key', 'label' => 'Secret API Key', 'type' => 'password', 'required' => true],
            ],
        ];
    }
    protected function call(string $method, string $path, array $body = []): array
    {
        $body = array_merge(['apikey' => $this->config('api_key', ''), 'secretapikey' => $this->config('secret_key', '')], $body);
        $res = $this->http($method, 'https://api.porkbun.com/api/json/v3' . $path, $body, [], ['json' => true]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $json = $res['json'];
        if (!is_array($json)) return $this->fail('返回格式异常');
        if (($json['status'] ?? '') !== 'SUCCESS') return $this->fail('Porkbun: ' . ($json['message'] ?? '未知错误'));
        return $this->ok(['data' => $json]);
    }
    public function check(string $domain): array
    {
        $res = $this->call('POST', '/domain/checkDomain/' . $domain);
        if (!$res['ok']) return $res;
        $r = $res['data']['response'] ?? [];
        return $this->ok([
            'available' => (bool)($r['avail'] ?? false),
            'premium' => !empty($r['premium']),
            'price' => (float)($r['price'] ?? 0),
        ]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $c = $this->contactBasic();
        $body = [
            'years' => max(1, $years),
            'name' => $c['first_name'] . ' ' . $c['last_name'],
            'email' => $c['email'], 'address' => $c['address1'], 'city' => $c['city'],
            'state' => $c['state'], 'zip' => $c['postcode'], 'country' => $c['country'],
            'phone' => preg_replace('/\D/', '', $c['phone']) ?: '13800000000',
            'agreeToTerms' => 'yes',
        ];
        if ($ns) $body['ns'] = array_values($ns);
        $res = $this->call('POST', '/domain/create/' . $domain, $body);
        if (!$res['ok']) return $res;
        return $this->ok(['id' => $domain, 'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]);
    }
    public function renew(string $domain, int $years): array
    {
        $res = $this->call('POST', '/domain/renew/' . $domain, ['years' => max(1, $years)]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function getInfo(string $domain): array
    {
        $res = $this->call('POST', '/domain/getDomainInfo/' . $domain);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        $ns = [];
        foreach ((array)($d['ns'] ?? []) as $n) $ns[] = is_array($n) ? ($n['host'] ?? '') : $n;
        $status = strtolower((string)($d['status'] ?? 'ACTIVE'));
        return $this->ok([
            'status' => $status === 'active' ? 'active' : 'pending',
            'expire_date' => !empty($d['expireDate']) ? date('Y-m-d H:i:s', strtotime((string)$d['expireDate'])) : null,
            'ns' => array_values(array_filter($ns)),
        ]);
    }
    public function setNS(string $domain, array $ns): array
    {
        $res = $this->call('POST', '/domain/updateNs/' . $domain, ['ns' => array_values($ns)]);
        return $res['ok'] ? $this->ok() : $res;
    }
    public function getEpp(string $domain): array
    {
        $res = $this->call('POST', '/domain/getAuthCode/' . $domain);
        return $res['ok'] ? $this->ok(['code' => (string)($res['data']['authCode'] ?? '')]) : $res;
    }
    public function test(): array
    {
        $res = $this->call('POST', '/domain/checkDomain/idceasy-test.com');
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

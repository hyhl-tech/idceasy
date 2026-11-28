<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return ['id' => 'internetbs', 'title' => 'Internet.bs', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'Internet.bs Reseller API，支持 500+ 后缀与批量操作。',
            'fields' => [
                ['name' => 'api_key', 'label' => 'API Password', 'type' => 'password', 'required' => true],
                ['name' => 'username', 'label' => '账户名', 'type' => 'text'],
                ['name' => 'sandbox', 'label' => '测试环境', 'type' => 'checkbox'],
            ]];
    }
    protected function api(string $path, array $params = [], string $method = 'GET'): array
    {
        $base = $this->config('sandbox', '0') === '1' ? 'https://testapi.internet.bs' : 'https://api.internet.bs';
        $params = array_merge(['Password' => $this->config('api_key', ''),
            'UserName' => $this->config('username', ''), 'ResponseFormat' => 'JSON'], $params);
        $res = $this->http($method, $base . $path, $params);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $j = $res['json'];
        if (!is_array($j)) return $this->fail('返回格式异常');
        if (strtoupper((string)($j['status'] ?? '')) === 'FAILURE') {
            return $this->fail('Internet.bs: ' . ($j['message'] ?? '未知错误'));
        }
        return $this->ok(['data' => $j]);
    }
    public function check(string $domain): array
    {
        $res = $this->api('/domain/check', ['Domain' => $domain]);
        if (!$res['ok']) return $res;
        $raw = strtoupper(json_encode($res['data'], JSON_UNESCAPED_UNICODE));
        $available = str_contains($raw, 'AVAILABLE') && !str_contains($raw, 'NOT AVAILABLE');
        return $this->ok(['available' => $available, 'premium' => false]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $c = $this->contactBasic();
        $params = ['Domain' => $domain, 'Period' => max(1, $years),
            'Registrant_FirstName' => $c['first_name'], 'Registrant_LastName' => $c['last_name'],
            'Registrant_Email' => $c['email'], 'Registrant_Address1' => $c['address1'],
            'Registrant_City' => $c['city'], 'Registrant_State' => $c['state'],
            'Registrant_PostalCode' => $c['postcode'], 'Registrant_Country' => $c['country'],
            'Registrant_PhoneNumber' => preg_replace('/\D/', '', $c['phone']) ?: '13800000000'];
        foreach (array_values($ns) as $i => $host) $params['ns' . $i] = $host;
        $res = $this->api('/domain/register', $params, 'POST');
        return $res['ok'] ? $this->ok(['id' => $domain, 'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function renew(string $domain, int $years): array
    {
        $res = $this->api('/domain/renew', ['Domain' => $domain, 'Period' => max(1, $years)], 'POST');
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function getInfo(string $domain): array
    {
        $res = $this->api('/domain/info', ['Domain' => $domain]);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok(['status' => stripos((string)($d['status'] ?? 'active'), 'active') !== false ? 'active' : 'pending',
            'expire_date' => !empty($d['expirationdate']) ? date('Y-m-d H:i:s', strtotime((string)$d['expirationdate'])) : null,
            'ns' => (array)($d['nameservers'] ?? [])]);
    }
    public function setNS(string $domain, array $ns): array
    {
        $params = ['Domain' => $domain];
        foreach (array_values($ns) as $i => $host) $params['ns' . $i] = $host;
        $res = $this->api('/domain/update', $params, 'POST');
        return $res['ok'] ? $this->ok() : $res;
    }
    public function test(): array
    {
        $res = $this->api('/domain/check', ['Domain' => 'idceasy-test.com']);
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

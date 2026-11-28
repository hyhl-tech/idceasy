<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return [
            'id' => 'dynadot', 'title' => 'Dynadot', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'Dynadot API3，支持查询、注册、续费、NS 与域名信息。',
            'fields' => [
                ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                ['name' => 'currency', 'label' => '结算货币', 'type' => 'text', 'help' => 'USD / CNY'],
            ],
        ];
    }
    protected function api(string $command, array $params = []): array
    {
        $params = array_merge(['key' => $this->config('api_key', ''), 'command' => $command], $params);
        $res = $this->http('GET', 'https://api.dynadot.com/api3.xml', $params);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $xml = $this->parseXml($res['body']);
        if (!$xml) return $this->fail('返回格式异常');
        if (isset($xml->Error) || (string)$xml->Status === 'error') {
            return $this->fail('Dynadot: ' . trim((string)($xml->Error ?? $xml->Response ?? '未知错误')));
        }
        return $this->ok(['xml' => $xml]);
    }
    public function check(string $domain): array
    {
        $res = $this->api('search', ['domain0' => $domain, 'show_price' => '1', 'currency' => $this->config('currency', 'USD')]);
        if (!$res['ok']) return $res;
        $x = $res['xml'];
        $available = strtolower((string)($x->SearchResponse->SearchResult->Available ?? 'no')) === 'yes';
        return $this->ok(['available' => $available, 'premium' => false,
            'price' => (float)($x->SearchResponse->SearchResult->Price ?? 0)]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $c = $this->contactBasic();
        $params = [
            'domain' => $domain, 'duration' => max(1, $years), 'currency' => $this->config('currency', 'USD'),
            'registrant_first_name' => $c['first_name'], 'registrant_last_name' => $c['last_name'],
            'registrant_email' => $c['email'], 'registrant_address1' => $c['address1'],
            'registrant_city' => $c['city'], 'registrant_state' => $c['state'],
            'registrant_zip' => $c['postcode'], 'registrant_country' => $c['country'],
            'registrant_phone' => preg_replace('/\D/', '', $c['phone']) ?: '13800000000',
        ];
        foreach (array_values($ns) as $i => $host) $params['ns' . $i] = $host;
        $res = $this->api('register', $params);
        return $res['ok'] ? $this->ok(['id' => $domain, 'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function renew(string $domain, int $years): array
    {
        $res = $this->api('renew', ['domain' => $domain, 'duration' => max(1, $years), 'currency' => $this->config('currency', 'USD')]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function getInfo(string $domain): array
    {
        $res = $this->api('domain_info', ['domain' => $domain]);
        if (!$res['ok']) return $res;
        $x = $res['xml']->DomainInfo;
        $ns = [];
        for ($i = 0; $i <= 12; $i++) {
            $n = trim((string)($x->{"ns$i"} ?? ''));
            if ($n !== '') $ns[] = $n;
        }
        return $this->ok([
            'status' => stripos((string)($x->Status ?? 'active'), 'active') !== false ? 'active' : 'pending',
            'expire_date' => !empty($x->Expiration) ? date('Y-m-d H:i:s', strtotime((string)$x->Expiration)) : null,
            'ns' => $ns,
        ]);
    }
    public function setNS(string $domain, array $ns): array
    {
        $params = ['domain' => $domain];
        foreach (array_values($ns) as $i => $host) $params['ns' . $i] = $host;
        $res = $this->api('set_ns', $params);
        return $res['ok'] ? $this->ok() : $res;
    }
    public function getEpp(string $domain): array
    {
        $res = $this->api('get_transfer_auth_code', ['domain' => $domain]);
        return $res['ok'] ? $this->ok(['code' => trim((string)($res['xml']->AuthCode ?? ''))]) : $res;
    }
};

<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return [
            'id' => 'namesilo', 'title' => 'NameSilo', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'NameSilo 注册商，API 简单稳定，支持批量查询与隐私保护。',
            'fields' => [
                ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                ['name' => 'sandbox', 'label' => '使用沙箱', 'type' => 'checkbox'],
                ['name' => 'default_ns1', 'label' => '默认 NS1', 'type' => 'text', 'help' => '可留空'],
                ['name' => 'default_ns2', 'label' => '默认 NS2', 'type' => 'text'],
            ],
        ];
    }

    protected function api(string $op, array $params = []): array
    {
        $base = $this->config('sandbox', '0') === '1' ? 'https://sandbox.namesilo.com/api/' : 'https://www.namesilo.com/api/';
        $params = array_merge(['version' => '1', 'type' => 'xml', 'key' => (string)$this->config('api_key', '')], $params);
        $res = $this->http('GET', $base . $op, $params, [], ['timeout' => 25]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $xml = $this->parseXml($res['body']);
        if (!$xml) return $this->fail('注册商返回异常：' . mb_substr($res['body'], 0, 160));
        $code = (string)$xml->reply->code;
        if ($code !== '300') {
            return $this->fail('NameSilo: ' . trim((string)$xml->reply->detail) . ' (code ' . $code . ')');
        }
        return $this->ok(['xml' => $xml]);
    }

    public function check(string $domain): array
    {
        $res = $this->api('checkRegisterAvailability', ['domains' => $domain]);
        if (!$res['ok']) return $res;
        $xml = $res['xml'];
        foreach ($xml->reply->available->domain ?? [] as $node) {
            if (strcasecmp((string)$node['name'], $domain) === 0) {
                $available = strtolower((string)$node['available']) === 'yes';
                return $this->ok([
                    'available' => $available,
                    'premium' => strtolower((string)$node['premium']) === 'yes',
                    'price' => (float)$node['price'],
                ]);
            }
        }
        $text = strtolower($res['xml']->asXML() ?: '');
        return $this->ok(['available' => str_contains($text, 'available="yes"'), 'premium' => false]);
    }

    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $c = $this->contactBasic();
        $ns1 = $ns[0] ?? $this->config('default_ns1', 'ns1.idceasy.site');
        $ns2 = $ns[1] ?? $this->config('default_ns2', 'ns2.idceasy.site');
        $res = $this->api('registerDomain', [
            'domain' => $domain, 'years' => max(1, $years), 'private' => '0', 'auto_renew' => '1',
            'ns1' => $ns1, 'ns2' => $ns2,
            'fn' => $c['first_name'], 'ln' => $c['last_name'], 'ad' => $c['address1'],
            'ci' => $c['city'], 'st' => $c['state'], 'zp' => $c['postcode'], 'ct' => $c['country'],
            'em' => $c['email'], 'ph' => preg_replace('/\D/', '', $c['phone']),
        ]);
        if (!$res['ok']) return $res;
        return $this->ok([
            'id' => (string)($res['xml']->reply->domain_id ?? ''),
            'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years')),
        ]);
    }

    public function renew(string $domain, int $years): array
    {
        $res = $this->api('renewDomain', ['domain' => $domain, 'years' => max(1, $years)]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }

    public function transfer(string $domain, string $authCode, int $years = 1): array
    {
        $res = $this->api('transferDomain', ['domain' => $domain, 'years' => max(1, $years), 'auth' => $authCode]);
        return $res['ok'] ? $this->ok() : $res;
    }

    public function getInfo(string $domain): array
    {
        $res = $this->api('getDomainInfo', ['domain' => $domain]);
        if (!$res['ok']) return $res;
        $d = $res['xml']->reply->domain;
        $ns = [];
        foreach ($d->nameservers->nameserver ?? [] as $n) $ns[] = trim((string)$n);
        $status = strtolower(trim((string)$d->status));
        return $this->ok([
            'status' => str_contains($status, 'active') ? 'active' : 'pending',
            'expire_date' => trim((string)$d->expires) ? date('Y-m-d H:i:s', strtotime((string)$d->expires)) : null,
            'ns' => $ns, 'lock' => strtolower((string)$d->locked) === 'yes',
        ]);
    }

    public function setNS(string $domain, array $ns): array
    {
        $params = ['domain' => $domain];
        foreach (array_values($ns) as $i => $host) $params['ns' . ($i + 1)] = $host;
        $res = $this->api('changeNameServers', $params);
        return $res['ok'] ? $this->ok() : $res;
    }

    public function getEpp(string $domain): array
    {
        $res = $this->api('retrieveAuthCode', ['domain' => $domain]);
        if (!$res['ok']) return $res;
        return $this->ok(['code' => trim((string)$res['xml']->reply->auth_code)]);
    }

    public function setLock(string $domain, bool $lock): array
    {
        $res = $this->api('domainLock', ['domain' => $domain, 'status' => $lock ? 'lock' : 'unlock']);
        return $res['ok'] ? $this->ok() : $res;
    }

    public function delete(string $domain): array
    {
        $res = $this->api('deleteDomain', ['domain' => $domain]);
        return $res['ok'] ? $this->ok() : $res;
    }
};

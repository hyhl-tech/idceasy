<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return ['id' => 'enom', 'title' => 'eNom', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'eNom Reseller API，使用 UID 与 API 密码，支持注册、续费、NS。',
            'fields' => [
                ['name' => 'uid', 'label' => 'eNom UID', 'type' => 'text', 'required' => true],
                ['name' => 'password', 'label' => 'API 密码', 'type' => 'password', 'required' => true],
                ['name' => 'sandbox', 'label' => '测试环境', 'type' => 'checkbox'],
            ]];
    }
    protected function api(string $command, array $params = []): array
    {
        $base = $this->config('sandbox', '0') === '1' ? 'https://resellertest.enom.com/interface.asp' : 'https://reseller.enom.com/interface.asp';
        $params = array_merge(['command' => $command, 'uid' => $this->config('uid', ''),
            'pw' => $this->config('password', ''), 'responsetype' => 'xml'], $params);
        $res = $this->http('GET', $base, $params);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $xml = $this->parseXml($res['body']);
        if (!$xml) return $this->fail('返回格式异常');
        if ((int)($xml->ErrCount ?? 0) > 0) return $this->fail('eNom: ' . trim((string)($xml->errors->Err1 ?? '未知错误')));
        return $this->ok(['xml' => $xml]);
    }
    public function check(string $domain): array
    {
        $pos = strrpos($domain, '.');
        $res = $this->api('Check', ['sld' => substr($domain, 0, $pos), 'tld' => substr($domain, $pos + 1)]);
        if (!$res['ok']) return $res;
        $x = $res['xml'];
        $available = strtolower((string)($x->CheckDomain->DomainName->Available ?? $x->Available ?? 'false')) === 'true';
        return $this->ok(['available' => $available, 'premium' => false]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $c = $this->contactBasic();
        $pos = strrpos($domain, '.');
        $params = [
            'sld' => substr($domain, 0, $pos), 'tld' => substr($domain, $pos + 1), 'numyears' => max(1, $years),
            'RegistrantFirstName' => $c['first_name'], 'RegistrantLastName' => $c['last_name'],
            'RegistrantEmailAddress' => $c['email'], 'RegistrantAddress1' => $c['address1'],
            'RegistrantCity' => $c['city'], 'RegistrantStateProvince' => $c['state'],
            'RegistrantPostalCode' => $c['postcode'], 'RegistrantCountry' => $c['country'],
            'RegistrantPhone' => '+86.13800000000',
            'UseDefaults' => 'default', 'AutoRenew' => 'Yes',
        ];
        foreach (array_values($ns) as $i => $host) $params['ns' . ($i + 1)] = $host;
        $res = $this->api('Purchase', $params);
        return $res['ok'] ? $this->ok(['id' => $domain, 'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function renew(string $domain, int $years): array
    {
        $pos = strrpos($domain, '.');
        $res = $this->api('Extend', ['sld' => substr($domain, 0, $pos), 'tld' => substr($domain, $pos + 1), 'NumYears' => max(1, $years)]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function getInfo(string $domain): array
    {
        $pos = strrpos($domain, '.');
        $res = $this->api('GetDomainInfo', ['sld' => substr($domain, 0, $pos), 'tld' => substr($domain, $pos + 1)]);
        if (!$res['ok']) return $res;
        $x = $res['xml']->GetDomainInfo;
        $ns = [];
        if (isset($x->nameservers)) {
            foreach (preg_split('/[\s,]+/', trim((string)$x->nameservers)) as $n) if ($n) $ns[] = $n;
        }
        return $this->ok(['status' => 'active',
            'expire_date' => !empty($x->{'expiration-date'}) ? date('Y-m-d H:i:s', strtotime((string)$x->{'expiration-date'})) : null,
            'ns' => $ns]);
    }
    public function setNS(string $domain, array $ns): array
    {
        $pos = strrpos($domain, '.');
        $params = ['sld' => substr($domain, 0, $pos), 'tld' => substr($domain, $pos + 1)];
        foreach (array_values($ns) as $i => $host) $params['ns' . ($i + 1)] = $host;
        $res = $this->api('ModifyNS', $params);
        return $res['ok'] ? $this->ok() : $res;
    }
    public function getEpp(string $domain): array
    {
        $pos = strrpos($domain, '.');
        $res = $this->api('GetDomainAuthCode', ['sld' => substr($domain, 0, $pos), 'tld' => substr($domain, $pos + 1)]);
        return $res['ok'] ? $this->ok(['code' => trim((string)($res['xml']->AuthCode ?? ''))]) : $res;
    }
};

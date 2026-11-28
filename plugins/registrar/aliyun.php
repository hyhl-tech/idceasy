<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return ['id' => 'aliyun', 'title' => '阿里云域名', 'version' => '1.0', 'author' => 'idceasy',
            'description' => '阿里云域名 RPC API（2018-01-29），需 AccessKey 与已实名的信息模板 ID。',
            'fields' => [
                ['name' => 'access_key_id', 'label' => 'AccessKey ID', 'type' => 'text', 'required' => true],
                ['name' => 'access_key_secret', 'label' => 'AccessKey Secret', 'type' => 'password', 'required' => true],
                ['name' => 'profile_id', 'label' => '信息模板 ID', 'type' => 'text', 'help' => '实名认证模板，注册时必填'],
                ['name' => 'endpoint', 'label' => 'API 地址', 'type' => 'text', 'help' => '默认 https://domain.aliyuncs.com'],
            ]];
    }
    protected function pe(string $v): string
    {
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], rawurlencode($v));
    }
    protected function api(string $action, array $params = []): array
    {
        $params = array_merge([
            'AccessKeyId' => $this->config('access_key_id', ''), 'Action' => $action, 'Format' => 'JSON',
            'SignatureMethod' => 'HMAC-SHA1', 'SignatureNonce' => bin2hex(random_bytes(8)),
            'SignatureVersion' => '1.0', 'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'Version' => '2018-01-29',
        ], $params);
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) $pairs[] = $this->pe((string)$k) . '=' . $this->pe((string)$v);
        $canonical = implode('&', $pairs);
        $stringToSign = 'GET&%2F&' . $this->pe($canonical);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->config('access_key_secret', '') . '&', true));
        $url = rtrim((string)$this->config('endpoint', 'https://domain.aliyuncs.com'), '/') . '/?Signature=' . $this->pe($signature) . '&' . $canonical;
        $res = $this->http('GET', $url);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $j = $res['json'];
        if (!is_array($j)) return $this->fail('返回格式异常');
        if (!empty($j['Code'])) return $this->fail('阿里云: ' . ($j['Message'] ?? $j['Code']));
        return $this->ok(['data' => $j]);
    }
    public function check(string $domain): array
    {
        $res = $this->api('CheckDomain', ['DomainName' => $domain]);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok(['available' => (int)($d['Avail'] ?? 0) === 1,
            'premium' => !empty($d['Premium']), 'price' => (float)($d['Price'] ?? 0)]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $profile = (string)$this->config('profile_id', '');
        if ($profile === '') return $this->fail('请先配置阿里云信息模板 ID');
        $params = ['DomainName' => $domain, 'RegistrantProfileId' => $profile, 'SubscriptionDuration' => max(1, $years)];
        if (!empty($ns[0])) $params['Dns1'] = $ns[0];
        if (!empty($ns[1])) $params['Dns2'] = $ns[1];
        $res = $this->api('SaveSingleTaskForCreatingOrderActivate', $params);
        return $res['ok'] ? $this->ok(['id' => (string)($res['data']['TaskNo'] ?? $domain),
            'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function renew(string $domain, int $years): array
    {
        $res = $this->api('SaveSingleTaskForRenewal', ['DomainName' => $domain, 'RenewPeriod' => max(1, $years)]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function getInfo(string $domain): array
    {
        $res = $this->api('QueryDomainByDomainName', ['DomainName' => $domain]);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok(['status' => stripos((string)($d['DomainStatus'] ?? ''), 'ok') !== false ? 'active' : 'pending',
            'expire_date' => !empty($d['ExpirationDate']) ? date('Y-m-d H:i:s', strtotime((string)$d['ExpirationDate'])) : null,
            'ns' => (array)($d['DnsList']['Dns'] ?? [])]);
    }
    public function setNS(string $domain, array $ns): array
    {
        $params = ['DomainName' => $domain];
        foreach (array_values($ns) as $i => $n) $params['Dns' . ($i + 1)] = $n;
        $res = $this->api('SaveSingleTaskForModifyingDnsHost', $params);
        return $res['ok'] ? $this->ok() : $res;
    }
    public function test(): array
    {
        $res = $this->api('CheckDomain', ['DomainName' => 'idceasy-test.com']);
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

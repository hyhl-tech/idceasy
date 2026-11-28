<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return ['id' => 'tencentcloud', 'title' => '腾讯云域名', 'version' => '1.0', 'author' => 'idceasy',
            'description' => '腾讯云 DNSPod / Domain API（TC3-HMAC-SHA256），需 SecretId、SecretKey 与实名模板。',
            'fields' => [
                ['name' => 'secret_id', 'label' => 'SecretId', 'type' => 'text', 'required' => true],
                ['name' => 'secret_key', 'label' => 'SecretKey', 'type' => 'password', 'required' => true],
                ['name' => 'template_id', 'label' => '实名模板 ID', 'type' => 'text'],
                ['name' => 'region', 'label' => '地域', 'type' => 'text', 'help' => '默认 ap-guangzhou'],
            ]];
    }
    protected function api(string $action, array $params = []): array
    {
        $host = 'domain.tencentcloudapi.com';
        $service = 'domain';
        $version = '2018-08-08';
        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);
        $payload = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $canonical = "POST\n/\n\ncontent-type:application/json; charset=utf-8\nhost:" . $host . "\n\ncontent-type;host\n" . hash('sha256', $payload);
        $scope = $date . '/' . $service . '/tc3_request';
        $stringToSign = "TC3-HMAC-SHA256\n" . $timestamp . "\n" . $scope . "\n" . hash('sha256', $canonical);
        $kDate = hash_hmac('sha256', $date, 'TC3' . $this->config('secret_key', ''), true);
        $kService = hash_hmac('sha256', $service, $kDate, true);
        $kSigning = hash_hmac('sha256', 'tc3_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $auth = 'TC3-HMAC-SHA256 Credential=' . $this->config('secret_id', '') . '/' . $scope
            . ', SignedHeaders=content-type;host, Signature=' . $signature;
        $res = $this->http('POST', 'https://' . $host, [], [
            'Authorization: ' . $auth,
            'Content-Type: application/json; charset=utf-8',
            'X-TC-Action: ' . $action,
            'X-TC-Version: ' . $version,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Region: ' . $this->config('region', 'ap-guangzhou'),
        ], ['raw' => $payload]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $j = $res['json'];
        if (!is_array($j)) return $this->fail('返回格式异常');
        if (!empty($j['Response']['Error'])) {
            return $this->fail('腾讯云: ' . ($j['Response']['Error']['Message'] ?? '请求失败'));
        }
        return $this->ok(['data' => $j['Response'] ?? []]);
    }
    public function check(string $domain): array
    {
        $res = $this->api('CheckDomain', ['DomainName' => $domain]);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok(['available' => (bool)($d['Availability'] ?? false),
            'premium' => false, 'price' => (float)($d['Price'] ?? 0)]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $body = ['Domains' => [['Domain' => $domain, 'Period' => max(1, $years)]]];
        if ($this->config('template_id', '') !== '') $body['TemplateId'] = $this->config('template_id');
        $res = $this->api('CreateDomainBatch', $body);
        return $res['ok'] ? $this->ok(['id' => $domain, 'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function renew(string $domain, int $years): array
    {
        $res = $this->api('RenewDomainBatch', ['Domains' => [$domain], 'Period' => max(1, $years)]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function getInfo(string $domain): array
    {
        $res = $this->api('DescribeDomainNameList', ['Limit' => 100, 'Offset' => 0]);
        if (!$res['ok']) return $res;
        foreach ((array)($res['data']['DomainSet'] ?? []) as $d) {
            if (strcasecmp((string)($d['DomainName'] ?? ''), $domain) === 0) {
                return $this->ok(['status' => 'active',
                    'expire_date' => !empty($d['ExpirationDate']) ? date('Y-m-d H:i:s', strtotime((string)$d['ExpirationDate'])) : null]);
            }
        }
        return $this->fail('未在腾讯云账户中找到该域名');
    }
    public function setNS(string $domain, array $ns): array
    {
        $res = $this->api('ModifyDomainDNSBatch', ['Domains' => [$domain], 'Dns' => array_values($ns)]);
        return $res['ok'] ? $this->ok() : $res;
    }
    public function test(): array
    {
        $res = $this->api('CheckDomain', ['DomainName' => 'idceasy-test.com']);
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

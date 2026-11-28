<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return [
            'id' => 'namecheap', 'title' => 'Namecheap', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'Namecheap XML API，需要在账户中开启 API 并添加服务器 IP 白名单。',
            'fields' => [
                ['name' => 'api_user', 'label' => 'API User', 'type' => 'text', 'required' => true],
                ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                ['name' => 'username', 'label' => '账户用户名', 'type' => 'text', 'required' => true],
                ['name' => 'client_ip', 'label' => '白名单 IP', 'type' => 'text', 'required' => true],
                ['name' => 'sandbox', 'label' => '沙箱', 'type' => 'checkbox'],
                ['name' => 'default_ns1', 'label' => '默认 NS1', 'type' => 'text'],
                ['name' => 'default_ns2', 'label' => '默认 NS2', 'type' => 'text'],
            ],
        ];
    }

    protected function api(string $command, array $params = []): array
    {
        $base = $this->config('sandbox', '0') === '1' ? 'https://api.sandbox.namecheap.com/xml.response' : 'https://api.namecheap.com/xml.response';
        $params = array_merge([
            'ApiUser' => (string)$this->config('api_user', ''),
            'ApiKey' => (string)$this->config('api_key', ''),
            'UserName' => (string)$this->config('username', ''),
            'ClientIp' => (string)$this->config('client_ip', ''),
            'Command' => $command,
        ], $params);
        $res = $this->http('GET', $base, $params);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $xml = $this->parseXml($res['body']);
        if (!$xml) return $this->fail('返回格式异常');
        if ((string)$xml['Status'] !== 'OK') {
            $msg = '';
            foreach ($xml->Errors->Error ?? [] as $e) $msg .= (string)$e . ' ';
            return $this->fail('Namecheap: ' . trim($msg ?: '未知错误'));
        }
        return $this->ok(['xml' => $xml]);
    }

    public function check(string $domain): array
    {
        $res = $this->api('namecheap.domains.check', ['DomainList' => $domain]);
        if (!$res['ok']) return $res;
        $r = $res['xml']->CommandResponse->DomainCheckResult ?? null;
        if (!$r) return $this->fail('无法解析查询结果');
        return $this->ok([
            'available' => strtolower((string)$r['Available']) === 'true',
            'premium' => strtolower((string)$r['IsPremiumName'] ?? 'false') === 'true',
            'price' => (float)($r['PremiumRegistrationPrice'] ?? 0),
        ]);
    }

    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $c = $this->contactBasic();
        $prefix = ['Registrant', 'Tech', 'Admin', 'AuxBilling'];
        $params = ['DomainName' => $domain, 'Years' => max(1, $years)];
        foreach ($prefix as $p) {
            $params[$p . 'FirstName'] = $c['first_name'];
            $params[$p . 'LastName'] = $c['last_name'];
            $params[$p . 'Company'] = $this->contactValue('company', 'Individual');
            $params[$p . 'Address1'] = $c['address1'];
            $params[$p . 'City'] = $c['city'];
            $params[$p . 'StateProvince'] = $c['state'];
            $params[$p . 'PostalCode'] = $c['postcode'];
            $params[$p . 'Country'] = $c['country'];
            $params[$p . 'Phone'] = '+86.13800000000';
            $params[$p . 'EmailAddress'] = $c['email'];
        }
        $nsList = $ns ?: array_filter([$this->config('default_ns1', ''), $this->config('default_ns2', '')]);
        if ($nsList) $params['Nameservers'] = implode(',', $nsList);
        $params['AddFreeWhoisguard'] = 'yes';
        $params['WGEnabled'] = 'no';
        $res = $this->api('namecheap.domains.create', $params);
        if (!$res['ok']) return $res;
        $r = $res['xml']->CommandResponse->DomainCreateResult ?? null;
        return $this->ok([
            'id' => (string)($r['DomainID'] ?? ''),
            'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years')),
        ]);
    }

    public function renew(string $domain, int $years): array
    {
        $res = $this->api('namecheap.domains.renew', ['DomainName' => $domain, 'Years' => max(1, $years)]);
        if (!$res['ok']) return $res;
        $r = $res['xml']->CommandResponse->DomainRenewResult ?? null;
        return $this->ok(['expire_date' => isset($r['ExpiredDate']) ? date('Y-m-d H:i:s', strtotime((string)$r['ExpiredDate'])) : null]);
    }

    public function transfer(string $domain, string $authCode, int $years = 1): array
    {
        $res = $this->api('namecheap.domains.transfer.create', [
            'DomainName' => $domain, 'Years' => max(1, $years), 'EPPCode' => $authCode,
            'AddFreeWhoisguard' => 'yes', 'WGEnabled' => 'no',
        ]);
        return $res['ok'] ? $this->ok() : $res;
    }

    public function getInfo(string $domain): array
    {
        $res = $this->api('namecheap.domains.getInfo', ['DomainName' => $domain]);
        if (!$res['ok']) return $res;
        $r = $res['xml']->CommandResponse->DomainGetInfoResult ?? null;
        if (!$r) return $this->fail('无法解析域名信息');
        $ns = [];
        foreach ($r->DnsDetails->Nameserver ?? [] as $n) $ns[] = trim((string)$n);
        return $this->ok([
            'status' => strtolower((string)$r['Status']) === 'active' ? 'active' : 'pending',
            'expire_date' => trim((string)$r->DomainDetails->ExpiredDate) ? date('Y-m-d H:i:s', strtotime((string)$r->DomainDetails->ExpiredDate)) : null,
            'ns' => $ns, 'lock' => strtolower((string)$r['IsLocked'] ?? 'false') === 'true',
        ]);
    }

    public function setNS(string $domain, array $ns): array
    {
        $sld = explode('.', $domain)[0];
        $tld = substr($domain, strlen($sld) + 1);
        $res = $this->api('namecheap.domains.dns.setCustom', [
            'SLD' => $sld, 'TLD' => $tld, 'Nameservers' => implode(',', $ns),
        ]);
        return $res['ok'] ? $this->ok() : $res;
    }

    public function setLock(string $domain, bool $lock): array
    {
        $res = $this->api('namecheap.domains.setRegLock', ['DomainName' => $domain, 'LockAction' => $lock ? 'LOCK' : 'UNLOCK']);
        return $res['ok'] ? $this->ok() : $res;
    }

    public function test(): array
    {
        $res = $this->api('namecheap.domains.check', ['DomainList' => 'idceasy-test.com']);
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return ['id' => 'domainnameapi', 'title' => 'DomainNameAPI', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'DomainNameAPI SOAP 接口，适用于土耳其及欧洲后缀，需启用 API 权限。',
            'fields' => [
                ['name' => 'username', 'label' => 'API 用户名', 'type' => 'text', 'required' => true],
                ['name' => 'password', 'label' => 'API 密码', 'type' => 'password', 'required' => true],
                ['name' => 'endpoint', 'label' => '服务地址', 'type' => 'text', 'help' => '默认 https://api.domainnameapi.com/DomainApi.svc'],
            ]];
    }
    protected function soap(string $method, array $fields): array
    {
        $endpoint = (string)$this->config('endpoint', 'https://api.domainnameapi.com/DomainApi.svc');
        $inner = '<UserName>' . htmlspecialchars((string)$this->config('username', '')) . '</UserName>'
            . '<Password>' . htmlspecialchars((string)$this->config('password', '')) . '</Password>';
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                $inner .= '<' . $k . '>';
                foreach ($v as $item) $inner .= '<string>' . htmlspecialchars((string)$item) . '</string>';
                $inner .= '</' . $k . '>';
            } else {
                $inner .= '<' . $k . '>' . htmlspecialchars((string)$v) . '</' . $k . '>';
            }
        }
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:t="http://tempuri.org/">'
            . '<soap:Body><t:' . $method . '><request>' . $inner . '</request></t:' . $method . '></soap:Body></soap:Envelope>';
        $res = $this->http('POST', $endpoint, [], [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "http://tempuri.org/IDomainApi/' . $method . '"',
        ], ['raw' => $xml]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $dom = new DOMDocument();
        if (!@$dom->loadXML($res['body'])) return $this->fail('返回格式异常');
        $text = $dom->textContent;
        $get = function (string $tag) use ($dom) {
            $nodes = $dom->getElementsByTagName($tag);
            return $nodes->length ? trim((string)$nodes->item(0)->textContent) : '';
        };
        $result = $get('OperationResult') ?: $get('Result') ?: $get('Status');
        if (stripos($result, 'error') !== false || stripos($result, 'fail') !== false) {
            return $this->fail('DomainNameAPI: ' . ($get('Message') ?: $result));
        }
        return $this->ok(['data' => $text, 'get' => $get]);
    }
    public function check(string $domain): array
    {
        $res = $this->soap('CheckDomainAvailability', ['DomainName' => $domain]);
        if (!$res['ok']) return $res;
        $get = $res['get'];
        $available = strtolower($get('Available')) === 'true' || stripos($get('Status'), 'available') !== false;
        return $this->ok(['available' => $available, 'premium' => false]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $c = $this->contactBasic();
        $res = $this->soap('RegisterDomain', [
            'DomainName' => $domain, 'Period' => max(1, $years),
            'NameServer1' => $ns[0] ?? '', 'NameServer2' => $ns[1] ?? '',
            'RegistrantFirstName' => $c['first_name'], 'RegistrantLastName' => $c['last_name'],
            'RegistrantEmail' => $c['email'], 'RegistrantAddress' => $c['address1'],
            'RegistrantCity' => $c['city'], 'RegistrantCountry' => $c['country'],
            'RegistrantPhone' => preg_replace('/\D/', '', $c['phone']) ?: '13800000000',
        ]);
        return $res['ok'] ? $this->ok(['id' => $domain, 'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function renew(string $domain, int $years): array
    {
        $res = $this->soap('RenewDomain', ['DomainName' => $domain, 'Period' => max(1, $years)]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function getInfo(string $domain): array
    {
        $res = $this->soap('GetDomainInfo', ['DomainName' => $domain]);
        if (!$res['ok']) return $res;
        $get = $res['get'];
        return $this->ok(['status' => stripos($get('Status'), 'active') !== false ? 'active' : 'pending',
            'expire_date' => $get('ExpirationDate') ? date('Y-m-d H:i:s', strtotime($get('ExpirationDate'))) : null]);
    }
    public function setNS(string $domain, array $ns): array
    {
        $fields = ['DomainName' => $domain];
        foreach (array_values($ns) as $i => $n) $fields['NameServer' . ($i + 1)] = $n;
        $res = $this->soap('ModifyNameServer', $fields);
        return $res['ok'] ? $this->ok() : $res;
    }
    public function getEpp(string $domain): array
    {
        $res = $this->soap('GetDomainAuthCode', ['DomainName' => $domain]);
        return $res['ok'] ? $this->ok(['code' => $res['get']('AuthCode')]) : $res;
    }
};

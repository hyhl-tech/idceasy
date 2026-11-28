<?php
/**
 * OpenSRS (Tucows) XML API 驱动
 */
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return ['id' => 'opensrs', 'title' => 'OpenSRS (Tucows)', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'OpenSRS XML API（端口 55443），需在控制面板启用 API 并配置 IP 白名单。',
            'fields' => [
                ['name' => 'username', 'label' => 'Reseller Username', 'type' => 'text', 'required' => true],
                ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                ['name' => 'host', 'label' => 'API 主机', 'type' => 'text', 'help' => '默认 rr-n1-tor.opensrs.net'],
                ['name' => 'default_ns1', 'label' => '默认 NS1', 'type' => 'text'],
                ['name' => 'default_ns2', 'label' => '默认 NS2', 'type' => 'text'],
            ]];
    }

    protected function call(string $object, string $action, array $attributes): array
    {
        $host = $this->config('host', 'rr-n1-tor.opensrs.net');
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . "\n"
            . '<!DOCTYPE OPS_envelope SYSTEM "ops.dtd"><OPS_envelope><header><version>0.9</version></header><body><data_block><dt_assoc>'
            . '<item key="protocol">XCP</item><item key="object">' . htmlspecialchars($object) . '</item>'
            . '<item key="action">' . htmlspecialchars($action) . '</item>'
            . '<item key="attributes">' . $this->encode($attributes) . '</item>'
            . '</dt_assoc></data_block></body></OPS_envelope>';
        $ch = curl_init('https://' . $host . ':55443');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $xml, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml', 'X-Username: ' . $this->config('username', ''),
                'X-Signature: ' . md5($xml . $this->config('api_key', '')), 'Expect:'],
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) return $this->fail('网络错误：' . $err);
        $dom = new DOMDocument();
        if (!@$dom->loadXML($body)) return $this->fail('返回格式异常');
        $code = '';
        foreach ($dom->getElementsByTagName('item') as $it) {
            if ($it->getAttribute('key') === 'response_code') { $code = trim((string)$it->textContent); break; }
        }
        $attrs = $this->parseAttrs($dom);
        if ($code !== '200' && $code !== '') {
            return $this->fail('OpenSRS [' . $code . ']: ' . ($attrs['response_text'] ?? '请求失败'));
        }
        return $this->ok(['data' => $attrs]);
    }

    protected function encode(array $data): string
    {
        $isList = array_is_list($data);
        $tag = $isList ? 'dt_array' : 'dt_assoc';
        $xml = '<' . $tag . '>';
        foreach ($data as $k => $v) {
            if ($isList) {
                $xml .= '<item key="' . $k . '">' . (is_array($v) ? $this->encode($v) : htmlspecialchars((string)$v)) . '</item>';
            } else {
                $xml .= '<item key="' . htmlspecialchars((string)$k) . '">'
                    . (is_array($v) ? $this->encode($v) : htmlspecialchars((string)$v)) . '</item>';
            }
        }
        return $xml . '</' . $tag . '>';
    }

    protected function parseAttrs(DOMDocument $dom): array
    {
        foreach ($dom->getElementsByTagName('item') as $item) {
            if ($item->getAttribute('key') !== 'attributes') continue;
            foreach ($item->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && $child->nodeName === 'dt_assoc') {
                    return $this->parseAssoc($child);
                }
            }
        }
        return [];
    }

    protected function parseAssoc(DOMElement $el): array
    {
        $out = [];
        foreach ($el->childNodes as $item) {
            if ($item->nodeType !== XML_ELEMENT_NODE || $item->nodeName !== 'item') continue;
            $key = $item->getAttribute('key');
            $out[$key] = $this->parseValue($item);
        }
        return $out;
    }

    protected function parseValue(DOMElement $item)
    {
        foreach ($item->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            if ($child->nodeName === 'dt_assoc') return $this->parseAssoc($child);
            if ($child->nodeName === 'dt_array') {
                $arr = [];
                foreach ($child->childNodes as $sub) {
                    if ($sub->nodeType === XML_ELEMENT_NODE && $sub->nodeName === 'item') $arr[] = $this->parseValue($sub);
                }
                return $arr;
            }
        }
        return trim($item->textContent);
    }

    protected function contactSet(): array
    {
        $c = $this->contactBasic();
        $person = [
            'firstname' => $c['first_name'], 'lastname' => $c['last_name'],
            'email' => $c['email'], 'phone' => preg_replace('/\D/', '', $c['phone']) ?: '13800000000',
            'address1' => $c['address1'], 'city' => $c['city'], 'state' => $c['state'],
            'postalcode' => $c['postcode'], 'country' => $c['country'],
        ];
        return ['owner' => $person, 'admin' => $person, 'tech' => $person, 'billing' => $person];
    }

    public function check(string $domain): array
    {
        $res = $this->call('DOMAIN', 'LOOKUP', ['domain' => $domain]);
        if (!$res['ok']) return $res;
        $status = strtolower((string)($res['data']['status'] ?? ''));
        return $this->ok(['available' => str_contains($status, 'available'), 'premium' => false]);
    }

    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $nsList = $ns ?: array_filter([$this->config('default_ns1'), $this->config('default_ns2')]);
        $res = $this->call('DOMAIN', 'SW_REGISTER', [
            'domain' => $domain, 'reg_type' => 'new', 'period' => max(1, $years),
            'contact_set' => $this->contactSet(),
            'nameserver_list' => array_map(fn($n) => ['nameserver' => $n], array_values($nsList)),
            'auto_renew' => '1',
        ]);
        return $res['ok'] ? $this->ok(['id' => $domain, 'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }

    public function renew(string $domain, int $years): array
    {
        $res = $this->call('DOMAIN', 'RENEW', ['domain' => $domain, 'period' => max(1, $years),
            'currentdate' => date('Y-m-d')]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }

    public function getInfo(string $domain): array
    {
        $res = $this->call('DOMAIN', 'GET_DOMAIN', ['domain' => $domain, 'type' => 'all_info']);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok(['status' => 'active',
            'expire_date' => !empty($d['expire_date']) ? date('Y-m-d H:i:s', strtotime((string)$d['expire_date'])) : null,
            'ns' => (array)($d['nameserver_list'] ?? [])]);
    }

    public function setNS(string $domain, array $ns): array
    {
        $res = $this->call('DOMAIN', 'MODIFY_NS', [
            'domain' => $domain, 'nameserver_list' => array_map(fn($n) => ['nameserver' => $n], array_values($ns)),
        ]);
        return $res['ok'] ? $this->ok() : $res;
    }

    public function getEpp(string $domain): array
    {
        $res = $this->call('DOMAIN', 'GET_DOMAIN_AUTH_CODE', ['domain' => $domain]);
        return $res['ok'] ? $this->ok(['code' => (string)($res['data']['auth_code'] ?? '')]) : $res;
    }

    public function test(): array
    {
        $res = $this->call('DOMAIN', 'LOOKUP', ['domain' => 'idceasy-test.com']);
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};

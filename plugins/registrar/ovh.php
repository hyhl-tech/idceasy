<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return ['id' => 'ovh', 'title' => 'OVHcloud', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'OVH API v1，需创建 App Key / App Secret / Consumer Key，并授权 domain 与 order 范围。',
            'fields' => [
                ['name' => 'app_key', 'label' => 'Application Key', 'type' => 'text', 'required' => true],
                ['name' => 'app_secret', 'label' => 'Application Secret', 'type' => 'password', 'required' => true],
                ['name' => 'consumer_key', 'label' => 'Consumer Key', 'type' => 'password', 'required' => true],
                ['name' => 'endpoint', 'label' => 'API 端点', 'type' => 'text', 'help' => '默认 https://eu.api.ovh.com/1.0'],
                ['name' => 'subsidiary', 'label' => '国家代码', 'type' => 'text', 'help' => '如 FR / IE / CN，默认 FR'],
            ]];
    }
    protected function endpoint(): string
    {
        return rtrim((string)$this->config('endpoint', 'https://eu.api.ovh.com/1.0'), '/');
    }
    protected function call(string $method, string $path, array $body = []): array
    {
        $timeRes = $this->http('GET', $this->endpoint() . '/auth/time');
        $time = (int)trim($timeRes['body']);
        if ($time <= 0) return $this->fail('无法获取 OVH 服务器时间');
        $url = $this->endpoint() . $path;
        $raw = $body ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $sig = '$1$' . sha1($this->config('app_secret', '') . '+' . $this->config('consumer_key', '') . '+'
            . $method . '+' . $url . '+' . $raw . '+' . $time);
        $headers = [
            'X-Ovh-Application: ' . $this->config('app_key', ''),
            'X-Ovh-Consumer: ' . $this->config('consumer_key', ''),
            'X-Ovh-Timestamp: ' . $time,
            'X-Ovh-Signature: ' . $sig,
        ];
        if ($method === 'GET') $res = $this->http('GET', $url, [], $headers);
        else $res = $this->http($method, $url, $body, $headers, ['json' => true, 'raw' => $raw]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        if ($res['code'] >= 400) {
            $j = $res['json'];
            return $this->fail('OVH: ' . (is_array($j) ? ($j['message'] ?? '请求失败') : mb_substr($res['body'], 0, 180)));
        }
        return $this->ok(['data' => $res['json']]);
    }
    public function check(string $domain): array
    {
        $res = $this->call('GET', '/domain/available?domain=' . urlencode($domain));
        if (!$res['ok']) return $res;
        $rows = (array)$res['data'];
        $row = $rows[0] ?? [];
        return $this->ok(['available' => !empty($row['available']), 'premium' => false]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $cart = $this->call('POST', '/order/cart', ['ovhSubsidiary' => $this->config('subsidiary', 'FR')]);
        if (!$cart['ok']) return $cart;
        $cartId = $cart['data']['cartId'] ?? '';
        if (!$cartId) return $this->fail('创建 OVH 购物车失败');
        $item = $this->call('POST', '/order/cart/' . $cartId . '/domain', [
            'domain' => $domain, 'duration' => max(1, $years) . 'y', 'planCode' => explode('.', $domain)[0] . '-' . max(1, $years) . 'y',
        ]);
        if (!$item['ok']) return $item;
        $checkout = $this->call('POST', '/order/cart/' . $cartId . '/checkout', []);
        if (!$checkout['ok']) return $checkout;
        return $this->ok(['id' => $domain, 'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]);
    }
    public function renew(string $domain, int $years): array
    {
        $cart = $this->call('POST', '/order/cart', ['ovhSubsidiary' => $this->config('subsidiary', 'FR')]);
        if (!$cart['ok']) return $cart;
        $cartId = $cart['data']['cartId'] ?? '';
        $item = $this->call('POST', '/order/cart/' . $cartId . '/domain', [
            'domain' => $domain, 'duration' => max(1, $years) . 'y', 'planCode' => explode('.', $domain)[0] . '-' . max(1, $years) . 'y',
        ]);
        if (!$item['ok']) return $item;
        $checkout = $this->call('POST', '/order/cart/' . $cartId . '/checkout', []);
        return $checkout['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $checkout;
    }
    public function getInfo(string $domain): array
    {
        $res = $this->call('GET', '/domain/' . $domain);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok(['status' => stripos((string)($d['status'] ?? 'ok'), 'ok') !== false ? 'active' : 'pending',
            'expire_date' => !empty($d['expiration']) ? date('Y-m-d H:i:s', strtotime((string)$d['expiration'])) : null,
            'ns' => (array)($d['nameServers'] ?? [])]);
    }
    public function setNS(string $domain, array $ns): array
    {
        $res = $this->call('PUT', '/domain/' . $domain . '/nameServers', ['nameServers' => array_values($ns)]);
        return $res['ok'] ? $this->ok() : $res;
    }
};

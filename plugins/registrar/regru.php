<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return ['id' => 'regru', 'title' => 'REG.RU', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'REG.RU API，支持俄罗斯及国际后缀，使用账户 API 密码。',
            'fields' => [
                ['name' => 'username', 'label' => '用户名', 'type' => 'text', 'required' => true],
                ['name' => 'password', 'label' => 'API 密码', 'type' => 'password', 'required' => true],
            ]];
    }
    protected function api(string $path, array $params = []): array
    {
        $params = array_merge(['username' => $this->config('username', ''), 'password' => $this->config('password', ''),
            'input_format' => 'json', 'output_format' => 'json'], $params);
        $res = $this->http('POST', 'https://api.reg.ru/api/regru2' . $path, $params);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $j = $res['json'];
        if (!is_array($j)) return $this->fail('返回格式异常');
        if (($j['result'] ?? '') !== 'success') return $this->fail('REG.RU: ' . ($j['error_text'] ?? '请求失败'));
        return $this->ok(['data' => $j['answer'] ?? []]);
    }
    public function check(string $domain): array
    {
        $res = $this->api('/domain/check', ['domains' => json_encode([['dname' => $domain]])]);
        if (!$res['ok']) return $res;
        $row = $res['data']['domains'][0] ?? [];
        return $this->ok(['available' => ($row['result'] ?? '') === 'available', 'premium' => false]);
    }
    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $params = ['domain_name' => $domain, 'period' => max(1, $years)];
        foreach (array_values($ns) as $i => $n) $params['ns' . $i] = $n;
        $res = $this->api('/domain/create', $params);
        return $res['ok'] ? $this->ok(['id' => $domain, 'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function renew(string $domain, int $years): array
    {
        $res = $this->api('/domain/renew', ['domain_name' => $domain, 'period' => max(1, $years)]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }
    public function getInfo(string $domain): array
    {
        $res = $this->api('/domain/getinfo', ['domain_name' => $domain]);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        return $this->ok(['status' => 'active',
            'expire_date' => !empty($d['expiration_date']) ? date('Y-m-d H:i:s', strtotime((string)$d['expiration_date'])) : null,
            'ns' => (array)($d['nss'] ?? [])]);
    }
    public function setNS(string $domain, array $ns): array
    {
        $params = ['domain_name' => $domain];
        foreach (array_values($ns) as $i => $n) $params['ns' . $i] = $n;
        $res = $this->api('/domain/update_ns', $params);
        return $res['ok'] ? $this->ok() : $res;
    }
};

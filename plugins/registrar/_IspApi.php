<?php
/**
 * ISPAPI / 1API 通用驱动（Hexonet、RRPproxy）
 */
require_once __DIR__ . '/../Registrar.php';
use plugins\Registrar;

abstract class IspApiRegistrar extends Registrar
{
    protected function endpoint(): string
    {
        return rtrim((string)$this->config('base_url', 'https://api.ispapi.net/api/call.cgi'), '/');
    }

    protected function call(string $command, array $params = []): array
    {
        $params = array_merge([
            's_login' => (string)$this->config('login', ''),
            's_pw' => (string)$this->config('password', ''),
            'command' => $command,
            's_ver' => '1.0',
        ], $params);
        $res = $this->http('POST', $this->endpoint(), $params);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $xml = $this->parseXml($res['body']);
        if (!$xml) return $this->fail('返回格式异常');
        $props = [];
        foreach ($xml->property ?? [] as $p) {
            $name = trim((string)$p->name);
            $value = trim((string)$p->value);
            if ($name === '') continue;
            if (isset($props[$name])) {
                if (!is_array($props[$name])) $props[$name] = [$props[$name]];
                $props[$name][] = $value;
            } else {
                $props[$name] = $value;
            }
        }
        $status = strtoupper((string)($props['status'] ?? ''));
        if (in_array($status, ['ERROR', 'FAILED'], true) || isset($xml->error)) {
            $msg = (string)($props['message'] ?? $xml->error->msg ?? '注册商返回错误');
            return $this->fail('ISPAPI: ' . $msg);
        }
        return $this->ok(['props' => $props, 'xml' => $xml]);
    }

    public function check(string $domain): array
    {
        $pos = strrpos($domain, '.');
        $res = $this->call('CheckDomain', ['sld' => substr($domain, 0, $pos), 'tld' => substr($domain, $pos + 1)]);
        if (!$res['ok']) return $res;
        $p = $res['props'];
        $raw = strtolower((string)($p['status'] ?? $p['avail'] ?? ''));
        $available = str_contains($raw, 'available') || str_contains($raw, 'free') || ($p['avail'] ?? '') === '1';
        return $this->ok(['available' => $available, 'premium' => false,
            'price' => isset($p['price']) ? (float)$p['price'] : 0]);
    }

    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $handle = (string)$this->config('contact_handle', '');
        if ($handle === '') return $this->fail('请先在插件配置中填写联系人 Handle（ownercontact0）');
        $params = ['domain' => $domain, 'period' => max(1, $years),
            'ownercontact0' => $handle, 'admincontact0' => $handle,
            'techcontact0' => $handle, 'billingcontact0' => $handle];
        foreach (array_values($ns) as $i => $host) $params['nameserver' . $i] = $host;
        $res = $this->call('RegisterDomain', $params);
        return $res['ok'] ? $this->ok(['id' => $domain, 'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }

    public function renew(string $domain, int $years): array
    {
        $res = $this->call('RenewDomain', ['domain' => $domain, 'period' => max(1, $years)]);
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }

    public function getInfo(string $domain): array
    {
        $res = $this->call('StatusDomain', ['domain' => $domain]);
        if (!$res['ok']) return $res;
        $p = $res['props'];
        $ns = [];
        for ($i = 0; $i <= 12; $i++) {
            if (!empty($p['nameserver' . $i])) $ns[] = $p['nameserver' . $i];
        }
        if (!$ns && !empty($p['nameserver'])) $ns = (array)$p['nameserver'];
        $expire = $p['expirationdate'] ?? $p['expiredate'] ?? '';
        return $this->ok([
            'status' => stripos((string)($p['status'] ?? 'active'), 'active') !== false ? 'active' : 'pending',
            'expire_date' => $expire ? date('Y-m-d H:i:s', strtotime((string)$expire)) : null,
            'ns' => $ns,
        ]);
    }

    public function setNS(string $domain, array $ns): array
    {
        $params = ['domain' => $domain];
        foreach (array_values($ns) as $i => $host) $params['nameserver' . $i] = $host;
        $res = $this->call('ModifyDomain', $params);
        return $res['ok'] ? $this->ok() : $res;
    }

    public function getEpp(string $domain): array
    {
        $res = $this->call('RetrieveAuthCode', ['domain' => $domain]);
        if (!$res['ok']) return $res;
        return $this->ok(['code' => (string)($res['props']['authcode'] ?? '')]);
    }

    public function test(): array
    {
        $res = $this->call('CheckDomain', ['sld' => 'idceasy-test', 'tld' => 'com']);
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
}

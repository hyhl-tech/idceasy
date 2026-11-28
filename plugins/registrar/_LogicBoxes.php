<?php
/**
 * LogicBoxes / ResellerClub 系注册商通用驱动（供 resellerclub / resellbiz / netearthone 复用）
 * 文档：https://manage.resellerclub.com/kb/answer/744
 */
require_once __DIR__ . '/../Registrar.php';

use plugins\Registrar;

abstract class LogicBoxesRegistrar extends Registrar
{
    protected function apiBase(): string
    {
        $default = $this->config('base_url', 'https://httpapi.com/api');
        return rtrim($default, '/');
    }

    protected function call(string $path, array $params = [], string $method = 'GET'): array
    {
        $params['auth-userid'] = (string)$this->config('reseller_id', '');
        $params['api-key'] = (string)$this->config('api_key', '');
        $url = $this->apiBase() . $path;
        if ($method === 'GET') {
            $res = $this->http('GET', $url, $params);
        } else {
            $res = $this->http('POST', $url, $params);
        }
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $json = $res['json'];
        if (!is_array($json)) return $this->fail('返回格式异常：' . mb_substr($res['body'], 0, 160));
        if (isset($json['status']) && strtoupper((string)$json['status']) === 'ERROR') {
            return $this->fail((string)($json['message'] ?? '注册商返回错误'));
        }
        return $this->ok(['data' => $json]);
    }

    protected function ensureContact(): ?string
    {
        $existing = (string)$this->config('contact_id', '');
        if ($existing !== '') return $existing;
        $c = $this->contactBasic();
        $res = $this->call('/contacts/add.json', [
            'name' => $c['first_name'] . ' ' . $c['last_name'],
            'company' => $this->contactValue('company', 'Individual'),
            'email' => $c['email'],
            'address-line-1' => $c['address1'],
            'city' => $c['city'],
            'state' => $c['state'],
            'country' => $c['country'],
            'zipcode' => $c['postcode'],
            'phone-cc' => '86',
            'phone' => preg_replace('/\D/', '', $c['phone']) ?: '13800000000',
            'type' => 'Contact',
        ], 'POST');
        if (!$res['ok']) return null;
        $data = $res['data'] ?? [];
        $id = $data['contact_id'] ?? ($data[0]['contact_id'] ?? '');
        if ($id === '') return null;
        $this->config['contact_id'] = (string)$id;
        return (string)$id;
    }

    public function check(string $domain): array
    {
        $pos = strrpos($domain, '.');
        $sld = substr($domain, 0, $pos);
        $tld = substr($domain, $pos + 1);
        $res = $this->call('/domains/available.json', ['domain-name' => $sld, 'tlds' => $tld]);
        if (!$res['ok']) return $res;
        $data = $res['data'];
        $entry = $data[$domain] ?? $data[$sld . '.' . $tld] ?? (isset($data[0]) ? $data[0] : null);
        if (!is_array($entry)) return $this->fail('无法解析可用性结果');
        $status = strtolower((string)($entry['status'] ?? ''));
        return $this->ok([
            'available' => $status === 'available',
            'premium' => false,
            'price' => isset($entry['price']) ? (float)$entry['price'] : 0,
        ]);
    }

    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $pos = strrpos($domain, '.');
        $sld = substr($domain, 0, $pos);
        $tld = substr($domain, $pos + 1);
        $contactId = $this->ensureContact();
        if (!$contactId) return $this->fail('创建注册联系人失败，请检查 reseller_id / api_key');
        $customerId = (string)$this->config('customer_id', $contactId);
        $res = $this->call('/domains/register.json', [
            'domain-name' => $sld, 'tlds' => $tld, 'years' => max(1, $years),
            'ns' => $ns ?: ['ns1.idceasy.site', 'ns2.idceasy.site'],
            'customer-id' => $customerId,
            'reg-contact-id' => $contactId, 'admin-contact-id' => $contactId,
            'tech-contact-id' => $contactId, 'billing-contact-id' => $contactId,
            'invoice-option' => 'NoInvoice',
            'protect-privacy' => !empty($this->config('privacy', '0')) ? 'true' : 'false',
        ], 'POST');
        if (!$res['ok']) return $res;
        $data = $res['data'];
        return $this->ok([
            'id' => (string)($data['entityid'] ?? $data['domainid'] ?? ''),
            'expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years')),
        ]);
    }

    public function renew(string $domain, int $years): array
    {
        $pos = strrpos($domain, '.');
        $res = $this->call('/domains/renew.json', [
            'domain-name' => substr($domain, 0, $pos), 'tlds' => substr($domain, $pos + 1),
            'years' => max(1, $years), 'invoice-option' => 'NoInvoice',
        ], 'POST');
        return $res['ok'] ? $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime('+' . $years . ' years'))]) : $res;
    }

    public function getInfo(string $domain): array
    {
        $pos = strrpos($domain, '.');
        $res = $this->call('/domains/details.json', [
            'domain-name' => substr($domain, 0, $pos), 'tlds' => substr($domain, $pos + 1),
            'options' => 'OrderDetails',
        ]);
        if (!$res['ok']) return $res;
        $d = $res['data'];
        $ns = [];
        foreach ((array)($d['ns'] ?? []) as $n) $ns[] = is_array($n) ? ($n['nameserver'] ?? '') : $n;
        return $this->ok([
            'status' => stripos((string)($d['currentstatus'] ?? 'active'), 'active') !== false ? 'active' : 'pending',
            'expire_date' => isset($d['endtime']) ? date('Y-m-d H:i:s', strtotime((string)$d['endtime'])) : null,
            'ns' => array_values(array_filter($ns)),
            'lock' => !empty($d['transferlock']),
        ]);
    }

    public function setNS(string $domain, array $ns): array
    {
        $pos = strrpos($domain, '.');
        $res = $this->call('/domains/modify-ns.json', [
            'domain-name' => substr($domain, 0, $pos), 'tlds' => substr($domain, $pos + 1), 'ns' => array_values($ns),
        ], 'POST');
        return $res['ok'] ? $this->ok() : $res;
    }

    public function getEpp(string $domain): array
    {
        $pos = strrpos($domain, '.');
        $res = $this->call('/domains/retrieve-auth-code.json', [
            'domain-name' => substr($domain, 0, $pos), 'tlds' => substr($domain, $pos + 1),
        ]);
        if (!$res['ok']) return $res;
        return $this->ok(['code' => (string)($res['data']['auth-code'] ?? $res['data']['epp'] ?? '')]);
    }

    public function setPrivacy(string $domain, bool $enable): array
    {
        $pos = strrpos($domain, '.');
        $res = $this->call('/domains/modify-privacy-protection.json', [
            'domain-name' => substr($domain, 0, $pos), 'tlds' => substr($domain, $pos + 1),
            'privacy-enabled' => $enable ? 'true' : 'false',
        ], 'POST');
        return $res['ok'] ? $this->ok() : $res;
    }

    public function delete(string $domain): array
    {
        $pos = strrpos($domain, '.');
        $res = $this->call('/domains/delete.json', [
            'domain-name' => substr($domain, 0, $pos), 'tlds' => substr($domain, $pos + 1),
        ], 'POST');
        return $res['ok'] ? $this->ok() : $res;
    }

    public function test(): array
    {
        $res = $this->call('/domains/available.json', ['domain-name' => 'idceasy-test', 'tlds' => 'com']);
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功，API 凭证有效'] : ['ok' => false, 'error' => $res['error']];
    }
}

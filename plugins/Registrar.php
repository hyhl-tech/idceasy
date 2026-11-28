<?php
/**
 * 域名注册商插件基类
 * 所有方法统一返回 ['ok'=>bool,'error'=>string,...]
 */
namespace plugins;

require_once __DIR__ . '/PluginBase.php';
require_once __DIR__ . '/Http.php';

abstract class Registrar extends PluginBase
{
    use Http;

    /** @var array 联系信息：firstname,lastname,company,email,phone,address1,city,state,postcode,country */
    protected array $contact = [];

    public function setContact(array $contact): void
    {
        $this->contact = $contact;
    }

    abstract public function check(string $domain): array;

    abstract public function register(string $domain, int $years, array $contact, array $ns = []): array;

    public function renew(string $domain, int $years): array
    {
        return $this->fail('该注册商未实现续费接口');
    }

    public function transfer(string $domain, string $authCode, int $years = 1): array
    {
        return $this->fail('该注册商未实现转入接口');
    }

    public function getInfo(string $domain): array
    {
        return $this->fail('该注册商未实现信息查询接口');
    }

    public function setNS(string $domain, array $ns): array
    {
        return $this->fail('该注册商未实现 NS 修改接口');
    }

    public function getNS(string $domain): array
    {
        return $this->fail('该注册商未实现 NS 查询接口');
    }

    public function setLock(string $domain, bool $lock): array
    {
        return $this->fail('该注册商未实现转移锁接口');
    }

    public function getEpp(string $domain): array
    {
        return $this->fail('该注册商未实现 EPP 获取接口');
    }

    public function setPrivacy(string $domain, bool $enable): array
    {
        return $this->fail('该注册商未实现隐私保护接口');
    }

    public function delete(string $domain): array
    {
        return $this->fail('该注册商未实现删除接口');
    }

    public function dnsGet(string $domain): array
    {
        return $this->fail('该注册商未实现 DNS 记录接口');
    }

    public function dnsSet(string $domain, array $records): array
    {
        return $this->fail('该注册商未实现 DNS 记录接口');
    }

    protected function contactValue(string $key, string $default = ''): string
    {
        return trim((string)($this->contact[$key] ?? $default));
    }

    /** 常见联系人字段映射为各注册商格式 */
    protected function contactBasic(): array
    {
        return [
            'first_name' => $this->contactValue('firstname', 'John'),
            'last_name'  => $this->contactValue('lastname', 'Doe'),
            'email'      => $this->contactValue('email', 'admin@example.com'),
            'phone'      => preg_replace('/[^0-9+]/', '', $this->contactValue('phone', '+8613800000000')),
            'address1'   => $this->contactValue('address1', 'Nanshan District'),
            'city'       => $this->contactValue('city', 'Shenzhen'),
            'state'      => $this->contactValue('state', 'Guangdong'),
            'postcode'   => $this->contactValue('postcode', '518000'),
            'country'    => strtoupper($this->contactValue('country', 'CN')),
        ];
    }
}

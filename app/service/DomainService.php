<?php
/**
 * 域名业务：查询、注册、续费、转入、NS、DNS、同步
 * 通过 registrar 插件完成真实注册商 API 调用。
 */
namespace app\service;

use think\Db;
use think\Plugin;

class DomainService
{
    public static function tldOf(string $domain): string
    {
        $parts = explode('.', strtolower(trim($domain)));
        return count($parts) > 1 ? end($parts) : '';
    }

    /** 取得 TLD 对应的注册商插件 */
    public static function registrarFor(string $tld): array
    {
        $tldRow = Db::table('domain_tld')->where('tld', $tld)->where('status', 1)->find();
        if (!$tldRow) return ['ok' => false, 'error' => '暂不支持 .' . $tld . ' 后缀'];
        $name = $tldRow['registrar'] ?: cfg('domain_default_registrar', 'namesilo');
        $record = Plugin::record('registrar', $name);
        if (!$record || (int)$record['status'] !== 1) {
            return ['ok' => false, 'error' => '注册商 ' . $name . ' 未启用，请联系管理员'];
        }
        $config = json_decode((string)$record['config'], true) ?: [];
        try {
            $plugin = Plugin::make('registrar', $name, $config);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => '注册商插件加载失败: ' . $e->getMessage()];
        }
        return ['ok' => true, 'plugin' => $plugin, 'name' => $name, 'tld' => $tldRow, 'config' => $config];
    }

    /** 域名搜索：$tlds 为空时查询前 6 个已启用后缀 */
    public static function search(string $keyword, array $tlds = []): array
    {
        $keyword = strtolower(trim($keyword));
        $keyword = preg_replace('#^https?://#', '', $keyword);
        $keyword = preg_replace('#/.*$#', '', $keyword);
        $keyword = preg_replace('/^www\./', '', $keyword);
        $keyword = preg_replace('/[^a-z0-9\.\-]/', '', $keyword);
        if ($keyword === '') return ['ok' => false, 'error' => '请输入要查询的域名', 'items' => []];

        $all = Db::table('domain_tld')->where('status', 1)->order('sort')->select();
        $map = [];
        foreach ($all as $row) $map[$row['tld']] = $row;

        $queries = [];
        if (str_contains($keyword, '.')) {
            $tld = self::tldOf($keyword);
            if (!isset($map[$tld])) return ['ok' => false, 'error' => '暂不支持 .' . $tld . ' 后缀', 'items' => []];
            $queries[] = ['domain' => $keyword, 'row' => $map[$tld]];
        } else {
            $wanted = $tlds ?: array_slice(array_keys($map), 0, 6);
            foreach ($wanted as $tld) {
                if (!isset($map[$tld])) continue;
                $queries[] = ['domain' => $keyword . '.' . $tld, 'row' => $map[$tld]];
            }
        }

        $items = [];
        foreach ($queries as $q) {
            $domain = $q['domain'];
            $row = $q['row'];
            $item = ['domain' => $domain, 'tld' => $row['tld'], 'available' => null, 'premium' => false,
                'price' => (float)$row['register_price'], 'renew_price' => (float)$row['renew_price'],
                'currency' => $row['currency'], 'registrar' => $row['registrar'], 'error' => ''];
            $reg = self::registrarFor($row['tld']);
            if (!$reg['ok']) {
                $item['error'] = $reg['error'];
                $items[] = $item;
                continue;
            }
            try {
                $res = $reg['plugin']->check($domain);
                if (!empty($res['ok'])) {
                    $item['available'] = (bool)($res['available'] ?? false);
                    $item['premium'] = (bool)($res['premium'] ?? false);
                    if ($item['premium']) {
                        $item['price'] = 0;
                        $item['error'] = '溢价域名，请联系客服报价';
                    }
                } else {
                    $item['error'] = $res['error'] ?? '查询失败';
                }
            } catch (\Throwable $e) {
                $item['error'] = $e->getMessage();
                log_write('domain', '查询失败 ' . $domain . ': ' . $e->getMessage());
            }
            $items[] = $item;
        }
        return ['ok' => true, 'items' => $items];
    }

    public static function register(int $domainId): array
    {
        $domain = Db::table('domain')->where('id', $domainId)->find();
        if (!$domain) return ['ok' => false, 'error' => '域名记录不存在'];
        if ($domain['status'] === 'active') return ['ok' => true, 'msg' => '域名已激活'];
        $reg = self::registrarFor($domain['tld']);
        if (!$reg['ok']) return $reg;
        $user = Db::table('user')->where('id', $domain['user_id'])->find();
        $years = max(1, (int)round((strtotime($domain['expire_date']) - time()) / 31536000));
        $ns = json_decode((string)$domain['ns'], true) ?: [];
        try {
            $reg['plugin']->setContact(self::contact($domain['user_id']));
            $res = $reg['plugin']->register($domain['domain'], $years, self::contact($domain['user_id']), $ns);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (empty($res['ok'])) {
            log_write('domain', '注册失败 ' . $domain['domain'] . ': ' . ($res['error'] ?? '未知错误'));
            return ['ok' => false, 'error' => $res['error'] ?? '注册失败'];
        }
        $expire = $res['expire_date'] ?? date('Y-m-d H:i:s', strtotime('+' . $years . ' years'));
        Db::table('domain')->where('id', $domainId)->update([
            'status' => 'active', 'registrar_domain_id' => (string)($res['id'] ?? ''),
            'reg_date' => $res['reg_date'] ?? now(), 'expire_date' => $expire, 'next_due' => $expire,
            'updated_at' => now(),
        ]);
        hook('domain.registered', ['domain_id' => $domainId, 'domain' => $domain['domain']]);
        if ($user) {
            Mailer::sendTemplate('domain_expiry', $user['email'], [
                'name' => $user['name'] ?: $user['email'], 'domain' => $domain['domain'],
                'expire_date' => $expire, 'url' => abs_url('/domain/' . $domainId),
            ], false);
        }
        return ['ok' => true, 'expire_date' => $expire];
    }

    public static function renew(int $domainId, int $years = 1): array
    {
        $domain = Db::table('domain')->where('id', $domainId)->find();
        if (!$domain) return ['ok' => false, 'error' => '域名不存在'];
        $reg = self::registrarFor($domain['tld']);
        if (!$reg['ok']) return $reg;
        try {
            $res = $reg['plugin']->renew($domain['domain'], $years);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (empty($res['ok'])) return ['ok' => false, 'error' => $res['error'] ?? '续费失败'];
        $base = max(time(), strtotime($domain['expire_date'] ?: 'now'));
        $expire = date('Y-m-d H:i:s', strtotime('+' . $years . ' years', $base));
        Db::table('domain')->where('id', $domainId)->update([
            'expire_date' => $expire, 'next_due' => $expire, 'status' => 'active', 'updated_at' => now(),
        ]);
        hook('domain.renewed', ['domain_id' => $domainId, 'years' => $years]);
        return ['ok' => true, 'expire_date' => $expire];
    }

    public static function transfer(int $domainId, string $authCode): array
    {
        $domain = Db::table('domain')->where('id', $domainId)->find();
        if (!$domain) return ['ok' => false, 'error' => '域名不存在'];
        $reg = self::registrarFor($domain['tld']);
        if (!$reg['ok']) return $reg;
        $reg['plugin']->setContact(self::contact($domain['user_id']));
        $res = $reg['plugin']->transfer($domain['domain'], $authCode, 1);
        if (empty($res['ok'])) return ['ok' => false, 'error' => $res['error'] ?? '转入失败'];
        Db::table('domain')->where('id', $domainId)->update([
            'status' => 'pending_transfer', 'auth_code' => $authCode, 'updated_at' => now(),
        ]);
        hook('domain.transferred', ['domain_id' => $domainId]);
        return ['ok' => true];
    }

    public static function sync(int $domainId): array
    {
        $domain = Db::table('domain')->where('id', $domainId)->find();
        if (!$domain) return ['ok' => false, 'error' => '域名不存在'];
        $reg = self::registrarFor($domain['tld']);
        if (!$reg['ok']) return $reg;
        $res = $reg['plugin']->getInfo($domain['domain']);
        if (empty($res['ok'])) return ['ok' => false, 'error' => $res['error'] ?? '同步失败'];
        $update = ['updated_at' => now()];
        if (!empty($res['expire_date'])) {
            $update['expire_date'] = $res['expire_date'];
            $update['next_due'] = $res['expire_date'];
        }
        if (!empty($res['status'])) $update['status'] = $res['status'] === 'active' ? 'active' : $res['status'];
        if (!empty($res['ns'])) $update['ns'] = json_encode($res['ns'], JSON_UNESCAPED_UNICODE);
        if (isset($res['lock'])) $update['lock'] = $res['lock'] ? 1 : 0;
        Db::table('domain')->where('id', $domainId)->update($update);
        hook('domain.synced', ['domain_id' => $domainId, 'data' => $res]);
        return ['ok' => true, 'data' => $res];
    }

    public static function setNs(int $domainId, array $ns): array
    {
        $flat = [];
        foreach ($ns as $item) {
            foreach (preg_split('/[\r\n,]+/', (string)$item) as $host) {
                $host = trim($host);
                if ($host !== '') $flat[] = $host;
            }
        }
        $ns = array_values(array_unique($flat));
        foreach ($ns as $host) {
            if (!preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $host)) {
                return ['ok' => false, 'error' => 'NS 格式不正确: ' . $host];
            }
        }
        if (count($ns) < 2) return ['ok' => false, 'error' => '至少需要两组 NS'];
        $domain = Db::table('domain')->where('id', $domainId)->find();
        if (!$domain) return ['ok' => false, 'error' => '域名不存在'];
        $reg = self::registrarFor($domain['tld']);
        if (!$reg['ok']) return $reg;
        $res = $reg['plugin']->setNS($domain['domain'], $ns);
        if (empty($res['ok'])) return ['ok' => false, 'error' => $res['error'] ?? '修改失败'];
        Db::table('domain')->where('id', $domainId)->update([
            'ns' => json_encode($ns, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
        ]);
        hook('domain.ns_changed', ['domain_id' => $domainId, 'ns' => $ns]);
        return ['ok' => true];
    }

    public static function getEpp(int $domainId): array
    {
        $domain = Db::table('domain')->where('id', $domainId)->find();
        if (!$domain) return ['ok' => false, 'error' => '域名不存在'];
        $reg = self::registrarFor($domain['tld']);
        if (!$reg['ok']) return $reg;
        $res = $reg['plugin']->getEpp($domain['domain']);
        if (empty($res['ok'])) return ['ok' => false, 'error' => $res['error'] ?? '获取失败'];
        Db::table('domain')->where('id', $domainId)->update(['auth_code' => $res['code'] ?? '', 'updated_at' => now()]);
        return ['ok' => true, 'code' => $res['code'] ?? ''];
    }

    public static function setLock(int $domainId, bool $lock): array
    {
        $domain = Db::table('domain')->where('id', $domainId)->find();
        if (!$domain) return ['ok' => false, 'error' => '域名不存在'];
        $reg = self::registrarFor($domain['tld']);
        if (!$reg['ok']) return $reg;
        $res = $reg['plugin']->setLock($domain['domain'], $lock);
        if (empty($res['ok'])) return ['ok' => false, 'error' => $res['error'] ?? '操作失败'];
        Db::table('domain')->where('id', $domainId)->update(['lock' => $lock ? 1 : 0, 'updated_at' => now()]);
        return ['ok' => true];
    }

    public static function setPrivacy(int $domainId, bool $enable): array
    {
        $domain = Db::table('domain')->where('id', $domainId)->find();
        if (!$domain) return ['ok' => false, 'error' => '域名不存在'];
        $reg = self::registrarFor($domain['tld']);
        if (!$reg['ok']) return $reg;
        $res = $reg['plugin']->setPrivacy($domain['domain'], $enable);
        if (empty($res['ok'])) return ['ok' => false, 'error' => $res['error'] ?? '操作失败'];
        Db::table('domain')->where('id', $domainId)->update(['privacy' => $enable ? 1 : 0, 'updated_at' => now()]);
        return ['ok' => true];
    }

    /** 注册联系人（优先使用默认地址） */
    public static function contact(int $userId): array
    {
        $user = Db::table('user')->where('id', $userId)->find() ?: [];
        $addr = Db::table('user_address')->where('user_id', $userId)->order('is_default', 'desc')->find() ?: [];
        $first = $addr['firstname'] ?? '';
        $last = $addr['lastname'] ?? '';
        if ($first === '' && $last === '') {
            $name = trim((string)($user['name'] ?? ''));
            if ($name !== '') {
                $first = mb_substr($name, 0, 1);
                $last = mb_substr($name, 1);
            }
        }
        return [
            'firstname' => $first ?: 'John', 'lastname' => $last ?: 'Doe',
            'company'   => $addr['company'] ?? ($user['company'] ?? ''),
            'email'     => $addr['email'] ?? ($user['email'] ?? ''),
            'phone'     => $addr['phone'] ?? ($user['phone'] ?? '+8613800000000'),
            'address1'  => $addr['address1'] ?? ($user['address'] ?? 'Nanshan District'),
            'city'      => $addr['city'] ?? ($user['city'] ?? 'Shenzhen'),
            'state'     => $addr['state'] ?? ($user['province'] ?? 'Guangdong'),
            'postcode'  => $addr['postcode'] ?? ($user['postcode'] ?? '518000'),
            'country'   => strtoupper($addr['country'] ?? ($user['country'] ?? 'CN')),
        ];
    }
}

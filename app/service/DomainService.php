<?php
/**
 * 域名业务：查询、比价、注册、转入、续费、NS、DNS、同步
 * 通过 registrar 插件完成真实注册商 API 调用。
 */
namespace app\service;

use think\Db;
use think\Plugin;

class DomainService
{
    /** 单次请求内的报价缓存 */
    protected static array $quoteCache = [];

    /* ------------------------------------------------------------------
     * 基础
     * ------------------------------------------------------------------ */

    public static function normalize(string $input): string
    {
        $domain = strtolower(trim($input));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        $domain = preg_replace('/^www\./', '', $domain);
        $domain = preg_replace('/[^a-z0-9\.\-]/', '', $domain);
        return trim($domain, '.');
    }

    public static function valid(string $domain): bool
    {
        return (bool)preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $domain);
    }

    public static function tldOf(string $domain): string
    {
        $parts = explode('.', strtolower(trim($domain)));
        return count($parts) > 1 ? end($parts) : '';
    }

    public static function compareEnabled(): bool
    {
        return (string)cfg('domain_compare', '0') === '1';
    }

    public static function markup(): float
    {
        return max(0, (float)cfg('domain_markup', '0'));
    }

    public static function baseCurrency(): string
    {
        return strtoupper((string)cfg('currency', 'CNY'));
    }

    /** 注册商结算货币（用于把上游报价换算成本位币） */
    public static function registrarCurrency(string $name, array $config = []): string
    {
        $c = strtoupper(trim((string)($config['currency'] ?? '')));
        if ($c !== '') return $c;
        $map = [
            'aliyun' => 'CNY', 'tencentcloud' => 'CNY',
            'namesilo' => 'USD', 'namecheap' => 'USD', 'namecom' => 'USD', 'porkbun' => 'USD',
            'godaddy' => 'USD', 'enom' => 'USD', 'internetbs' => 'USD', 'opensrs' => 'USD',
            'resellerclub' => 'USD', 'resellbiz' => 'USD', 'netearthone' => 'USD',
            'domainnameapi' => 'USD', 'dynadot' => 'USD',
            'gandi' => 'EUR', 'openprovider' => 'EUR', 'hexonet' => 'EUR', 'rrpproxy' => 'EUR',
            'ovh' => 'EUR', 'regru' => 'RUB',
        ];
        return $map[$name] ?? 'USD';
    }

    /** 汇率：currency.rate 表示 1 本位币可兑换的外币数量 */
    public static function toBase(float $amount, string $currency): float
    {
        $currency = strtoupper($currency);
        $base = self::baseCurrency();
        if ($currency === '' || $currency === $base) return round($amount, 4);
        $c = currency_info($currency);
        $rate = (float)($c['rate'] ?? 0);
        if ($rate <= 0) return 0.0;
        return round($amount / $rate, 4);
    }

    /** 取得 TLD 对应的注册商插件；$name 为空时使用 TLD 绑定或默认注册商 */
    public static function registrarFor(string $tld, ?string $name = null): array
    {
        $tldRow = Db::table('domain_tld')->where('tld', $tld)->where('status', 1)->find();
        if (!$tldRow) return ['ok' => false, 'error' => '暂不支持 .' . $tld . ' 后缀'];
        $name = $name ?: ($tldRow['registrar'] ?: cfg('domain_default_registrar', 'namesilo'));
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

    /** 已启用的注册商列表 */
    public static function enabledRegistrars(): array
    {
        $out = [];
        foreach (Plugin::records() as $r) {
            if ($r['type'] !== 'registrar' || (int)$r['status'] !== 1) continue;
            $out[$r['name']] = [
                'name' => $r['name'],
                'title' => $r['title'] ?: $r['name'],
                'config' => json_decode((string)$r['config'], true) ?: [],
            ];
        }
        return $out;
    }

    /**
     * 比价候选：TLD 绑定的注册商优先，其余按服务后缀数量排序，最多 3 家。
     * 只在开启比价且存在多家上游时生效。
     */
    public static function candidates(array $tldRow, bool $compare): array
    {
        $assigned = (string)($tldRow['registrar'] ?: cfg('domain_default_registrar', 'namesilo'));
        $enabled = self::enabledRegistrars();
        if (!$compare || count($enabled) < 2) {
            return array_values(array_intersect([$assigned], array_keys($enabled))) ?: [$assigned];
        }
        $weights = [];
        foreach (Db::table('domain_tld')->where('status', 1)->select() as $row) {
            $r = (string)($row['registrar'] ?: '');
            if ($r !== '') $weights[$r] = ($weights[$r] ?? 0) + 1;
        }
        $others = array_keys($enabled);
        usort($others, function ($a, $b) use ($weights, $assigned) {
            if ($a === $assigned) return -1;
            if ($b === $assigned) return 1;
            return ($weights[$b] ?? 0) <=> ($weights[$a] ?? 0) ?: strcmp($a, $b);
        });
        return array_slice($others, 0, 3);
    }

    /** 查询单个注册商的报价 */
    protected static function quoteOne(string $name, string $domain, int $years, array $tldRow, bool $compare): array
    {
        $offer = [
            'registrar' => $name, 'title' => $name, 'available' => null, 'premium' => false,
            'cost' => 0.0, 'currency' => '', 'cost_base' => 0.0, 'price' => 0.0, 'error' => '',
        ];
        $reg = self::registrarFor($tldRow['tld'], $name);
        if (!$reg['ok']) {
            log_write('domain', '注册商不可用 ' . $name . ' @' . $tldRow['tld'] . ': ' . $reg['error']);
            $offer['error'] = '该后缀暂不可注册，请稍后再试';
            return $offer;
        }
        $record = Plugin::record('registrar', $name);
        $offer['title'] = $record['title'] ?? $name;
        try {
            $res = $reg['plugin']->check($domain);
        } catch (\Throwable $e) {
            $offer['error'] = $e->getMessage();
            log_write('domain', '查询失败 ' . $domain . ' @' . $name . ': ' . $e->getMessage());
            return $offer;
        }
        if (empty($res['ok'])) {
            $offer['error'] = $res['error'] ?? '查询失败';
            return $offer;
        }
        $offer['available'] = (bool)($res['available'] ?? false);
        $offer['premium'] = (bool)($res['premium'] ?? false);
        $cost = (float)($res['price'] ?? 0);
        $currency = strtoupper((string)($res['currency'] ?? self::registrarCurrency($name, $reg['config'])));
        $offer['cost'] = $cost;
        $offer['currency'] = $currency;
        $offer['cost_base'] = $cost > 0 ? self::toBase($cost, $currency) : 0.0;

        // 关闭比价时始终使用后台定价；开启比价时成本价 + 加价，无报价则回落到后台定价
        $fixed = (float)($tldRow['register_price'] ?? 0);
        if ($compare && $offer['cost_base'] > 0) {
            $offer['price'] = round($offer['cost_base'] * (1 + self::markup() / 100), 2);
        } else {
            $offer['price'] = $fixed;
        }
        if ($offer['premium']) {
            $offer['price'] = 0;
            $offer['error'] = '溢价域名，请联系客服报价';
        }
        return $offer;
    }

    /**
     * 报价：返回全部上游报价与最优方案
     * $only 指定注册商时只查这一家（用于下单校验）
     */
    public static function quote(string $domain, int $years = 1, ?string $only = null): array
    {
        $domain = self::normalize($domain);
        if (!self::valid($domain)) return ['ok' => false, 'error' => '域名格式不正确', 'items' => [], 'best' => null];
        $tld = self::tldOf($domain);
        $tldRow = Db::table('domain_tld')->where('tld', $tld)->where('status', 1)->find();
        if (!$tldRow) return ['ok' => false, 'error' => '暂不支持 .' . $tld . ' 后缀', 'items' => [], 'best' => null];
        $years = max(1, min(10, $years));

        $compare = self::compareEnabled();
        $cacheKey = $domain . '|' . $years . '|' . ($only ?: ($compare ? 'cmp' : 'single'));
        if (isset(self::$quoteCache[$cacheKey])) return self::$quoteCache[$cacheKey];

        $names = $only !== null ? [$only] : self::candidates($tldRow, $compare);
        $items = [];
        foreach ($names as $name) {
            $items[] = self::quoteOne($name, $domain, $years, $tldRow, $compare);
        }

        $best = null;
        foreach ($items as $offer) {
            if ($offer['available'] !== true || $offer['premium']) continue;
            if ($offer['price'] <= 0) continue;
            if ($best === null) { $best = $offer; continue; }
            $a = $offer['cost_base'] > 0 ? $offer['cost_base'] : $offer['price'];
            $b = $best['cost_base'] > 0 ? $best['cost_base'] : $best['price'];
            if ($a < $b) $best = $offer;
        }

        if ($best === null) {
            $error = '';
            foreach ($items as $offer) {
                if ($offer['error'] !== '') { $error = $offer['error']; break; }
            }
            $result = ['ok' => true, 'items' => $items, 'best' => null, 'error' => $error];
        } else {
            $result = ['ok' => true, 'items' => $items, 'best' => $best, 'error' => ''];
        }
        self::$quoteCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * 下单价格：优先使用用户选择的注册商，失效时自动回落到最优报价
     */
    public static function priceForOrder(string $domain, int $years, string $registrar = ''): array
    {
        $domain = self::normalize($domain);
        $years = max(1, min(10, $years));
        if ($registrar !== '') {
            $q = self::quote($domain, $years, $registrar);
            if (!empty($q['best'])) {
                return ['ok' => true, 'price' => (float)$q['best']['price'], 'registrar' => $registrar,
                    'cost_base' => (float)$q['best']['cost_base'], 'title' => $q['best']['title']];
            }
        }
        $q = self::quote($domain, $years);
        if (empty($q['best'])) {
            return ['ok' => false, 'error' => $q['error'] ?? '该域名当前不可注册'];
        }
        return ['ok' => true, 'price' => (float)$q['best']['price'], 'registrar' => $q['best']['registrar'],
            'cost_base' => (float)$q['best']['cost_base'], 'title' => $q['best']['title']];
    }

    /**
     * 开通时选择注册商：多上游时实时选最低成本，失败则用 TLD 绑定
     */
    public static function chooseRegistrar(string $domain): string
    {
        $q = self::quote($domain, 1);
        if (!empty($q['best']['registrar'])) return (string)$q['best']['registrar'];
        $row = Db::table('domain_tld')->where('tld', self::tldOf($domain))->find();
        return (string)($row['registrar'] ?? cfg('domain_default_registrar', 'namesilo'));
    }

    /* ------------------------------------------------------------------
     * 查询
     * ------------------------------------------------------------------ */

    /** 域名搜索：$tlds 为空时查询前 6 个已启用后缀 */
    public static function search(string $keyword, array $tlds = []): array
    {
        $keyword = self::normalize($keyword);
        if ($keyword === '') return ['ok' => false, 'error' => '请输入要查询的域名', 'items' => []];
        if (str_contains($keyword, '.') && !self::valid($keyword)) {
            return ['ok' => false, 'error' => '域名格式不正确', 'items' => []];
        }

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
            $quote = self::quote($domain, 1);
            $best = $quote['best'] ?? null;
            $item = [
                'domain' => $domain, 'tld' => $row['tld'],
                'available' => $best ? true : self::firstAvailable($quote['items'] ?? []),
                'premium' => false, 'price' => (float)$row['register_price'],
                'renew_price' => (float)$row['renew_price'],
                'transfer_price' => (float)$row['transfer_price'],
                'currency' => $row['currency'], 'registrar' => $row['registrar'],
                'offers' => $quote['items'] ?? [], 'compare_count' => 0,
                'error' => $best ? '' : ($quote['error'] ?? '查询失败'),
            ];
            if ($best) {
                $item['price'] = (float)$best['price'];
                $item['registrar'] = $best['registrar'];
                $item['registrar_title'] = $best['title'];
                $item['compare_count'] = count(array_filter($quote['items'], function ($o) {
                    return $o['available'] === true && !$o['premium'];
                }));
                $item['premium'] = (bool)$best['premium'];
                $item['error'] = $best['error'] ?? '';
            } elseif (!empty($quote['items'])) {
                foreach ($quote['items'] as $o) {
                    if ($o['available'] === true && $o['premium']) { $item['premium'] = true; break; }
                }
            }
            $items[] = $item;
        }
        return ['ok' => true, 'items' => $items, 'compare' => self::compareEnabled()];
    }

    protected static function firstAvailable(array $offers)
    {
        foreach ($offers as $o) {
            if ($o['available'] === true && !$o['premium']) return true;
        }
        foreach ($offers as $o) {
            if ($o['available'] === false) return false;
        }
        return null;
    }

    /* ------------------------------------------------------------------
     * 注册 / 续费 / 转入 / 同步
     * ------------------------------------------------------------------ */

    public static function register(int $domainId): array
    {
        $domain = Db::table('domain')->where('id', $domainId)->find();
        if (!$domain) return ['ok' => false, 'error' => '域名记录不存在'];
        if ($domain['status'] === 'active') return ['ok' => true, 'msg' => '域名已激活'];
        $reg = self::registrarFor($domain['tld'], $domain['registrar'] ?: null);
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
                'expire_date' => $expire, 'url' => abs_url('/user/domain/' . $domainId),
            ], false);
        }
        return ['ok' => true, 'expire_date' => $expire];
    }

    public static function renew(int $domainId, int $years = 1): array
    {
        $domain = Db::table('domain')->where('id', $domainId)->find();
        if (!$domain) return ['ok' => false, 'error' => '域名不存在'];
        $reg = self::registrarFor($domain['tld'], $domain['registrar'] ?: null);
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
        $reg = self::registrarFor($domain['tld'], $domain['registrar'] ?: null);
        if (!$reg['ok']) return $reg;
        $reg['plugin']->setContact(self::contact($domain['user_id']));
        try {
            $res = $reg['plugin']->transfer($domain['domain'], $authCode, 1);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
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
        $reg = self::registrarFor($domain['tld'], $domain['registrar'] ?: null);
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
        $reg = self::registrarFor($domain['tld'], $domain['registrar'] ?: null);
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
        $reg = self::registrarFor($domain['tld'], $domain['registrar'] ?: null);
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
        $reg = self::registrarFor($domain['tld'], $domain['registrar'] ?: null);
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
        $reg = self::registrarFor($domain['tld'], $domain['registrar'] ?: null);
        if (!$reg['ok']) return $reg;
        $res = $reg['plugin']->setPrivacy($domain['domain'], $enable);
        if (empty($res['ok'])) return ['ok' => false, 'error' => $res['error'] ?? '操作失败'];
        Db::table('domain')->where('id', $domainId)->update(['privacy' => $enable ? 1 : 0, 'updated_at' => now()]);
        return ['ok' => true];
    }

    /* ------------------------------------------------------------------
     * 联系人
     * ------------------------------------------------------------------ */

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

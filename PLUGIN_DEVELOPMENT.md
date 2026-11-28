# idceasy 插件开发规范

本文件是 idceasy 插件体系的权威说明。所有内置插件都遵循同一套约定，你也可以据此开发私有插件或商业插件。

- 版本：1.0
- 适用：idceasy 1.0+
- 许可证：插件可使用任意许可证，但分发时须遵守 idceasy 的 AGPL-3.0

---

## 1. 目录与命名

```
plugins/
├── Registrar.php          注册商基类（框架文件）
├── Gateway.php            支付网关基类（框架文件）
├── Server.php             服务器模块基类（框架文件）
├── PluginBase.php         通用插件基类（框架文件）
├── Http.php               HTTP 客户端 trait（框架文件）
├── registrar/
│   └── your_registrar.php
├── gateway/
│   └── your_gateway.php
├── server/
│   └── your_server.php
└── addon/
    └── your_addon.php
```

规则：

1. 文件名即插件 ID，只允许 `a-z0-9_-`，例如 `namesilo.php` → ID `namesilo`。
2. 以 `_` 开头的文件不会被识别为插件，可用于共享基类（如 `_LogicBoxes.php`）。
3. 一个插件一个文件；文件必须 `return` 一个对象（addon 返回数组）。
4. 使用匿名类可避免类名冲突：

```php
<?php
return new class extends \plugins\Registrar {
    public function info(): array { /* ... */ }
};
```

5. 不要在插件文件中声明命名空间；需要时使用完全限定类名，如 `\think\Db`、`\app\service\Mailer`。
6. 插件由后台「插件」页面自动发现；启用与配置保存在 `ice_plugin` 表。

---

## 2. 元信息与配置字段

所有插件必须实现 `info()`：

```php
public function info(): array
{
    return [
        'id'          => 'your_registrar',   // 与文件名一致
        'title'       => 'Your Registrar',
        'version'     => '1.0.0',
        'author'      => 'Your Name',
        'description' => '一句话说明',
        'fields'      => [
            ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ['name' => 'sandbox', 'label' => '沙箱', 'type' => 'checkbox'],
            ['name' => 'region', 'label' => '区域', 'type' => 'select',
             'options' => ['cn' => '中国', 'us' => '美国']],
            ['name' => 'note', 'label' => '说明', 'type' => 'textarea',
             'help' => '显示在字段下方的提示'],
        ],
    ];
}
```

字段类型：`text`、`password`、`textarea`、`select`、`checkbox`、`number`。

后台配置页会自动渲染这些字段并保存。读取配置：

```php
$this->config('api_key');              // 不存在时返回 null
$this->config('sandbox', '0');         // 默认值
$this->config();                       // 全部配置数组
```

密码字段留空保存时不会覆盖旧值。

---

## 3. 注册商插件

基类：`plugins\Registrar`（已内置 `Http` trait、`ok()`、`fail()`、`contactBasic()`）。

### 3.1 必须实现

```php
public function check(string $domain): array;
public function register(string $domain, int $years, array $contact, array $ns = []): array;
```

返回约定：

```php
// 查询
['ok' => true, 'available' => true, 'premium' => false, 'price' => 12.50]
['ok' => false, 'error' => '注册商返回的错误']

// 注册
['ok' => true, 'id' => '注册商内部ID', 'expire_date' => '2027-01-01 00:00:00']
['ok' => false, 'error' => '...']
```

`$contact` 字段：`firstname`、`lastname`、`company`、`email`、`phone`、`address1`、`city`、`state`、`postcode`、`country`。

### 3.2 可选覆盖

| 方法 | 说明 |
|---|---|
| `renew($domain, $years)` | 续费 |
| `transfer($domain, $authCode, $years)` | 转入 |
| `getInfo($domain)` | 返回 `status`、`expire_date`、`ns`、`lock` |
| `setNS($domain, array $ns)` | 修改 NS |
| `getNS($domain)` | 查询 NS |
| `setLock($domain, bool $lock)` | 转移锁 |
| `getEpp($domain)` | 返回 `code` |
| `setPrivacy($domain, bool $enable)` | 隐私保护 |
| `delete($domain)` | 删除域名 |
| `dnsGet($domain)` / `dnsSet($domain, $records)` | DNS 记录（可选） |
| `test()` | 后台「测试」按钮，返回 `['ok'=>true,'msg'=>'...']` |

### 3.3 完整示例

```php
<?php
return new class extends \plugins\Registrar {
    public function info(): array
    {
        return [
            'id' => 'example_registrar', 'title' => 'Example Registrar',
            'version' => '1.0.0', 'author' => 'Acme',
            'description' => '示例注册商，演示查询、注册、续费与 NS 修改。',
            'fields' => [
                ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                ['name' => 'sandbox', 'label' => '沙箱', 'type' => 'checkbox'],
            ],
        ];
    }

    protected function api(string $path, array $data = [], string $method = 'GET'): array
    {
        $base = $this->config('sandbox', '0') === '1'
            ? 'https://sandbox.example.com/api' : 'https://api.example.com';
        $res = $this->http($method, $base . $path, $data,
            ['Authorization: Bearer ' . $this->config('api_key')], ['json' => $method !== 'GET']);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        if ($res['code'] >= 400) return $this->fail('上游返回 HTTP ' . $res['code']);
        return $this->ok(['data' => $res['json'] ?? []]);
    }

    public function check(string $domain): array
    {
        $res = $this->api('/domains/check', ['domain' => $domain]);
        if (!$res['ok']) return $res;
        return $this->ok([
            'available' => (bool)($res['data']['available'] ?? false),
            'premium'   => (bool)($res['data']['premium'] ?? false),
            'price'     => (float)($res['data']['price'] ?? 0),
        ]);
    }

    public function register(string $domain, int $years, array $contact, array $ns = []): array
    {
        $c = $this->contactBasic();
        $res = $this->api('/domains', [
            'domain' => $domain, 'years' => $years, 'nameservers' => array_values($ns),
            'registrant' => $c,
        ], 'POST');
        if (!$res['ok']) return $res;
        return $this->ok([
            'id' => (string)($res['data']['id'] ?? $domain),
            'expire_date' => date('Y-m-d H:i:s', strtotime("+{$years} years")),
        ]);
    }

    public function renew(string $domain, int $years): array
    {
        $res = $this->api('/domains/' . $domain . '/renew', ['years' => $years], 'POST');
        if (!$res['ok']) return $res;
        return $this->ok(['expire_date' => date('Y-m-d H:i:s', strtotime("+{$years} years"))]);
    }

    public function setNS(string $domain, array $ns): array
    {
        $res = $this->api('/domains/' . $domain . '/nameservers', ['ns' => array_values($ns)], 'PUT');
        return $res['ok'] ? $this->ok() : $res;
    }

    public function test(): array
    {
        $res = $this->api('/ping');
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};
```

### 3.4 上游返回处理建议

- 永远检查 `curl_error` 与 HTTP 状态码
- 不要把上游原始错误直接抛给终端用户，写入 `log_write('domain', ...)` 并返回可读提示
- 金额字段注意单位（分/元、微单位）
- 时间统一转换为 `Y-m-d H:i:s`
- 幂等：同一个域名重复注册时返回已有记录而不是报错

---

## 4. 支付网关插件

基类：`plugins\Gateway`。

### 4.1 接口

```php
public function pay(array $invoice, string $returnUrl, string $notifyUrl): array;
public function notify(array $params, string $raw = ''): array;
```

`pay()` 返回：

```php
['ok' => true, 'type' => 'redirect', 'url' => 'https://...']
['ok' => true, 'type' => 'html', 'html' => '<form>...</form>']
['ok' => true, 'type' => 'qrcode', 'qr' => 'weixin://wxpay/...', 'url' => '']
['ok' => false, 'error' => '...']
```

`notify()` 返回：

```php
['ok' => true, 'invoice_no' => 'INV202601010001', 'trade_no' => '网关交易号',
 'amount' => 99.00, 'fee' => 0.60, 'raw' => $params]
['ok' => false, 'error' => '签名错误', 'ack' => 'fail']
```

`$invoice` 字段：`id`、`invoice_no`、`total`、`paid`、`currency`、`user_id`。

### 4.2 可选

| 方法 | 说明 |
|---|---|
| `query($invoiceNo)` | 主动查单 |
| `refund($invoice, $amount, $reason)` | 在线退款 |
| `fee($amount)` | 手续费估算 |
| `ack($result)` | 返回给网关的应答文本，默认 `success` |

### 4.3 示例（易支付风格）

```php
<?php
return new class extends \plugins\Gateway {
    public function info(): array
    {
        return [
            'id' => 'my_epay', 'title' => 'MyPay', 'version' => '1.0.0', 'author' => 'Acme',
            'description' => 'MD5 签名的聚合支付',
            'fields' => [
                ['name' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => true],
                ['name' => 'pid', 'label' => '商户号', 'type' => 'text', 'required' => true],
                ['name' => 'key', 'label' => '密钥', 'type' => 'password', 'required' => true],
            ],
        ];
    }

    protected function sign(array $p): string
    {
        ksort($p);
        unset($p['sign'], $p['sign_type']);
        $pairs = [];
        foreach ($p as $k => $v) if ($v !== '') $pairs[] = $k . '=' . $v;
        return md5(implode('&', $pairs) . $this->config('key'));
    }

    public function pay(array $invoice, string $returnUrl, string $notifyUrl): array
    {
        $due = round((float)$invoice['total'] - (float)$invoice['paid'], 2);
        $p = [
            'pid' => $this->config('pid'), 'out_trade_no' => $invoice['invoice_no'],
            'notify_url' => $notifyUrl, 'return_url' => $returnUrl,
            'name' => '账单 ' . $invoice['invoice_no'], 'money' => number_format($due, 2, '.', ''),
        ];
        $p['sign'] = $this->sign($p);
        $p['sign_type'] = 'MD5';
        return $this->ok(['type' => 'redirect',
            'url' => rtrim($this->config('api_url'), '/') . '/submit.php?' . http_build_query($p)]);
    }

    public function notify(array $params, string $raw = ''): array
    {
        if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS') return $this->fail('未支付');
        if (!hash_equals(strtolower($this->sign($params)), strtolower((string)($params['sign'] ?? '')))) {
            return $this->fail('签名错误');
        }
        return $this->ok([
            'invoice_no' => (string)$params['out_trade_no'],
            'trade_no' => (string)$params['trade_no'],
            'amount' => (float)$params['money'],
        ]);
    }
};
```

### 4.4 回调地址

系统自动提供：

- 异步通知：`https://站点/pay/notify/{plugin_id}`
- 同步返回：`https://站点/pay/return/{plugin_id}`

回调路由不校验登录与 CSRF，必须由插件完成签名验证。入账由系统统一处理，插件不要直接改数据库。

---

## 5. 服务器模块插件

基类：`plugins\Server`。

### 5.1 接口

```php
public function create(array $params): array;
public function suspend(array $params): array;
public function unsuspend(array $params): array;
public function terminate(array $params): array;
public function changePassword(array $params, string $password): array;
public function changePackage(array $params): array;
public function power(array $params, string $action): array;
public function usage(array $params): array;
public function loginUrl(array $params): array;
public function test(): array;
```

`$params` 结构：

```php
[
  'host_id' => 12,
  'domain' => 'example.com',
  'username' => 'example123',
  'password' => '明文密码（已解密）',
  'product_id' => 3,
  'product_name' => '香港云服务器',
  'cycle' => 'monthly',
  'server' => [...],     // ice_server 行
  'config' => [          // 已解密的服务器凭据
      'host' => 'panel.example.com', 'port' => 2087,
      'username' => 'root', 'password' => '...', 'api_key' => '...', 'secure' => 1,
  ],
]
```

`create()` 成功返回：

```php
['ok' => true, 'remote_id' => '123', 'ip' => '1.2.3.4', 'username' => 'root', 'password' => '...']
['ok' => true, 'manual' => true, 'msg' => '等待人工开通']
```

### 5.2 约定

- `remote_id` 会写入 `ice_host.remote_id`，后续 suspend/terminate 通过 `$params['host']['remote_id']` 读取
- `suspend/unsuspend/terminate` 成功后系统会自动更新服务状态
- 人工模块返回 `['ok'=>true,'manual'=>true]`，服务保持待处理
- `loginUrl()` 返回 `['ok'=>true,'url'=>'https://...']` 时，用户点击「登录面板」会 302 跳转
- `test()` 由后台服务器节点「测试」按钮调用

### 5.3 示例

```php
<?php
return new class extends \plugins\Server {
    public function info(): array
    {
        return [
            'id' => 'my_panel', 'title' => 'MyPanel', 'version' => '1.0.0', 'author' => 'Acme',
            'description' => '示例面板模块', 'fields' => [
                ['name' => 'host', 'label' => '地址', 'type' => 'text', 'required' => true],
                ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ],
        ];
    }

    protected function call(string $path, array $data = []): array
    {
        $res = $this->http('POST', 'https://' . $this->config('host') . $path, $data,
            ['Authorization: Bearer ' . $this->config('api_key')], ['verify' => false, 'json' => true]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        if ($res['code'] >= 400) return $this->fail('面板返回 HTTP ' . $res['code']);
        return $this->ok(['data' => $res['json'] ?? []]);
    }

    public function create(array $params): array
    {
        $res = $this->call('/accounts', [
            'domain' => $params['domain'], 'username' => $params['username'], 'password' => $params['password'],
        ]);
        if (!$res['ok']) return $res;
        return $this->ok(['remote_id' => (string)$res['data']['id'], 'ip' => $res['data']['ip'] ?? '']);
    }

    public function suspend(array $params): array
    {
        return $this->call('/accounts/' . $params['host']['remote_id'] . '/suspend');
    }

    public function unsuspend(array $params): array
    {
        return $this->call('/accounts/' . $params['host']['remote_id'] . '/unsuspend');
    }

    public function terminate(array $params): array
    {
        return $this->call('/accounts/' . $params['host']['remote_id'] . '/delete');
    }

    public function test(): array
    {
        $res = $this->call('/ping');
        return $res['ok'] ? ['ok' => true, 'msg' => '连接成功'] : ['ok' => false, 'error' => $res['error']];
    }
};
```

---

## 6. 钩子扩展（addon）

addon 文件直接返回数组：

```php
<?php
use think\Db;
use think\Plugin;

return [
    'info' => [
        'title' => 'Demo Addon',
        'version' => '1.0.0',
        'author' => 'Acme',
        'description' => '演示钩子与配置',
        'fields' => [
            ['name' => 'webhook', 'label' => 'Webhook 地址', 'type' => 'text'],
            ['name' => 'enabled', 'label' => '启用推送', 'type' => 'checkbox'],
        ],
    ],

    'install' => function () {
        // 启用时执行一次，可建表
    },
    'uninstall' => function () {
        // 停用时执行，不要删除用户数据，除非明确提示
    },
    'boot' => function (array $config) {
        // 每次请求启动时执行（可选）
    },
    'hooks' => [
        'order.paid' => function ($data) {
            $cfg = Plugin::config('addon', 'demo_addon');
            if (($cfg['enabled'] ?? '0') !== '1') return;
            log_write('plugin', '订单已支付', $data);
        },
        'cron.run' => function () {
            // 定时任务
        },
    ],
];
```

### 6.1 可用钩子

| 事件 | 参数 |
|---|---|
| `user.registered` | `['user_id','email']` |
| `user.login` | `['user_id','ip']` |
| `user.password_changed` | `['user_id']` |
| `cart.added` | `['product_id','cycle']` |
| `order.created` | `['order_id','order_no','user_id']` |
| `order.paid` | `['order_id','invoice_id']` |
| `order.activated` | `['order_id','user_id']` |
| `invoice.created` | `$invoice` 数组 |
| `invoice.paid` | `['invoice_id','amount','gateway']` |
| `invoice.refunded` | `['invoice_id','amount','reason']` |
| `host.created` | `['host_id','product_id']` |
| `host.provisioned` | `['host_id','result']` |
| `host.create_failed` | `['host_id','error']` |
| `host.suspended` / `host.terminated` / `host.renewed` / `host.upgraded` | `['host_id', ...]` |
| `host.password_changed` | `['host_id']` |
| `domain.registered` | `['domain_id','domain']` |
| `domain.renewed` | `['domain_id','years']` |
| `domain.transferred` | `['domain_id']` |
| `domain.synced` | `['domain_id','data']` |
| `domain.ns_changed` | `['domain_id','ns']` |
| `domain.expired` | `['domain_id']` |
| `domain.register_failed` | `['domain_id','error']` |
| `ticket.created` | `['ticket_id','user_id']` |
| `ticket.replied` | `['ticket_id','admin_id'?]` |
| `ticket.closed` | `['ticket_id']` |
| `affiliate.commission` | `['affiliate_id','order_id','amount']` |
| `balance.changed` | `['user_id','amount','remark']` |
| `admin.login` | `['admin_id']` |
| `cron.run` | `['time']` |

钩子返回值会被收集为数组，异常会被捕获并写入 `data/logs/plugin-*.log`，不会中断主流程。

### 6.2 读取配置

```php
$cfg = \think\Plugin::config('addon', 'your_addon');
```

### 6.3 依赖其他插件

```php
$registrar = \think\Plugin::make('registrar', 'namesilo', $config);
```

---

## 7. 可用服务

插件内可直接使用全局辅助函数：

```php
cfg('site_name');                 // 读取配置
money(99.00);                     // 格式化金额
url('/cart');                     // 生成 URL
abs_url('/pay/notify/x');         // 绝对 URL
now();                            // 当前时间
random_str(32);                   // 随机字符串
log_write('plugin', 'message');  // 写日志
```

常用类：

```php
\think\Db::table('host')->where('id', 1)->find();
\app\service\Mailer::send($to, $subject, $html);
\app\service\Billing::markPaid($invoiceId, 'gateway', $tradeNo, $amount);
\app\service\DomainService::search('example.com');
\app\service\Provision::create($hostId);
```

不要在插件中直接 `echo` 或 `exit`，除非是支付回调的应答（由 `ack()` 统一处理）。

---

## 8. 调试与测试

1. 把文件放入对应目录，后台「插件」会立即出现。
2. 点击「安装启用」，填写配置，点击「测试」。
3. 日志：`data/logs/plugin-YYYY-MM-DD.log`。
4. 注册商：先用 `check()` 验证凭证，再用低价后缀测试注册/续费。
5. 支付：先用沙箱或 0.01 元订单走完整流程，确认异步通知能重复投递而不重复入账。
6. 服务器模块：先「测试」，再用管理员「添加服务」并勾选「立即开通」。

---

## 9. 安全要求

- 所有上游请求必须设置超时（默认 30 秒）
- 验证 TLS 证书；只有自签面板才允许 `verify => false`，并在插件描述中说明
- 不记录完整卡号、密钥、密码到日志
- 回调必须验签；金额、商户号、订单号都要校验
- 不要信任 `$_GET`/`$_POST` 的金额，以本地账单为准
- 文件上传、反序列化、命令执行一律禁止
- 插件代码在站点进程内运行，拥有与主程序相同的数据库权限，请只安装可信插件

---

## 10. 发布清单

- [ ] 文件名与 `info()['id']` 一致
- [ ] `info()` 包含 title/version/author/description/fields
- [ ] 所有方法返回统一数组，不抛未捕获异常
- [ ] 错误信息可读，详细错误写日志
- [ ] 通过后台「测试」
- [ ] 在 README 中说明所需账户权限、IP 白名单、支持的 TLD/通道
- [ ] 声明许可证
- [ ] 不修改核心文件；需要扩展时优先用钩子

---

## 11. 版本与兼容

- 插件 `version` 使用语义化版本
- 破坏性变更请提升主版本号，并在描述中注明最低 idceasy 版本
- 核心钩子名称保持稳定；新增钩子不会移除旧钩子
- 数据库表名统一 `ice_` 前缀；插件自建表建议使用 `ice_插件名_` 前缀

---

## 12. 附：HTTP trait

`plugins\Http` 提供：

```php
$this->http($method, $url, $data, $headers, $options)
// 返回 ['code'=>int,'body'=>string,'json'=>?array,'error'=>string]
// options: timeout, verify, follow, json, raw

$this->postJson($url, $data, $headers);
$this->parseXml($body);      // SimpleXMLElement|null
$this->xmlValue($node, 'xpath', 'default');
$this->ok([...]);            // ['ok'=>true,'error'=>'',...]
$this->fail('message');      // ['ok'=>false,'error'=>'message']
```

需要更复杂的签名（AWS、TC3、RSA）时，在插件内实现，不要修改 trait。

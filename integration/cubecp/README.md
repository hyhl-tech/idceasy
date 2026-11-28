# 魔方财务桥接

idceasy 提供 Bridge v1 接口，供魔方财务（CubeDC）或自研财务系统调用，覆盖客户、产品、订单、账单、服务、域名。

## 一、在 idceasy 中准备

后台「设置 - 接口与自动化」：

- 启用「魔方财务桥接 API」
- 设置 Bridge Key（随机长字符串）

接口地址：`https://你的域名/api/cube`

认证方式二选一：

1. 请求头 `X-ICE-KEY: <Bridge Key>`
2. 请求头 `X-ICE-KEY` + `X-ICE-TIMESTAMP` + `X-ICE-SIGN`
   - `SIGN = HMAC_SHA256(TIMESTAMP + 原始JSON请求体, Bridge Key)`
   - 时间戳有效期 300 秒

## 二、请求格式

```http
POST /api/cube
Content-Type: application/json
X-ICE-KEY: your-key

{"action":"client.create","data":{"email":"a@b.com","name":"张三"}}
```

响应：

```json
{"code":0,"msg":"ok","data":{"id":12,"password":"..."}}
```

`code` 非 0 即为错误，`msg` 为原因。

## 三、动作列表

| action | data | 说明 |
|---|---|---|
| `ping` | - | 连通性 |
| `stats` | - | 客户/订单/服务/域名数量 |
| `client.list` | `limit` | 客户列表 |
| `client.get` | `id` 或 `email` | 客户详情 |
| `client.create` | `email,name,company,phone,country,currency,password?` | 创建客户 |
| `client.update` | `id` + 字段 | 更新客户 |
| `product.list` | - | 产品与周期价格 |
| `order.list` | `limit` | 订单列表 |
| `order.get` | `id` | 订单详情（含项目） |
| `order.create` | `user_id,items[],coupon?,note?` | 下单并生成账单 |
| `invoice.list` | `limit` | 账单列表 |
| `invoice.get` | `id` | 账单详情（含项目） |
| `invoice.create` | `user_id,items[],due_days?,send_mail?` | 手工账单 |
| `invoice.pay` | `invoice_id,amount,trade_no,gateway` | 入账（幂等） |
| `host.list` | `limit` | 服务列表 |
| `host.action` | `id,action,password?` | create/suspend/unsuspend/terminate/password |
| `domain.list` | `limit` | 域名列表 |
| `domain.action` | `id,action,...` | register/renew/sync/ns |

`items` 格式：

```json
[{"product_id":1,"cycle":"annual","qty":1,"domain":"example.com","options":[1,5]}]
```

域名注册：

```json
[{"type":"domain","domain":"example.com","qty":1}]
```

## 四、PHP 客户端

```php
require __DIR__ . '/IdceasyBridge.php';

$ice = new IdceasyBridge('https://idceasy.site', 'your-key', true);

$client = $ice->createClient(['email' => 'a@b.com', 'name' => '张三']);
$order  = $ice->createOrder($client['data']['id'], [
    ['product_id' => 1, 'cycle' => 'annual', 'domain' => 'example.com'],
]);
$ice->payInvoice($order['data']['invoice_id'], 120.00, 'CUBE-20260101-001');
```

## 五、与魔方财务的字段映射

| 魔方财务 | idceasy |
|---|---|
| 客户 ID / 邮箱 | `user.id` / `user.email` |
| 产品 ID | `product.id` |
| 账单 / 订单号 | `invoice.invoice_no` / `order.order_no` |
| 主机 ID | `host.id` |
| 域名 ID | `domain.id` |

建议：

- 用邮箱作为客户唯一键
- 用 `trade_no` 作为支付幂等键，重复回调不会重复入账
- 定时拉取 `invoice.list` 对账，差异以 idceasy 为准
- 不要在两边同时对同一服务执行暂停/删除

## 六、反向同步

如果需要 idceasy 主动推送事件到魔方财务，可开发一个 addon 插件监听：

- `order.paid`
- `invoice.paid`
- `host.provisioned`
- `ticket.created`

参考 `PLUGIN_DEVELOPMENT.md` 第 6 节。

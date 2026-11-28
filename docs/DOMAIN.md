# 域名系统

域名不是“产品”，它有自己的入口、定价与履约流程。

## 1. 前台入口

| 地址 | 说明 |
|---|---|
| `/domains` | 注册商门户：搜索、后缀价格、转入入口 |
| `/domains/search?keyword=example` | 查询结果，支持多后缀 |
| `/domains/register` | POST，加入购物车（不直接扣款） |
| `/domains/transfer` | 转入表单与价格 |
| `/user/domains` | 客户已拥有的域名 |
| `/user/domain/{id}` | NS、EPP、锁、隐私、续费、同步 |

`/domain` 保留为 301 跳转到 `/domains`，旧链接不会失效。

## 2. 价格与比价

两个模式，由后台「设置 - 域名设置」控制：

### 固定价（`domain_compare = 0`）

- 售价取 `domain_tld.register_price / renew_price / transfer_price`
- 下单时使用该 TLD 绑定的注册商
- 上游成本变化不影响售价

### 比价（`domain_compare = 1`）

- 查询时同时询问已启用的注册商，最多 3 家：TLD 绑定的优先，其余按服务后缀数量排序
- 上游返回 `price` 与 `currency`，按 `currency.rate` 换算成本位币
- 售价 = 成本本位币 ×（1 + `domain_markup`%）
- 自动选择成本最低的一家下单
- 上游没有返回价格时，回落到该 TLD 的固定价，并保留原注册商

候选数量限制为 3，是为了避免一次查询打十几个上游。需要比较更多家时，修改 `DomainService::candidates()` 的上限，并接受查询耗时。

### 汇率约定

`ice_currency.rate` 表示 **1 本位币可兑换的外币数量**：

```
USD 0.14  →  100 CNY = 14 USD
USD 成本 8.00 → 8.00 / 0.14 = 57.14 CNY
```

未知货币（例如未配置 RUB）会被视为无法比价，回落到固定价。

### 缓存

同一次请求内，`DomainService::$quoteCache` 会按 `domain|years|模式` 缓存报价。跨请求暂不缓存，避免展示已失效的可注册状态。上游较慢时，建议把 TLD 数量控制在 6 个以内。

## 3. 下单链路

```
/domains/register
  → 服务端重新报价（不信任客户端价格）
  → cart_add(type=domain, registrar=最低价上游, price=报价, qty=年限)
  → /checkout
  → Billing::createOrder 再次报价并写入 order_item
  → 支付成功
  → Billing::activateOrder
  → createDomainRecord（选最低成本注册商）
  → DomainService::register（调用插件）
  → domain.status = active
```

价格在三个位置计算：展示、加入购物车、创建订单。**只有创建订单时的价格会进入账单**，前两步仅用于展示。

### 转入

```
/domains/transfer
  → cart_add(type=transfer, auth_code=EPP, price=transfer_price)
  → 支付成功
  → createDomainRecord(status=transferring, auth_code=...)
  → DomainService::transfer
  → 成功后 status=pending_transfer，等待注册商确认
```

转移密码存在 `order_item.options` JSON 与 `domain.auth_code`，不写日志。

## 4. 注册商插件契约

插件继承 `plugins\Registrar`，`check()` 建议返回：

```php
return $this->ok([
    'available' => true,
    'premium'   => false,
    'price'     => 8.99,      // 你的上游成本价
    'currency'  => 'USD',     // 不填则按注册商默认货币
]);
```

- `price` 是成本价，不是售价；售价由系统按加价规则计算
- 没有 `price` 的插件仍可用于固定价模式
- `premium = true` 时不参与比价，前台显示“请联系客服”
- 注册、续费、转入、NS、EPP、锁、隐私、同步的方法签名见 `plugins/Registrar.php`

## 5. 后台配置

1. 「插件 - 域名注册商」启用至少一家，填写 API 凭据并测试
2. 「商品 - 域名定价」为每个后缀设置注册 / 续费 / 转入价格与绑定注册商
3. 「设置 - 域名设置」：
   - 默认注册商
   - 启用多上游比价
   - 比价加价（%）

开启比价后，前台结果会显示“比价 N 家”和各家报价；关闭后只显示本站定价。

## 6. 常见问题

**Q：查询显示“注册商未启用”？**
A：该 TLD 绑定的注册商没有启用，或没有配置凭据。先在插件页启用并测试。

**Q：比价后价格比固定价低很多？**
A：比价按成本 + 加价计算。如果上游价格本身很低，说明原固定价留了较多毛利。可以调高 `domain_markup`，或关闭比价。

**Q：为什么只比较 3 家？**
A：一次查询要覆盖多个后缀，上游 API 通常 1–3 秒。3 家是速度与覆盖的折中，可按需调整。

**Q：溢价域名怎么处理？**
A：`premium = true` 的域名不自动报价，前台引导联系客服。需要自动化时，可在插件中返回真实溢价并去掉 `premium` 标记。

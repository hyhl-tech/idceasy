# idceasy

开源 IDC 计费与云服务管理系统。官网：[idceasy.site](https://idceasy.site)

idceasy 把客户、产品、订单、账单、域名、主机、工单、插件与开放接口放在同一套系统里。它自带 ThinkPHP 风格的 MVC 内核（`think\` 命名空间，零 Composer 依赖），上传即可运行，同时保留与 ThinkPHP 6/8 一致的控制器、路由、数据库与模板用法，便于二次开发或迁移。

许可证：**AGPL-3.0**。你可以自由使用、修改与商用，但对外提供修改后的网络服务时必须公开源码。

---

## 功能

**计费与财务**

- 产品分组、产品、计费周期（月/季/半年/年/两年/三年）、设置费、可配置选项、库存
- 购物车、优惠码、税费规则、多货币、订单、账单、部分付款、余额、信用额度
- 余额充值（在线支付自动到账）、手动入账、在线退款（退回余额）、交易流水、支付日志
- 自动续费账单、逾期暂停、长期欠费终止、域名到期标记
- 联盟推广：专属链接、注册归因、订单佣金、佣金记录
- 经营报表：月度收入、订单状态、支付渠道、热销产品、客户消费排行

**域名**

- 21 家注册商插件：NameSilo、Namecheap、Name.com、Porkbun、Dynadot、GoDaddy/WildWest、eNom、Internet.bs、Openprovider、Gandi、OVHcloud、OpenSRS、DomainNameAPI、阿里云、腾讯云、REG.RU、Hexonet、RRPproxy、ResellerClub、ResellBiz、NetEarthOne
- 实时查询、注册、续费、转入、EPP、NS 修改、转移锁、隐私保护、状态同步
- TLD 独立定价、溢价域名提示、到期提醒与自动续费账单

**主机与服务器**

- 服务器分组、节点负载、按产品自动选节点
- 模块：WHM/cPanel、cPanel、DirectAdmin、Plesk、Virtualizor、SolusVM、Proxmox VE、人工开通
- 自动开通、暂停/解除、终止、改密、升降配、电源操作、用量查询、面板单点登录（模块支持时）

**运营与内容**

- 工单部门、工单对话、预设回复、邮件通知
- 公告、单页、知识库分类/文章、下载中心、联系表单
- 原生 SMTP（SSL / STARTTLS / AUTH LOGIN）、邮件模板、队列、发送日志
- 前台全部为 `.htm` 模板，后台可在线编辑、备份、切换主题

**开放与集成**

- WHMCS 兼容 API（服务端），配套 WHMCS 服务器模块（客户端）
- 魔方财务桥接 API（Bridge v1），配套 PHP 客户端
- 用户级 REST API v1（API Key）
- 插件体系：注册商、支付网关、服务器模块、钩子扩展
- 定时任务：续费、催缴、暂停、终止、邮件队列、域名同步、日志清理

---

## 环境要求

- PHP **8.1+**，扩展：`pdo_mysql`、`curl`、`mbstring`、`openssl`、`json`、`simplexml`、`dom`
- MySQL **5.7+** 或 MariaDB **10.3+**
- Apache（mod_rewrite）或 Nginx
- 可写目录：`config/`、`data/`、`data/logs/`、`data/cache/`、`template/`、`plugins/`

不需要 Composer，不需要 Node.js，不依赖任何外部 CDN。

---

## 安装

1. 上传全部文件到网站根目录（与 WordPress 类似，`index.php` 位于根目录）。
2. 浏览器访问 `https://你的域名/install.php`。
3. 按向导完成环境检测、数据库配置、站点信息与管理员账户。
4. 安装完成后**删除 `install.php`**（或保留，`install.lock.php` 会阻止重复安装）。
5. 配置计划任务，每分钟执行一次：
   ```
   * * * * * curl -s "https://你的域名/cron.php?token=后台生成的令牌" > /dev/null
   ```
   也可用 CLI：`php /path/to/cron.php`。
6. 后台「设置」中配置 SMTP、支付网关、域名注册商，并在「插件」中启用。

### Apache

项目自带 `.htaccess`。确认 `mod_rewrite` 已开启且 `AllowOverride All`。

### Nginx

参考 `nginx.conf.example`。核心是 `try_files $uri $uri/ /index.php?$query_string;`，并禁止访问 `config/`、`data/`、`core/`、`app/`。

### 目录权限

```bash
chown -R www-data:www-data /var/www/idceasy
chmod -R 755 /var/www/idceasy
chmod -R 775 /var/www/idceasy/{config,data,template,plugins}
```

---

## 目录结构

```
idceasy/
├── index.php                 入口
├── install.php               一键安装向导
├── install.sql               表结构
├── install.data.sql          初始数据
├── cron.php                  计划任务入口
├── config/
│   ├── app.php               应用配置
│   ├── database.php          安装时生成（勿提交）
│   └── routes.php            路由
├── core/                     ThinkPHP 风格内核
│   ├── App.php  Db.php  Router.php  Controller.php
│   ├── View.php  Session.php  Request.php  Response.php
│   └── Plugin.php            插件内核
├── app/
│   ├── common.php            全局辅助函数
│   ├── controller/           前台、后台、API
│   └── service/              计费、域名、开通、邮件、任务
├── plugins/
│   ├── Registrar.php  Gateway.php  Server.php  Http.php
│   ├── registrar/            注册商插件
│   ├── gateway/              支付网关插件
│   ├── server/               服务器模块
│   └── addon/                钩子扩展
├── template/
│   ├── default/              前台主题（全部 .htm）
│   └── admin/                后台主题
├── public/assets/            CSS / JS
├── data/                     日志、缓存、备份（禁止 Web 访问）
└── integration/              WHMCS / 魔方财务对接示例
```

---

## 模板

前台与后台模板均为 `.htm`，后台「模板」可在线编辑，保存时自动备份到 `data/backups/templates/`。

```html
{include header}
<h1>{$title}</h1>
{if $user}
  <p>欢迎，{$user.name}</p>
{else}
  <a href="{url('/login')}">登录</a>
{/if}
{loop $products as $p}
  <a href="{url('/product/' . $p.slug)}">{$p.name}</a>
{/loop}
{:nl2br($article.content)}
{include footer}
```

- `{$var}` 自动 HTML 转义，`{$obj.key}` 支持点式访问
- `{:表达式}` 原样输出（用于可信 HTML）
- `{if}` `{elseif}` `{else}` `{/if}`、`{loop}` `{/loop}`、`{include}`、`{csrf}`
- 修改模板后自动重新编译；主题在「设置 - 站点」切换

---

## 插件

插件放在 `/plugins/{type}/{id}.php`，文件返回一个对象（addon 返回数组）。类型：

| 类型 | 用途 | 基类 |
|---|---|---|
| `registrar` | 域名注册商 | `plugins\Registrar` |
| `gateway` | 支付网关 | `plugins\Gateway` |
| `server` | 主机/服务器开通 | `plugins\Server` |
| `addon` | 钩子扩展 | 返回数组 |

完整规范、方法契约、示例代码与钩子列表见 **[PLUGIN_DEVELOPMENT.md](PLUGIN_DEVELOPMENT.md)**。

---

## API

### WHMCS 兼容 API

`POST /api/whmcs`

| 参数 | 说明 |
|---|---|
| `identifier` / `secret` | 后台「设置 - 接口」中配置 |
| `action` | 操作名 |
| `responsetype` | `json` |

支持：`GetClients`、`GetClientsDetails`、`AddClient`、`UpdateClient`、`GetOrders`、`GetOrder`、`AddOrder`、`AcceptOrder`、`GetInvoices`、`GetInvoice`、`CreateInvoice`、`AddInvoicePayment`、`GetProducts`、`GetTLDPricing`、`GetDomains`、`DomainRegister`、`DomainRenew`、`DomainTransfer`、`GetTickets`、`GetTicket`、`OpenTicket`、`AddTicketReply`、`CreateHost`、`ModuleCreate/Suspend/Unsuspend/Terminate/ChangePassword`、`Ping`。

`integration/whmcs/idceasy.php` 是 WHMCS 服务器模块：把 WHMCS 的服务开通/暂停/删除转发到 idceasy，形成双向链路。

### 魔方财务桥接

`POST /api/cube`，请求头 `X-ICE-KEY`，可选 `X-ICE-SIGN` + `X-ICE-TIMESTAMP`（HMAC-SHA256）。

```json
{"action":"order.create","data":{"user_id":1,"items":[{"product_id":1,"cycle":"annual","domain":"example.com"}]}}
```

支持：`ping`、`stats`、`client.list/get/create/update`、`product.list`、`order.list/get/create`、`invoice.list/get/create/pay`、`host.list/action`、`domain.list/action`。

`integration/cubecp/IdceasyBridge.php` 是可直接引入的客户端类。

### REST API v1

`/api/v1/{action}`，认证：`Authorization: Bearer ice_xxx` 或 `?api_key=ice_xxx`。

支持：`ping`、`profile`、`products`、`services`、`service`、`domains`、`invoices`、`invoice`、`order.create`、`invoice.pay`（仅余额）、`tickets`、`ticket.create`。

---

## 计划任务

`cron.php` 依次执行：

| 任务 | 说明 |
|---|---|
| `invoice` | 提前生成续费账单 |
| `suspend` | 逾期暂停服务 |
| `terminate` | 长期欠费终止 |
| `expire` | 标记过期域名 |
| `mail` | 发送邮件队列 |
| `domain_sync` | 从注册商同步状态 |
| `ticket_close` | 关闭闲置工单 |
| `cleanup` | 清理历史日志 |
| `hook` | 触发 addon 的 `cron.run` |

后台「定时任务」可手动执行并查看日志。

---

## 安全

- PDO 预处理，全部查询参数绑定
- 密码 `password_hash()`（bcrypt），登录失败计数
- 表单 CSRF Token，输出默认转义
- 服务器/主机密码 AES-256-GCM 加密存储（`app_key`）
- 目录级 `.htaccess` 与 Nginx 规则禁止访问配置、数据与内核
- 后台角色权限：`*`、`client.*`、`invoice.view` 等
- 建议：全站 HTTPS、定期备份、删除 `install.php`、关闭 `IDCEASY_DEBUG`

---

## 备份与升级

- 备份数据库与 `config/database.php`、`template/`、`plugins/` 即可完整还原
- 升级前先备份；覆盖程序文件后访问 `/install.php` 不会重装（存在锁文件）
- 数据库变更请自行执行增量 SQL，或联系官方获取升级脚本

---

## 已知边界

- 注册商与支付网关均调用各平台公开 API，实际可用性取决于你的账户权限、IP 白名单与后缀支持；上线前请用测试域名/小额支付验证。
- 不同注册商对联系人、实名模板、价格字段的要求不同，插件已做字段映射，仍可能需要按账户情况调整。
- `think\` 内核实现了本项目所需子集，不等同于官方 ThinkPHP 全量功能；如引入官方框架，请保留控制器与模板约定。

---

## 许可证

GNU Affero General Public License v3.0，见 [LICENSE](LICENSE)。

官网：[idceasy.site](https://idceasy.site)

# WHMCS 双向对接

idceasy 同时提供「服务端 API」与「WHMCS 服务器模块」，形成双向链路：

- **WHMCS → idceasy**：WHMCS 开通、暂停、解除、删除、改密时，调用 idceasy 的 `/api/whmcs`。
- **idceasy → WHMCS**：内置「WHMCS 双向同步」插件，可定时拉取 WHMCS 客户，也可把新注册用户推送到 WHMCS。

## 一、在 idceasy 中准备

1. 后台「设置 - 接口与自动化」：
   - 启用 WHMCS 兼容 API
   - 填写 Identifier、Secret（随机长字符串）
2. 后台「产品」创建一个用于 WHMCS 的产品，记下产品 ID。
3. 如果需要自动开通，确认产品已绑定服务器模块与节点。

## 二、安装 WHMCS 服务器模块

1. 把本目录下的 `idceasy/` 整个文件夹复制到 WHMCS 的 `modules/servers/`。
2. 确认最终路径为 `modules/servers/idceasy/idceasy.php`。
3. WHMCS 后台「系统设置 - 产品/服务」，编辑产品：
   - 模块：`idceasy`
   - API 地址：`https://你的idceasy域名`
   - Identifier / Secret：与 idceasy 后台一致
   - 产品 ID：idceasy 中的产品 ID
   - 自动开通：是
4. 保存后点击「测试连接」。

## 三、支持的模块动作

| WHMCS 动作 | idceasy API | 说明 |
|---|---|---|
| Create | `CreateHost` | 按邮箱找客户；不存在则报错，需先同步客户 |
| Suspend | `ModuleSuspend` | 按域名/用户名定位服务 |
| Unsuspend | `ModuleUnsuspend` | |
| Terminate | `ModuleTerminate` | |
| ChangePassword | `ModuleChangePassword` | |

客户同步有两种方式：

- 在 WHMCS 中先调用 idceasy `AddClient`（或使用同步插件把客户推过去）
- 使用 idceasy 的同步插件从 WHMCS 拉取客户后再开通

## 四、双向同步插件

后台「插件 - 扩展插件」启用 `WHMCS 双向同步`，填写：

- WHMCS 地址
- Identifier / Secret
- 拉取客户：开启后，计划任务会调用 WHMCS `GetClients`，把缺失客户导入 idceasy
- 推送新用户：开启后，idceasy 新注册用户会调用 WHMCS `AddClient`

注意：

- WHMCS 需要在「系统设置 - 接口」中启用 API，并添加调用方 IP 白名单。
- 双向同步只同步客户基础资料，不复制订单与账单，避免重复计费。
- 生产环境建议先关闭推送，仅做拉取，确认无重复邮箱后再开启。

## 五、安全

- 使用 HTTPS
- Identifier/Secret 定期轮换
- 限制 `/api/whmcs` 的来源 IP（Nginx `allow/deny`）
- 不要在两个系统同时对同一客户开通同一服务

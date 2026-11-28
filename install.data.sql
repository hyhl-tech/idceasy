-- idceasy 初始数据
INSERT INTO `ice_config` (`k`,`v`) VALUES
('site_name','idceasy'),
('site_url',''),
('site_slogan','简单、快速、可扩展的 IDC 计费与云服务系统'),
('company_name','idceasy 云计算'),
('company_email','support@idceasy.site'),
('company_phone','400-000-0000'),
('company_address','中国'),
('currency','CNY'),
('url_rewrite','1'),
('theme','default'),
('invoice_due_days','7'),
('invoice_prefix','INV'),
('auto_renew_days','14'),
('suspend_days','3'),
('terminate_days','30'),
('late_fee','0'),
('tax_enabled','0'),
('signup_enabled','1'),
('require_email_verify','0'),
('maintenance','0'),
('mail_driver','smtp'),
('mail_queue','1'),
('smtp_host',''),
('smtp_port','465'),
('smtp_user',''),
('smtp_pass',''),
('smtp_secure','ssl'),
('smtp_from',''),
('smtp_from_name','idceasy'),
('domain_default_registrar','namesilo'),
('domain_markup','0'),
('affiliate_enabled','1'),
('affiliate_rate','10'),
('ticket_auto_close_days','7'),
('api_whmcs_enabled','1'),
('api_cube_enabled','1'),
('api_whmcs_identifier',''),
('api_whmcs_secret',''),
('api_cube_key',''),
('cron_token',''),
('footer_html',''),
('icp',''),
('announce_home_count','3'),
('host_default_suspend_days','3'),
('host_default_terminate_days','30'),
('debug','0');

INSERT INTO `ice_admin_role` (`id`,`name`,`permissions`,`created_at`) VALUES
(1,'超级管理员','[\"*\"]','2026-01-01 00:00:00'),
(2,'客服','[\"dashboard.*\",\"client.*\",\"ticket.*\",\"order.view\",\"invoice.view\",\"domain.view\"]','2026-01-01 00:00:00'),
(3,'财务','[\"dashboard.*\",\"invoice.*\",\"order.*\",\"transaction.*\",\"report.*\",\"client.view\"]','2026-01-01 00:00:00'),
(4,'技术支持','[\"dashboard.*\",\"host.*\",\"server.*\",\"domain.*\",\"ticket.*\",\"product.view\"]','2026-01-01 00:00:00');

INSERT INTO `ice_currency` (`code`,`name`,`symbol`,`rate`,`prefix`,`default`) VALUES
('CNY','人民币','¥',1.0000,1,1),
('USD','美元','$',0.1400,1,0),
('HKD','港币','HK$',1.0800,1,0),
('EUR','欧元','€',0.1300,1,0);

INSERT INTO `ice_product_group` (`id`,`name`,`slug`,`description`,`sort`,`hidden`) VALUES
(1,'云虚拟主机','hosting','全 SSD 高速虚拟主机，支持一键部署与自动备份。',1,0),
(2,'云服务器','cloud','KVM 架构云服务器，独立 IP、快照与自定义镜像。',2,0),
(3,'域名服务','domain','全球 1000+ 后缀域名注册、转入与解析管理。',3,0),
(4,'SSL 证书','ssl','DV / OV / EV 证书，自动化签发与部署。',4,0),
(5,'独立服务器','dedicated','独享物理资源，适合高负载与合规业务。',5,0);

INSERT INTO `ice_product` (`id`,`group_id`,`name`,`slug`,`type`,`description`,`module`,`server_group_id`,`stock`,`auto_setup`,`require_domain`,`status`,`hidden`,`sort`,`created_at`) VALUES
(1,1,'入门虚拟主机','starter','host','5GB SSD、无限流量、免费 SSL、每日备份，适合个人站点与小型企业展示。','cpanel',1,0,1,1,1,0,1,'2026-01-01 00:00:00'),
(2,1,'专业虚拟主机','business','host','30GB SSD、不限站点、免费 CDN、企业邮局与高级防护。','cpanel',1,0,1,1,1,0,2,'2026-01-01 00:00:00'),
(3,2,'香港云服务器','hk-cloud','vps','2 核 4G、60GB SSD、5Mbps 独享带宽、可随时升级配置。','manual',2,20,1,0,1,0,3,'2026-01-01 00:00:00'),
(4,2,'美国云服务器','us-cloud','vps','4 核 8G、120GB SSD、30Mbps 带宽，适合外贸与跨境业务。','manual',2,20,1,0,1,0,4,'2026-01-01 00:00:00'),
(5,3,'域名注册','domain','domain','实时查询 20+ 注册商库存，支持转入、续费、DNS 与隐私保护。','manual',0,0,1,0,1,0,5,'2026-01-01 00:00:00'),
(6,4,'DV SSL 证书','dv-ssl','ssl','单域名 DV 证书，10 分钟签发，浏览器全兼容。','manual',0,0,1,0,1,0,6,'2026-01-01 00:00:00'),
(7,5,'独立服务器','dedicated','dedicated','E5 双路、64G 内存、2×1TB SSD、1Gbps 共享带宽，含 IPMI。','manual',0,3,0,0,1,0,7,'2026-01-01 00:00:00'),
(8,1,'企业邮局','mail','other','自定义域名邮箱，5 用户起，反垃圾与海外中继。','manual',0,0,1,1,1,0,8,'2026-01-01 00:00:00');

INSERT INTO `ice_pricing` (`product_id`,`cycle`,`price`,`setup_fee`,`currency`,`enabled`) VALUES
(1,'monthly',12.00,0,'CNY',1),(1,'quarterly',34.00,0,'CNY',1),(1,'annual',120.00,0,'CNY',1),
(2,'monthly',28.00,0,'CNY',1),(2,'quarterly',80.00,0,'CNY',1),(2,'annual',288.00,0,'CNY',1),
(3,'monthly',59.00,0,'CNY',1),(3,'quarterly',169.00,0,'CNY',1),(3,'annual',599.00,0,'CNY',1),
(4,'monthly',39.00,0,'CNY',1),(4,'quarterly',109.00,0,'CNY',1),(4,'annual',399.00,0,'CNY',1),
(5,'annual',78.00,0,'CNY',1),
(6,'annual',199.00,0,'CNY',1),
(7,'monthly',899.00,0,'CNY',1),
(8,'monthly',15.00,0,'CNY',1),(8,'annual',150.00,0,'CNY',1);

INSERT INTO `ice_product_option` (`id`,`product_id`,`name`,`type`,`required`,`sort`) VALUES
(1,1,'机房线路','select',1,1),
(2,3,'内存配置','select',1,1),
(3,3,'系统镜像','select',1,2),
(4,2,'附加服务','checkbox',0,1);

INSERT INTO `ice_product_option_value` (`option_id`,`name`,`price`,`setup_fee`,`sort`) VALUES
(1,'华东电信',0,0,1),(1,'华南联通',0,0,2),(1,'香港 BGP',20,0,3),
(2,'2 核 4G',0,0,1),(2,'4 核 8G',100,0,2),(2,'8 核 16G',260,0,3),
(3,'Debian 12',0,0,1),(3,'Ubuntu 24.04',0,0,2),(3,'CentOS Stream 9',0,0,3),(3,'Windows Server 2022',50,0,4),
(4,'每日备份',10,0,1),(4,'独立 IP',30,0,2),(4,'企业邮局',15,0,3);

INSERT INTO `ice_domain_tld` (`tld`,`registrar`,`register_price`,`renew_price`,`transfer_price`,`currency`,`status`,`sort`) VALUES
('com','namesilo',78.00,88.00,78.00,'CNY',1,1),
('net','namesilo',88.00,98.00,88.00,'CNY',1,2),
('org','namesilo',98.00,108.00,98.00,'CNY',1,3),
('cn','aliyun',29.00,39.00,29.00,'CNY',1,4),
('com.cn','aliyun',29.00,39.00,29.00,'CNY',1,5),
('io','namecheap',268.00,298.00,268.00,'CNY',1,6),
('cc','dynadot',58.00,68.00,58.00,'CNY',1,7),
('xyz','namesilo',12.00,68.00,12.00,'CNY',1,8),
('top','aliyun',9.00,39.00,9.00,'CNY',1,9),
('site','namesilo',15.00,88.00,15.00,'CNY',1,10),
('online','namesilo',15.00,88.00,15.00,'CNY',1,11),
('info','namesilo',25.00,88.00,25.00,'CNY',1,12),
('me','namecheap',68.00,78.00,68.00,'CNY',1,13),
('co','namecheap',168.00,188.00,168.00,'CNY',1,14),
('biz','namesilo',45.00,58.00,45.00,'CNY',1,15),
('vip','aliyun',28.00,39.00,28.00,'CNY',1,16),
('club','namesilo',45.00,58.00,45.00,'CNY',1,17),
('shop','namesilo',25.00,48.00,25.00,'CNY',1,18),
('ltd','namesilo',35.00,48.00,35.00,'CNY',1,19),
('cloud','namesilo',68.00,88.00,68.00,'CNY',1,20),
('app','namecheap',88.00,108.00,88.00,'CNY',1,21),
('dev','namecheap',88.00,108.00,88.00,'CNY',1,22),
('tech','namesilo',45.00,58.00,45.00,'CNY',1,23);

INSERT INTO `ice_server_group` (`id`,`name`,`created_at`) VALUES (1,'默认节点','2026-01-01 00:00:00'),(2,'云服务器节点','2026-01-01 00:00:00');

INSERT INTO `ice_ticket_dept` (`id`,`name`,`email`,`description`,`sort`) VALUES
(1,'技术支持','support@idceasy.site','产品使用、故障排查与配置协助',1),
(2,'财务部门','billing@idceasy.site','付款、发票、退款与账单问题',2),
(3,'销售咨询','sales@idceasy.site','售前咨询、批量采购与代理合作',3);

INSERT INTO `ice_canned_reply` (`title`,`message`,`created_at`) VALUES
('已收到工单','您好，我们已收到您的工单，工程师正在处理，通常会在 30 分钟内回复。','2026-01-01 00:00:00'),
('需要更多信息','您好，为了更快定位问题，请提供订单号、域名或服务器 IP，以及具体的报错截图。','2026-01-01 00:00:00'),
('续费提醒','您好，该服务将于近期到期，建议提前续费以免影响使用。您可以在会员中心的服务列表直接续费。','2026-01-01 00:00:00');

INSERT INTO `ice_email_template` (`code`,`name`,`subject`,`body`,`enabled`) VALUES
('welcome','欢迎邮件','欢迎加入 {{site_name}}','<p>您好 {{name}}，</p><p>感谢注册 {{site_name}}。您的账户已开通，登录地址：<a href="{{site_url}}/login">{{site_url}}/login</a></p><p>如有任何问题，回复本邮件即可。</p>',1),
('verify','邮箱验证','请验证您的邮箱','<p>您好 {{name}}，</p><p>请点击以下链接完成邮箱验证：</p><p><a href="{{url}}">{{url}}</a></p><p>链接 24 小时内有效。</p>',1),
('reset','密码重置','重置您的密码','<p>您好 {{name}}，</p><p>请点击以下链接重置密码（1 小时内有效）：</p><p><a href="{{url}}">{{url}}</a></p><p>如果不是您本人操作，请忽略本邮件。</p>',1),
('invoice_created','账单创建','账单 {{invoice_no}} 待支付','<p>您好 {{name}}，</p><p>您有一张新账单 <strong>{{invoice_no}}</strong>，金额 {{total}}，到期日 {{due_at}}。</p><p><a href="{{url}}">立即支付</a></p>',1),
('payment_received','付款成功','付款成功：{{invoice_no}}','<p>您好 {{name}}，</p><p>我们已收到您的付款 {{total}}，账单 {{invoice_no}} 已结清。</p><p>感谢您的支持。</p>',1),
('host_created','主机开通','您的服务已开通','<p>您好 {{name}}，</p><p>服务 {{domain}} 已开通。</p><p>面板地址：<a href="{{panel}}">{{panel}}</a><br>用户名：{{username}}<br>密码：{{password}}</p>',1),
('domain_expiry','域名到期提醒','域名 {{domain}} 即将到期','<p>您好 {{name}}，</p><p>域名 <strong>{{domain}}</strong> 将于 {{expire_date}} 到期，请及时续费。</p><p><a href="{{url}}">续费域名</a></p>',1),
('ticket_reply','工单回复','工单 #{{ticket_id}} 有新回复','<p>您好 {{name}}，</p><p>您的工单 <strong>{{subject}}</strong> 有新的回复：</p><blockquote>{{message}}</blockquote><p><a href="{{url}}">查看工单</a></p>',1),
('order_paid','订单完成','订单 {{order_no}} 已处理','<p>您好 {{name}}，</p><p>订单 {{order_no}} 已完成，共 {{total}}。</p><p><a href="{{url}}">查看详情</a></p>',1);

INSERT INTO `ice_page` (`title`,`slug`,`content`,`status`,`created_at`) VALUES
('关于我们','about','<h2>关于 idceasy</h2><p>idceasy 是一套开源的 IDC 计费与云服务管理系统，采用 AGPL-3.0 许可发布。我们相信托管业务的基础设施应当简单、透明、可自行掌控。</p><p>系统原生支持 20 余家域名注册商、主流支付网关与服务器面板，并提供完整的插件开发规范。</p>',1,'2026-01-01 00:00:00'),
('服务条款','terms','<h2>服务条款</h2><p>使用本平台即表示您同意遵守当地法律法规，不得利用本服务从事违法活动。禁止发送垃圾邮件、发起攻击、传播恶意软件或侵犯他人知识产权。</p><p>我们保留在欠费、滥用或司法要求下暂停服务的权利。</p>',1,'2026-01-01 00:00:00'),
('隐私政策','privacy','<h2>隐私政策</h2><p>我们仅收集提供服务所必需的信息，包括账户资料、联系方式、账单记录与必要的访问日志。我们不会出售您的个人数据。</p><p>支付信息由持牌支付机构处理，我们不存储完整卡号。</p>',1,'2026-01-01 00:00:00'),
('服务等级协议','sla','<h2>服务等级协议</h2><p>我们承诺网络与电力可用性不低于 99.9%。因计划维护、不可抗力或用户自身原因导致的中断不计入赔付范围。</p><p>符合条件的故障可按实际中断时长申请服务补偿。</p>',1,'2026-01-01 00:00:00'),
('联系我们','contact','<h2>联系我们</h2><p>售前与技术支持：support@idceasy.site<br>财务与发票：billing@idceasy.site</p><p>工作时间：周一至周日 09:00 - 22:00</p>',1,'2026-01-01 00:00:00');

INSERT INTO `ice_kb_category` (`id`,`name`,`slug`,`sort`) VALUES
(1,'快速开始','getting-started',1),(2,'域名与解析','domain',2),(3,'账单与支付','billing',3);

INSERT INTO `ice_kb_article` (`category_id`,`title`,`slug`,`content`,`views`,`sort`,`created_at`) VALUES
(1,'如何绑定域名到虚拟主机','bind-domain','<p>1. 在会员中心复制主机的服务器 IP。<br>2. 到域名服务商处添加 A 记录指向该 IP。<br>3. 等待 DNS 生效（通常 10 分钟）。<br>4. 在主机面板中添加域名并签发免费 SSL。</p>',0,1,'2026-01-01 00:00:00'),
(1,'云服务器如何重置密码','reset-root','<p>进入会员中心 - 我的服务 - 云服务器 - 管理，点击「重置密码」，输入新密码后重启实例即可生效。</p>',0,2,'2026-01-01 00:00:00'),
(2,'域名转入需要准备什么','domain-transfer','<p>请准备：原注册商的转移密码（EPP Code）、域名未过期、距到期超过 15 天、关闭隐私保护（部分后缀）。转入通常 5-7 天完成，期间解析不受影响。</p>',0,1,'2026-01-01 00:00:00'),
(2,'如何修改域名的 NS 记录','change-ns','<p>会员中心 - 我的域名 - 管理 - DNS 服务器，填写至少两组 NS 地址后保存。修改后全球生效时间为 24-48 小时。</p>',0,2,'2026-01-01 00:00:00'),
(3,'支持哪些付款方式','payment-methods','<p>支持账户余额、支付宝、微信支付、PayPal、Stripe、易支付等，具体以结算页面展示为准。企业客户可申请对公转账与月结。</p>',0,1,'2026-01-01 00:00:00'),
(3,'如何申请发票','invoice-request','<p>在会员中心 - 账单页面点击「申请发票」，填写抬头与税号，我们会在 3 个工作日内开具电子发票并发送到您的邮箱。</p>',0,2,'2026-01-01 00:00:00');

INSERT INTO `ice_announcement` (`title`,`slug`,`content`,`published`,`views`,`created_at`) VALUES
('idceasy 1.0 正式发布','idceasy-1-0','<p>经过持续打磨，idceasy 1.0 正式发布。本次版本包含完整计费、域名、主机、工单、插件市场与双向对接能力。</p><p>欢迎在 GitHub 上提交 Issue 与 Pull Request。</p>',1,0,'2026-01-10 10:00:00'),
('香港节点网络升级完成','hk-network-upgrade','<p>香港机房已完成核心交换升级，峰值带宽提升至 200Gbps，晚高峰丢包率显著下降。现有用户无需任何操作。</p>',1,0,'2026-02-02 09:30:00');

INSERT INTO `ice_coupon` (`code`,`type`,`value`,`min_total`,`uses`,`max_uses`,`start_at`,`end_at`,`status`,`created_at`) VALUES
('WELCOME10','percent',10.00,0.00,0,0,NULL,NULL,1,'2026-01-01 00:00:00'),
('NEW20','fixed',20.00,100.00,0,100,NULL,NULL,1,'2026-01-01 00:00:00');

INSERT INTO `ice_tax_rule` (`name`,`country`,`rate`,`priority`,`status`) VALUES ('中国大陆增值税','CN',6.00,1,0);

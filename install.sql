-- idceasy 数据库结构 (MySQL 5.7+ / MariaDB 10.3+)
-- 表前缀 ice_ ，字符集 utf8mb4

CREATE TABLE IF NOT EXISTS `ice_config` (
  `k` varchar(64) NOT NULL,
  `v` longtext,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_admin_role` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `permissions` text,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_admin` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(50) DEFAULT '',
  `email` varchar(120) DEFAULT '',
  `role_id` int unsigned DEFAULT 1,
  `status` tinyint NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_user` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(120) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(60) DEFAULT '',
  `company` varchar(120) DEFAULT '',
  `phone` varchar(40) DEFAULT '',
  `country` varchar(60) DEFAULT 'CN',
  `province` varchar(60) DEFAULT '',
  `city` varchar(60) DEFAULT '',
  `address` varchar(255) DEFAULT '',
  `postcode` varchar(20) DEFAULT '',
  `currency` varchar(10) DEFAULT 'CNY',
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `credit_limit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` tinyint NOT NULL DEFAULT 1,
  `email_verified` tinyint NOT NULL DEFAULT 0,
  `verify_token` varchar(64) DEFAULT '',
  `reset_token` varchar(64) DEFAULT '',
  `reset_expire` datetime DEFAULT NULL,
  `api_key` varchar(64) DEFAULT '',
  `affiliate_code` varchar(32) DEFAULT '',
  `referred_by` int unsigned DEFAULT 0,
  `lang` varchar(10) DEFAULT 'zh-cn',
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_email` (`email`),
  KEY `idx_user_api` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_user_login_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `ip` varchar(45) DEFAULT '',
  `ua` varchar(255) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_log_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_user_address` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `label` varchar(50) DEFAULT '',
  `firstname` varchar(60) DEFAULT '',
  `lastname` varchar(60) DEFAULT '',
  `company` varchar(120) DEFAULT '',
  `email` varchar(120) DEFAULT '',
  `phone` varchar(40) DEFAULT '',
  `address1` varchar(255) DEFAULT '',
  `address2` varchar(255) DEFAULT '',
  `city` varchar(60) DEFAULT '',
  `state` varchar(60) DEFAULT '',
  `postcode` varchar(20) DEFAULT '',
  `country` varchar(60) DEFAULT 'CN',
  `is_default` tinyint NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_addr_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_product_group` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `slug` varchar(80) DEFAULT '',
  `description` text,
  `sort` int NOT NULL DEFAULT 0,
  `hidden` tinyint NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_product` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(120) NOT NULL,
  `slug` varchar(120) DEFAULT '',
  `type` varchar(20) NOT NULL DEFAULT 'host',
  `description` text,
  `module` varchar(30) DEFAULT 'manual',
  `server_group_id` int unsigned DEFAULT 0,
  `stock` int NOT NULL DEFAULT 0,
  `auto_setup` tinyint NOT NULL DEFAULT 1,
  `require_domain` tinyint NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `hidden` tinyint NOT NULL DEFAULT 0,
  `sort` int NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_pricing` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `cycle` varchar(20) NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `setup_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'CNY',
  `enabled` tinyint NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_price_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_product_option` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `name` varchar(80) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'select',
  `required` tinyint NOT NULL DEFAULT 0,
  `sort` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_opt_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_product_option_value` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `option_id` int unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `setup_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sort` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_optv_option` (`option_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_coupon` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'percent',
  `value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `min_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `uses` int NOT NULL DEFAULT 0,
  `max_uses` int NOT NULL DEFAULT 0,
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_coupon_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(40) NOT NULL,
  `user_id` int unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'CNY',
  `coupon_id` int unsigned DEFAULT 0,
  `note` text,
  `ip` varchar(45) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_order_user` (`user_id`),
  KEY `idx_order_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_order_item` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `domain` varchar(255) DEFAULT '',
  `type` varchar(20) DEFAULT 'host',
  `qty` int NOT NULL DEFAULT 1,
  `cycle` varchar(20) DEFAULT 'monthly',
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `setup_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `options` text,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `host_id` int unsigned DEFAULT 0,
  `domain_id` int unsigned DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_item_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_invoice` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(40) NOT NULL,
  `user_id` int unsigned NOT NULL,
  `order_id` int unsigned DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'unpaid',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'CNY',
  `due_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `note` text,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invoice_no` (`invoice_no`),
  KEY `idx_invoice_user` (`user_id`),
  KEY `idx_invoice_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_invoice_item` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int unsigned NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `qty` int NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_invitem_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_transaction` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `invoice_id` int unsigned DEFAULT 0,
  `gateway` varchar(30) DEFAULT '',
  `trade_no` varchar(120) DEFAULT '',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'CNY',
  `status` varchar(20) DEFAULT 'success',
  `remark` varchar(255) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tx_user` (`user_id`),
  KEY `idx_tx_invoice` (`invoice_id`),
  UNIQUE KEY `uk_tx_trade` (`trade_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_payment_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int unsigned DEFAULT 0,
  `gateway` varchar(30) DEFAULT '',
  `action` varchar(30) DEFAULT '',
  `request` mediumtext,
  `response` mediumtext,
  `ip` varchar(45) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_paylog_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_domain_tld` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tld` varchar(30) NOT NULL,
  `registrar` varchar(30) NOT NULL DEFAULT 'namesilo',
  `register_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `renew_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `transfer_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'CNY',
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tld` (`tld`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_domain` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `order_id` int unsigned DEFAULT 0,
  `domain` varchar(255) NOT NULL,
  `tld` varchar(30) DEFAULT '',
  `registrar` varchar(30) DEFAULT '',
  `registrar_domain_id` varchar(120) DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `reg_date` datetime DEFAULT NULL,
  `expire_date` datetime DEFAULT NULL,
  `next_due` datetime DEFAULT NULL,
  `auto_renew` tinyint NOT NULL DEFAULT 1,
  `lock` tinyint NOT NULL DEFAULT 1,
  `privacy` tinyint NOT NULL DEFAULT 0,
  `auth_code` varchar(120) DEFAULT '',
  `ns` text,
  `dns` text,
  `cycle` varchar(20) NOT NULL DEFAULT 'annual',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'CNY',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain_name` (`domain`),
  KEY `idx_domain_user` (`user_id`),
  KEY `idx_domain_expire` (`expire_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_server_group` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_server` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `module` varchar(30) NOT NULL DEFAULT 'manual',
  `group_id` int unsigned DEFAULT 0,
  `host` varchar(255) DEFAULT '',
  `port` int NOT NULL DEFAULT 0,
  `username` varchar(120) DEFAULT '',
  `password` text,
  `api_key` text,
  `secure` tinyint NOT NULL DEFAULT 1,
  `status` tinyint NOT NULL DEFAULT 1,
  `max_accounts` int NOT NULL DEFAULT 0,
  `notes` text,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_host` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `order_id` int unsigned DEFAULT 0,
  `product_id` int unsigned DEFAULT 0,
  `server_id` int unsigned DEFAULT 0,
  `domain` varchar(255) DEFAULT '',
  `username` varchar(120) DEFAULT '',
  `password` text,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `module` varchar(30) DEFAULT 'manual',
  `cycle` varchar(20) NOT NULL DEFAULT 'monthly',
  `remote_id` varchar(120) DEFAULT '',
  `ip` varchar(60) DEFAULT '',
  `notes` text,
  `next_due` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_host_user` (`user_id`),
  KEY `idx_host_server` (`server_id`),
  KEY `idx_host_due` (`next_due`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_ticket_dept` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `email` varchar(120) DEFAULT '',
  `description` varchar(255) DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_ticket` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `dept_id` int unsigned NOT NULL DEFAULT 1,
  `subject` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `priority` varchar(20) NOT NULL DEFAULT 'normal',
  `last_reply_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_user` (`user_id`),
  KEY `idx_ticket_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_ticket_reply` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int unsigned NOT NULL,
  `user_id` int unsigned DEFAULT 0,
  `admin_id` int unsigned DEFAULT 0,
  `message` mediumtext,
  `attachment` varchar(255) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reply_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_canned_reply` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(120) NOT NULL,
  `message` text,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_announcement` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT '',
  `content` mediumtext,
  `published` tinyint NOT NULL DEFAULT 1,
  `views` int NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_kb_category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `slug` varchar(80) DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_kb_article` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT '',
  `content` mediumtext,
  `views` int NOT NULL DEFAULT 0,
  `sort` int NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kb_cat` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_download` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `file` varchar(255) DEFAULT '',
  `size` int NOT NULL DEFAULT 0,
  `description` text,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_page` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `content` mediumtext,
  `status` tinyint NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_page_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_email_template` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL,
  `name` varchar(120) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` mediumtext,
  `enabled` tinyint NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tpl_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_email_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(120) NOT NULL,
  `subject` varchar(255) DEFAULT '',
  `body` mediumtext,
  `status` varchar(20) DEFAULT 'success',
  `error` varchar(255) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_email_queue` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(120) NOT NULL,
  `subject` varchar(255) DEFAULT '',
  `body` mediumtext,
  `status` varchar(20) DEFAULT 'pending',
  `attempts` int NOT NULL DEFAULT 0,
  `last_error` varchar(255) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_queue_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_plugin` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(20) NOT NULL,
  `name` varchar(60) NOT NULL,
  `title` varchar(120) DEFAULT '',
  `version` varchar(30) DEFAULT '1.0',
  `status` tinyint NOT NULL DEFAULT 0,
  `config` mediumtext,
  `sort` int NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugin` (`type`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_plugin_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `plugin` varchar(80) DEFAULT '',
  `event` varchar(80) DEFAULT '',
  `data` mediumtext,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_admin_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned DEFAULT 0,
  `action` varchar(120) DEFAULT '',
  `detail` text,
  `ip` varchar(45) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admlog_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_affiliate` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `code` varchar(32) NOT NULL,
  `rate` decimal(6,2) NOT NULL DEFAULT 10.00,
  `visits` int NOT NULL DEFAULT 0,
  `signups` int NOT NULL DEFAULT 0,
  `earnings` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` tinyint NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_aff_code` (`code`),
  UNIQUE KEY `uk_aff_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_affiliate_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `affiliate_id` int unsigned NOT NULL,
  `order_id` int unsigned DEFAULT 0,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `type` varchar(20) DEFAULT 'commission',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_cron_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `task` varchar(80) NOT NULL,
  `status` varchar(20) DEFAULT 'success',
  `output` text,
  `duration` decimal(10,3) NOT NULL DEFAULT 0.000,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_api_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT 0,
  `key_name` varchar(80) DEFAULT '',
  `method` varchar(10) DEFAULT '',
  `path` varchar(255) DEFAULT '',
  `ip` varchar(45) DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_currency` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(60) NOT NULL,
  `symbol` varchar(10) NOT NULL,
  `rate` decimal(12,4) NOT NULL DEFAULT 1.0000,
  `prefix` tinyint NOT NULL DEFAULT 1,
  `default` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_currency_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ice_tax_rule` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `country` varchar(60) DEFAULT '',
  `rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `priority` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

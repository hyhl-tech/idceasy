<?php
/**
 * idceasy - 全局辅助函数
 * 前台模板与插件均可直接调用。
 */
use think\Config;
use think\Db;
use think\Request;
use think\Response;
use think\Session;
use think\Plugin;

/* ------------------------------------------------------------------
 * 配置
 * ------------------------------------------------------------------ */
function cfg(?string $key = null, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (Db::table('config')->select() as $row) $cache[$row['k']] = $row['v'];
        } catch (\Throwable $e) {
            $cache = [];
        }
    }
    if ($key === null) return $cache;
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function cfg_set(string $key, $value): void
{
    $exists = Db::table('config')->where('k', $key)->find();
    if ($exists) Db::table('config')->where('k', $key)->update(['v' => (string)$value]);
    else Db::table('config')->insert(['k' => $key, 'v' => (string)$value]);
}

function db(string $table)
{
    return Db::table($table);
}

/* ------------------------------------------------------------------
 * 输出与 URL
 * ------------------------------------------------------------------ */
function e($value): string
{
    if (is_array($value) || is_object($value)) return '';
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '', array $params = []): string
{
    $base = Request::instance()->base();
    $path = '/' . ltrim($path, '/');
    if ($path === '/') {
        $href = $base . '/';
    } else {
        $rewrite = (string)cfg('url_rewrite', '1') === '1';
        $href = $base . ($rewrite ? '' : '/index.php') . $path;
    }
    if ($params) $href .= (str_contains($href, '?') ? '&' : '?') . http_build_query($params);
    return $href;
}

function admin_url(string $path = '', array $params = []): string
{
    return url('/admin' . '/' . ltrim($path, '/'), $params);
}

function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $ver = is_file($file) ? filemtime($file) : 1;
    return Request::instance()->base() . '/public/assets/' . ltrim($path, '/') . '?v=' . $ver;
}

function redirect(string $path): Response
{
    return Response::redirect(str_starts_with($path, 'http') ? $path : url($path));
}

/* ------------------------------------------------------------------
 * 当前用户 / 管理员
 * ------------------------------------------------------------------ */
function current_user(bool $refresh = false): ?array
{
    static $user = null, $loaded = false;
    if ($refresh) { $loaded = false; $user = null; }
    if ($loaded) return $user;
    $loaded = true;
    $uid = (int)Session::get('uid');
    if (!$uid) return null;
    try {
        $user = Db::table('user')->where('id', $uid)->find();
    } catch (\Throwable $e) {
        return null;
    }
    if (!$user || (int)$user['status'] !== 1) {
        $user = null;
        Session::forget('uid');
    }
    return $user;
}

function current_admin(bool $refresh = false): ?array
{
    static $admin = null, $loaded = false;
    if ($refresh) { $loaded = false; $admin = null; }
    if ($loaded) return $admin;
    $loaded = true;
    $aid = (int)Session::get('aid');
    if (!$aid) return null;
    try {
        $admin = Db::table('admin')->where('id', $aid)->find();
    } catch (\Throwable $e) {
        return null;
    }
    if (!$admin || (int)$admin['status'] !== 1) {
        $admin = null;
        Session::forget('aid');
    }
    return $admin;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/* ------------------------------------------------------------------
 * 金额与货币
 * ------------------------------------------------------------------ */
function currency_info(?string $code = null): array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        try {
            foreach (Db::table('currency')->select() as $c) $all[$c['code']] = $c;
        } catch (\Throwable $e) {}
    }
    $code = $code ?: (current_user()['currency'] ?? cfg('currency', 'CNY'));
    $c = $all[$code] ?? null;
    if (!$c) $c = ['code' => $code, 'symbol' => '¥', 'rate' => 1, 'prefix' => 1];
    $c['rate'] = (float)$c['rate'] ?: 1;
    return $c;
}

function money($amount, ?string $code = null): string
{
    $c = currency_info($code);
    $amount = (float)$amount;
    $formatted = number_format($amount, 2, '.', ',');
    return (int)$c['prefix'] === 1 ? $c['symbol'] . $formatted : $formatted . ' ' . $c['symbol'];
}

function cycle_text(string $cycle): string
{
    return [
        'monthly' => '月付', 'quarterly' => '季付', 'semiannual' => '半年付',
        'annual' => '年付', 'biennial' => '两年付', 'triennial' => '三年付',
        'onetime' => '一次性',
    ][$cycle] ?? $cycle;
}

function cycle_months(string $cycle): int
{
    return ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12,
        'biennial' => 24, 'triennial' => 36, 'onetime' => 0][$cycle] ?? 1;
}

function cycles(): array
{
    return ['monthly', 'quarterly', 'semiannual', 'annual', 'biennial', 'triennial'];
}

function format_bytes($bytes, int $precision = 2): string
{
    $bytes = (float)$bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, $precision) . ' ' . $units[$i];
}

function status_text(?string $status): string
{
    return [
        'pending' => '待处理', 'unpaid' => '未支付', 'paid' => '已支付', 'active' => '正常',
        'suspended' => '已暂停', 'terminated' => '已终止', 'cancelled' => '已取消',
        'refunded' => '已退款', 'fraud' => '风险订单', 'open' => '待回复', 'answered' => '已回复',
        'customer_reply' => '客户回复', 'closed' => '已关闭', 'expired' => '已过期',
        'transferred' => '已转出', 'pending_transfer' => '转入中', 'failed' => '失败',
        'success' => '成功', 'completed' => '已完成', 'processing' => '处理中',
        'unverified' => '未验证', 'verified' => '已验证', 'disabled' => '已禁用',
    ][$status] ?? (string)$status;
}

function status_class(?string $status): string
{
    return [
        'paid' => 'ok', 'active' => 'ok', 'success' => 'ok', 'completed' => 'ok', 'answered' => 'ok',
        'unpaid' => 'warn', 'pending' => 'warn', 'open' => 'warn', 'processing' => 'warn',
        'customer_reply' => 'warn', 'pending_transfer' => 'warn',
        'suspended' => 'danger', 'terminated' => 'danger', 'cancelled' => 'muted',
        'refunded' => 'muted', 'fraud' => 'danger', 'failed' => 'danger', 'expired' => 'danger',
        'closed' => 'muted',
    ][$status] ?? 'muted';
}

/* ------------------------------------------------------------------
 * 编号 / 字符串 / 时间
 * ------------------------------------------------------------------ */
function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function random_str(int $len = 16, string $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'): string
{
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, $max)];
    return $out;
}

function order_no(): string
{
    return 'O' . date('YmdHis') . random_int(1000, 9999);
}

function invoice_no(): string
{
    return 'INV' . date('Ymd') . random_int(10000, 99999);
}

function generate_password(int $len = 12): string
{
    return random_str($len, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%');
}

function mask_email(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') return $email;
    $keep = max(1, min(2, strlen($name)));
    return substr($name, 0, $keep) . '***@' . $domain;
}

function first_char(string $text): string
{
    return mb_strtoupper(mb_substr(trim($text), 0, 1));
}

function client_ip(): string
{
    return Request::instance()->ip();
}

function next_due(string $cycle, ?string $from = null): string
{
    $months = cycle_months($cycle);
    $ts = $from ? strtotime($from) : time();
    if ($months <= 0) return date('Y-m-d H:i:s', $ts);
    return date('Y-m-d H:i:s', strtotime('+' . $months . ' months', $ts));
}

/* ------------------------------------------------------------------
 * CSRF / 闪存
 * ------------------------------------------------------------------ */
function csrf_token(): string
{
    return Session::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Session::token()) . '">';
}

function old(string $key, $default = '')
{
    $old = Session::get('_old', []);
    return $old[$key] ?? $default;
}

/* ------------------------------------------------------------------
 * 购物车
 * ------------------------------------------------------------------ */
function cart(): array
{
    return Session::get('cart', []);
}

function cart_save(array $items): void
{
    Session::set('cart', $items);
}

function cart_add(array $item): string
{
    $item += ['qty' => 1, 'options' => [], 'domain' => '', 'cycle' => 'monthly', 'type' => 'product'];
    $key = substr(md5($item['type'] . '|' . ($item['product_id'] ?? 0) . '|' . ($item['domain'] ?? '') . '|' . ($item['cycle'] ?? '') . '|' . ($item['registrar'] ?? '') . '|' . ($item['auth_code'] ?? '') . '|' . json_encode($item['options'] ?? [])), 0, 12);
    $items = cart();
    if (isset($items[$key])) $items[$key]['qty'] += (int)$item['qty'];
    else $items[$key] = $item + ['key' => $key];
    cart_save($items);
    return $key;
}

function cart_update(string $key, int $qty): void
{
    $items = cart();
    if (isset($items[$key])) {
        if ($qty <= 0) unset($items[$key]);
        else $items[$key]['qty'] = $qty;
        cart_save($items);
    }
}

function cart_remove(string $key): void
{
    $items = cart();
    unset($items[$key]);
    cart_save($items);
}

function cart_clear(): void
{
    Session::forget('cart');
}

function cart_count(): int
{
    $n = 0;
    foreach (cart() as $item) $n += (int)($item['qty'] ?? 1);
    return $n;
}

function cart_total(): float
{
    $total = 0;
    foreach (cart() as $item) {
        if (($item['type'] ?? '') === 'domain') {
            $total += (float)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
            continue;
        }
        $p = product_price((int)($item['product_id'] ?? 0), $item['cycle'] ?? 'monthly');
        $total += ((float)$p['price'] + (float)$p['setup_fee']) * (int)($item['qty'] ?? 1);
    }
    return $total;
}

function product_price(int $productId, string $cycle): array
{
    $row = Db::table('pricing')->where(['product_id' => $productId, 'cycle' => $cycle, 'enabled' => 1])->find();
    if (!$row) $row = Db::table('pricing')->where(['product_id' => $productId, 'enabled' => 1])->order('price')->find();
    return ['price' => (float)($row['price'] ?? 0), 'setup_fee' => (float)($row['setup_fee'] ?? 0), 'currency' => $row['currency'] ?? 'CNY'];
}

/* ------------------------------------------------------------------
 * 导航 / 分页 / 钩子
 * ------------------------------------------------------------------ */
function nav_menu(): array
{
    static $menu = null;
    if ($menu !== null) return $menu;
    $menu = [];
    try {
        foreach (Db::table('product_group')->where('hidden', 0)->order('sort')->select() as $g) {
            $menu[] = ['title' => $g['name'], 'url' => url('/products', ['group' => $g['id']])];
        }
        $menu[] = ['title' => '域名注册', 'url' => url('/domains')];
        // 单页放在页脚，避免顶部导航过长
        $menu[] = ['title' => '帮助中心', 'url' => url('/kb')];
        $menu[] = ['title' => '公告', 'url' => url('/announcements')];
    } catch (\Throwable $e) {}
    return $menu;
}

function paginate_links(array $pagination, string $baseUrl): string
{
    if (($pagination['pages'] ?? 1) <= 1) return '';
    $page = (int)$pagination['page'];
    $pages = (int)$pagination['pages'];
    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    $html = '<nav class="pager">';
    $prev = max(1, $page - 1);
    $next = min($pages, $page + 1);
    $html .= '<a class="pager__item' . ($page <= 1 ? ' is-disabled' : '') . '" href="' . e($baseUrl . $sep . 'page=' . $prev) . '">上一页</a>';
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
        $html .= '<a class="pager__item' . ($i === $page ? ' is-active' : '') . '" href="' . e($baseUrl . $sep . 'page=' . $i) . '">' . $i . '</a>';
    }
    $html .= '<a class="pager__item' . ($page >= $pages ? ' is-disabled' : '') . '" href="' . e($baseUrl . $sep . 'page=' . $next) . '">下一页</a>';
    return $html . '</nav>';
}

function hook(string $event, ...$args): array
{
    return Plugin::hook($event, ...$args);
}

/* ------------------------------------------------------------------
 * 日志
 * ------------------------------------------------------------------ */
function log_write(string $channel, string $message, array $context = []): void
{
    $dir = DATA_PATH . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/' . preg_replace('/[^a-z0-9_\-]/i', '', $channel) . '-' . date('Y-m-d') . '.log';
    if (is_file($file) && filesize($file) > 5 * 1024 * 1024) @rename($file, $file . '.1');
    $line = '[' . date('Y-m-d H:i:s') . '] [' . client_ip() . '] ' . $message;
    if ($context) $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* ------------------------------------------------------------------
 * 邮件模板渲染
 * ------------------------------------------------------------------ */
function mail_template(string $code, array $vars = []): array
{
    $tpl = Db::table('email_template')->where('code', $code)->find();
    if (!$tpl) return ['subject' => $code, 'body' => json_encode($vars, JSON_UNESCAPED_UNICODE)];
    $subject = $tpl['subject'];
    $body = $tpl['body'];
    foreach ($vars as $k => $v) {
        $subject = str_replace('{{' . $k . '}}', (string)$v, $subject);
        $body = str_replace('{{' . $k . '}}', (string)$v, $body);
    }
    $body = str_replace('{{site_name}}', cfg('site_name', 'idceasy'), $body);
    $body = str_replace('{{site_url}}', rtrim(cfg('site_url', ''), '/'), $body);
    return ['subject' => $subject, 'body' => $body];
}

/* ------------------------------------------------------------------
 * 加密（用于主机密码、服务器密码）
 * ------------------------------------------------------------------ */
function app_key(): string
{
    return (string)Config::get('database.app_key', 'idceasy-default-key-change-me');
}

function encrypt_str(?string $plain): string
{
    if ($plain === null || $plain === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', app_key(), true), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) return $plain;
    return 'enc:' . base64_encode($iv . $tag . $cipher);
}

function decrypt_str(?string $value): string
{
    if (!$value) return '';
    if (!str_starts_with($value, 'enc:')) return $value;
    $raw = base64_decode(substr($value, 4), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', app_key(), true), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

/* ------------------------------------------------------------------
 * 绝对地址 / 权限
 * ------------------------------------------------------------------ */
function abs_url(string $path = ''): string
{
    $configured = trim((string)cfg('site_url', ''));
    if ($configured !== '') return rtrim($configured, '/') . ($path === '' ? '' : '/' . ltrim($path, '/'));
    return Request::instance()->scheme() . '://' . Request::instance()->host() . url($path);
}

function can(string $permission): bool
{
    $admin = current_admin();
    if (!$admin) return false;
    if ((int)$admin['role_id'] === 1) return true;
    static $cache = [];
    $rid = (int)$admin['role_id'];
    if (!isset($cache[$rid])) {
        $role = Db::table('admin_role')->where('id', $rid)->find();
        $cache[$rid] = $role ? (json_decode((string)$role['permissions'], true) ?: []) : [];
    }
    foreach ($cache[$rid] as $p) {
        if ($p === '*' || $p === $permission) return true;
        if (str_ends_with($p, '.*') && str_starts_with($permission, substr($p, 0, -1))) return true;
        if (str_ends_with($permission, '.*') && str_starts_with($p, substr($permission, 0, -1))) return true;
    }
    return false;
}

/* ------------------------------------------------------------------
 * 后台菜单
 * ------------------------------------------------------------------ */
function class_basename($class): string
{
    $name = is_object($class) ? get_class($class) : (string)$class;
    $pos = strrpos($name, '\\');
    return $pos === false ? $name : substr($name, $pos + 1);
}

function admin_menu(): array
{
    return [
        ['title' => '概览', 'items' => [
            ['title' => '仪表盘', 'url' => admin_url('/'), 'perm' => 'dashboard.index', 'icon' => 'grid'],
        ]],
        ['title' => '业务', 'items' => [
            ['title' => '客户', 'url' => admin_url('/clients'), 'perm' => 'client.index', 'icon' => 'users'],
            ['title' => '订单', 'url' => admin_url('/orders'), 'perm' => 'order.index', 'icon' => 'cart'],
            ['title' => '账单', 'url' => admin_url('/invoices'), 'perm' => 'invoice.index', 'icon' => 'file'],
            ['title' => '域名', 'url' => admin_url('/domains'), 'perm' => 'domain.index', 'icon' => 'globe'],
            ['title' => '服务', 'url' => admin_url('/hosts'), 'perm' => 'host.index', 'icon' => 'server'],
            ['title' => '工单', 'url' => admin_url('/tickets'), 'perm' => 'ticket.index', 'icon' => 'chat'],
        ]],
        ['title' => '商品', 'items' => [
            ['title' => '产品', 'url' => admin_url('/products'), 'perm' => 'product.index', 'icon' => 'box'],
            ['title' => '产品分组', 'url' => admin_url('/data/product_group'), 'perm' => 'product.index', 'icon' => 'layers'],
            ['title' => '域名定价', 'url' => admin_url('/data/tld'), 'perm' => 'domain.index', 'icon' => 'tag'],
            ['title' => '优惠码', 'url' => admin_url('/data/coupon'), 'perm' => 'invoice.index', 'icon' => 'percent'],
            ['title' => '服务器', 'url' => admin_url('/servers'), 'perm' => 'server.index', 'icon' => 'cpu'],
            ['title' => '服务器分组', 'url' => admin_url('/data/server_group'), 'perm' => 'server.index', 'icon' => 'layers'],
            ['title' => '税率', 'url' => admin_url('/data/tax_rule'), 'perm' => 'setting.index', 'icon' => 'percent'],
        ]],
        ['title' => '内容', 'items' => [
            ['title' => '公告', 'url' => admin_url('/data/announcement'), 'perm' => 'content.index', 'icon' => 'bell'],
            ['title' => '单页', 'url' => admin_url('/data/page'), 'perm' => 'content.index', 'icon' => 'file'],
            ['title' => '知识库', 'url' => admin_url('/data/kb_article'), 'perm' => 'content.index', 'icon' => 'book'],
            ['title' => '邮件模板', 'url' => admin_url('/data/email_template'), 'perm' => 'content.index', 'icon' => 'mail'],
            ['title' => '知识库分类', 'url' => admin_url('/data/kb_category'), 'perm' => 'content.index', 'icon' => 'book'],
            ['title' => '下载中心', 'url' => admin_url('/data/download'), 'perm' => 'content.index', 'icon' => 'file'],
            ['title' => '预设回复', 'url' => admin_url('/data/canned_reply'), 'perm' => 'ticket.index', 'icon' => 'chat'],
        ]],
        ['title' => '系统', 'items' => [
            ['title' => '插件', 'url' => admin_url('/plugins'), 'perm' => 'plugin.index', 'icon' => 'plug'],
            ['title' => '模板', 'url' => admin_url('/templates'), 'perm' => 'template.index', 'icon' => 'layout'],
            ['title' => '员工', 'url' => admin_url('/data/staff'), 'perm' => 'staff.index', 'icon' => 'shield'],
            ['title' => '角色权限', 'url' => admin_url('/data/role'), 'perm' => 'staff.index', 'icon' => 'shield'],
            ['title' => '货币', 'url' => admin_url('/data/currency'), 'perm' => 'setting.index', 'icon' => 'tag'],
            ['title' => '设置', 'url' => admin_url('/settings'), 'perm' => 'setting.index', 'icon' => 'settings'],
            ['title' => '报表', 'url' => admin_url('/reports'), 'perm' => 'report.index', 'icon' => 'chart'],
            ['title' => '日志', 'url' => admin_url('/logs'), 'perm' => 'log.index', 'icon' => 'list'],
            ['title' => '定时任务', 'url' => admin_url('/cron'), 'perm' => 'cron.index', 'icon' => 'clock'],
        ]],
    ];
}

function admin_breadcrumb(string $current = ''): array
{
    return $current === '' ? [] : [['title' => $current, 'url' => '']];
}


function arr_get($array, string $path, $default = null)
{
    if (!is_array($array)) return $default;
    foreach (explode('.', $path) as $key) {
        if (!is_array($array) || !array_key_exists($key, $array)) return $default;
        $array = $array[$key];
    }
    return $array;
}

function date_format_str(?string $date, string $format = 'Y-m-d'): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '-';
}

function option_label($options, $value): string
{
    if (!is_array($options)) return (string)$value;
    return (string)($options[$value] ?? $value);
}

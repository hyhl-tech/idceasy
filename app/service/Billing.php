<?php
/**
 * 计费核心：购物车结算、账单、支付、开通、续费、催缴、退款、升级
 */
namespace app\service;

use think\Db;

class Billing
{
    /* ---------------------------- 优惠码 ---------------------------- */
    public static function couponCheck(string $code, float $subtotal): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') return ['ok' => false, 'error' => '请输入优惠码'];
        $coupon = Db::table('coupon')->where('code', $code)->where('status', 1)->find();
        if (!$coupon) return ['ok' => false, 'error' => '优惠码不存在或已停用'];
        $now = time();
        if ($coupon['start_at'] && strtotime($coupon['start_at']) > $now) return ['ok' => false, 'error' => '优惠码尚未生效'];
        if ($coupon['end_at'] && strtotime($coupon['end_at']) < $now) return ['ok' => false, 'error' => '优惠码已过期'];
        if ((int)$coupon['max_uses'] > 0 && (int)$coupon['uses'] >= (int)$coupon['max_uses']) {
            return ['ok' => false, 'error' => '优惠码使用次数已满'];
        }
        if ((float)$coupon['min_total'] > 0 && $subtotal < (float)$coupon['min_total']) {
            return ['ok' => false, 'error' => '订单满 ' . money($coupon['min_total']) . ' 可用'];
        }
        $discount = $coupon['type'] === 'percent'
            ? $subtotal * (float)$coupon['value'] / 100
            : (float)$coupon['value'];
        $discount = round(min($discount, $subtotal), 2);
        return ['ok' => true, 'error' => '', 'discount' => $discount, 'coupon' => $coupon];
    }

    /* ---------------------------- 下单 ---------------------------- */
    public static function createOrder(int $userId, array $items, array $opts = []): array
    {
        $user = Db::table('user')->where('id', $userId)->find();
        if (!$user) throw new \RuntimeException('用户不存在');
        if (!$items) throw new \RuntimeException('购物车为空');

        $currency = $user['currency'] ?: cfg('currency', 'CNY');
        $subtotal = 0.0;
        $prepared = [];

        foreach ($items as $item) {
            $type = $item['type'] ?? 'product';
            $qty = max(1, min(10, (int)($item['qty'] ?? 1)));

            if ($type === 'domain') {
                $domain = strtolower(trim((string)($item['domain'] ?? '')));
                if (!preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,12}$/', $domain)) {
                    throw new \RuntimeException('域名格式不正确: ' . $domain);
                }
                $tld = substr($domain, strrpos($domain, '.') + 1);
                $tldRow = Db::table('domain_tld')->where('tld', $tld)->where('status', 1)->find();
                if (!$tldRow) throw new \RuntimeException('暂不支持 .' . $tld . ' 后缀');
                $years = max(1, min(10, $qty));
                $unit = (float)$tldRow['register_price'];
                $amount = round($unit * $years, 2);
                $prepared[] = [
                    'product_id' => (int)($item['product_id'] ?? 0), 'domain' => $domain, 'type' => 'domain',
                    'qty' => $years, 'cycle' => 'annual', 'unit_price' => $unit, 'setup_fee' => 0,
                    'options' => [], 'option_names' => '', 'amount' => $amount,
                    'name' => '.' . $tld . ' 域名注册 ' . $domain, 'tld' => $tld, 'registrar' => $tldRow['registrar'],
                ];
            } else {
                $pid = (int)($item['product_id'] ?? 0);
                $product = Db::table('product')->where('id', $pid)->where('status', 1)->find();
                if (!$product) throw new \RuntimeException('产品不存在或已下架');
                if ((int)$product['stock'] > 0 && (int)$product['stock'] < $qty) {
                    throw new \RuntimeException($product['name'] . ' 库存不足');
                }
                if ((int)$product['require_domain'] === 1 && trim((string)($item['domain'] ?? '')) === '') {
                    throw new \RuntimeException($product['name'] . ' 必须填写绑定域名');
                }
                $cycle = in_array($item['cycle'] ?? '', cycles(), true) ? $item['cycle'] : 'monthly';
                $pricing = Db::table('pricing')->where([
                    'product_id' => $pid, 'cycle' => $cycle, 'enabled' => 1,
                ])->find();
                if (!$pricing) throw new \RuntimeException($product['name'] . ' 未配置该计费周期');
                $unit = (float)$pricing['price'];
                $setup = (float)$pricing['setup_fee'];

                $optionIds = array_values(array_unique(array_map('intval', (array)($item['options'] ?? []))));
                $optionAmount = 0.0;
                $optionNames = [];
                if ($optionIds) {
                    $values = Db::table('product_option_value')->whereIn('id', $optionIds)->select();
                    $valid = Db::table('product_option')->whereIn('id', array_column($values, 'option_id'))
                        ->where('product_id', $pid)->column('id');
                    foreach ($values as $v) {
                        if (!in_array((int)$v['option_id'], array_map('intval', $valid), true)) continue;
                        $optionAmount += (float)$v['price'];
                        $optionNames[] = $v['name'];
                    }
                }
                $amount = round(($unit + $setup + $optionAmount) * $qty, 2);
                $prepared[] = [
                    'product_id' => $pid, 'domain' => trim((string)($item['domain'] ?? '')), 'type' => $product['type'],
                    'qty' => $qty, 'cycle' => $cycle, 'unit_price' => $unit, 'setup_fee' => $setup,
                    'options' => $optionIds, 'option_names' => implode(' / ', $optionNames), 'amount' => $amount,
                    'name' => $product['name'] . ' (' . cycle_text($cycle) . ')',
                ];
            }
            $subtotal += $amount;
        }
        $subtotal = round($subtotal, 2);

        $discount = 0.0;
        $couponId = 0;
        if (!empty($opts['coupon'])) {
            $check = self::couponCheck((string)$opts['coupon'], $subtotal);
            if (!$check['ok']) throw new \RuntimeException($check['error']);
            $discount = $check['discount'];
            $couponId = (int)$check['coupon']['id'];
        }

        $tax = 0.0;
        if ((string)cfg('tax_enabled', '0') === '1') {
            $rule = Db::table('tax_rule')->where('status', 1)
                ->whereRaw("(country = ? OR country = '')", [$user['country']])
                ->order('priority')->find();
            if ($rule) $tax = round(($subtotal - $discount) * (float)$rule['rate'] / 100, 2);
        }
        $total = round(max(0, $subtotal - $discount + $tax), 2);
        $orderNo = order_no();

        $orderId = Db::transaction(function () use ($userId, $orderNo, $subtotal, $discount, $tax, $total, $currency, $couponId, $opts, $prepared, $user) {
            $orderId = Db::table('order')->insert([
                'order_no' => $orderNo, 'user_id' => $userId, 'status' => 'pending',
                'total' => $total, 'discount' => $discount, 'tax' => $tax, 'credit' => 0,
                'paid_amount' => 0, 'currency' => $currency, 'coupon_id' => $couponId,
                'note' => (string)($opts['note'] ?? ''), 'ip' => client_ip(), 'created_at' => now(),
            ]);
            foreach ($prepared as $p) {
                Db::table('order_item')->insert([
                    'order_id' => $orderId, 'product_id' => $p['product_id'], 'domain' => $p['domain'],
                    'type' => $p['type'], 'qty' => $p['qty'], 'cycle' => $p['cycle'],
                    'unit_price' => $p['unit_price'], 'setup_fee' => $p['setup_fee'],
                    'options' => json_encode($p['options'], JSON_UNESCAPED_UNICODE),
                    'amount' => $p['amount'], 'host_id' => 0, 'domain_id' => 0,
                ]);
            }
            if ($couponId) Db::table('coupon')->where('id', $couponId)->increment('uses');
            return $orderId;
        });

        $lines = [];
        foreach ($prepared as $p) {
            $desc = $p['name'] . ($p['option_names'] !== '' ? ' [' . $p['option_names'] . ']' : '')
                . ($p['domain'] !== '' && $p['type'] !== 'domain' ? ' - ' . $p['domain'] : '');
            $lines[] = ['description' => $desc, 'amount' => $p['amount'], 'qty' => 1];
        }
        $invoice = self::createInvoice($userId, $lines, [
            'order_id' => $orderId, 'discount' => $discount, 'tax' => $tax,
            'note' => '订单 ' . $orderNo, 'send_mail' => true,
        ]);

        hook('order.created', ['order_id' => $orderId, 'order_no' => $orderNo, 'user_id' => $userId]);
        if ($total <= 0.01) {
            self::markPaid($invoice['id'], 'free', 'FREE' . $orderNo, 0, 0, '0 元订单自动支付');
        }
        return [
            'ok' => true, 'order_id' => $orderId, 'order_no' => $orderNo,
            'invoice_id' => $invoice['id'], 'invoice_no' => $invoice['invoice_no'],
            'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total,
            'currency' => $currency,
        ];
    }

    /* ---------------------------- 余额充值 ---------------------------- */
    public static function createTopup(int $userId, float $amount): array
    {
        $amount = round($amount, 2);
        if ($amount < 1) throw new \RuntimeException('充值金额至少 1 元');
        if ($amount > 100000) throw new \RuntimeException('单次充值不能超过 100000 元');
        $invoice = self::createInvoice($userId, [
            ['description' => '账户余额充值', 'amount' => $amount, 'qty' => 1],
        ], ['note' => '[TOPUP] 账户余额充值', 'send_mail' => true]);
        return ['ok' => true, 'invoice_id' => $invoice['id'], 'invoice_no' => $invoice['invoice_no'], 'amount' => $amount];
    }

    /* ---------------------------- 账单 ---------------------------- */
    public static function createInvoice(int $userId, array $lines, array $opts = []): array
    {
        $user = Db::table('user')->where('id', $userId)->find();
        if (!$user) throw new \RuntimeException('用户不存在');
        $subtotal = 0.0;
        foreach ($lines as $line) $subtotal += (float)$line['amount'] * (int)($line['qty'] ?? 1);
        $subtotal = round($subtotal, 2);
        $discount = round((float)($opts['discount'] ?? 0), 2);
        $tax = round((float)($opts['tax'] ?? 0), 2);
        $total = round(max(0, $subtotal - $discount + $tax), 2);
        $days = (int)($opts['due_days'] ?? cfg('invoice_due_days', 7));
        $no = invoice_no();
        $currency = $opts['currency'] ?? ($user['currency'] ?: cfg('currency', 'CNY'));

        $invoiceId = Db::transaction(function () use ($no, $userId, $opts, $subtotal, $discount, $tax, $total, $days, $currency, $lines) {
            $id = Db::table('invoice')->insert([
                'invoice_no' => $no, 'user_id' => $userId, 'order_id' => (int)($opts['order_id'] ?? 0),
                'status' => 'unpaid', 'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax,
                'total' => $total, 'paid' => 0, 'currency' => $currency,
                'due_at' => date('Y-m-d H:i:s', strtotime('+' . max(0, $days) . ' days')),
                'note' => (string)($opts['note'] ?? ''), 'created_at' => now(),
            ]);
            foreach ($lines as $line) {
                Db::table('invoice_item')->insert([
                    'invoice_id' => $id, 'description' => (string)$line['description'],
                    'amount' => (float)$line['amount'], 'qty' => (int)($line['qty'] ?? 1),
                ]);
            }
            return $id;
        });

        $invoice = Db::table('invoice')->where('id', $invoiceId)->find();
        hook('invoice.created', $invoice);
        if (($opts['send_mail'] ?? false) && $user['email']) {
            Mailer::sendTemplate('invoice_created', $user['email'], [
                'name' => $user['name'] ?: $user['email'], 'invoice_no' => $no,
                'total' => money($total, $currency), 'due_at' => $invoice['due_at'],
                'url' => abs_url('/invoice/' . $invoiceId),
            ]);
        }
        return ['id' => $invoiceId, 'invoice_no' => $no, 'total' => $total, 'subtotal' => $subtotal];
    }

    /* ---------------------------- 支付 ---------------------------- */
    public static function markPaid(int $invoiceId, string $gateway, string $tradeNo, float $amount, float $fee = 0.0, string $remark = ''): array
    {
        $paidNow = false;
        $orderId = 0;
        $result = Db::transaction(function () use ($invoiceId, $gateway, $tradeNo, $amount, $fee, $remark, &$paidNow, &$orderId) {
            $invoice = Db::first('SELECT * FROM ' . Db::prefix() . 'invoice WHERE id = ? FOR UPDATE', [$invoiceId]);
            if (!$invoice) return ['ok' => false, 'error' => '账单不存在'];
            if ($invoice['status'] === 'paid') return ['ok' => true, 'paid' => false, 'msg' => '账单已支付', 'invoice' => $invoice];
            if ($tradeNo !== '' && Db::table('transaction')->where('trade_no', $tradeNo)->exists()) {
                return ['ok' => true, 'paid' => false, 'msg' => '重复回调已忽略', 'invoice' => $invoice];
            }
            $amount = round($amount, 2);
            if ($gateway === 'balance') {
                $payer = Db::first('SELECT * FROM ' . Db::prefix() . 'user WHERE id = ? FOR UPDATE', [$invoice['user_id']]);
                if (!$payer || (float)$payer['balance'] + 0.001 < $amount) {
                    return ['ok' => false, 'error' => '账户余额不足'];
                }
                Db::table('user')->where('id', $invoice['user_id'])->decrement('balance', $amount);
            }
            Db::table('transaction')->insert([
                'user_id' => $invoice['user_id'], 'invoice_id' => $invoiceId, 'gateway' => $gateway,
                'trade_no' => $tradeNo ?: ('T' . date('YmdHis') . random_int(1000, 9999)),
                'amount' => $amount, 'fee' => $fee, 'currency' => $invoice['currency'],
                'status' => 'success', 'remark' => $remark, 'created_at' => now(),
            ]);
            $paid = round((float)$invoice['paid'] + $amount, 2);
            $isPaid = $paid + 0.001 >= (float)$invoice['total'];
            Db::table('invoice')->where('id', $invoiceId)->update([
                'paid' => $paid, 'status' => $isPaid ? 'paid' : 'unpaid',
                'paid_at' => $isPaid ? now() : null,
            ]);
            $orderId = (int)$invoice['order_id'];
            if ($isPaid && $orderId) {
                Db::table('order')->where('id', $orderId)->update([
                    'status' => 'paid', 'paid_amount' => $paid, 'paid_at' => now(),
                ]);
            }
            $paidNow = $isPaid;
            return ['ok' => true, 'paid' => $isPaid, 'invoice' => $invoice, 'order_id' => $orderId];
        });

        if (!$result['ok']) return $result;

        if ($paidNow) {
            $invoice = $result['invoice'];
            $user = Db::table('user')->where('id', $invoice['user_id'])->find();
            if ($orderId) $orderId = (int)$orderId;
            if ($result['order_id'] ?? 0) $orderId = (int)$result['order_id'];
            $isTopup = str_starts_with((string)($invoice['note'] ?? ''), '[TOPUP]');
            if ($isTopup) {
                Db::table('user')->where('id', $invoice['user_id'])->increment('balance', (float)$invoice['total']);
                hook('balance.changed', ['user_id' => (int)$invoice['user_id'], 'amount' => (float)$invoice['total'], 'remark' => '在线充值']);
            } elseif ($orderId) {
                try {
                    self::affiliateCommission($orderId);
                } catch (\Throwable $e) {
                    log_write('billing', '佣金结算失败 #' . $orderId . ': ' . $e->getMessage());
                }
                hook('order.paid', ['order_id' => $orderId, 'invoice_id' => $invoiceId]);
                try {
                    self::activateOrder($orderId);
                } catch (\Throwable $e) {
                    log_write('billing', '订单开通失败 #' . $orderId . ': ' . $e->getMessage());
                }
            }
            hook('invoice.paid', ['invoice_id' => $invoiceId, 'amount' => $amount, 'gateway' => $gateway]);
            if ($user) {
                try {
                    Mailer::sendTemplate('payment_received', $user['email'], [
                        'name' => $user['name'] ?: $user['email'],
                        'invoice_no' => $invoice['invoice_no'], 'total' => money($amount, $invoice['currency']),
                    ]);
                } catch (\Throwable $e) {
                    log_write('mail', '付款通知入队失败: ' . $e->getMessage());
                }
            }
        }
        return ['ok' => true, 'paid' => $paidNow, 'msg' => $paidNow ? '支付成功' : '已记录付款'];
    }

    public static function payWithBalance(int $invoiceId, int $userId): array
    {
        $invoice = Db::table('invoice')->where('id', $invoiceId)->where('user_id', $userId)->find();
        if (!$invoice) return ['ok' => false, 'error' => '账单不存在'];
        if ($invoice['status'] === 'paid') return ['ok' => false, 'error' => '账单已支付'];
        if (str_starts_with((string)($invoice['note'] ?? ''), '[TOPUP]')) return ['ok' => false, 'error' => '充值账单不能使用余额支付'];
        $due = round((float)$invoice['total'] - (float)$invoice['paid'], 2);
        return self::markPaid($invoiceId, 'balance', 'BAL' . $invoiceId . '-' . str_replace('.', '', microtime(true)), $due, 0, '余额支付');
    }

    /* ---------------------------- 开通 / 续费 ---------------------------- */
    public static function activateOrder(int $orderId): void
    {
        $order = Db::table('order')->where('id', $orderId)->find();
        if (!$order) return;
        if (in_array($order['status'], ['active', 'completed'], true)) return;
        $user = Db::table('user')->where('id', $order['user_id'])->find();
        $items = Db::table('order_item')->where('order_id', $orderId)->select();

        foreach ($items as $item) {
            try {
                if ($item['type'] === 'renewal') {
                    if ((int)$item['host_id'] > 0) self::renewHost((int)$item['host_id'], $item['cycle']);
                    if ((int)$item['domain_id'] > 0) DomainService::renew((int)$item['domain_id'], max(1, (int)$item['qty']));
                    continue;
                }
                if ($item['type'] === 'upgrade' && (int)$item['host_id'] > 0) {
                    self::applyUpgrade((int)$item['host_id'], (int)$item['product_id'], $item['cycle']);
                    continue;
                }
                if ((int)$item['domain_id'] > 0) continue;

                if ($item['type'] === 'domain' || ($item['product_id'] && self::productType((int)$item['product_id']) === 'domain')) {
                    $domainId = self::createDomainRecord($item, $user, $order);
                    Db::table('order_item')->where('id', $item['id'])->update(['domain_id' => $domainId]);
                    $res = DomainService::register($domainId);
                    if (!$res['ok']) {
                        log_write('domain', '注册失败 ' . $item['domain'] . ': ' . $res['error']);
                        hook('domain.register_failed', ['domain_id' => $domainId, 'error' => $res['error']]);
                    }
                } else {
                    $hostId = self::createHostRecord($item, $user, $order);
                    Db::table('order_item')->where('id', $item['id'])->update(['host_id' => $hostId]);
                    $res = Provision::create($hostId);
                    if (!$res['ok']) {
                        log_write('provision', '开通失败 host#' . $hostId . ': ' . $res['error']);
                        hook('host.create_failed', ['host_id' => $hostId, 'error' => $res['error']]);
                    }
                }
            } catch (\Throwable $e) {
                log_write('billing', '订单项处理失败 #' . $item['id'] . ': ' . $e->getMessage());
            }
        }

        Db::table('order')->where('id', $orderId)->update(['status' => 'active']);
        hook('order.activated', ['order_id' => $orderId, 'user_id' => $order['user_id']]);
        if ($user) {
            Mailer::sendTemplate('order_paid', $user['email'], [
                'name' => $user['name'] ?: $user['email'], 'order_no' => $order['order_no'],
                'total' => money($order['total'], $order['currency']), 'url' => abs_url('/order/' . $orderId),
            ]);
        }
    }

    protected static function productType(int $productId): string
    {
        static $cache = [];
        if (!isset($cache[$productId])) {
            $cache[$productId] = (string)Db::table('product')->where('id', $productId)->value('type', 'host');
        }
        return $cache[$productId];
    }

    protected static function createHostRecord(array $item, ?array $user, array $order): int
    {
        $product = Db::table('product')->where('id', $item['product_id'])->find();
        if (!$product) throw new \RuntimeException('产品不存在');
        $domain = $item['domain'] ?: ('service-' . $item['id'] . '.' . parse_url(abs_url('/'), PHP_URL_HOST));
        $username = Provision::makeUsername($domain);
        $password = generate_password(14);
        $hostId = Db::table('host')->insert([
            'user_id' => $order['user_id'], 'order_id' => $order['id'], 'product_id' => $product['id'],
            'server_id' => 0, 'domain' => $domain, 'username' => $username,
            'password' => encrypt_str($password), 'status' => 'pending',
            'module' => $product['module'] ?: 'manual', 'cycle' => $item['cycle'] ?: 'monthly',
            'next_due' => next_due($item['cycle'] ?: 'monthly'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ((int)$product['stock'] > 0) {
            Db::table('product')->where('id', $product['id'])->whereRaw('stock > 0')->decrement('stock');
        }
        hook('host.created', ['host_id' => $hostId, 'product_id' => $product['id']]);
        return $hostId;
    }

    protected static function createDomainRecord(array $item, ?array $user, array $order): int
    {
        $domain = strtolower($item['domain']);
        $exists = Db::table('domain')->where('domain', $domain)->find();
        if ($exists) {
            if ((int)$exists['user_id'] !== (int)$order['user_id']) {
                throw new \RuntimeException('域名 ' . $domain . ' 已被其他账户占用');
            }
            return (int)$exists['id'];
        }
        $tld = substr($domain, strrpos($domain, '.') + 1);
        $tldRow = Db::table('domain_tld')->where('tld', $tld)->find();
        $years = max(1, (int)$item['qty']);
        $expire = date('Y-m-d H:i:s', strtotime('+' . $years . ' years'));
        return Db::table('domain')->insert([
            'user_id' => $order['user_id'], 'order_id' => $order['id'], 'domain' => $domain, 'tld' => $tld,
            'registrar' => $tldRow['registrar'] ?? cfg('domain_default_registrar', 'namesilo'),
            'registrar_domain_id' => '', 'status' => 'pending',
            'reg_date' => now(), 'expire_date' => $expire, 'next_due' => $expire,
            'auto_renew' => 1, 'lock' => 1, 'privacy' => 0, 'auth_code' => '',
            'ns' => json_encode([], JSON_UNESCAPED_UNICODE), 'dns' => json_encode([], JSON_UNESCAPED_UNICODE),
            'cycle' => 'annual', 'price' => $item['unit_price'], 'currency' => $order['currency'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function renewHost(int $hostId, string $cycle): bool
    {
        $host = Db::table('host')->where('id', $hostId)->find();
        if (!$host) return false;
        $months = cycle_months($cycle ?: $host['cycle']);
        $base = max(time(), strtotime($host['next_due'] ?: 'now'));
        $newDue = date('Y-m-d H:i:s', strtotime('+' . max(1, $months) . ' months', $base));
        Db::table('host')->where('id', $hostId)->update([
            'next_due' => $newDue, 'cycle' => $cycle ?: $host['cycle'], 'updated_at' => now(),
        ]);
        if ($host['status'] === 'suspended') {
            $res = Provision::unsuspend($hostId);
            if ($res['ok']) Db::table('host')->where('id', $hostId)->update(['status' => 'active']);
        }
        hook('host.renewed', ['host_id' => $hostId, 'next_due' => $newDue]);
        return true;
    }

    public static function applyUpgrade(int $hostId, int $productId, string $cycle): void
    {
        $host = Db::table('host')->where('id', $hostId)->find();
        $product = Db::table('product')->where('id', $productId)->find();
        if (!$host || !$product) return;
        $months = cycle_months($cycle);
        Db::table('host')->where('id', $hostId)->update([
            'product_id' => $productId, 'cycle' => $cycle,
            'next_due' => date('Y-m-d H:i:s', strtotime('+' . max(1, $months) . ' months')),
            'updated_at' => now(),
        ]);
        Provision::changePackage($hostId);
        hook('host.upgraded', ['host_id' => $hostId, 'product_id' => $productId, 'cycle' => $cycle]);
    }

    /* ---------------------------- 升级报价 ---------------------------- */
    public static function quoteUpgrade(int $hostId, int $newProductId, string $newCycle): array
    {
        $host = Db::table('host')->where('id', $hostId)->find();
        if (!$host) return ['ok' => false, 'error' => '服务不存在'];
        $product = Db::table('product')->where('id', $newProductId)->where('status', 1)->find();
        if (!$product) return ['ok' => false, 'error' => '目标产品不可用'];
        $oldPrice = product_price((int)$host['product_id'], $host['cycle']);
        $oldCycleDays = max(1, cycle_months($host['cycle']) * 30);
        $remainDays = max(0, (strtotime($host['next_due']) - time()) / 86400);
        $credit = round((float)$oldPrice['price'] * min(1, $remainDays / $oldCycleDays), 2);
        $newPrice = product_price($newProductId, $newCycle);
        $payable = round(max(0, (float)$newPrice['price'] - $credit), 2);
        return ['ok' => true, 'credit' => $credit, 'new_price' => (float)$newPrice['price'],
            'payable' => $payable, 'product' => $product];
    }

    /* ---------------------------- 续费账单生成 ---------------------------- */
    public static function generateRenewals(int $days = 14): array
    {
        $deadline = date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
        $created = 0;
        $hosts = Db::table('host')->whereIn('status', ['active', 'suspended'])
            ->whereRaw('next_due IS NOT NULL AND next_due <= ?', [$deadline])->limit(200)->select();
        foreach ($hosts as $host) {
            try {
            if (self::hasUnpaidRenewal('host_id', (int)$host['id'])) continue;
            $product = Db::table('product')->where('id', $host['product_id'])->find();
            if (!$product) continue;
            $price = product_price((int)$host['product_id'], $host['cycle']);
            if ($price['price'] <= 0) continue;
            self::createRenewalOrder($host['user_id'], [
                'product_id' => $host['product_id'], 'type' => 'renewal', 'domain' => $host['domain'],
                'qty' => 1, 'cycle' => $host['cycle'], 'unit_price' => $price['price'],
                'setup_fee' => 0, 'amount' => $price['price'], 'host_id' => $host['id'], 'domain_id' => 0,
                'name' => $product['name'] . ' 续费',
            ], $host['currency'] ?? 'CNY');
            $created++;
            } catch (\Throwable $e) {
                log_write('billing', '生成主机续费账单失败 #' . $host['id'] . ': ' . $e->getMessage());
            }
        }

        $domains = Db::table('domain')->where('status', 'active')->where('auto_renew', 1)
            ->whereRaw('next_due IS NOT NULL AND next_due <= ?', [$deadline])->limit(200)->select();
        foreach ($domains as $domain) {
            try {
            if (self::hasUnpaidRenewal('domain_id', (int)$domain['id'])) continue;
            $tld = Db::table('domain_tld')->where('tld', $domain['tld'])->find();
            $price = (float)($tld['renew_price'] ?? $domain['price']);
            if ($price <= 0) continue;
            self::createRenewalOrder($domain['user_id'], [
                'product_id' => 0, 'type' => 'renewal', 'domain' => $domain['domain'], 'qty' => 1,
                'cycle' => 'annual', 'unit_price' => $price, 'setup_fee' => 0, 'amount' => $price,
                'host_id' => 0, 'domain_id' => $domain['id'], 'name' => '域名续费 ' . $domain['domain'],
            ], $domain['currency'] ?? 'CNY');
            $created++;
            } catch (\Throwable $e) {
                log_write('billing', '生成域名续费账单失败 #' . $domain['id'] . ': ' . $e->getMessage());
            }
        }
        return ['created' => $created];
    }

    protected static function hasUnpaidRenewal(string $field, int $id): bool
    {
        if (!in_array($field, ['host_id', 'domain_id'], true)) return false;
        $sql = 'SELECT COUNT(*) AS c FROM ' . Db::prefix() . 'invoice i'
            . ' JOIN ' . Db::prefix() . 'order o ON o.id = i.order_id'
            . ' JOIN ' . Db::prefix() . 'order_item oi ON oi.order_id = o.id'
            . " WHERE i.status = 'unpaid' AND oi.{$field} = ? AND oi.type = 'renewal'";
        $row = Db::first($sql, [$id]);
        return ((int)($row['c'] ?? 0)) > 0;
    }

    protected static function createRenewalOrder(int $userId, array $item, string $currency): int
    {
        $orderNo = order_no();
        $total = round((float)$item['amount'], 2);
        $orderId = Db::table('order')->insert([
            'order_no' => $orderNo, 'user_id' => $userId, 'status' => 'pending', 'total' => $total,
            'discount' => 0, 'tax' => 0, 'credit' => 0, 'paid_amount' => 0, 'currency' => $currency,
            'coupon_id' => 0, 'note' => '自动续费', 'ip' => client_ip(), 'created_at' => now(),
        ]);
        Db::table('order_item')->insert([
            'order_id' => $orderId, 'product_id' => $item['product_id'], 'domain' => $item['domain'],
            'type' => 'renewal', 'qty' => $item['qty'], 'cycle' => $item['cycle'],
            'unit_price' => $item['unit_price'], 'setup_fee' => 0, 'options' => '[]', 'amount' => $item['amount'],
            'host_id' => $item['host_id'], 'domain_id' => $item['domain_id'],
        ]);
        self::createInvoice($userId, [['description' => $item['name'], 'amount' => $item['amount'], 'qty' => 1]], [
            'order_id' => $orderId, 'note' => '自动续费账单 ' . $orderNo, 'send_mail' => true,
        ]);
        return $orderId;
    }

    /* ---------------------------- 用户主动续费 / 升级 ---------------------------- */
    public static function createServiceRenewal(string $type, int $id, int $years = 1): array
    {
        $years = max(1, min(10, $years));
        if ($type === 'domain') {
            $domain = Db::table('domain')->where('id', $id)->find();
            if (!$domain) return ['ok' => false, 'error' => '域名不存在'];
            if (self::hasUnpaidRenewal('domain_id', $id)) return ['ok' => false, 'error' => '已有待支付的续费账单'];
            $tld = Db::table('domain_tld')->where('tld', $domain['tld'])->find();
            $price = (float)($tld['renew_price'] ?? $domain['price']);
            $orderId = self::createRenewalOrder($domain['user_id'], [
                'product_id' => 0, 'type' => 'renewal', 'domain' => $domain['domain'], 'qty' => $years,
                'cycle' => 'annual', 'unit_price' => $price, 'setup_fee' => 0,
                'amount' => round($price * $years, 2), 'host_id' => 0, 'domain_id' => $id,
                'name' => '域名续费 ' . $domain['domain'] . ' (' . $years . ' 年)',
            ], $domain['currency'] ?? 'CNY');
        } else {
            $host = Db::table('host')->where('id', $id)->find();
            if (!$host) return ['ok' => false, 'error' => '服务不存在'];
            if (self::hasUnpaidRenewal('host_id', $id)) return ['ok' => false, 'error' => '已有待支付的续费账单'];
            $product = Db::table('product')->where('id', $host['product_id'])->find();
            if (!$product) return ['ok' => false, 'error' => '产品不存在'];
            $price = product_price((int)$host['product_id'], $host['cycle']);
            $orderId = self::createRenewalOrder($host['user_id'], [
                'product_id' => $host['product_id'], 'type' => 'renewal', 'domain' => $host['domain'], 'qty' => 1,
                'cycle' => $host['cycle'], 'unit_price' => $price['price'], 'setup_fee' => 0,
                'amount' => $price['price'], 'host_id' => $id, 'domain_id' => 0,
                'name' => $product['name'] . ' 续费',
            ], $host['currency'] ?? 'CNY');
        }
        $invoice = Db::table('invoice')->where('order_id', $orderId)->find();
        return ['ok' => true, 'order_id' => $orderId, 'invoice_id' => (int)($invoice['id'] ?? 0)];
    }

    public static function createUpgradeOrder(int $hostId, int $productId, string $cycle): array
    {
        $host = Db::table('host')->where('id', $hostId)->find();
        if (!$host) return ['ok' => false, 'error' => '服务不存在'];
        $quote = self::quoteUpgrade($hostId, $productId, $cycle);
        if (!$quote['ok']) return $quote;
        if ($quote['payable'] <= 0.01) {
            self::applyUpgrade($hostId, $productId, $cycle);
            return ['ok' => true, 'free' => true, 'invoice_id' => 0];
        }
        $orderNo = order_no();
        $orderId = Db::table('order')->insert([
            'order_no' => $orderNo, 'user_id' => $host['user_id'], 'status' => 'pending',
            'total' => $quote['payable'], 'discount' => 0, 'tax' => 0, 'credit' => 0,
            'paid_amount' => 0, 'currency' => $host['currency'] ?? 'CNY', 'coupon_id' => 0,
            'note' => '服务升级 #' . $hostId, 'ip' => client_ip(), 'created_at' => now(),
        ]);
        Db::table('order_item')->insert([
            'order_id' => $orderId, 'product_id' => $productId, 'domain' => $host['domain'],
            'type' => 'upgrade', 'qty' => 1, 'cycle' => $cycle,
            'unit_price' => $quote['new_price'], 'setup_fee' => 0,
            'options' => json_encode(['credit' => $quote['credit']], JSON_UNESCAPED_UNICODE),
            'amount' => $quote['payable'], 'host_id' => $hostId, 'domain_id' => 0,
        ]);
        $invoice = self::createInvoice($host['user_id'], [[
            'description' => '服务升级：' . $quote['product']['name'] . ' (' . cycle_text($cycle) . ')',
            'amount' => $quote['payable'], 'qty' => 1,
        ]], ['order_id' => $orderId, 'note' => '升级订单 ' . $orderNo, 'send_mail' => true]);
        return ['ok' => true, 'invoice_id' => $invoice['id'], 'order_id' => $orderId, 'payable' => $quote['payable']];
    }

    /* ---------------------------- 暂停 / 终止 / 过期 ---------------------------- */
    public static function suspendOverdue(): int
    {
        $days = (int)cfg('suspend_days', 3);
        $deadline = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $hosts = Db::table('host')->where('status', 'active')->whereRaw('next_due < ?', [$deadline])->limit(100)->select();
        $n = 0;
        foreach ($hosts as $host) {
            $res = Provision::suspend((int)$host['id']);
            if ($res['ok']) {
                Db::table('host')->where('id', $host['id'])->update(['status' => 'suspended', 'updated_at' => now()]);
                hook('host.suspended', ['host_id' => $host['id']]);
                $n++;
            }
        }
        return $n;
    }

    public static function terminateOverdue(): int
    {
        $days = (int)cfg('terminate_days', 30);
        $deadline = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $hosts = Db::table('host')->where('status', 'suspended')->whereRaw('next_due < ?', [$deadline])->limit(50)->select();
        $n = 0;
        foreach ($hosts as $host) {
            $res = Provision::terminate((int)$host['id']);
            if ($res['ok']) {
                Db::table('host')->where('id', $host['id'])->update(['status' => 'terminated', 'updated_at' => now()]);
                hook('host.terminated', ['host_id' => $host['id']]);
                $n++;
            }
        }
        return $n;
    }

    public static function expireDomains(): int
    {
        $rows = Db::table('domain')->where('status', 'active')->whereRaw('expire_date < ?', [now()])->limit(200)->select();
        foreach ($rows as $row) {
            Db::table('domain')->where('id', $row['id'])->update(['status' => 'expired', 'updated_at' => now()]);
            hook('domain.expired', ['domain_id' => $row['id']]);
        }
        return count($rows);
    }

    /* ---------------------------- 余额 / 退款 / 分佣 ---------------------------- */
    public static function addBalance(int $userId, float $amount, string $remark = '', string $gateway = 'manual'): bool
    {
        if (abs($amount) < 0.001) return false;
        Db::table('user')->where('id', $userId)->increment('balance', $amount);
        Db::table('transaction')->insert([
            'user_id' => $userId, 'invoice_id' => 0, 'gateway' => $gateway,
            'trade_no' => 'B' . date('YmdHis') . random_int(1000, 9999), 'amount' => $amount,
            'fee' => 0, 'currency' => 'CNY', 'status' => 'success', 'remark' => $remark, 'created_at' => now(),
        ]);
        hook('balance.changed', ['user_id' => $userId, 'amount' => $amount, 'remark' => $remark]);
        return true;
    }

    public static function refund(int $invoiceId, float $amount, string $reason = ''): array
    {
        $invoice = Db::table('invoice')->where('id', $invoiceId)->find();
        if (!$invoice) return ['ok' => false, 'error' => '账单不存在'];
        $amount = round(min($amount, (float)$invoice['paid']), 2);
        if ($amount <= 0) return ['ok' => false, 'error' => '无可退款金额'];
        Db::transaction(function () use ($invoice, $amount, $reason) {
            $refunded = (float)$invoice['paid'] - $amount;
            Db::table('invoice')->where('id', $invoice['id'])->update([
                'status' => $refunded <= 0.001 ? 'refunded' : $invoice['status'],
                'paid' => max(0, $refunded),
            ]);
            Db::table('transaction')->insert([
                'user_id' => $invoice['user_id'], 'invoice_id' => $invoice['id'], 'gateway' => 'refund',
                'trade_no' => 'R' . date('YmdHis') . random_int(1000, 9999), 'amount' => -$amount,
                'fee' => 0, 'currency' => $invoice['currency'], 'status' => 'success',
                'remark' => $reason, 'created_at' => now(),
            ]);
            Db::table('user')->where('id', $invoice['user_id'])->increment('balance', $amount);
        });
        hook('invoice.refunded', ['invoice_id' => $invoiceId, 'amount' => $amount, 'reason' => $reason]);
        return ['ok' => true, 'amount' => $amount];
    }

    protected static function affiliateCommission(int $orderId): void
    {
        if ((string)cfg('affiliate_enabled', '0') !== '1') return;
        $order = Db::table('order')->where('id', $orderId)->find();
        if (!$order) return;
        $user = Db::table('user')->where('id', $order['user_id'])->find();
        if (!$user || !$user['referred_by']) return;
        $aff = Db::table('affiliate')->where('user_id', $user['referred_by'])->where('status', 1)->find();
        if (!$aff) return;
        $exists = Db::table('affiliate_log')->where('order_id', $orderId)->exists();
        if ($exists) return;
        $commission = round((float)$order['total'] * (float)$aff['rate'] / 100, 2);
        if ($commission <= 0) return;
        Db::table('affiliate')->where('id', $aff['id'])->increment('earnings', $commission, ['balance' => $aff['balance'] + $commission]);
        Db::table('affiliate_log')->insert([
            'affiliate_id' => $aff['id'], 'order_id' => $orderId, 'amount' => $commission,
            'type' => 'commission', 'created_at' => now(),
        ]);
        hook('affiliate.commission', ['affiliate_id' => $aff['id'], 'order_id' => $orderId, 'amount' => $commission]);
    }
}

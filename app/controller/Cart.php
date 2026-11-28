<?php
/**
 * 前台：购物车与结算
 */
namespace app\controller;

use app\service\Billing;
use think\Db;
use think\Plugin;
use think\Response;
use think\Session;

class Cart extends Front
{
    public function index(): Response
    {
        [$items, $subtotal, $optionInfo] = $this->detail();
        $coupon = (string)Session::get('cart_coupon', '');
        $discount = 0.0;
        $couponError = '';
        if ($coupon !== '') {
            $check = Billing::couponCheck($coupon, $subtotal);
            if ($check['ok']) $discount = $check['discount'];
            else { $couponError = $check['error']; Session::forget('cart_coupon'); }
        }
        return $this->view('cart', compact('items', 'subtotal', 'optionInfo', 'coupon', 'discount', 'couponError'));
    }

    public function add(): Response
    {
        if (!$this->request->isPost()) return $this->redirect(url('/cart'));
        $type = (string)$this->request->param('type', 'product');
        if ($type === 'domain') {
            $domain = strtolower(trim((string)$this->request->param('domain')));
            if (!preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,12}$/', $domain)) {
                return $this->error('域名格式不正确');
            }
            $tld = substr($domain, strrpos($domain, '.') + 1);
            $row = Db::table('domain_tld')->where('tld', $tld)->where('status', 1)->find();
            if (!$row) return $this->error('暂不支持 .' . $tld . ' 后缀');
            $years = max(1, min(10, $this->request->int('years', 1)));
            cart_add([
                'type' => 'domain', 'product_id' => 0, 'domain' => $domain, 'tld' => $tld,
                'cycle' => 'annual', 'qty' => $years, 'price' => (float)$row['register_price'],
                'name' => $domain, 'options' => [],
            ]);
            return $this->redirect(url('/cart'));
        }

        $productId = $this->request->int('product_id');
        $product = Db::table('product')->where('id', $productId)->where('status', 1)->find();
        if (!$product) return $this->error('产品不存在');
        $cycle = (string)$this->request->param('cycle', 'monthly');
        if (!in_array($cycle, cycles(), true)) $cycle = 'monthly';
        if ($product['type'] === 'domain') {
            $domain = strtolower(trim((string)$this->request->param('domain')));
            if (!preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,12}$/', $domain)) {
                return $this->error('请填写要注册的域名');
            }
            $tld = substr($domain, strrpos($domain, '.') + 1);
            $tldRow = Db::table('domain_tld')->where('tld', $tld)->where('status', 1)->find();
            if (!$tldRow) return $this->error('暂不支持 .' . $tld . ' 后缀');
            $months = cycle_months($cycle);
            $years = max(1, (int)round($months / 12));
            cart_add([
                'type' => 'domain', 'product_id' => 0, 'domain' => $domain, 'tld' => $tld,
                'cycle' => 'annual', 'qty' => $years, 'price' => (float)$tldRow['register_price'],
                'name' => $domain, 'options' => [],
            ]);
            return $this->redirect(url('/cart'));
        }
        $pricing = Db::table('pricing')->where(['product_id' => $productId, 'cycle' => $cycle, 'enabled' => 1])->find();
        if (!$pricing) return $this->error('该计费周期不可用');
        $domain = trim((string)$this->request->param('domain'));
        if ((int)$product['require_domain'] === 1 && $domain === '') return $this->error('请填写要绑定的域名');
        $options = array_map('intval', (array)$this->request->param('options', []));
        cart_add([
            'type' => 'product', 'product_id' => $productId, 'domain' => $domain,
            'cycle' => $cycle, 'qty' => max(1, min(10, $this->request->int('qty', 1))),
            'options' => $options, 'name' => $product['name'],
        ]);
        hook('cart.added', ['product_id' => $productId, 'cycle' => $cycle]);
        return $this->success('已加入购物车', url('/cart'));
    }

    public function update(): Response
    {
        cart_update((string)$this->request->param('key'), $this->request->int('qty'));
        return $this->redirect(url('/cart'));
    }

    public function remove(): Response
    {
        cart_remove((string)$this->request->param('key'));
        return $this->redirect(url('/cart'));
    }

    public function clear(): Response
    {
        cart_clear();
        Session::forget('cart_coupon');
        return $this->redirect(url('/cart'));
    }

    public function coupon(): Response
    {
        $code = trim((string)$this->request->param('code'));
        $check = Billing::couponCheck($code, cart_total());
        if (!$this->request->isPost()) return $this->redirect(url('/cart'));
        if (!$check['ok']) {
            Session::forget('cart_coupon');
            return $this->error($check['error'], url('/cart'));
        }
        Session::set('cart_coupon', strtoupper($code));
        return $this->success('优惠码已应用，抵扣 ' . money($check['discount']), url('/cart'));
    }

    public function checkout(): Response
    {
        $this->requireLogin();
        $user = $this->user();
        [$items, $subtotal, $optionInfo] = $this->detail();
        if (!$items) return $this->redirect(url('/cart'));
        $coupon = (string)Session::get('cart_coupon', '');
        $discount = 0.0;
        if ($coupon !== '') {
            $check = Billing::couponCheck($coupon, $subtotal);
            if ($check['ok']) $discount = $check['discount'];
        }
        $tax = 0.0;
        if ((string)cfg('tax_enabled', '0') === '1') {
            $rule = Db::table('tax_rule')->where('status', 1)->whereRaw("(country = ? OR country = '')", [$user['country']])->order('priority')->find();
            if ($rule) $tax = round(($subtotal - $discount) * (float)$rule['rate'] / 100, 2);
        }
        $total = round(max(0, $subtotal - $discount + $tax), 2);

        if ($this->request->isPost()) {
            if (!$this->request->bool('agree')) return $this->error('请阅读并同意服务条款');
            $payload = [];
            foreach ($items as $item) {
                $payload[] = [
                    'type' => $item['type'], 'product_id' => $item['product_id'], 'domain' => $item['domain'],
                    'cycle' => $item['cycle'], 'qty' => $item['qty'], 'options' => $item['options'],
                    'registrar' => $item['registrar'] ?? '', 'auth_code' => $item['auth_code'] ?? '',
                ];
            }
            try {
                $result = Billing::createOrder((int)$user['id'], $payload, [
                    'coupon' => $coupon, 'note' => trim((string)$this->request->param('note')),
                ]);
            } catch (\Throwable $e) {
                return $this->error($e->getMessage(), url('/cart'));
            }
            cart_clear();
            Session::forget('cart_coupon');
            return $this->redirect(url('/invoice/' . $result['invoice_id'] . '/pay'));
        }
        return $this->view('checkout', compact('items', 'subtotal', 'optionInfo', 'discount', 'tax', 'total', 'coupon'));
    }

    /** @return array [items, subtotal, optionInfo] */
    protected function detail(): array
    {
        $items = [];
        $subtotal = 0.0;
        $optionInfo = [];
        foreach (cart() as $key => $item) {
            $row = $item;
            $row['key'] = $key;
            if (in_array(($item['type'] ?? ''), ['domain', 'transfer'], true)) {
                $row['price'] = (float)$item['price'];
                $row['amount'] = $row['price'] * (int)$item['qty'];
                $row['setup_fee'] = 0;
                $row['cycle_text'] = $item['qty'] . ' 年';
                $items[] = $row;
                $subtotal += $row['amount'];
                continue;
            }
            $product = Db::table('product')->where('id', $item['product_id'])->find();
            if (!$product) continue;
            $price = product_price((int)$item['product_id'], $item['cycle']);
            $optionAmount = 0.0;
            $names = [];
            if (!empty($item['options'])) {
                foreach (Db::table('product_option_value')->whereIn('id', (array)$item['options'])->select() as $v) {
                    $optionAmount += (float)$v['price'];
                    $names[] = $v['name'];
                }
            }
            $row['product'] = $product;
            $row['name'] = $product['name'];
            $row['price'] = (float)$price['price'];
            $row['setup_fee'] = (float)$price['setup_fee'];
            $row['option_names'] = implode(' / ', $names);
            $row['amount'] = ($row['price'] + $row['setup_fee'] + $optionAmount) * (int)$item['qty'];
            $row['cycle_text'] = cycle_text($item['cycle']);
            $items[] = $row;
            $subtotal += $row['amount'];
        }
        return [$items, round($subtotal, 2), $optionInfo];
    }
}

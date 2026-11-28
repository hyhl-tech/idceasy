<?php
/**
 * Stripe Checkout
 */
return new class extends \plugins\Gateway {
    public function info(): array
    {
        return ['id' => 'stripe', 'title' => 'Stripe', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'Stripe Checkout，支持信用卡、Apple Pay、Google Pay 等。',
            'fields' => [
                ['name' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
                ['name' => 'publishable_key', 'label' => 'Publishable Key', 'type' => 'text'],
                ['name' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password', 'help' => '用于验证异步通知签名'],
                ['name' => 'currency', 'label' => '结算货币', 'type' => 'text', 'help' => '默认 cny'],
            ]];
    }
    protected function api(string $method, string $path, array $params = []): array
    {
        $res = $this->http($method, 'https://api.stripe.com/v1' . $path, $params,
            ['Authorization: Bearer ' . $this->config('secret_key', '')]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $j = $res['json'];
        if (!is_array($j)) return $this->fail('返回格式异常');
        if (isset($j['error'])) return $this->fail('Stripe: ' . ($j['error']['message'] ?? '请求失败'));
        return $this->ok(['data' => $j]);
    }
    public function pay(array $invoice, string $returnUrl, string $notifyUrl): array
    {
        $due = round((float)$invoice['total'] - (float)$invoice['paid'], 2);
        if ($due <= 0) return $this->fail('账单已结清');
        $currency = strtolower((string)$this->config('currency', 'cny'));
        $params = [
            'mode' => 'payment',
            'success_url' => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $returnUrl,
            'client_reference_id' => $invoice['invoice_no'],
            'metadata[invoice_no]' => $invoice['invoice_no'],
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => $currency,
            'line_items[0][price_data][unit_amount]' => (int)round($due * 100),
            'line_items[0][price_data][product_data][name]' => '账单 ' . $invoice['invoice_no'],
        ];
        $res = $this->api('POST', '/checkout/sessions', $params);
        if (!$res['ok']) return $res;
        return $this->ok(['type' => 'redirect', 'url' => (string)($res['data']['url'] ?? '')]);
    }
    protected function sessionToResult(array $session): array
    {
        if (($session['payment_status'] ?? '') !== 'paid') return $this->fail('支付未完成');
        return $this->ok([
            'invoice_no' => (string)($session['metadata']['invoice_no'] ?? $session['client_reference_id'] ?? ''),
            'trade_no' => (string)($session['payment_intent'] ?? $session['id'] ?? ''),
            'amount' => ((int)($session['amount_total'] ?? 0)) / 100,
            'fee' => 0, 'raw' => $session,
        ]);
    }
    public function notify(array $params, string $raw = ''): array
    {
        $secret = (string)$this->config('webhook_secret', '');
        if ($secret !== '' && $raw !== '' && isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'];
            $parts = [];
            foreach (explode(',', $sigHeader) as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $parts[$k][] = $v;
            }
            $timestamp = (int)($parts['t'][0] ?? 0);
            $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
            $valid = false;
            foreach ($parts['v1'] ?? [] as $sig) if (hash_equals($expected, $sig)) $valid = true;
            if (!$valid) return $this->fail('Webhook 签名错误');
            $event = json_decode($raw, true);
            $session = $event['data']['object'] ?? null;
            if (is_array($session)) return $this->sessionToResult($session);
            return $this->fail('无法解析事件');
        }
        $sessionId = (string)($params['session_id'] ?? '');
        if ($sessionId === '') return $this->fail('缺少 session_id');
        $res = $this->api('GET', '/checkout/sessions/' . $sessionId);
        if (!$res['ok']) return $res;
        return $this->sessionToResult($res['data']);
    }
};

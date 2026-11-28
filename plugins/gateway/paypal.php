<?php
/**
 * PayPal Orders v2
 */
return new class extends \plugins\Gateway {
    public function info(): array
    {
        return ['id' => 'paypal', 'title' => 'PayPal', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'PayPal 国际支付，支持沙箱与生产环境。',
            'fields' => [
                ['name' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
                ['name' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
                ['name' => 'currency', 'label' => '结算货币', 'type' => 'text', 'help' => '默认 USD'],
                ['name' => 'sandbox', 'label' => '沙箱环境', 'type' => 'checkbox'],
            ]];
    }
    protected function base(): string
    {
        return $this->config('sandbox', '0') === '1' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }
    protected function token(): ?string
    {
        $res = $this->http('POST', $this->base() . '/v1/oauth2/token', ['grant_type' => 'client_credentials'], [
            'Authorization: Basic ' . base64_encode($this->config('client_id', '') . ':' . $this->config('client_secret', '')),
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        return $res['json']['access_token'] ?? null;
    }
    public function pay(array $invoice, string $returnUrl, string $notifyUrl): array
    {
        $due = round((float)$invoice['total'] - (float)$invoice['paid'], 2);
        if ($due <= 0) return $this->fail('账单已结清');
        $token = $this->token();
        if (!$token) return $this->fail('PayPal 认证失败');
        $res = $this->http('POST', $this->base() . '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => ['currency_code' => strtoupper((string)$this->config('currency', 'USD')),
                    'value' => number_format($due, 2, '.', '')],
                'custom_id' => $invoice['invoice_no'],
                'description' => '账单 ' . $invoice['invoice_no'],
            ]],
            'application_context' => ['return_url' => $returnUrl, 'cancel_url' => $returnUrl, 'brand_name' => cfg('site_name', 'idceasy')],
        ], ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], ['json' => true]);
        if ($res['error'] !== '' || $res['code'] >= 400) {
            return $this->fail('PayPal: ' . ($res['json']['message'] ?? $res['error'] ?? '创建订单失败'));
        }
        $approve = '';
        foreach (($res['json']['links'] ?? []) as $link) if (($link['rel'] ?? '') === 'approve') $approve = $link['href'];
        if ($approve === '') return $this->fail('未获取到 PayPal 支付链接');
        return $this->ok(['type' => 'redirect', 'url' => $approve]);
    }
    public function notify(array $params, string $raw = ''): array
    {
        $orderId = (string)($params['token'] ?? '');
        if ($orderId === '' && $raw !== '') {
            $event = json_decode($raw, true);
            if (($event['event_type'] ?? '') === 'CHECKOUT.ORDER.APPROVED') $orderId = (string)($event['resource']['id'] ?? '');
        }
        if ($orderId === '') return $this->fail('缺少 PayPal 订单号');
        $token = $this->token();
        if (!$token) return $this->fail('PayPal 认证失败');
        $cap = $this->http('POST', $this->base() . '/v2/checkout/orders/' . $orderId . '/capture', [],
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], ['json' => true]);
        $j = $cap['json'];
        if (!is_array($j)) return $this->fail('PayPal 返回异常');
        $status = $j['status'] ?? '';
        if (!in_array($status, ['COMPLETED', 'APPROVED'], true)) return $this->fail('PayPal 状态：' . $status);
        $unit = $j['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? [];
        return $this->ok([
            'invoice_no' => (string)($unit['custom_id'] ?? ''),
            'trade_no' => (string)($capture['id'] ?? $orderId),
            'amount' => (float)($capture['amount']['value'] ?? $unit['amount']['value'] ?? 0),
            'fee' => 0, 'raw' => $j,
        ]);
    }
};

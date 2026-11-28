<?php
/**
 * 易支付（彩虹易支付兼容）
 */
return new class extends \plugins\Gateway {
    public function info(): array
    {
        return ['id' => 'epay', 'title' => '易支付', 'version' => '1.0', 'author' => 'idceasy',
            'description' => '彩虹易支付 / 聚合支付，支持支付宝、微信、QQ 钱包等通道。',
            'fields' => [
                ['name' => 'api_url', 'label' => '接口地址', 'type' => 'text', 'required' => true, 'help' => '如 https://pay.example.com'],
                ['name' => 'pid', 'label' => '商户 ID', 'type' => 'text', 'required' => true],
                ['name' => 'key', 'label' => '商户密钥', 'type' => 'password', 'required' => true],
                ['name' => 'type', 'label' => '支付通道', 'type' => 'select',
                    'options' => ['alipay' => '支付宝', 'wxpay' => '微信支付', 'qqpay' => 'QQ 钱包']],
                ['name' => 'sitename', 'label' => '站点名称', 'type' => 'text'],
            ]];
    }
    protected function sign(array $p): string
    {
        $keys = ['pid', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money'];
        $s = '';
        foreach ($keys as $k) $s .= $k . '=' . ($p[$k] ?? '') . '&';
        return md5(rtrim($s, '&') . (string)$this->config('key', ''));
    }
    public function pay(array $invoice, string $returnUrl, string $notifyUrl): array
    {
        $due = round((float)$invoice['total'] - (float)$invoice['paid'], 2);
        if ($due <= 0) return $this->fail('账单已结清');
        $params = [
            'pid' => (string)$this->config('pid', ''),
            'type' => (string)$this->config('type', 'alipay'),
            'out_trade_no' => $invoice['invoice_no'],
            'notify_url' => $notifyUrl, 'return_url' => $returnUrl,
            'name' => '账单 ' . $invoice['invoice_no'],
            'money' => number_format($due, 2, '.', ''),
            'sitename' => (string)$this->config('sitename', cfg('site_name', 'idceasy')),
        ];
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = 'MD5';
        return $this->ok(['type' => 'redirect', 'url' => rtrim((string)$this->config('api_url', ''), '/') . '/submit.php?' . http_build_query($params)]);
    }
    public function notify(array $params, string $raw = ''): array
    {
        if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS') return $this->fail('交易未完成');
        if (($params['pid'] ?? '') !== (string)$this->config('pid', '')) return $this->fail('商户号不匹配');
        if (strtoupper((string)($params['sign'] ?? '')) !== strtoupper($this->sign($params))) return $this->fail('签名错误');
        return $this->ok(['invoice_no' => (string)($params['out_trade_no'] ?? ''),
            'trade_no' => (string)($params['trade_no'] ?? ''), 'amount' => (float)($params['money'] ?? 0),
            'fee' => 0, 'raw' => $params]);
    }
    public function ack(array $result): string { return 'success'; }
};

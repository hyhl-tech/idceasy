<?php
/**
 * 支付宝电脑网站支付（alipay.trade.page.pay，RSA2）
 */
return new class extends \plugins\Gateway {
    public function info(): array
    {
        return ['id' => 'alipay', 'title' => '支付宝', 'version' => '1.0', 'author' => 'idceasy',
            'description' => '支付宝电脑网站支付，支持 RSA2 签名与异步通知验签。',
            'fields' => [
                ['name' => 'app_id', 'label' => 'App ID', 'type' => 'text', 'required' => true],
                ['name' => 'private_key', 'label' => '应用私钥', 'type' => 'textarea', 'required' => true,
                    'help' => 'PKCS#8 私钥，可填完整 PEM 或纯 Base64'],
                ['name' => 'public_key', 'label' => '支付宝公钥', 'type' => 'textarea', 'required' => true],
                ['name' => 'gateway', 'label' => '网关地址', 'type' => 'text',
                    'help' => '默认 https://openapi.alipay.com/gateway.do'],
                ['name' => 'sandbox', 'label' => '沙箱网关', 'type' => 'checkbox'],
            ]];
    }

    protected function pem(string $key, string $type): string
    {
        $key = trim($key);
        if (str_contains($key, 'BEGIN')) return $key;
        $body = chunk_split(preg_replace('/\s+/', '', $key), 64, "\n");
        return "-----BEGIN {$type}-----\n" . $body . "-----END {$type}-----\n";
    }

    protected function sign(array $params): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $k === 'sign_type' || $v === '' || $v === null) continue;
            $pairs[] = $k . '=' . $v;
        }
        $data = implode('&', $pairs);
        $key = openssl_pkey_get_private($this->pem((string)$this->config('private_key', ''), 'RSA PRIVATE KEY'));
        if (!$key) throw new \RuntimeException('支付宝应用私钥格式错误');
        openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    protected function verify(array $params): bool
    {
        $sign = (string)($params['sign'] ?? '');
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if (is_array($v)) continue;
            if ($v === '' || $v === null) continue;
            $pairs[] = $k . '=' . $v;
        }
        $data = implode('&', $pairs);
        $pub = openssl_pkey_get_public($this->pem((string)$this->config('public_key', ''), 'PUBLIC KEY'));
        if (!$pub) return false;
        return openssl_verify($data, base64_decode($sign), $pub, OPENSSL_ALGO_SHA256) === 1;
    }

    protected function gatewayUrl(): string
    {
        return $this->config('sandbox', '0') === '1'
            ? 'https://openapi-sandbox.dl.alipaydev.com/gateway.do'
            : (string)$this->config('gateway', 'https://openapi.alipay.com/gateway.do');
    }

    public function pay(array $invoice, string $returnUrl, string $notifyUrl): array
    {
        $due = round((float)$invoice['total'] - (float)$invoice['paid'], 2);
        if ($due <= 0) return $this->fail('账单已结清');
        $params = [
            'app_id' => (string)$this->config('app_id', ''),
            'method' => 'alipay.trade.page.pay',
            'format' => 'JSON', 'charset' => 'utf-8', 'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'), 'version' => '1.0',
            'notify_url' => $notifyUrl, 'return_url' => $returnUrl,
            'biz_content' => json_encode([
                'out_trade_no' => $invoice['invoice_no'],
                'total_amount' => number_format($due, 2, '.', ''),
                'subject' => mb_substr('账单 ' . $invoice['invoice_no'], 0, 100),
                'product_code' => 'FAST_INSTANT_TRADE_PAY',
                'timeout_express' => '2h',
            ], JSON_UNESCAPED_UNICODE),
        ];
        $params['sign'] = $this->sign($params);
        return $this->ok(['type' => 'redirect', 'url' => $this->gatewayUrl() . '?' . http_build_query($params)]);
    }

    public function notify(array $params, string $raw = ''): array
    {
        if (empty($params['sign'])) return $this->fail('缺少签名');
        if (!$this->verify($params)) return $this->fail('签名验证失败');
        if ((string)($params['app_id'] ?? '') !== (string)$this->config('app_id', '')) return $this->fail('App ID 不匹配');
        $status = (string)($params['trade_status'] ?? '');
        if (!in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) return $this->fail('交易未完成：' . $status);
        return $this->ok([
            'invoice_no' => (string)($params['out_trade_no'] ?? ''),
            'trade_no' => (string)($params['trade_no'] ?? ''),
            'amount' => (float)($params['total_amount'] ?? 0),
            'fee' => 0, 'raw' => $params,
        ]);
    }

    public function ack(array $result): string
    {
        return 'success';
    }
};

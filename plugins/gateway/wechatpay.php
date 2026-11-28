<?php
/**
 * 微信支付 API v3（Native 扫码）
 */
return new class extends \plugins\Gateway {
    public function info(): array
    {
        return ['id' => 'wechatpay', 'title' => '微信支付', 'version' => '1.0', 'author' => 'idceasy',
            'description' => '微信支付 API v3 Native 下单，支持回调验签与 AES-GCM 解密。',
            'fields' => [
                ['name' => 'app_id', 'label' => 'AppID', 'type' => 'text', 'required' => true],
                ['name' => 'mch_id', 'label' => '商户号', 'type' => 'text', 'required' => true],
                ['name' => 'serial_no', 'label' => '证书序列号', 'type' => 'text', 'required' => true],
                ['name' => 'private_key', 'label' => '商户私钥', 'type' => 'textarea', 'required' => true],
                ['name' => 'api_v3_key', 'label' => 'APIv3 密钥', 'type' => 'password', 'required' => true],
                ['name' => 'platform_public_key', 'label' => '平台公钥', 'type' => 'textarea', 'help' => '可选，填写后校验回调签名'],
            ]];
    }
    protected function pem(string $key): string
    {
        $key = trim($key);
        if (str_contains($key, 'BEGIN')) return $key;
        return "-----BEGIN PRIVATE KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $key), 64, "\n") . "-----END PRIVATE KEY-----\n";
    }
    protected function sign(string $method, string $path, string $body): array
    {
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $message = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $key = openssl_pkey_get_private($this->pem((string)$this->config('private_key', '')));
        if (!$key) throw new \RuntimeException('微信支付商户私钥格式错误');
        openssl_sign($message, $sig, $key, OPENSSL_ALGO_SHA256);
        $auth = 'WECHATPAY2-SHA256-RSA2048 mchid="' . $this->config('mch_id', '') . '",nonce_str="' . $nonce
            . '",signature="' . base64_encode($sig) . '",timestamp="' . $timestamp . '",serial_no="' . $this->config('serial_no', '') . '"';
        return ['Authorization: ' . $auth, 'Accept: application/json', 'Content-Type: application/json',
            'User-Agent: idceasy/1.0'];
    }
    public function pay(array $invoice, string $returnUrl, string $notifyUrl): array
    {
        $due = round((float)$invoice['total'] - (float)$invoice['paid'], 2);
        if ($due <= 0) return $this->fail('账单已结清');
        $path = '/v3/pay/transactions/native';
        $body = json_encode([
            'appid' => (string)$this->config('app_id', ''),
            'mchid' => (string)$this->config('mch_id', ''),
            'description' => mb_substr('账单 ' . $invoice['invoice_no'], 0, 120),
            'out_trade_no' => $invoice['invoice_no'],
            'notify_url' => $notifyUrl,
            'amount' => ['total' => (int)round($due * 100), 'currency' => 'CNY'],
        ], JSON_UNESCAPED_UNICODE);
        $headers = $this->sign('POST', $path, $body);
        $res = $this->http('POST', 'https://api.mch.weixin.qq.com' . $path, [], $headers, ['raw' => $body]);
        if ($res['error'] !== '') return $this->fail('网络错误：' . $res['error']);
        $j = $res['json'];
        if (!is_array($j) || empty($j['code_url'])) {
            return $this->fail('微信支付: ' . ($j['message'] ?? mb_substr($res['body'], 0, 160)));
        }
        return $this->ok(['type' => 'qrcode', 'qr' => $j['code_url'], 'html' => '']);
    }
    protected function decrypt(array $resource): ?array
    {
        $cipher = base64_decode((string)($resource['ciphertext'] ?? ''), true);
        if ($cipher === false || strlen($cipher) < 17) return null;
        $tag = substr($cipher, -16);
        $data = substr($cipher, 0, -16);
        $plain = openssl_decrypt($data, 'aes-256-gcm', (string)$this->config('api_v3_key', ''),
            OPENSSL_RAW_DATA, (string)($resource['nonce'] ?? ''), $tag, (string)($resource['associated_data'] ?? ''));
        if ($plain === false) return null;
        $j = json_decode($plain, true);
        return is_array($j) ? $j : null;
    }
    protected function verifyNotify(string $raw): bool
    {
        $pub = trim((string)$this->config('platform_public_key', ''));
        if ($pub === '') return true;
        $timestamp = $_SERVER['HTTP_WECHATPAY_TIMESTAMP'] ?? '';
        $nonce = $_SERVER['HTTP_WECHATPAY_NONCE'] ?? '';
        $signature = $_SERVER['HTTP_WECHATPAY_SIGNATURE'] ?? '';
        if (!$timestamp || !$signature) return false;
        if (abs(time() - (int)$timestamp) > 300) return false;
        $message = $timestamp . "\n" . $nonce . "\n" . $raw . "\n";
        if (!str_contains($pub, 'BEGIN')) {
            $pub = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $pub), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }
        $key = openssl_pkey_get_public($pub);
        return $key && openssl_verify($message, base64_decode($signature), $key, OPENSSL_ALGO_SHA256) === 1;
    }
    public function notify(array $params, string $raw = ''): array
    {
        if ($raw === '') return $this->fail('缺少回调报文');
        if (!$this->verifyNotify($raw)) return $this->fail('回调签名验证失败');
        $j = json_decode($raw, true);
        if (!is_array($j)) return $this->fail('报文格式错误');
        $data = $this->decrypt((array)($j['resource'] ?? []));
        if (!$data) return $this->fail('回调解密失败');
        if (($data['trade_state'] ?? '') !== 'SUCCESS') return $this->fail('交易状态：' . ($data['trade_state'] ?? '未知'));
        return $this->ok([
            'invoice_no' => (string)($data['out_trade_no'] ?? ''),
            'trade_no' => (string)($data['transaction_id'] ?? ''),
            'amount' => ((int)($data['amount']['total'] ?? 0)) / 100,
            'fee' => 0, 'raw' => $data,
        ]);
    }
    public function ack(array $result): string
    {
        return json_encode(['code' => 'SUCCESS', 'message' => '成功'], JSON_UNESCAPED_UNICODE);
    }
};

<?php
/**
 * idceasy 魔方财务桥接客户端
 * 在魔方财务 / 自有系统中引入本文件，即可调用 idceasy Bridge v1。
 *
 *   $ice = new IdceasyBridge('https://idceasy.site', '你的 Bridge Key');
 *   $ice->ping();
 *   $ice->createClient(['email' => 'a@b.com', 'name' => '张三']);
 *   $ice->createOrder($userId, [['product_id' => 1, 'cycle' => 'annual', 'domain' => 'x.com']]);
 */
class IdceasyBridge
{
    protected string $baseUrl;
    protected string $key;
    protected bool $sign = false;
    protected int $timeout = 30;

    public function __construct(string $baseUrl, string $key, bool $sign = false)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->key = $key;
        $this->sign = $sign;
    }

    public function ping(): array
    {
        return $this->call('ping');
    }

    public function stats(): array
    {
        return $this->call('stats');
    }

    public function createClient(array $data): array
    {
        return $this->call('client.create', $data);
    }

    public function updateClient(array $data): array
    {
        return $this->call('client.update', $data);
    }

    public function clients(int $limit = 50): array
    {
        return $this->call('client.list', ['limit' => $limit]);
    }

    public function products(): array
    {
        return $this->call('product.list');
    }

    public function createOrder(int $userId, array $items, array $extra = []): array
    {
        return $this->call('order.create', array_merge(['user_id' => $userId, 'items' => $items], $extra));
    }

    public function orders(int $limit = 50): array
    {
        return $this->call('order.list', ['limit' => $limit]);
    }

    public function createInvoice(int $userId, array $items, array $extra = []): array
    {
        return $this->call('invoice.create', array_merge(['user_id' => $userId, 'items' => $items], $extra));
    }

    public function payInvoice(int $invoiceId, float $amount, string $tradeNo, string $gateway = 'cube'): array
    {
        return $this->call('invoice.pay', [
            'invoice_id' => $invoiceId, 'amount' => $amount, 'trade_no' => $tradeNo, 'gateway' => $gateway,
        ]);
    }

    public function hostAction(int $hostId, string $action, array $extra = []): array
    {
        return $this->call('host.action', array_merge(['id' => $hostId, 'action' => $action], $extra));
    }

    public function domainAction(int $domainId, string $action, array $extra = []): array
    {
        return $this->call('domain.action', array_merge(['id' => $domainId, 'action' => $action], $extra));
    }

    public function call(string $action, array $data = []): array
    {
        $payload = json_encode(['action' => $action, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type: application/json', 'X-ICE-KEY: ' . $this->key];
        if ($this->sign) {
            $ts = (string)time();
            $headers[] = 'X-ICE-TIMESTAMP: ' . $ts;
            $headers[] = 'X-ICE-SIGN: ' . hash_hmac('sha256', $ts . $payload, $this->key);
        }
        $ch = curl_init($this->baseUrl . '/api/cube');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) return ['code' => -1, 'msg' => '网络错误：' . $error, 'data' => null];
        $json = json_decode($body, true);
        return is_array($json) ? $json : ['code' => $code, 'msg' => '返回异常', 'data' => $body];
    }
}

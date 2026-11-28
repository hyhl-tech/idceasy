<?php
/**
 * 支付网关插件基类
 *
 * pay() 返回：
 *   ['ok'=>true,'type'=>'redirect','url'=>'https://...']
 *   ['ok'=>true,'type'=>'html','html'=>'<form>...</form>']
 *   ['ok'=>true,'type'=>'qrcode','qr'=>'weixin://...','html'=>'(可选)']
 *   ['ok'=>false,'error'=>'...']
 *
 * notify() 返回：
 *   ['ok'=>true,'invoice_no'=>'INV...','trade_no'=>'...','amount'=>99.00,'fee'=>0.6,'raw'=>[]]
 *   ['ok'=>false,'error'=>'签名错误','ack'=>'fail']
 */
namespace plugins;

require_once __DIR__ . '/PluginBase.php';
require_once __DIR__ . '/Http.php';

abstract class Gateway extends PluginBase
{
    use Http;

    abstract public function pay(array $invoice, string $returnUrl, string $notifyUrl): array;

    abstract public function notify(array $params, string $raw = ''): array;

    public function query(string $invoiceNo): array
    {
        return $this->fail('该网关不支持主动查单');
    }

    public function refund(array $invoice, float $amount, string $reason = ''): array
    {
        return $this->fail('该网关不支持在线退款，请在商户后台操作');
    }

    /** 网关手续费（用于报表估算） */
    public function fee(float $amount): float
    {
        return 0.0;
    }

    /** 异步通知需要返回给网关的应答内容 */
    public function ack(array $result): string
    {
        return 'success';
    }

    public function isOnline(): bool
    {
        return true;
    }

    protected function invoiceNo(array $invoice): string
    {
        return (string)($invoice['invoice_no'] ?? '');
    }
}

<?php
/**
 * 支付回调（异步通知 / 同步返回），不做登录校验，由网关签名验证
 */
namespace app\controller;

use app\service\Billing;
use think\Db;
use think\Plugin;
use think\Response;

class Payment extends Front
{
    public function notify(string $gateway): Response
    {
        $params = array_merge($_GET, $_POST);
        $raw = $this->request->raw();
        $record = Plugin::record('gateway', $gateway);
        Db::table('payment_log')->insert([
            'invoice_id' => 0, 'gateway' => $gateway, 'action' => 'notify',
            'request' => json_encode($params, JSON_UNESCAPED_UNICODE) . "\n" . $raw,
            'response' => '', 'ip' => client_ip(), 'created_at' => now(),
        ]);
        if (!$record || (int)$record['status'] !== 1) return Response::text('gateway disabled', 400);
        try {
            $plugin = Plugin::make('gateway', $gateway, json_decode((string)$record['config'], true) ?: []);
            $result = $plugin->notify($params, $raw);
        } catch (\Throwable $e) {
            log_write('payment', '回调异常 ' . $gateway . ': ' . $e->getMessage());
            return Response::text('fail', 500);
        }
        if (empty($result['ok'])) {
            log_write('payment', '回调验证失败 ' . $gateway . ': ' . ($result['error'] ?? ''));
            return Response::text($result['ack'] ?? 'fail', 400);
        }
        $invoice = Db::table('invoice')->where('invoice_no', $result['invoice_no'])->find();
        if (!$invoice) return Response::text('invoice not found', 404);
        Billing::markPaid((int)$invoice['id'], $gateway, (string)($result['trade_no'] ?? ''),
            (float)($result['amount'] ?? 0), (float)($result['fee'] ?? 0), '网关异步通知');
        return Response::text($plugin->ack($result));
    }

    public function return(string $gateway): Response
    {
        $params = array_merge($_GET, $_POST);
        $record = Plugin::record('gateway', $gateway);
        if (!$record) return $this->redirect(url('/user'));
        $invoice = null;
        try {
            $plugin = Plugin::make('gateway', $gateway, json_decode((string)$record['config'], true) ?: []);
            $result = $plugin->notify($params, $this->request->raw());
            if (!empty($result['ok'])) {
                $invoice = Db::table('invoice')->where('invoice_no', $result['invoice_no'])->find();
                if ($invoice) {
                    Billing::markPaid((int)$invoice['id'], $gateway, (string)($result['trade_no'] ?? ''),
                        (float)($result['amount'] ?? 0), (float)($result['fee'] ?? 0), '网关同步返回');
                }
            }
        } catch (\Throwable $e) {
            log_write('payment', '同步返回异常 ' . $gateway . ': ' . $e->getMessage());
        }
        if ($invoice) return $this->redirect(url('/invoice/' . $invoice['id']));
        return $this->view('message', ['title' => '支付结果确认中', 'message' => '支付结果正在异步确认，请稍后刷新账单页面。']);
    }
}

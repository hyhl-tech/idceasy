<?php
/**
 * 前台：账单、支付
 */
namespace app\controller;

use app\service\Billing;
use think\Db;
use think\Plugin;
use think\Response;
use think\Session;

class Invoice extends Front
{
    public function index(): Response
    {
        $user = $this->user();
        $q = Db::table('invoice')->where('user_id', $user['id']);
        $status = (string)$this->request->param('status', '');
        if (in_array($status, ['unpaid', 'paid', 'refunded', 'cancelled'], true)) $q->where('status', $status);
        $list = $q->order('id', 'desc')->paginate(15, $this->pageNo());
        return $this->view('invoice_list', ['list' => $list, 'status' => $status]);
    }

    public function detail(int $id): Response
    {
        $user = $this->user();
        $invoice = Db::table('invoice')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$invoice) return $this->notFound('账单不存在');
        $items = Db::table('invoice_item')->where('invoice_id', $id)->select();
        $transactions = Db::table('transaction')->where('invoice_id', $id)->order('id', 'desc')->select();
        $order = $invoice['order_id'] ? Db::table('order')->where('id', $invoice['order_id'])->find() : null;
        return $this->view('invoice_detail', compact('invoice', 'items', 'transactions', 'order'));
    }

    public function printView(int $id): Response
    {
        $user = $this->user();
        $invoice = Db::table('invoice')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$invoice) return $this->notFound('账单不存在');
        $items = Db::table('invoice_item')->where('invoice_id', $id)->select();
        return $this->view('invoice_print', compact('invoice', 'items', 'user'));
    }

    public function pay(int $id): Response
    {
        $user = $this->user();
        $invoice = Db::table('invoice')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$invoice) return $this->notFound('账单不存在');
        if ($invoice['status'] === 'paid') return $this->redirect(url('/invoice/' . $id));
        $gateways = [];
        foreach (Plugin::discover('gateway') as $name => $info) {
            $record = Plugin::record('gateway', $name);
            if (!$record || (int)$record['status'] !== 1) continue;
            $gateways[$name] = $info;
        }
        $due = (float)$invoice['total'] - (float)$invoice['paid'];
        return $this->view('invoice_pay', compact('invoice', 'gateways', 'due'));
    }

    public function payGateway(int $id, string $gateway): Response
    {
        $user = $this->user();
        $invoice = Db::table('invoice')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$invoice) return $this->notFound('账单不存在');
        if ($invoice['status'] === 'paid') return $this->redirect(url('/invoice/' . $id));
        if ($gateway === 'balance') return $this->balance($id);
        $record = Plugin::record('gateway', $gateway);
        if (!$record || (int)$record['status'] !== 1) return $this->error('支付方式未启用');
        try {
            $plugin = Plugin::make('gateway', $gateway, json_decode((string)$record['config'], true) ?: []);
            $result = $plugin->pay($invoice, abs_url('/pay/return/' . $gateway), abs_url('/pay/notify/' . $gateway));
        } catch (\Throwable $e) {
            log_write('payment', '网关调用失败 ' . $gateway . ': ' . $e->getMessage());
            return $this->error('支付网关异常：' . $e->getMessage());
        }
        if (empty($result['ok'])) return $this->error($result['error'] ?? '无法发起支付');
        $type = $result['type'] ?? 'redirect';
        if ($type === 'redirect' && !empty($result['url'])) return $this->redirect($result['url']);
        return $this->view('invoice_gateway', [
            'invoice' => $invoice, 'gateway' => $gateway, 'result' => $result,
        ]);
    }

    public function topup(): Response
    {
        $user = $this->user();
        if ($this->request->isPost()) {
            try {
                $res = Billing::createTopup((int)$user['id'], (float)$this->request->param('amount'));
            } catch (\Throwable $e) {
                return $this->error($e->getMessage());
            }
            return $this->redirect(url('/invoice/' . $res['invoice_id'] . '/pay'));
        }
        return $this->view('user/topup');
    }

    public function balance(int $id): Response
    {
        $user = $this->user();
        $result = Billing::payWithBalance($id, (int)$user['id']);
        if (!$result['ok']) return $this->error($result['error'], url('/invoice/' . $id . '/pay'));
        return $this->success('余额支付成功', url('/invoice/' . $id));
    }
}

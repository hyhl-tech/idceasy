<?php
/**
 * 后台：账单与收款
 */
namespace app\controller\admin;

use app\service\Billing;
use app\service\Mailer;
use think\Db;
use think\Response;

class Invoice extends Base
{
    protected string $perm = 'invoice';

    public function index(): Response
    {
        $q = Db::table('invoice');
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $uid = (int)Db::table('user')->where('email', $keyword)->value('id', 0);
            $q->whereRaw('(invoice_no LIKE ? OR id = ? OR user_id = ?)', ['%' . $keyword . '%', (int)$keyword, $uid]);
        }
        $status = (string)$this->request->param('status', '');
        if ($status !== '') $q->where('status', $status);
        $pagination = $q->order('id', 'desc')->paginate(20, $this->pageNo());
        foreach ($pagination['data'] as &$row) {
            $row['user'] = Db::table('user')->where('id', $row['user_id'])->find();
            $row['due'] = (float)$row['total'] - (float)$row['paid'];
        }
        unset($row);
        return $this->view('table', [
            'title' => '账单管理',
            'columns' => [
                ['field' => 'invoice_no', 'label' => '账单号'],
                ['field' => 'user.email', 'label' => '客户'],
                ['field' => 'total', 'label' => '金额', 'type' => 'money'],
                ['field' => 'due', 'label' => '待收', 'type' => 'money'],
                ['field' => 'status', 'label' => '状态', 'type' => 'status'],
                ['field' => 'due_at', 'label' => '到期', 'type' => 'date'],
                ['field' => 'created_at', 'label' => '创建', 'type' => 'date'],
            ],
            'rows' => $pagination,
            'rowLinks' => [['label' => '详情', 'url' => admin_url('/invoice/__ID__'), 'class' => 'btn-sm']],
            'filters' => [
                ['type' => 'text', 'name' => 'keyword', 'placeholder' => '账单号 / 邮箱 / ID', 'value' => $keyword],
                ['type' => 'select', 'name' => 'status', 'options' => [
                    'unpaid' => '未支付', 'paid' => '已支付', 'refunded' => '已退款', 'cancelled' => '已取消',
                ], 'value' => $status],
            ],
            'topActions' => [['label' => '创建账单', 'url' => admin_url('/invoice/create'), 'class' => 'btn-primary']],
            'baseUrl' => admin_url('/invoices'),
        ]);
    }

    public function detail(int $id): Response
    {
        $invoice = Db::table('invoice')->where('id', $id)->find();
        if (!$invoice) return $this->error('账单不存在', admin_url('/invoices'));
        $user = Db::table('user')->where('id', $invoice['user_id'])->find();
        $items = Db::table('invoice_item')->where('invoice_id', $id)->select();
        $transactions = Db::table('transaction')->where('invoice_id', $id)->order('id', 'desc')->select();
        $order = $invoice['order_id'] ? Db::table('order')->where('id', $invoice['order_id'])->find() : null;
        $logs = Db::table('payment_log')->where('invoice_id', $id)->order('id', 'desc')->limit(20)->select();
        return $this->view('invoice', compact('invoice', 'user', 'items', 'transactions', 'order', 'logs'));
    }

    public function printView(int $id): Response
    {
        $invoice = Db::table('invoice')->where('id', $id)->find();
        if (!$invoice) return $this->error('账单不存在');
        $user = Db::table('user')->where('id', $invoice['user_id'])->find();
        $items = Db::table('invoice_item')->where('invoice_id', $id)->select();
        $this->theme = 'default';
        return $this->view('invoice_print', compact('invoice', 'items', 'user'));
    }

    public function markPaid(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/invoice/' . $id));
        $amount = (float)$this->request->param('amount');
        if ($amount <= 0) return $this->error('金额必须大于 0');
        $res = Billing::markPaid($id, (string)$this->request->param('gateway', 'manual'),
            (string)$this->request->param('trade_no', ''), $amount, 0, '管理员手动入账');
        if (!$res['ok']) return $this->error($res['error']);
        $this->logAction('手动入账', ['invoice_id' => $id, 'amount' => $amount]);
        return $this->success('已入账');
    }

    public function refund(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/invoice/' . $id));
        $res = Billing::refund($id, (float)$this->request->param('amount'), (string)$this->request->param('reason', '管理员退款'));
        if (!$res['ok']) return $this->error($res['error']);
        $this->logAction('账单退款', ['invoice_id' => $id, 'amount' => $res['amount']]);
        return $this->success('退款已记入客户余额');
    }

    public function cancel(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/invoice/' . $id));
        Db::table('invoice')->where('id', $id)->where('status', 'unpaid')->update(['status' => 'cancelled']);
        $this->logAction('取消账单', ['invoice_id' => $id]);
        return $this->success('账单已取消');
    }

    public function send(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/invoice/' . $id));
        $invoice = Db::table('invoice')->where('id', $id)->find();
        $user = $invoice ? Db::table('user')->where('id', $invoice['user_id'])->find() : null;
        if (!$invoice || !$user) return $this->error('账单不存在');
        Mailer::sendTemplate('invoice_created', $user['email'], [
            'name' => $user['name'] ?: $user['email'], 'invoice_no' => $invoice['invoice_no'],
            'total' => money((float)$invoice['total'] - (float)$invoice['paid'], $invoice['currency']),
            'due_at' => $invoice['due_at'], 'url' => abs_url('/invoice/' . $id),
        ], false);
        $this->logAction('发送账单提醒', ['invoice_id' => $id]);
        return $this->success('提醒邮件已发送');
    }

    public function create(): Response
    {
        if (!$this->request->isPost()) {
            return $this->view('invoice_create');
        }
        $user = Db::table('user')->where('id', $this->request->int('user_id'))->find();
        if (!$user) return $this->error('客户不存在');
        $lines = [];
        $desc = array_values((array)$this->request->param('description', []));
        $amounts = array_values((array)$this->request->param('amount', []));
        foreach ($desc as $i => $d) {
            $d = trim((string)$d);
            $a = (float)($amounts[$i] ?? 0);
            if ($d === '' || abs($a) < 0.001) continue;
            $lines[] = ['description' => $d, 'amount' => $a, 'qty' => 1];
        }
        if (!$lines) return $this->error('请至少填写一项金额');
        $invoice = Billing::createInvoice((int)$user['id'], $lines, [
            'due_days' => $this->request->int('due_days', 7), 'note' => (string)$this->request->param('note'),
            'send_mail' => $this->request->bool('send_mail'),
        ]);
        $this->logAction('创建账单', ['invoice_id' => $invoice['id'], 'user_id' => $user['id'], 'total' => $invoice['total']]);
        return $this->success('账单已创建', admin_url('/invoice/' . $invoice['id']));
    }
}

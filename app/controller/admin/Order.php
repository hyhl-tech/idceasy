<?php
/**
 * 后台：订单
 */
namespace app\controller\admin;

use app\service\Billing;
use think\Db;
use think\Response;

class Order extends Base
{
    protected string $perm = 'order';

    public function index(): Response
    {
        $q = Db::table('order');
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $uid = (int)Db::table('user')->where('email', $keyword)->value('id', 0);
            $q->whereRaw('(order_no LIKE ? OR id = ? OR user_id = ?)',
                ['%' . $keyword . '%', (int)$keyword, $uid]);
        }
        $status = (string)$this->request->param('status', '');
        if ($status !== '') $q->where('status', $status);
        $pagination = $q->order('id', 'desc')->paginate(20, $this->pageNo());
        foreach ($pagination['data'] as &$row) {
            $row['user'] = Db::table('user')->where('id', $row['user_id'])->find();
            $row['item_count'] = Db::table('order_item')->where('order_id', $row['id'])->count();
        }
        unset($row);
        return $this->view('table', [
            'title' => '订单管理',
            'columns' => [
                ['field' => 'order_no', 'label' => '订单号'],
                ['field' => 'user.email', 'label' => '客户'],
                ['field' => 'total', 'label' => '金额', 'type' => 'money'],
                ['field' => 'status', 'label' => '状态', 'type' => 'status'],
                ['field' => 'item_count', 'label' => '项目', 'type' => 'text'],
                ['field' => 'created_at', 'label' => '下单时间', 'type' => 'date'],
            ],
            'rows' => $pagination,
            'rowLinks' => [['label' => '详情', 'url' => admin_url('/order/__ID__'), 'class' => 'btn-sm']],
            'filters' => [
                ['type' => 'text', 'name' => 'keyword', 'placeholder' => '订单号 / 邮箱 / ID', 'value' => $keyword],
                ['type' => 'select', 'name' => 'status', 'options' => [
                    'pending' => '待支付', 'paid' => '已支付', 'active' => '已开通', 'cancelled' => '已取消', 'fraud' => '风险',
                ], 'value' => $status],
            ],
            'baseUrl' => admin_url('/orders'),
        ]);
    }

    public function detail(int $id): Response
    {
        $order = Db::table('order')->where('id', $id)->find();
        if (!$order) return $this->error('订单不存在', admin_url('/orders'));
        $user = Db::table('user')->where('id', $order['user_id'])->find();
        $items = Db::table('order_item')->where('order_id', $id)->select();
        foreach ($items as &$item) {
            $item['product'] = Db::table('product')->where('id', $item['product_id'])->find();
        }
        unset($item);
        $invoice = Db::table('invoice')->where('order_id', $id)->find();
        return $this->view('order', compact('order', 'user', 'items', 'invoice'));
    }

    public function status(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/order/' . $id));
        $status = (string)$this->request->param('status');
        if (!in_array($status, ['pending', 'paid', 'active', 'cancelled', 'fraud', 'refunded'], true)) {
            return $this->error('无效状态');
        }
        Db::table('order')->where('id', $id)->update(['status' => $status]);
        $this->logAction('修改订单状态', ['order_id' => $id, 'status' => $status]);
        return $this->success('订单状态已更新');
    }

    public function activate(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/order/' . $id));
        Billing::activateOrder($id);
        $this->logAction('手动开通订单', ['order_id' => $id]);
        return $this->success('已执行开通流程，请检查服务状态');
    }
}

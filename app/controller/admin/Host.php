<?php
/**
 * 后台：主机服务
 */
namespace app\controller\admin;

use app\service\Provision;
use think\Db;
use think\Response;

class Host extends Base
{
    protected string $perm = 'host';

    public function index(): Response
    {
        $q = Db::table('host');
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $q->whereRaw('(domain LIKE ? OR username LIKE ? OR id = ?)', ['%' . $keyword . '%', '%' . $keyword . '%', (int)$keyword]);
        }
        $status = (string)$this->request->param('status', '');
        if ($status !== '') $q->where('status', $status);
        $pagination = $q->order('id', 'desc')->paginate(20, $this->pageNo());
        foreach ($pagination['data'] as &$row) {
            $row['user'] = Db::table('user')->where('id', $row['user_id'])->find();
            $row['product'] = Db::table('product')->where('id', $row['product_id'])->find();
        }
        unset($row);
        return $this->view('table', [
            'title' => '服务管理',
            'columns' => [
                ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                ['field' => 'domain', 'label' => '域名 / 标识'],
                ['field' => 'user.email', 'label' => '客户'],
                ['field' => 'product.name', 'label' => '产品'],
                ['field' => 'module', 'label' => '模块'],
                ['field' => 'status', 'label' => '状态', 'type' => 'status'],
                ['field' => 'next_due', 'label' => '下次到期', 'type' => 'date'],
            ],
            'rows' => $pagination,
            'rowLinks' => [['label' => '管理', 'url' => admin_url('/host/__ID__'), 'class' => 'btn-sm']],
            'filters' => [
                ['type' => 'text', 'name' => 'keyword', 'placeholder' => '域名 / 用户名 / ID', 'value' => $keyword],
                ['type' => 'select', 'name' => 'status', 'options' => [
                    'pending' => '待开通', 'active' => '正常', 'suspended' => '暂停', 'terminated' => '终止',
                ], 'value' => $status],
            ],
            'topActions' => [['label' => '添加服务', 'url' => admin_url('/host/create'), 'class' => 'btn-primary']],
            'baseUrl' => admin_url('/hosts'),
        ]);
    }

    public function detail(int $id): Response
    {
        $host = Db::table('host')->where('id', $id)->find();
        if (!$host) return $this->error('服务不存在', admin_url('/hosts'));
        $user = Db::table('user')->where('id', $host['user_id'])->find();
        $product = Db::table('product')->where('id', $host['product_id'])->find();
        $server = $host['server_id'] ? Db::table('server')->where('id', $host['server_id'])->find() : null;
        $usage = in_array($host['status'], ['active', 'suspended'], true) ? Provision::usage($id) : null;
        $plainPassword = decrypt_str($host['password']);
        return $this->view('host', compact('host', 'user', 'product', 'server', 'usage', 'plainPassword'));
    }

    public function action(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/host/' . $id));
        $host = Db::table('host')->where('id', $id)->find();
        if (!$host) return $this->error('服务不存在');
        $action = (string)$this->request->param('action');
        switch ($action) {
            case 'provision':
                $res = Provision::create($id);
                break;
            case 'suspend':
                $res = Provision::suspend($id);
                break;
            case 'unsuspend':
                $res = Provision::unsuspend($id);
                break;
            case 'terminate':
                $res = Provision::terminate($id);
                break;
            case 'activate':
                Db::table('host')->where('id', $id)->update(['status' => 'active', 'updated_at' => now()]);
                $res = ['ok' => true];
                break;
            case 'password':
                $password = (string)$this->request->param('password');
                if (strlen($password) < 8) return $this->error('密码至少 8 位');
                $res = Provision::changePassword($id, $password);
                break;
            case 'login':
                $res = Provision::loginUrl($id);
                if (!empty($res['ok'])) return $this->redirect($res['url']);
                return $this->error($res['error'] ?? '无法登录面板');
            case 'renew':
                \app\service\Billing::renewHost($id, (string)$this->request->param('cycle', $host['cycle']));
                $res = ['ok' => true];
                break;
            default:
                return $this->error('未知操作');
        }
        $this->logAction('服务操作', ['host_id' => $id, 'action' => $action, 'result' => $res]);
        return !empty($res['ok']) ? $this->success('操作完成') : $this->error($res['error'] ?? '操作失败');
    }

    public function create(): Response
    {
        if (!$this->request->isPost()) {
            $clients = Db::table('user')->order('id', 'desc')->limit(200)->select();
            $products = Db::table('product')->order('sort')->select();
            return $this->view('host_form', compact('clients', 'products'));
        }
        $user = Db::table('user')->where('id', $this->request->int('user_id'))->find();
        $product = Db::table('product')->where('id', $this->request->int('product_id'))->find();
        if (!$user || !$product) return $this->error('请选择客户与产品');
        $cycle = (string)$this->request->param('cycle', 'monthly');
        if (!in_array($cycle, cycles(), true)) $cycle = 'monthly';
        $domain = trim((string)$this->request->param('domain'));
        if ($domain === '') return $this->error('请填写域名或服务标识');
        $password = (string)$this->request->param('password') ?: generate_password(14);
        $id = Db::table('host')->insert([
            'user_id' => $user['id'], 'order_id' => 0, 'product_id' => $product['id'], 'server_id' => 0,
            'domain' => $domain, 'username' => trim((string)$this->request->param('username')) ?: Provision::makeUsername($domain),
            'password' => encrypt_str($password), 'status' => 'pending', 'module' => $product['module'],
            'cycle' => $cycle,
            'next_due' => date('Y-m-d H:i:s', strtotime('+' . cycle_months($cycle) . ' months')),
            'notes' => (string)$this->request->param('notes'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($this->request->bool('provision')) {
            $res = Provision::create($id);
            if (empty($res['ok'])) return $this->error('服务已创建，但自动开通失败：' . ($res['error'] ?? ''));
        }
        $this->logAction('创建服务', ['host_id' => $id, 'user_id' => $user['id']]);
        return $this->success('服务已创建', admin_url('/host/' . $id));
    }
}

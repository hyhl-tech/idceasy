<?php
/**
 * 后台：客户管理
 */
namespace app\controller\admin;

use app\service\Billing;
use app\service\Mailer;
use think\Db;
use think\Response;
use think\Session;

class Client extends Base
{
    protected string $perm = 'client';

    public function index(): Response
    {
        $q = Db::table('user');
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $q->whereRaw('(email LIKE ? OR name LIKE ? OR company LIKE ? OR id = ?)',
                ['%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%', (int)$keyword]);
        }
        $status = (string)$this->request->param('status', '');
        if ($status !== '') $q->where('status', (int)$status);
        $pagination = $q->order('id', 'desc')->paginate(20, $this->pageNo());
        return $this->view('table', [
            'title' => '客户管理',
            'columns' => [
                ['field' => 'id', 'label' => 'ID', 'type' => 'text', 'width' => '70px'],
                ['field' => 'email', 'label' => '邮箱'],
                ['field' => 'name', 'label' => '姓名'],
                ['field' => 'company', 'label' => '公司'],
                ['field' => 'balance', 'label' => '余额', 'type' => 'money'],
                ['field' => 'status', 'label' => '状态', 'type' => 'status'],
                ['field' => 'created_at', 'label' => '注册时间', 'type' => 'date'],
            ],
            'rows' => $pagination,
            'rowLinks' => [
                ['label' => '详情', 'url' => admin_url('/client/__ID__'), 'class' => 'btn-sm'],
            ],
            'filters' => [
                ['type' => 'text', 'name' => 'keyword', 'placeholder' => '邮箱 / 姓名 / 公司 / ID', 'value' => $keyword],
                ['type' => 'select', 'name' => 'status', 'options' => ['1' => '正常', '0' => '禁用'], 'value' => $status],
            ],
            'topActions' => [['label' => '新建客户', 'url' => admin_url('/client/create'), 'class' => 'btn-primary']],
            'baseUrl' => admin_url('/clients'),
        ]);
    }

    public function detail(int $id): Response
    {
        $client = Db::table('user')->where('id', $id)->find();
        if (!$client) return $this->error('客户不存在', admin_url('/clients'));
        $hosts = Db::table('host')->where('user_id', $id)->order('id', 'desc')->select();
        foreach ($hosts as &$h) $h['product'] = Db::table('product')->where('id', $h['product_id'])->find();
        unset($h);
        $domains = Db::table('domain')->where('user_id', $id)->order('id', 'desc')->select();
        $invoices = Db::table('invoice')->where('user_id', $id)->order('id', 'desc')->limit(20)->select();
        $orders = Db::table('order')->where('user_id', $id)->order('id', 'desc')->limit(20)->select();
        $tickets = Db::table('ticket')->where('user_id', $id)->order('id', 'desc')->limit(20)->select();
        $transactions = Db::table('transaction')->where('user_id', $id)->order('id', 'desc')->limit(20)->select();
        $logs = Db::table('user_login_log')->where('user_id', $id)->order('id', 'desc')->limit(10)->select();
        return $this->view('client', compact('client', 'hosts', 'domains', 'invoices', 'orders', 'tickets', 'transactions', 'logs'));
    }

    public function create(): Response
    {
        if (!$this->request->isPost()) return $this->view('client_form', ['client' => null]);
        $email = strtolower(trim((string)$this->request->param('email')));
        $password = (string)$this->request->param('password');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->error('邮箱格式不正确');
        if (strlen($password) < 8) return $this->error('密码至少 8 位');
        if (Db::table('user')->where('email', $email)->exists()) return $this->error('邮箱已存在');
        $id = Db::table('user')->insert([
            'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT),
            'name' => trim((string)$this->request->param('name')), 'company' => trim((string)$this->request->param('company')),
            'phone' => trim((string)$this->request->param('phone')), 'currency' => (string)$this->request->param('currency', 'CNY'),
            'balance' => (float)$this->request->param('balance', 0), 'credit_limit' => (float)$this->request->param('credit_limit', 0),
            'status' => 1, 'email_verified' => 1, 'api_key' => 'ice_' . random_str(40),
            'affiliate_code' => random_str(10), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->logAction('创建客户', ['id' => $id, 'email' => $email]);
        return $this->success('客户已创建', admin_url('/client/' . $id));
    }

    public function update(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/client/' . $id));
        $client = Db::table('user')->where('id', $id)->find();
        if (!$client) return $this->error('客户不存在');
        $data = [
            'name' => trim((string)$this->request->param('name')),
            'company' => trim((string)$this->request->param('company')),
            'phone' => trim((string)$this->request->param('phone')),
            'country' => trim((string)$this->request->param('country', 'CN')),
            'province' => trim((string)$this->request->param('province')),
            'city' => trim((string)$this->request->param('city')),
            'address' => trim((string)$this->request->param('address')),
            'postcode' => trim((string)$this->request->param('postcode')),
            'currency' => trim((string)$this->request->param('currency', 'CNY')),
            'credit_limit' => (float)$this->request->param('credit_limit', 0),
            'status' => $this->request->int('status', 1) ? 1 : 0,
            'email_verified' => $this->request->int('email_verified', 0) ? 1 : 0,
            'updated_at' => now(),
        ];
        $newPassword = (string)$this->request->param('password');
        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) return $this->error('密码至少 8 位');
            $data['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }
        Db::table('user')->where('id', $id)->update($data);
        $this->logAction('编辑客户', ['id' => $id, 'fields' => array_keys($data)]);
        return $this->success('客户资料已更新', admin_url('/client/' . $id));
    }

    public function balance(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/client/' . $id));
        $amount = (float)$this->request->param('amount');
        $remark = trim((string)$this->request->param('remark', '管理员调整'));
        if (abs($amount) < 0.01) return $this->error('金额不能为 0');
        Billing::addBalance($id, $amount, $remark, 'admin');
        $this->logAction('调整余额', ['user_id' => $id, 'amount' => $amount, 'remark' => $remark]);
        return $this->success('余额已调整');
    }

    public function mail(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/client/' . $id));
        $client = Db::table('user')->where('id', $id)->find();
        if (!$client) return $this->error('客户不存在');
        $subject = trim((string)$this->request->param('subject'));
        $message = trim((string)$this->request->param('message'));
        if ($subject === '' || $message === '') return $this->error('请填写主题与内容');
        Mailer::send($client['email'], $subject, '<div style="font:15px/1.7 system-ui">' . nl2br(e($message)) . '</div>', false);
        $this->logAction('发送邮件', ['user_id' => $id, 'subject' => $subject]);
        return $this->success('邮件已发送');
    }

    public function loginAs(int $id): Response
    {
        $client = Db::table('user')->where('id', $id)->find();
        if (!$client) return $this->error('客户不存在');
        if ((int)$client['status'] !== 1) return $this->error('该客户已被禁用');
        Session::set('uid', (int)$client['id']);
        $this->logAction('代登录客户', ['user_id' => $id]);
        return $this->redirect(url('/user'));
    }
}

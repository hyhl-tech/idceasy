<?php
/**
 * 后台：域名
 */
namespace app\controller\admin;

use app\service\DomainService;
use think\Db;
use think\Response;

class Domain extends Base
{
    protected string $perm = 'domain';

    public function index(): Response
    {
        $q = Db::table('domain');
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $q->whereRaw('(domain LIKE ? OR id = ?)', ['%' . $keyword . '%', (int)$keyword]);
        }
        $status = (string)$this->request->param('status', '');
        if ($status !== '') $q->where('status', $status);
        $pagination = $q->order('id', 'desc')->paginate(20, $this->pageNo());
        foreach ($pagination['data'] as &$row) {
            $row['user'] = Db::table('user')->where('id', $row['user_id'])->find();
        }
        unset($row);
        return $this->view('table', [
            'title' => '域名管理',
            'columns' => [
                ['field' => 'domain', 'label' => '域名'],
                ['field' => 'user.email', 'label' => '客户'],
                ['field' => 'registrar', 'label' => '注册商'],
                ['field' => 'status', 'label' => '状态', 'type' => 'status'],
                ['field' => 'expire_date', 'label' => '到期时间', 'type' => 'date'],
                ['field' => 'auto_renew', 'label' => '自动续费', 'type' => 'bool'],
            ],
            'rows' => $pagination,
            'rowLinks' => [['label' => '管理', 'url' => admin_url('/domain/__ID__'), 'class' => 'btn-sm']],
            'filters' => [
                ['type' => 'text', 'name' => 'keyword', 'placeholder' => '域名 / ID', 'value' => $keyword],
                ['type' => 'select', 'name' => 'status', 'options' => [
                    'pending' => '待注册', 'active' => '正常', 'expired' => '已过期',
                    'pending_transfer' => '转入中', 'transferred' => '已转出',
                ], 'value' => $status],
            ],
            'topActions' => [['label' => '添加域名', 'url' => admin_url('/domain/create'), 'class' => 'btn-primary']],
            'baseUrl' => admin_url('/domains'),
        ]);
    }

    public function detail(int $id): Response
    {
        $domain = Db::table('domain')->where('id', $id)->find();
        if (!$domain) return $this->error('域名不存在', admin_url('/domains'));
        $user = Db::table('user')->where('id', $domain['user_id'])->find();
        $tld = Db::table('domain_tld')->where('tld', $domain['tld'])->find();
        $ns = json_decode((string)$domain['ns'], true) ?: [];
        $orders = Db::table('order_item')->whereRaw('domain_id = ? OR domain = ?', [$id, $domain['domain']])
            ->order('id', 'desc')->limit(10)->select();
        return $this->view('domain', compact('domain', 'user', 'tld', 'ns', 'orders'));
    }

    public function action(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/domain/' . $id));
        $domain = Db::table('domain')->where('id', $id)->find();
        if (!$domain) return $this->error('域名不存在');
        $action = (string)$this->request->param('action');
        switch ($action) {
            case 'sync':
                $res = DomainService::sync($id);
                break;
            case 'register':
                $res = DomainService::register($id);
                break;
            case 'renew':
                $res = DomainService::renew($id, max(1, $this->request->int('years', 1)));
                break;
            case 'transfer':
                $res = DomainService::transfer($id, (string)$this->request->param('auth_code'));
                break;
            case 'ns':
                $res = DomainService::setNs($id, (array)$this->request->param('ns', []));
                break;
            case 'lock':
                $res = DomainService::setLock($id, $this->request->bool('enable'));
                break;
            case 'privacy':
                $res = DomainService::setPrivacy($id, $this->request->bool('enable'));
                break;
            case 'epp':
                $res = DomainService::getEpp($id);
                break;
            case 'autorenew':
                Db::table('domain')->where('id', $id)->update([
                    'auto_renew' => $this->request->bool('enable') ? 1 : 0, 'updated_at' => now(),
                ]);
                $res = ['ok' => true];
                break;
            case 'update':
                Db::table('domain')->where('id', $id)->update([
                    'expire_date' => (string)$this->request->param('expire_date'),
                    'next_due' => (string)$this->request->param('expire_date'),
                    'status' => (string)$this->request->param('status', $domain['status']),
                    'auto_renew' => $this->request->bool('auto_renew') ? 1 : 0,
                    'updated_at' => now(),
                ]);
                $res = ['ok' => true];
                break;
            case 'delete':
                if ($domain['registrar_domain_id'] !== '' && !in_array($domain['status'], ['expired', 'cancelled'], true)) {
                    return $this->error('已在注册商处注册的域名不能直接删除');
                }
                Db::table('domain')->where('id', $id)->delete();
                $res = ['ok' => true];
                break;
            default:
                return $this->error('未知操作');
        }
        $this->logAction('域名操作', ['domain_id' => $id, 'action' => $action, 'result' => $res]);
        if (!empty($res['ok'])) {
            $msg = $action === 'epp' ? '转移密码：' . ($res['code'] ?? '') : '操作完成';
            return $this->success($msg);
        }
        return $this->error($res['error'] ?? '操作失败');
    }

    public function create(): Response
    {
        if (!$this->request->isPost()) {
            $clients = Db::table('user')->order('id', 'desc')->limit(200)->select();
            $tlds = Db::table('domain_tld')->order('sort')->select();
            return $this->view('domain_form', compact('clients', 'tlds'));
        }
        $domain = strtolower(trim((string)$this->request->param('domain')));
        if (!preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,12}$/', $domain)) {
            return $this->error('域名格式不正确');
        }
        if (Db::table('domain')->where('domain', $domain)->exists()) return $this->error('域名已存在');
        $user = Db::table('user')->where('id', $this->request->int('user_id'))->find();
        if (!$user) return $this->error('客户不存在');
        $tld = substr($domain, strrpos($domain, '.') + 1);
        $tldRow = Db::table('domain_tld')->where('tld', $tld)->find();
        $expire = (string)$this->request->param('expire_date') ?: date('Y-m-d H:i:s', strtotime('+1 year'));
        $id = Db::table('domain')->insert([
            'user_id' => $user['id'], 'order_id' => 0, 'domain' => $domain, 'tld' => $tld,
            'registrar' => (string)$this->request->param('registrar') ?: ($tldRow['registrar'] ?? cfg('domain_default_registrar', 'namesilo')),
            'registrar_domain_id' => '', 'status' => (string)$this->request->param('status', 'pending'),
            'reg_date' => now(), 'expire_date' => $expire, 'next_due' => $expire,
            'auto_renew' => $this->request->bool('auto_renew') ? 1 : 0,
            'lock' => 1, 'privacy' => 0, 'auth_code' => '',
            'ns' => json_encode([], JSON_UNESCAPED_UNICODE), 'dns' => json_encode([], JSON_UNESCAPED_UNICODE),
            'cycle' => 'annual', 'price' => (float)($tldRow['renew_price'] ?? 0), 'currency' => 'CNY',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->logAction('添加域名', ['domain_id' => $id, 'domain' => $domain]);
        return $this->success('域名已添加', admin_url('/domain/' . $id));
    }
}

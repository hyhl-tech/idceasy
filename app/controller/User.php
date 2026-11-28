<?php
/**
 * 会员中心
 */
namespace app\controller;

use app\service\Billing;
use app\service\Mailer;
use app\service\Provision;
use think\Db;
use think\Response;
use think\Session;

class User extends Front
{
    public function index(): Response
    {
        $user = $this->user();
        $stats = [
            'hosts'    => Db::table('host')->where('user_id', $user['id'])->whereIn('status', ['active', 'suspended'])->count(),
            'domains'  => Db::table('domain')->where('user_id', $user['id'])->count(),
            'unpaid'   => Db::table('invoice')->where(['user_id' => $user['id'], 'status' => 'unpaid'])->sum('total')
                - Db::table('invoice')->where(['user_id' => $user['id'], 'status' => 'unpaid'])->sum('paid'),
            'tickets'  => Db::table('ticket')->where('user_id', $user['id'])->whereIn('status', ['open', 'customer_reply'])->count(),
        ];
        $invoices = Db::table('invoice')->where('user_id', $user['id'])->order('id', 'desc')->limit(5)->select();
        $tickets = Db::table('ticket')->where('user_id', $user['id'])->order('id', 'desc')->limit(5)->select();
        $announcements = Db::table('announcement')->where('published', 1)->order('id', 'desc')->limit(3)->select();
        return $this->view('user/index', compact('stats', 'invoices', 'tickets', 'announcements'));
    }

    public function services(): Response
    {
        $user = $this->user();
        $list = Db::table('host')->where('user_id', $user['id'])->order('id', 'desc')->paginate(10, $this->pageNo());
        foreach ($list['data'] as &$row) {
            $row['product'] = Db::table('product')->where('id', $row['product_id'])->find();
        }
        unset($row);
        return $this->view('user/services', compact('list'));
    }

    public function service(int $id): Response
    {
        $user = $this->user();
        $host = Db::table('host')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$host) return $this->notFound('服务不存在');
        $product = Db::table('product')->where('id', $host['product_id'])->find();
        $server = $host['server_id'] ? Db::table('server')->where('id', $host['server_id'])->find() : null;
        $usage = null;
        if (in_array($host['status'], ['active', 'suspended'], true) && ($host['module'] ?? 'manual') !== 'manual') {
            $usage = Provision::usage($id);
        }
        $upgrades = Db::table('product')->where('group_id', $product['group_id'] ?? 0)
            ->where('id', '<>', $host['product_id'])->where('status', 1)->select();
        $plainPassword = decrypt_str($host['password']);
        $renewPrice = product_price((int)$host['product_id'], $host['cycle'])['price'];
        return $this->view('user/service', compact('host', 'product', 'server', 'usage', 'upgrades', 'plainPassword', 'renewPrice'));
    }

    public function serviceAction(int $id): Response
    {
        $user = $this->user();
        $host = Db::table('host')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$host) return $this->error('服务不存在');
        $action = (string)$this->request->param('action');
        if ($action === 'login') {
            $res = Provision::loginUrl($id);
            if (!$res['ok']) return $this->error($res['error']);
            return $this->redirect($res['url']);
        }
        if (in_array($action, ['start', 'stop', 'reboot'], true)) {
            $res = Provision::power($id, $action);
            return $res['ok'] ? $this->success('指令已下发') : $this->error($res['error']);
        }
        return $this->error('未知操作');
    }

    public function servicePassword(int $id): Response
    {
        $user = $this->user();
        $host = Db::table('host')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$host) return $this->error('服务不存在');
        $password = (string)$this->request->param('password');
        if (strlen($password) < 8) return $this->error('密码至少 8 位');
        $res = Provision::changePassword($id, $password);
        return $res['ok'] ? $this->success('密码已修改') : $this->error($res['error']);
    }

    public function serviceRenew(int $id): Response
    {
        $user = $this->user();
        $host = Db::table('host')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$host) return $this->error('服务不存在');
        $res = Billing::createServiceRenewal('host', $id);
        if (!$res['ok']) return $this->error($res['error']);
        return $this->redirect(url('/invoice/' . $res['invoice_id'] . '/pay'));
    }

    public function upgrade(int $id): Response
    {
        $user = $this->user();
        $host = Db::table('host')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$host) return $this->notFound('服务不存在');
        $product = Db::table('product')->where('id', $host['product_id'])->find();
        $targets = Db::table('product')->where('group_id', $product['group_id'] ?? 0)
            ->where('id', '<>', $host['product_id'])->where('status', 1)->select();
        foreach ($targets as &$t) $t['pricing'] = Db::table('pricing')->where(['product_id' => $t['id'], 'enabled' => 1])->select();
        unset($t);
        $quote = null;
        $newId = $this->request->int('product_id');
        $newCycle = (string)$this->request->param('cycle', 'monthly');
        if ($newId) $quote = Billing::quoteUpgrade($id, $newId, $newCycle);
        if ($this->request->isPost()) {
            $res = Billing::createUpgradeOrder($id, $newId, $newCycle);
            if (!$res['ok']) return $this->error($res['error']);
            if (!empty($res['free'])) return $this->success('升级已生效');
            return $this->redirect(url('/invoice/' . $res['invoice_id'] . '/pay'));
        }
        return $this->view('user/upgrade', compact('host', 'product', 'targets', 'quote', 'newId', 'newCycle'));
    }

    public function domains(): Response
    {
        $user = $this->user();
        $list = Db::table('domain')->where('user_id', $user['id'])->order('id', 'desc')->paginate(15, $this->pageNo());
        return $this->view('user/domains', compact('list'));
    }

    public function domain(int $id): Response
    {
        $user = $this->user();
        $domain = Db::table('domain')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$domain) return $this->notFound('域名不存在');
        $ns = json_decode((string)$domain['ns'], true) ?: [];
        $tld = Db::table('domain_tld')->where('tld', $domain['tld'])->find();
        $epp = Session::pull('epp_code', '');
        return $this->view('user/domain', compact('domain', 'ns', 'tld', 'epp'));
    }

    public function domainNs(int $id): Response
    {
        $user = $this->user();
        $domain = Db::table('domain')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$domain) return $this->error('域名不存在');
        $ns = (array)$this->request->param('ns', []);
        $res = \app\service\DomainService::setNs($id, $ns);
        return $res['ok'] ? $this->success('NS 已更新') : $this->error($res['error']);
    }

    public function domainLock(int $id): Response
    {
        $user = $this->user();
        $domain = Db::table('domain')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$domain) return $this->error('域名不存在');
        $enable = $this->request->bool('enable');
        $res = \app\service\DomainService::setLock($id, $enable);
        return $res['ok'] ? $this->success($enable ? '已开启转移锁' : '已关闭转移锁') : $this->error($res['error']);
    }

    public function domainPrivacy(int $id): Response
    {
        $user = $this->user();
        $domain = Db::table('domain')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$domain) return $this->error('域名不存在');
        $enable = $this->request->bool('enable');
        $res = \app\service\DomainService::setPrivacy($id, $enable);
        return $res['ok'] ? $this->success($enable ? '已开启隐私保护' : '已关闭隐私保护') : $this->error($res['error']);
    }

    public function domainEpp(int $id): Response
    {
        $user = $this->user();
        $domain = Db::table('domain')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$domain) return $this->error('域名不存在');
        $res = \app\service\DomainService::getEpp($id);
        if (!$res['ok']) return $this->error($res['error']);
        Session::flash('epp_code', $res['code']);
        return $this->success('转移密码已获取，请及时保存');
    }

    public function domainRenew(int $id): Response
    {
        $user = $this->user();
        $domain = Db::table('domain')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$domain) return $this->error('域名不存在');
        $res = Billing::createServiceRenewal('domain', $id, $this->request->int('years', 1));
        if (!$res['ok']) return $this->error($res['error']);
        return $this->redirect(url('/invoice/' . $res['invoice_id'] . '/pay'));
    }

    public function domainSync(int $id): Response
    {
        $user = $this->user();
        $domain = Db::table('domain')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$domain) return $this->error('域名不存在');
        $res = \app\service\DomainService::sync($id);
        return $res['ok'] ? $this->success('已同步注册商状态') : $this->error($res['error']);
    }

    public function tickets(): Response
    {
        $user = $this->user();
        $list = Db::table('ticket')->where('user_id', $user['id'])->order('id', 'desc')->paginate(15, $this->pageNo());
        foreach ($list['data'] as &$row) {
            $row['dept'] = Db::table('ticket_dept')->where('id', $row['dept_id'])->find();
        }
        unset($row);
        return $this->view('user/tickets', compact('list'));
    }

    public function ticketNew(): Response
    {
        $user = $this->user();
        $depts = Db::table('ticket_dept')->order('sort')->select();
        if ($this->request->isPost()) {
            $subject = trim((string)$this->request->param('subject'));
            $message = trim((string)$this->request->param('message'));
            $deptId = $this->request->int('dept_id', 1);
            if ($subject === '' || $message === '') return $this->error('请填写主题与内容');
            $dept = Db::table('ticket_dept')->where('id', $deptId)->find();
            if (!$dept) return $this->error('部门不存在');
            $ticketId = Db::table('ticket')->insert([
                'user_id' => $user['id'], 'dept_id' => $deptId, 'subject' => mb_substr($subject, 0, 200),
                'status' => 'open', 'priority' => 'normal', 'last_reply_at' => now(), 'created_at' => now(),
            ]);
            Db::table('ticket_reply')->insert([
                'ticket_id' => $ticketId, 'user_id' => $user['id'], 'admin_id' => 0,
                'message' => $message, 'created_at' => now(),
            ]);
            if ($dept['email']) {
                Mailer::sendTemplate('ticket_reply', $dept['email'], [
                    'name' => '客服', 'ticket_id' => $ticketId, 'subject' => $subject,
                    'message' => nl2br(e($message)), 'url' => abs_url('/admin/ticket/' . $ticketId),
                ]);
            }
            hook('ticket.created', ['ticket_id' => $ticketId, 'user_id' => $user['id']]);
            return $this->success('工单已提交', url('/user/ticket/' . $ticketId));
        }
        return $this->view('user/ticket_new', compact('depts'));
    }

    public function ticket(int $id): Response
    {
        $user = $this->user();
        $ticket = Db::table('ticket')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$ticket) return $this->notFound('工单不存在');
        $replies = Db::table('ticket_reply')->where('ticket_id', $id)->order('id')->select();
        $dept = Db::table('ticket_dept')->where('id', $ticket['dept_id'])->find();
        return $this->view('user/ticket', compact('ticket', 'replies', 'dept'));
    }

    public function ticketReply(int $id): Response
    {
        $user = $this->user();
        $ticket = Db::table('ticket')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$ticket) return $this->error('工单不存在');
        if ($ticket['status'] === 'closed') return $this->error('工单已关闭');
        $message = trim((string)$this->request->param('message'));
        if ($message === '') return $this->error('请输入回复内容');
        Db::table('ticket_reply')->insert([
            'ticket_id' => $id, 'user_id' => $user['id'], 'admin_id' => 0,
            'message' => $message, 'created_at' => now(),
        ]);
        Db::table('ticket')->where('id', $id)->update(['status' => 'customer_reply', 'last_reply_at' => now()]);
        hook('ticket.replied', ['ticket_id' => $id]);
        return $this->success('回复已提交');
    }

    public function ticketClose(int $id): Response
    {
        $user = $this->user();
        $ticket = Db::table('ticket')->where(['id' => $id, 'user_id' => $user['id']])->find();
        if (!$ticket) return $this->error('工单不存在');
        Db::table('ticket')->where('id', $id)->update(['status' => 'closed']);
        hook('ticket.closed', ['ticket_id' => $id]);
        return $this->success('工单已关闭');
    }

    public function profile(): Response
    {
        $user = $this->user();
        if ($this->request->isPost()) {
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
                'updated_at' => now(),
            ];
            Db::table('user')->where('id', $user['id'])->update($data);
            return $this->success('资料已保存');
        }
        $currencies = Db::table('currency')->select();
        return $this->view('user/profile', ['profile' => $user, 'currencies' => $currencies]);
    }

    public function password(): Response
    {
        $user = $this->user();
        if ($this->request->isPost()) {
            $current = (string)$this->request->param('current');
            $new = (string)$this->request->param('password');
            if (!password_verify($current, $user['password'])) return $this->error('当前密码错误');
            if (strlen($new) < 8) return $this->error('新密码至少 8 位');
            if ($new !== (string)$this->request->param('password2')) return $this->error('两次密码不一致');
            Db::table('user')->where('id', $user['id'])->update([
                'password' => password_hash($new, PASSWORD_DEFAULT), 'updated_at' => now(),
            ]);
            hook('user.password_changed', ['user_id' => (int)$user['id']]);
            return $this->success('密码已修改');
        }
        return $this->view('user/password');
    }

    public function security(): Response
    {
        $user = $this->user();
        $logs = Db::table('user_login_log')->where('user_id', $user['id'])->order('id', 'desc')->limit(10)->select();
        return $this->view('user/security', ['profile' => $user, 'logs' => $logs]);
    }

    public function api(): Response
    {
        $user = $this->user();
        if ($this->request->isPost()) {
            $key = 'ice_' . random_str(40);
            Db::table('user')->where('id', $user['id'])->update(['api_key' => $key]);
            return $this->success('API 密钥已重置');
        }
        return $this->redirect(url('/user/security'));
    }

    public function affiliate(): Response
    {
        $user = $this->user();
        $aff = Db::table('affiliate')->where('user_id', $user['id'])->find();
        if ($this->request->isPost() && !$aff) {
            $code = $user['affiliate_code'] ?: random_str(10);
            Db::table('affiliate')->insert([
                'user_id' => $user['id'], 'code' => $code, 'rate' => (float)cfg('affiliate_rate', 10),
                'visits' => 0, 'signups' => 0, 'earnings' => 0, 'balance' => 0,
                'status' => 1, 'created_at' => now(),
            ]);
            Db::table('user')->where('id', $user['id'])->update(['affiliate_code' => $code]);
            $aff = Db::table('affiliate')->where('user_id', $user['id'])->find();
        }
        $logs = $aff ? Db::table('affiliate_log')->where('affiliate_id', $aff['id'])->order('id', 'desc')->limit(20)->select() : [];
        return $this->view('user/affiliate', compact('aff', 'logs'));
    }

    public function transactions(): Response
    {
        $user = $this->user();
        $list = Db::table('transaction')->where('user_id', $user['id'])->order('id', 'desc')->paginate(20, $this->pageNo());
        return $this->view('user/transactions', compact('list'));
    }

    public function addresses(): Response
    {
        $user = $this->user();
        $list = Db::table('user_address')->where('user_id', $user['id'])->order('is_default', 'desc')->select();
        $edit = null;
        $eid = $this->request->int('edit');
        if ($eid) $edit = Db::table('user_address')->where(['id' => $eid, 'user_id' => $user['id']])->find();
        return $this->view('user/addresses', compact('list', 'edit'));
    }

    public function addressSave(): Response
    {
        $user = $this->user();
        if (!$this->request->isPost()) return $this->redirect(url('/user/addresses'));
        $id = $this->request->int('id');
        $data = [
            'user_id' => $user['id'],
            'label' => trim((string)$this->request->param('label', '默认地址')),
            'firstname' => trim((string)$this->request->param('firstname')),
            'lastname' => trim((string)$this->request->param('lastname')),
            'company' => trim((string)$this->request->param('company')),
            'email' => trim((string)$this->request->param('email')),
            'phone' => trim((string)$this->request->param('phone')),
            'address1' => trim((string)$this->request->param('address1')),
            'address2' => trim((string)$this->request->param('address2')),
            'city' => trim((string)$this->request->param('city')),
            'state' => trim((string)$this->request->param('state')),
            'postcode' => trim((string)$this->request->param('postcode')),
            'country' => strtoupper(trim((string)$this->request->param('country', 'CN'))),
        ];
        if ($data['firstname'] === '' || $data['address1'] === '') return $this->error('请填写联系人姓名与地址');
        if ($this->request->bool('is_default')) {
            Db::table('user_address')->where('user_id', $user['id'])->update(['is_default' => 0]);
            $data['is_default'] = 1;
        }
        if ($id && Db::table('user_address')->where(['id' => $id, 'user_id' => $user['id']])->exists()) {
            Db::table('user_address')->where(['id' => $id, 'user_id' => $user['id']])->update($data);
        } else {
            $data['created_at'] = now();
            Db::table('user_address')->insert($data);
        }
        return $this->success('地址已保存');
    }

    public function addressDelete(): Response
    {
        $user = $this->user();
        Db::table('user_address')->where(['id' => $this->request->int('id'), 'user_id' => $user['id']])->delete();
        return $this->success('已删除');
    }
}

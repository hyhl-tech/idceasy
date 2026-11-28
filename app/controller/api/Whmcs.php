<?php
/**
 * WHMCS 兼容 API（双向对接的服务端）
 * 入口：POST /api/whmcs  identifier / secret / action / responsetype=json
 */
namespace app\controller\api;

use app\service\Billing;
use app\service\DomainService;
use app\service\Mailer;
use app\service\Provision;
use think\Controller;
use think\Db;
use think\Response;

class Whmcs extends Controller
{
    protected string $theme = 'default';

    public function index(): Response
    {
        if ((string)cfg('api_whmcs_enabled', '0') !== '1') return $this->fail('WHMCS API 未启用');
        $identifier = (string)$this->request->param('identifier');
        $secret = (string)$this->request->param('secret');
        if (!hash_equals((string)cfg('api_whmcs_identifier', ''), $identifier)
            || !hash_equals((string)cfg('api_whmcs_secret', ''), $secret)
            || $identifier === '') {
            return $this->fail('认证失败');
        }
        $action = (string)$this->request->param('action');
        try {
            return $this->dispatch($action);
        } catch (\Throwable $e) {
            log_write('api', 'WHMCS ' . $action . ': ' . $e->getMessage());
            return $this->fail($e->getMessage());
        }
    }

    protected function dispatch(string $action): Response
    {
        switch ($action) {
            case 'GetClients': return $this->getClients();
            case 'GetClientsDetails': return $this->getClientDetails();
            case 'AddClient': return $this->addClient();
            case 'UpdateClient': return $this->updateClient();
            case 'GetOrders': return $this->getOrders();
            case 'GetOrder': return $this->getOrder();
            case 'AddOrder': return $this->addOrder();
            case 'AcceptOrder': return $this->acceptOrder();
            case 'GetInvoices': return $this->getInvoices();
            case 'GetInvoice': return $this->getInvoice();
            case 'CreateInvoice': return $this->createInvoice();
            case 'AddInvoicePayment': return $this->addPayment();
            case 'GetProducts': return $this->getProducts();
            case 'GetTLDPricing': return $this->getTldPricing();
            case 'GetDomains': return $this->getDomains();
            case 'DomainRegister': return $this->domainRegister();
            case 'DomainRenew': return $this->domainRenew();
            case 'DomainTransfer': return $this->domainTransfer();
            case 'GetTickets': return $this->getTickets();
            case 'GetTicket': return $this->getTicket();
            case 'OpenTicket': return $this->openTicket();
            case 'AddTicketReply': return $this->addTicketReply();
            case 'CreateHost': return $this->createHost();
            case 'ModuleCreate': return $this->module('create');
            case 'ModuleSuspend': return $this->module('suspend');
            case 'ModuleUnsuspend': return $this->module('unsuspend');
            case 'ModuleTerminate': return $this->module('terminate');
            case 'ModuleChangePassword': return $this->modulePassword();
            case 'Ping': return $this->ok(['version' => \think\App::version()]);
            default: return $this->fail('不支持的操作: ' . $action);
        }
    }

    protected function ok(array $data): Response
    {
        return Response::json(array_merge(['result' => 'success'], $data));
    }

    protected function fail(string $message): Response
    {
        return Response::json(['result' => 'error', 'message' => $message]);
    }

    protected function limit(): array
    {
        $limit = $this->request->int('limitnum', $this->request->int('limit', 25));
        $start = $this->request->int('limitstart', $this->request->int('offset', 0));
        return [max(1, min(200, $limit)), max(0, $start)];
    }

    protected function clientFormat(array $u): array
    {
        [$first, $last] = array_pad(preg_split('/\s+/', trim($u['name'] ?: 'Client')), 2, '');
        return [
            'id' => (int)$u['id'], 'firstname' => $first, 'lastname' => $last ?: $first,
            'companyname' => $u['company'], 'email' => $u['email'], 'phonenumber' => $u['phone'],
            'address1' => $u['address'], 'city' => $u['city'], 'state' => $u['province'],
            'postcode' => $u['postcode'], 'country' => $u['country'],
            'status' => (int)$u['status'] === 1 ? 'Active' : 'Inactive',
            'credit' => (float)$u['balance'], 'datecreated' => $u['created_at'], 'currency_code' => $u['currency'],
        ];
    }

    protected function getClients(): Response
    {
        [$limit, $start] = $this->limit();
        $q = Db::table('user')->order('id', 'desc')->limit($limit, $start);
        $email = (string)$this->request->param('search', '');
        if ($email !== '') $q->whereRaw('(email LIKE ? OR name LIKE ?)', ['%' . $email . '%', '%' . $email . '%']);
        $rows = $q->select();
        return $this->ok([
            'totalresults' => Db::table('user')->count(),
            'clients' => array_map([$this, 'clientFormat'], $rows),
        ]);
    }

    protected function findClient(): ?array
    {
        $id = $this->request->int('clientid', $this->request->int('userid'));
        if ($id) return Db::table('user')->where('id', $id)->find();
        $email = (string)$this->request->param('email', '');
        if ($email !== '') return Db::table('user')->where('email', $email)->find();
        return null;
    }

    protected function getClientDetails(): Response
    {
        $u = $this->findClient();
        if (!$u) return $this->fail('客户不存在');
        return $this->ok(['client' => $this->clientFormat($u)]);
    }

    protected function addClient(): Response
    {
        $email = strtolower(trim((string)$this->request->param('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->fail('邮箱无效');
        if (Db::table('user')->where('email', $email)->exists()) return $this->fail('邮箱已存在');
        $password = (string)$this->request->param('password2') ?: generate_password(12);
        $name = trim((string)$this->request->param('firstname') . ' ' . (string)$this->request->param('lastname'));
        $id = Db::table('user')->insert([
            'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT),
            'name' => $name ?: $email, 'company' => (string)$this->request->param('companyname'),
            'phone' => (string)$this->request->param('phonenumber'),
            'address' => (string)$this->request->param('address1'), 'city' => (string)$this->request->param('city'),
            'province' => (string)$this->request->param('state'), 'postcode' => (string)$this->request->param('postcode'),
            'country' => (string)$this->request->param('country', 'CN'), 'currency' => cfg('currency', 'CNY'),
            'status' => 1, 'email_verified' => 1, 'api_key' => 'ice_' . random_str(40),
            'affiliate_code' => random_str(10), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Mailer::sendTemplate('welcome', $email, ['name' => $name ?: $email]);
        return $this->ok(['clientid' => $id, 'password' => $password]);
    }

    protected function updateClient(): Response
    {
        $u = $this->findClient();
        if (!$u) return $this->fail('客户不存在');
        $data = [];
        foreach (['companyname' => 'company', 'phonenumber' => 'phone', 'address1' => 'address',
                     'city' => 'city', 'state' => 'province', 'postcode' => 'postcode', 'country' => 'country'] as $from => $to) {
            $v = $this->request->param($from);
            if ($v !== null) $data[$to] = (string)$v;
        }
        $first = (string)$this->request->param('firstname');
        $last = (string)$this->request->param('lastname');
        if ($first !== '' || $last !== '') $data['name'] = trim($first . ' ' . $last);
        if ($data) Db::table('user')->where('id', $u['id'])->update($data + ['updated_at' => now()]);
        return $this->ok(['clientid' => (int)$u['id']]);
    }

    protected function orderStatus(string $s): string
    {
        return ['pending' => 'Pending', 'paid' => 'Paid', 'active' => 'Active', 'cancelled' => 'Cancelled',
            'fraud' => 'Fraud', 'refunded' => 'Refunded'][$s] ?? ucfirst($s);
    }

    protected function getOrders(): Response
    {
        [$limit, $start] = $this->limit();
        $q = Db::table('order')->order('id', 'desc')->limit($limit, $start);
        $clientId = $this->request->int('clientid', $this->request->int('userid'));
        if ($clientId) $q->where('user_id', $clientId);
        $rows = $q->select();
        $orders = [];
        foreach ($rows as $o) {
            $orders[] = [
                'id' => (int)$o['id'], 'ordernum' => (int)$o['id'], 'userid' => (int)$o['user_id'],
                'date' => $o['created_at'], 'amount' => (float)$o['total'], 'status' => $this->orderStatus($o['status']),
                'invoiceid' => (int)Db::table('invoice')->where('order_id', $o['id'])->value('id', 0),
            ];
        }
        return $this->ok(['totalresults' => Db::table('order')->count(), 'orders' => ['order' => $orders]]);
    }

    protected function getOrder(): Response
    {
        $id = $this->request->int('orderid', $this->request->int('id'));
        $o = Db::table('order')->where('id', $id)->find();
        if (!$o) return $this->fail('订单不存在');
        $items = [];
        foreach (Db::table('order_item')->where('order_id', $id)->select() as $item) {
            $items[] = [
                'id' => (int)$item['id'], 'type' => $item['type'], 'domain' => $item['domain'],
                'productid' => (int)$item['product_id'], 'billingcycle' => $item['cycle'],
                'amount' => (float)$item['amount'],
            ];
        }
        return $this->ok(['order' => [
            'id' => (int)$o['id'], 'userid' => (int)$o['user_id'], 'amount' => (float)$o['total'],
            'status' => $this->orderStatus($o['status']), 'date' => $o['created_at'], 'items' => $items,
        ]]);
    }

    protected function addOrder(): Response
    {
        $clientId = $this->request->int('clientid', $this->request->int('userid'));
        if (!Db::table('user')->where('id', $clientId)->exists()) return $this->fail('客户不存在');
        $pids = (array)$this->request->param('pid', []);
        $domains = (array)$this->request->param('domain', []);
        $cycles = (array)$this->request->param('billingcycle', []);
        $qtys = (array)$this->request->param('qty', []);
        $items = [];
        foreach ($pids as $i => $pid) {
            $items[] = [
                'product_id' => (int)$pid, 'domain' => (string)($domains[$i] ?? ''),
                'cycle' => (string)($cycles[$i] ?? 'monthly'), 'qty' => max(1, (int)($qtys[$i] ?? 1)),
            ];
        }
        if (!$items) return $this->fail('缺少订单项目');
        $res = Billing::createOrder($clientId, $items);
        return $this->ok(['orderid' => $res['order_id'], 'invoiceid' => $res['invoice_id'], 'total' => $res['total']]);
    }

    protected function acceptOrder(): Response
    {
        $id = $this->request->int('orderid');
        $order = Db::table('order')->where('id', $id)->find();
        if (!$order) return $this->fail('订单不存在');
        $invoice = Db::table('invoice')->where('order_id', $id)->find();
        if ($invoice && $invoice['status'] !== 'paid') {
            $due = (float)$invoice['total'] - (float)$invoice['paid'];
            Billing::markPaid((int)$invoice['id'], 'whmcs', 'WHMCS' . $id, $due, 0, 'WHMCS AcceptOrder');
        }
        Billing::activateOrder($id);
        return $this->ok(['orderid' => $id]);
    }

    protected function invoiceStatus(string $s): string
    {
        return ['unpaid' => 'Unpaid', 'paid' => 'Paid', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded'][$s] ?? ucfirst($s);
    }

    protected function getInvoices(): Response
    {
        [$limit, $start] = $this->limit();
        $q = Db::table('invoice')->order('id', 'desc')->limit($limit, $start);
        $clientId = $this->request->int('clientid', $this->request->int('userid'));
        if ($clientId) $q->where('user_id', $clientId);
        $status = (string)$this->request->param('status', '');
        if ($status !== '') $q->where('status', strtolower($status));
        $rows = $q->select();
        $invoices = [];
        foreach ($rows as $i) {
            $invoices[] = [
                'id' => (int)$i['id'], 'invoicenum' => $i['invoice_no'], 'userid' => (int)$i['user_id'],
                'date' => $i['created_at'], 'duedate' => $i['due_at'], 'total' => (float)$i['total'],
                'status' => $this->invoiceStatus($i['status']),
            ];
        }
        return $this->ok(['totalresults' => Db::table('invoice')->count(), 'invoices' => ['invoice' => $invoices]]);
    }

    protected function getInvoice(): Response
    {
        $id = $this->request->int('invoiceid');
        $i = Db::table('invoice')->where('id', $id)->find();
        if (!$i) return $this->fail('账单不存在');
        $items = [];
        foreach (Db::table('invoice_item')->where('invoice_id', $id)->select() as $item) {
            $items[] = ['id' => (int)$item['id'], 'description' => $item['description'], 'amount' => (float)$item['amount']];
        }
        return $this->ok(['invoiceid' => $id, 'status' => $this->invoiceStatus($i['status']),
            'total' => (float)$i['total'], 'items' => ['item' => $items]]);
    }

    protected function createInvoice(): Response
    {
        $clientId = $this->request->int('userid', $this->request->int('clientid'));
        $desc = (array)$this->request->param('itemdescription', []);
        $amounts = (array)$this->request->param('itemamount', []);
        $lines = [];
        foreach ($desc as $i => $d) {
            if ((string)$d === '') continue;
            $lines[] = ['description' => (string)$d, 'amount' => (float)($amounts[$i] ?? 0), 'qty' => 1];
        }
        if (!$lines) return $this->fail('缺少账单项目');
        $invoice = Billing::createInvoice($clientId, $lines, [
            'send_mail' => (bool)$this->request->int('sendinvoice'),
            'due_days' => $this->request->int('due_days', 7),
        ]);
        return $this->ok(['invoiceid' => $invoice['id'], 'total' => $invoice['total']]);
    }

    protected function addPayment(): Response
    {
        $id = $this->request->int('invoiceid');
        $amount = (float)$this->request->param('amount');
        $gateway = (string)$this->request->param('gateway', 'whmcs');
        $transId = (string)$this->request->param('transid', 'WHMCS-' . $id . '-' . time());
        $res = Billing::markPaid($id, $gateway, $transId, $amount, 0, 'WHMCS AddInvoicePayment');
        return !empty($res['ok']) ? $this->ok(['invoiceid' => $id]) : $this->fail($res['error'] ?? '入账失败');
    }

    protected function getProducts(): Response
    {
        $products = [];
        foreach (Db::table('product')->where('status', 1)->order('sort')->select() as $p) {
            $pricing = [];
            foreach (Db::table('pricing')->where('product_id', $p['id'])->select() as $pr) {
                $pricing[$pr['cycle']] = (float)$pr['price'];
            }
            $products[] = [
                'id' => (int)$p['id'], 'name' => $p['name'], 'type' => $p['type'],
                'description' => strip_tags((string)$p['description']), 'pricing' => $pricing,
            ];
        }
        return $this->ok(['products' => ['product' => $products]]);
    }

    protected function getTldPricing(): Response
    {
        $out = [];
        foreach (Db::table('domain_tld')->where('status', 1)->select() as $t) {
            $out[$t['tld']] = ['register' => (float)$t['register_price'], 'renew' => (float)$t['renew_price'],
                'transfer' => (float)$t['transfer_price'], 'currency' => $t['currency']];
        }
        return $this->ok(['pricing' => $out]);
    }

    protected function getDomains(): Response
    {
        [$limit, $start] = $this->limit();
        $q = Db::table('domain')->order('id', 'desc')->limit($limit, $start);
        $clientId = $this->request->int('clientid', $this->request->int('userid'));
        if ($clientId) $q->where('user_id', $clientId);
        $rows = $q->select();
        $domains = [];
        foreach ($rows as $d) {
            $domains[] = [
                'id' => (int)$d['id'], 'userid' => (int)$d['user_id'], 'domain' => $d['domain'],
                'registrar' => $d['registrar'], 'regdate' => $d['reg_date'],
                'expirydate' => $d['expire_date'], 'status' => ucfirst($d['status']),
                'autorenew' => (int)$d['auto_renew'],
            ];
        }
        return $this->ok(['totalresults' => Db::table('domain')->count(), 'domains' => ['domain' => $domains]]);
    }

    protected function ensureDomain(): array
    {
        $name = strtolower(trim((string)$this->request->param('domain')));
        $clientId = $this->request->int('clientid', $this->request->int('userid'));
        $row = Db::table('domain')->where('domain', $name)->find();
        if ($row) return $row;
        $tld = substr($name, strrpos($name, '.') + 1);
        $tldRow = Db::table('domain_tld')->where('tld', $tld)->find();
        $expire = date('Y-m-d H:i:s', strtotime('+1 year'));
        $id = Db::table('domain')->insert([
            'user_id' => $clientId, 'order_id' => 0, 'domain' => $name, 'tld' => $tld,
            'registrar' => $tldRow['registrar'] ?? cfg('domain_default_registrar', 'namesilo'),
            'registrar_domain_id' => '', 'status' => 'pending', 'reg_date' => now(),
            'expire_date' => $expire, 'next_due' => $expire, 'auto_renew' => 1, 'lock' => 1, 'privacy' => 0,
            'auth_code' => '', 'ns' => '[]', 'dns' => '[]', 'cycle' => 'annual',
            'price' => (float)($tldRow['register_price'] ?? 0), 'currency' => 'CNY',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Db::table('domain')->where('id', $id)->find();
    }

    protected function domainRegister(): Response
    {
        $domain = $this->ensureDomain();
        if (!$domain) return $this->fail('无法创建域名记录');
        $res = DomainService::register((int)$domain['id']);
        return !empty($res['ok']) ? $this->ok(['domainid' => (int)$domain['id']]) : $this->fail($res['error'] ?? '注册失败');
    }

    protected function domainRenew(): Response
    {
        $domain = $this->ensureDomain();
        $res = DomainService::renew((int)$domain['id'], max(1, $this->request->int('regperiod', 1)));
        return !empty($res['ok']) ? $this->ok(['domainid' => (int)$domain['id']]) : $this->fail($res['error'] ?? '续费失败');
    }

    protected function domainTransfer(): Response
    {
        $domain = $this->ensureDomain();
        $res = DomainService::transfer((int)$domain['id'], (string)$this->request->param('eppcode'));
        return !empty($res['ok']) ? $this->ok(['domainid' => (int)$domain['id']]) : $this->fail($res['error'] ?? '转入失败');
    }

    protected function getTickets(): Response
    {
        [$limit, $start] = $this->limit();
        $q = Db::table('ticket')->order('id', 'desc')->limit($limit, $start);
        $clientId = $this->request->int('clientid', $this->request->int('userid'));
        if ($clientId) $q->where('user_id', $clientId);
        $rows = $q->select();
        $tickets = [];
        foreach ($rows as $t) {
            $tickets[] = [
                'id' => (int)$t['id'], 'tid' => (int)$t['id'], 'userid' => (int)$t['user_id'],
                'subject' => $t['subject'], 'status' => ucfirst($t['status']), 'date' => $t['created_at'],
                'deptid' => (int)$t['dept_id'],
            ];
        }
        return $this->ok(['totalresults' => Db::table('ticket')->count(), 'tickets' => ['ticket' => $tickets]]);
    }

    protected function getTicket(): Response
    {
        $id = $this->request->int('ticketid', $this->request->int('id'));
        $t = Db::table('ticket')->where('id', $id)->find();
        if (!$t) return $this->fail('工单不存在');
        $replies = [];
        foreach (Db::table('ticket_reply')->where('ticket_id', $id)->order('id')->select() as $r) {
            $replies[] = ['id' => (int)$r['id'], 'message' => $r['message'], 'date' => $r['created_at'],
                'admin' => $r['admin_id'] > 0 ? $r['admin_id'] : ''];
        }
        return $this->ok(['ticket' => [
            'id' => $id, 'subject' => $t['subject'], 'status' => ucfirst($t['status']),
            'userid' => (int)$t['user_id'], 'replies' => ['reply' => $replies],
        ]]);
    }

    protected function openTicket(): Response
    {
        $clientId = $this->request->int('clientid', $this->request->int('userid'));
        $subject = trim((string)$this->request->param('subject'));
        $message = trim((string)$this->request->param('message'));
        $deptId = $this->request->int('deptid', 1);
        if (!$subject || !$message) return $this->fail('主题与内容不能为空');
        $id = Db::table('ticket')->insert([
            'user_id' => $clientId, 'dept_id' => $deptId, 'subject' => $subject,
            'status' => 'open', 'priority' => (string)$this->request->param('priority', 'normal'),
            'last_reply_at' => now(), 'created_at' => now(),
        ]);
        Db::table('ticket_reply')->insert([
            'ticket_id' => $id, 'user_id' => $clientId, 'admin_id' => 0, 'message' => $message, 'created_at' => now(),
        ]);
        return $this->ok(['ticketid' => $id]);
    }

    protected function addTicketReply(): Response
    {
        $id = $this->request->int('ticketid');
        $ticket = Db::table('ticket')->where('id', $id)->find();
        if (!$ticket) return $this->fail('工单不存在');
        $message = trim((string)$this->request->param('message'));
        if ($message === '') return $this->fail('回复内容不能为空');
        $adminId = $this->request->int('adminid');
        Db::table('ticket_reply')->insert([
            'ticket_id' => $id, 'user_id' => $adminId ? 0 : (int)$ticket['user_id'],
            'admin_id' => $adminId, 'message' => $message, 'created_at' => now(),
        ]);
        Db::table('ticket')->where('id', $id)->update([
            'status' => $adminId ? 'answered' : 'customer_reply', 'last_reply_at' => now(),
        ]);
        return $this->ok(['ticketid' => $id]);
    }

    protected function createHost(): Response
    {
        $u = $this->findClient();
        if (!$u) {
            $email = strtolower(trim((string)$this->request->param('email')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->fail('客户不存在，请传入 clientid 或有效 email');
            }
            $name = trim((string)$this->request->param('firstname') . ' ' . (string)$this->request->param('lastname'));
            $uid = Db::table('user')->insert([
                'email' => $email, 'password' => password_hash(generate_password(16), PASSWORD_DEFAULT),
                'name' => $name ?: $email, 'country' => (string)$this->request->param('country', 'CN'),
                'currency' => cfg('currency', 'CNY'), 'status' => 1, 'email_verified' => 1,
                'api_key' => 'ice_' . random_str(40), 'affiliate_code' => random_str(10),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $u = Db::table('user')->where('id', $uid)->find();
        }
        $productId = $this->request->int('productid');
        $product = Db::table('product')->where('id', $productId)->find();
        if (!$product) return $this->fail('产品不存在');
        $domain = trim((string)$this->request->param('domain'));
        if ($domain === '') return $this->fail('缺少域名');
        $cycle = (string)$this->request->param('billingcycle', 'monthly');
        if (!in_array($cycle, cycles(), true)) $cycle = 'monthly';
        $username = (string)$this->request->param('username') ?: Provision::makeUsername($domain);
        $password = (string)$this->request->param('password') ?: generate_password(14);
        $exists = Db::table('host')->where('domain', $domain)->where('user_id', $u['id'])->find();
        if ($exists) return $this->ok(['hostid' => (int)$exists['id'], 'username' => $exists['username']]);
        $hostId = Db::table('host')->insert([
            'user_id' => $u['id'], 'order_id' => 0, 'product_id' => $product['id'], 'server_id' => 0,
            'domain' => $domain, 'username' => $username, 'password' => encrypt_str($password),
            'status' => 'pending', 'module' => $product['module'], 'cycle' => $cycle,
            'next_due' => next_due($cycle), 'notes' => 'WHMCS CreateHost',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $res = $this->request->int('provision') ? Provision::create($hostId) : ['ok' => true];
        if (empty($res['ok'])) {
            return $this->ok(['hostid' => $hostId, 'username' => $username, 'password' => $password,
                'warning' => $res['error'] ?? '开通失败']);
        }
        return $this->ok(['hostid' => $hostId, 'username' => $username, 'password' => $password]);
    }

    protected function modulePassword(): Response
    {
        $host = Db::table('host')->where('domain', (string)$this->request->param('domain'))->find()
            ?: Db::table('host')->where('username', (string)$this->request->param('username'))->find();
        if (!$host) return $this->fail('服务不存在');
        $password = (string)$this->request->param('password');
        if (strlen($password) < 8) return $this->fail('密码至少 8 位');
        $res = Provision::changePassword((int)$host['id'], $password);
        return !empty($res['ok']) ? $this->ok(['hostid' => (int)$host['id']]) : $this->fail($res['error'] ?? '修改失败');
    }

    protected function module(string $action): Response
    {
        $host = Db::table('host')->where('domain', (string)$this->request->param('domain'))->find()
            ?: Db::table('host')->where('username', (string)$this->request->param('username'))->find()
            ?: Db::table('host')->where('id', $this->request->int('hostid'))->find();
        if (!$host) return $this->fail('服务不存在');
        $res = match ($action) {
            'create' => Provision::create((int)$host['id']),
            'suspend' => Provision::suspend((int)$host['id']),
            'unsuspend' => Provision::unsuspend((int)$host['id']),
            'terminate' => Provision::terminate((int)$host['id']),
            default => ['ok' => false, 'error' => '未知操作'],
        };
        return !empty($res['ok']) ? $this->ok(['hostid' => (int)$host['id']]) : $this->fail($res['error'] ?? '模块操作失败');
    }
}

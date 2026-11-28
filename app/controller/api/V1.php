<?php
/**
 * 开放 API v1（用户级 API Key）
 * 认证：Authorization: Bearer ice_xxx  或 ?api_key=ice_xxx
 */
namespace app\controller\api;

use app\service\Billing;
use think\Controller;
use think\Db;
use think\Response;

class V1 extends Controller
{
    protected string $theme = 'default';
    protected ?array $user = null;

    public function index(string $action): Response
    {
        $key = '';
        $auth = $this->request->header('Authorization');
        if (stripos($auth, 'Bearer ') === 0) $key = trim(substr($auth, 7));
        if ($key === '') $key = (string)$this->request->param('api_key', '');
        if ($key === '') return $this->fail('缺少 API 密钥', 401);
        $this->user = Db::table('user')->where('api_key', $key)->where('status', 1)->find();
        if (!$this->user) return $this->fail('API 密钥无效', 401);

        Db::table('api_log')->insert([
            'user_id' => (int)$this->user['id'], 'key_name' => 'v1', 'method' => $this->request->method(),
            'path' => 'api/v1/' . $action, 'ip' => client_ip(), 'created_at' => now(),
        ]);
        try {
            return $this->dispatch($action);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    protected function dispatch(string $action): Response
    {
        $uid = (int)$this->user['id'];
        switch ($action) {
            case 'ping':
                return $this->ok(['version' => \think\App::version(), 'time' => now()]);
            case 'profile':
                return $this->ok([
                    'id' => $uid, 'email' => $this->user['email'], 'name' => $this->user['name'],
                    'balance' => (float)$this->user['balance'], 'currency' => $this->user['currency'],
                ]);
            case 'products':
                $out = [];
                foreach (Db::table('product')->where(['status' => 1, 'hidden' => 0])->order('sort')->select() as $p) {
                    $pricing = [];
                    foreach (Db::table('pricing')->where('product_id', $p['id'])->where('enabled', 1)->select() as $pr) {
                        $pricing[$pr['cycle']] = ['price' => (float)$pr['price'], 'setup_fee' => (float)$pr['setup_fee']];
                    }
                    $out[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'type' => $p['type'], 'pricing' => $pricing];
                }
                return $this->ok($out);
            case 'services':
                $rows = Db::table('host')->where('user_id', $uid)->order('id', 'desc')->select();
                return $this->ok(array_map(fn($h) => [
                    'id' => (int)$h['id'], 'domain' => $h['domain'], 'status' => $h['status'],
                    'product_id' => (int)$h['product_id'], 'next_due' => $h['next_due'],
                ], $rows));
            case 'service':
                $h = Db::table('host')->where(['id' => $this->request->int('id'), 'user_id' => $uid])->find();
                if (!$h) return $this->fail('服务不存在', 404);
                return $this->ok(['id' => (int)$h['id'], 'domain' => $h['domain'], 'username' => $h['username'],
                    'status' => $h['status'], 'ip' => $h['ip'], 'next_due' => $h['next_due']]);
            case 'domains':
                $rows = Db::table('domain')->where('user_id', $uid)->order('id', 'desc')->select();
                return $this->ok(array_map(fn($d) => [
                    'id' => (int)$d['id'], 'domain' => $d['domain'], 'status' => $d['status'],
                    'expire_date' => $d['expire_date'], 'auto_renew' => (int)$d['auto_renew'],
                ], $rows));
            case 'invoices':
                $rows = Db::table('invoice')->where('user_id', $uid)->order('id', 'desc')->limit(100)->select();
                return $this->ok(array_map(fn($i) => [
                    'id' => (int)$i['id'], 'invoice_no' => $i['invoice_no'], 'total' => (float)$i['total'],
                    'paid' => (float)$i['paid'], 'status' => $i['status'], 'due_at' => $i['due_at'],
                ], $rows));
            case 'invoice':
                $i = Db::table('invoice')->where(['id' => $this->request->int('id'), 'user_id' => $uid])->find();
                if (!$i) return $this->fail('账单不存在', 404);
                return $this->ok(['id' => (int)$i['id'], 'invoice_no' => $i['invoice_no'],
                    'total' => (float)$i['total'], 'paid' => (float)$i['paid'], 'status' => $i['status'],
                    'items' => Db::table('invoice_item')->where('invoice_id', $i['id'])->select()]);
            case 'order.create':
                if (!$this->request->isPost()) return $this->fail('请使用 POST', 405);
                $items = $this->request->json()['items'] ?? $this->request->param('items', []);
                if (!is_array($items) || !$items) return $this->fail('缺少 items');
                return $this->ok(Billing::createOrder($uid, $items, ['note' => 'API v1']));
            case 'invoice.pay':
                if (!$this->request->isPost()) return $this->fail('请使用 POST', 405);
                $res = Billing::payWithBalance($this->request->int('id'), $uid);
                return !empty($res['ok']) ? $this->ok($res) : $this->fail($res['error'] ?? '支付失败');
            case 'tickets':
                $rows = Db::table('ticket')->where('user_id', $uid)->order('id', 'desc')->limit(50)->select();
                return $this->ok(array_map(fn($t) => [
                    'id' => (int)$t['id'], 'subject' => $t['subject'], 'status' => $t['status'],
                    'last_reply_at' => $t['last_reply_at'],
                ], $rows));
            case 'ticket.create':
                if (!$this->request->isPost()) return $this->fail('请使用 POST', 405);
                $subject = trim((string)$this->request->param('subject'));
                $message = trim((string)$this->request->param('message'));
                if (!$subject || !$message) return $this->fail('主题与内容不能为空');
                $id = Db::table('ticket')->insert([
                    'user_id' => $uid, 'dept_id' => $this->request->int('dept_id', 1), 'subject' => $subject,
                    'status' => 'open', 'priority' => 'normal', 'last_reply_at' => now(), 'created_at' => now(),
                ]);
                Db::table('ticket_reply')->insert([
                    'ticket_id' => $id, 'user_id' => $uid, 'admin_id' => 0, 'message' => $message, 'created_at' => now(),
                ]);
                return $this->ok(['ticket_id' => $id]);
            default:
                return $this->fail('未知接口: ' . $action, 404);
        }
    }

    protected function ok($data): Response
    {
        return Response::json(['ok' => true, 'data' => $data]);
    }

    protected function fail(string $msg, int $code = 400): Response
    {
        return Response::json(['ok' => false, 'error' => $msg], $code);
    }
}

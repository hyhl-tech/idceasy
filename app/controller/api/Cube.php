<?php
/**
 * 魔方财务桥接 API（idceasy Bridge v1）
 * 用于魔方财务 / 自研系统与 idceasy 双向同步客户、订单、账单、服务、域名。
 * 认证：请求头 X-ICE-KEY 或参数 key；可选 HMAC 签名 sign=HMAC_SHA256(timestamp.body, key)
 */
namespace app\controller\api;

use app\service\Billing;
use app\service\DomainService;
use app\service\Provision;
use think\Controller;
use think\Db;
use think\Response;

class Cube extends Controller
{
    protected string $theme = 'default';

    public function index(): Response
    {
        if ((string)cfg('api_cube_enabled', '0') !== '1') return $this->fail('魔方桥接未启用', 403);
        $key = (string)($this->request->header('X-ICE-KEY') ?: $this->request->param('key'));
        $expected = (string)cfg('api_cube_key', '');
        if ($expected === '' || !hash_equals($expected, $key)) return $this->fail('认证失败', 401);

        $raw = $this->request->raw();
        $timestamp = (string)$this->request->header('X-ICE-TIMESTAMP');
        $sign = (string)$this->request->header('X-ICE-SIGN');
        if ($sign !== '') {
            if (abs(time() - (int)$timestamp) > 300) return $this->fail('签名已过期', 401);
            if (!hash_equals(hash_hmac('sha256', $timestamp . $raw, $expected), $sign)) {
                return $this->fail('签名错误', 401);
            }
        }

        $payload = $this->request->json();
        $action = (string)($payload['action'] ?? $this->request->param('action', 'ping'));
        $data = $payload['data'] ?? $payload;
        try {
            $result = $this->dispatch($action, is_array($data) ? $data : []);
        } catch (\Throwable $e) {
            log_write('api', 'Cube ' . $action . ': ' . $e->getMessage());
            return $this->fail($e->getMessage(), 500);
        }
        Db::table('api_log')->insert([
            'user_id' => 0, 'key_name' => 'cube', 'method' => $this->request->method(),
            'path' => 'api/cube/' . $action, 'ip' => client_ip(), 'created_at' => now(),
        ]);
        return $this->ok($result);
    }

    protected function dispatch(string $action, array $d)
    {
        switch ($action) {
            case 'ping':
                return ['version' => \think\App::version(), 'time' => now()];
            case 'stats':
                return [
                    'clients' => Db::table('user')->count(), 'orders' => Db::table('order')->count(),
                    'hosts' => Db::table('host')->whereIn('status', ['active', 'suspended'])->count(),
                    'domains' => Db::table('domain')->count(), 'unpaid' => Db::table('invoice')->where('status', 'unpaid')->count(),
                ];
            case 'client.list':
                $rows = Db::table('user')->order('id', 'desc')->limit((int)($d['limit'] ?? 50))->select();
                return array_map(fn($u) => $this->client($u), $rows);
            case 'client.get':
                $u = Db::table('user')->where('id', (int)($d['id'] ?? 0))->find()
                    ?: Db::table('user')->where('email', (string)($d['email'] ?? ''))->find();
                return $u ? $this->client($u) : ['error' => '客户不存在'];
            case 'client.create':
                return $this->clientCreate($d);
            case 'client.update':
                return $this->clientUpdate($d);
            case 'product.list':
                $out = [];
                foreach (Db::table('product')->where('status', 1)->order('sort')->select() as $p) {
                    $pricing = [];
                    foreach (Db::table('pricing')->where('product_id', $p['id'])->select() as $pr) {
                        $pricing[$pr['cycle']] = (float)$pr['price'];
                    }
                    $out[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'type' => $p['type'],
                        'module' => $p['module'], 'pricing' => $pricing];
                }
                return $out;
            case 'order.list':
                $rows = Db::table('order')->order('id', 'desc')->limit((int)($d['limit'] ?? 50))->select();
                return array_map(fn($o) => $this->order($o), $rows);
            case 'order.get':
                $o = Db::table('order')->where('id', (int)($d['id'] ?? 0))->find();
                return $o ? $this->order($o, true) : ['error' => '订单不存在'];
            case 'order.create':
                return Billing::createOrder((int)($d['user_id'] ?? 0), (array)($d['items'] ?? []), [
                    'coupon' => $d['coupon'] ?? '', 'note' => $d['note'] ?? 'Cube Bridge',
                ]);
            case 'invoice.list':
                $rows = Db::table('invoice')->order('id', 'desc')->limit((int)($d['limit'] ?? 50))->select();
                return array_map(fn($i) => $this->invoice($i), $rows);
            case 'invoice.get':
                $i = Db::table('invoice')->where('id', (int)($d['id'] ?? 0))->find();
                return $i ? $this->invoice($i, true) : ['error' => '账单不存在'];
            case 'invoice.create':
                return Billing::createInvoice((int)($d['user_id'] ?? 0), (array)($d['items'] ?? []), [
                    'due_days' => (int)($d['due_days'] ?? 7), 'note' => $d['note'] ?? 'Cube Bridge',
                    'send_mail' => (bool)($d['send_mail'] ?? false),
                ]);
            case 'invoice.pay':
                return Billing::markPaid((int)($d['invoice_id'] ?? 0), (string)($d['gateway'] ?? 'cube'),
                    (string)($d['trade_no'] ?? ('CUBE' . time())), (float)($d['amount'] ?? 0), 0, '魔方桥接入账');
            case 'host.list':
                $rows = Db::table('host')->order('id', 'desc')->limit((int)($d['limit'] ?? 50))->select();
                return array_map(fn($h) => $this->host($h), $rows);
            case 'host.action':
                $id = (int)($d['id'] ?? 0);
                return match ((string)($d['action'] ?? '')) {
                    'create' => Provision::create($id),
                    'suspend' => Provision::suspend($id),
                    'unsuspend' => Provision::unsuspend($id),
                    'terminate' => Provision::terminate($id),
                    'password' => Provision::changePassword($id, (string)($d['password'] ?? '')),
                    default => ['ok' => false, 'error' => '未知操作'],
                };
            case 'domain.list':
                $rows = Db::table('domain')->order('id', 'desc')->limit((int)($d['limit'] ?? 50))->select();
                return array_map(fn($x) => [
                    'id' => (int)$x['id'], 'domain' => $x['domain'], 'user_id' => (int)$x['user_id'],
                    'status' => $x['status'], 'expire_date' => $x['expire_date'], 'registrar' => $x['registrar'],
                ], $rows);
            case 'domain.action':
                $id = (int)($d['id'] ?? 0);
                return match ((string)($d['action'] ?? '')) {
                    'register' => DomainService::register($id),
                    'renew' => DomainService::renew($id, (int)($d['years'] ?? 1)),
                    'sync' => DomainService::sync($id),
                    'ns' => DomainService::setNs($id, (array)($d['ns'] ?? [])),
                    default => ['ok' => false, 'error' => '未知操作'],
                };
            default:
                return ['error' => '不支持的操作: ' . $action];
        }
    }

    protected function client(array $u): array
    {
        return [
            'id' => (int)$u['id'], 'email' => $u['email'], 'name' => $u['name'], 'company' => $u['company'],
            'phone' => $u['phone'], 'balance' => (float)$u['balance'], 'currency' => $u['currency'],
            'status' => (int)$u['status'], 'created_at' => $u['created_at'],
        ];
    }

    protected function clientCreate(array $d): array
    {
        $email = strtolower(trim((string)($d['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('邮箱无效');
        if (Db::table('user')->where('email', $email)->exists()) throw new \RuntimeException('邮箱已存在');
        $password = (string)($d['password'] ?? generate_password(12));
        $id = Db::table('user')->insert([
            'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT),
            'name' => (string)($d['name'] ?? $email), 'company' => (string)($d['company'] ?? ''),
            'phone' => (string)($d['phone'] ?? ''), 'country' => (string)($d['country'] ?? 'CN'),
            'currency' => (string)($d['currency'] ?? cfg('currency', 'CNY')), 'status' => 1, 'email_verified' => 1,
            'api_key' => 'ice_' . random_str(40), 'affiliate_code' => random_str(10),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['id' => $id, 'password' => $password];
    }

    protected function clientUpdate(array $d): array
    {
        $id = (int)($d['id'] ?? 0);
        if (!Db::table('user')->where('id', $id)->exists()) throw new \RuntimeException('客户不存在');
        $data = [];
        foreach (['name', 'company', 'phone', 'country', 'province', 'city', 'address', 'postcode'] as $f) {
            if (isset($d[$f])) $data[$f] = (string)$d[$f];
        }
        if (!empty($d['password'])) $data['password'] = password_hash((string)$d['password'], PASSWORD_DEFAULT);
        if ($data) Db::table('user')->where('id', $id)->update($data + ['updated_at' => now()]);
        return ['id' => $id];
    }

    protected function order(array $o, bool $detail = false): array
    {
        $out = ['id' => (int)$o['id'], 'order_no' => $o['order_no'], 'user_id' => (int)$o['user_id'],
            'total' => (float)$o['total'], 'status' => $o['status'], 'created_at' => $o['created_at']];
        if ($detail) $out['items'] = Db::table('order_item')->where('order_id', $o['id'])->select();
        return $out;
    }

    protected function invoice(array $i, bool $detail = false): array
    {
        $out = ['id' => (int)$i['id'], 'invoice_no' => $i['invoice_no'], 'user_id' => (int)$i['user_id'],
            'total' => (float)$i['total'], 'paid' => (float)$i['paid'], 'status' => $i['status'],
            'due_at' => $i['due_at'], 'created_at' => $i['created_at']];
        if ($detail) $out['items'] = Db::table('invoice_item')->where('invoice_id', $i['id'])->select();
        return $out;
    }

    protected function host(array $h): array
    {
        return ['id' => (int)$h['id'], 'user_id' => (int)$h['user_id'], 'domain' => $h['domain'],
            'product_id' => (int)$h['product_id'], 'status' => $h['status'], 'module' => $h['module'],
            'next_due' => $h['next_due'], 'ip' => $h['ip']];
    }

    protected function ok($data, int $code = 200): Response
    {
        return Response::json(['code' => 0, 'msg' => 'ok', 'data' => $data], $code);
    }

    protected function fail(string $msg, int $code = 400): Response
    {
        return Response::json(['code' => $code, 'msg' => $msg, 'data' => null], $code);
    }
}

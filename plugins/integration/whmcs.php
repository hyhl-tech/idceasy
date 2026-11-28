<?php
/**
 * WHMCS 双向对接
 * 出站：调用 WHMCS API 拉取 / 推送客户
 * 入站：WHMCS 通过 /api/whmcs 调用本系统，配套服务器模块见 integration/whmcs
 */
return new class extends \plugins\Integration {
    public function info(): array
    {
        return [
            'id' => 'whmcs', 'title' => 'WHMCS 双向对接', 'version' => '1.0', 'author' => 'idceasy',
            'description' => '把 WHMCS 的客户拉进来，也把新客户推过去。入站接口为 /api/whmcs，支持开通、暂停、解除、删除与改密。',
            'note' => '入站地址：POST /api/whmcs（Identifier / Secret 在「设置 - 接口与自动化」配置）。出站需要 WHMCS 开放 API 并放行本机 IP。',
            'fields' => [
                ['name' => 'api_url', 'label' => 'WHMCS 地址', 'type' => 'text', 'required' => true, 'help' => '如 https://whmcs.example.com，不要带 /includes/api.php'],
                ['name' => 'identifier', 'label' => 'API Identifier', 'type' => 'text', 'required' => true],
                ['name' => 'secret', 'label' => 'API Secret', 'type' => 'password', 'required' => true],
                ['name' => 'auto_pull', 'label' => '计划任务自动拉取客户', 'type' => 'checkbox'],
                ['name' => 'auto_push', 'label' => '新用户自动推送到 WHMCS', 'type' => 'checkbox'],
            ],
        ];
    }

    protected function call(string $action, array $params = []): array
    {
        return \app\service\WhmcsSync::call($this->config, $action, $params);
    }

    public function test(): array
    {
        if ((string)$this->config('api_url', '') === '') {
            return ['ok' => false, 'error' => '请先填写 WHMCS 地址'];
        }
        $res = $this->call('GetClients', ['limitnum' => 1]);
        if (($res['result'] ?? '') !== 'success') {
            return ['ok' => false, 'error' => 'WHMCS: ' . ($res['message'] ?? '认证或接口失败')];
        }
        $total = (int)($res['totalresults'] ?? 0);
        return ['ok' => true, 'msg' => '连接成功，远端共有 ' . $total . ' 个客户'];
    }

    public function pull(): array
    {
        $res = $this->call('GetClients', ['limitnum' => 500]);
        if (($res['result'] ?? '') !== 'success') {
            return ['ok' => false, 'error' => 'WHMCS: ' . ($res['message'] ?? '拉取失败')];
        }
        $rows = $res['clients']['client'] ?? $res['clients'] ?? [];
        if (!is_array($rows)) $rows = [];
        $created = 0; $skipped = 0;
        foreach ($rows as $c) {
            $email = strtolower(trim((string)($c['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }
            if (\think\Db::table('user')->where('email', $email)->find()) { $skipped++; continue; }
            $name = trim((string)($c['firstname'] ?? '') . ' ' . (string)($c['lastname'] ?? ''));
            \think\Db::table('user')->insert([
                'email' => $email,
                'password' => password_hash(generate_password(18), PASSWORD_DEFAULT),
                'name' => $name !== '' ? $name : $email,
                'company' => (string)($c['companyname'] ?? ''),
                'phone' => (string)($c['phonenumber'] ?? ''),
                'country' => strtoupper((string)($c['country'] ?? 'CN')) ?: 'CN',
                'currency' => (string)($c['currency_code'] ?? cfg('currency', 'CNY')),
                'balance' => (float)($c['credit'] ?? 0),
                'status' => 1, 'email_verified' => 1,
                'api_key' => 'ice_' . random_str(40), 'affiliate_code' => random_str(10),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $created++;
        }
        $this->log('WHMCS 拉取完成，新增 ' . $created . '，跳过 ' . $skipped);
        return ['ok' => true, 'msg' => '拉取完成：新增 ' . $created . ' 个客户，跳过 ' . $skipped . ' 个', 'created' => $created, 'skipped' => $skipped, 'total' => count($rows)];
    }

    public function push(array $user): array
    {
        $name = preg_split('/\s+/', trim((string)($user['name'] ?? 'Client'))) ?: ['Client'];
        $res = $this->call('AddClient', [
            'firstname' => $name[0] ?: 'Client',
            'lastname' => $name[1] ?? 'Client',
            'email' => (string)($user['email'] ?? ''),
            'companyname' => (string)($user['company'] ?? ''),
            'phonenumber' => (string)($user['phone'] ?? ''),
            'country' => (string)($user['country'] ?? 'CN'),
            'currency' => (string)($user['currency'] ?? cfg('currency', 'CNY')),
            'password2' => generate_password(16),
            'noemail' => 1,
        ]);
        if (($res['result'] ?? '') === 'success') {
            return ['ok' => true, 'msg' => '已推送到 WHMCS', 'client_id' => $res['clientid'] ?? null];
        }
        $message = (string)($res['message'] ?? '推送失败');
        if (stripos($message, 'already exists') !== false) {
            return ['ok' => true, 'msg' => 'WHMCS 中已存在该邮箱'];
        }
        return ['ok' => false, 'error' => 'WHMCS: ' . $message];
    }

    public function stats(): array
    {
        return [
            '客户数' => \think\Db::table('user')->count(),
            '待处理工单' => \think\Db::table('ticket')->whereIn('status', ['open', 'answered'])->count(),
        ];
    }

    public function boot(): void
    {
        if ((string)$this->config('auto_push', '0') === '1') {
            \think\Plugin::listen('user.registered', function ($data) {
                try {
                    $user = \think\Db::table('user')->where('id', (int)($data['user_id'] ?? 0))->find();
                    if ($user) $this->push($user);
                } catch (\Throwable $e) {
                    $this->log('WHMCS 自动推送失败: ' . $e->getMessage());
                }
            });
        }
        if ((string)$this->config('auto_pull', '0') === '1') {
            \think\Plugin::listen('cron.run', function () {
                try { $this->pull(); } catch (\Throwable $e) { $this->log('WHMCS 定时拉取失败: ' . $e->getMessage()); }
            });
        }
    }
};

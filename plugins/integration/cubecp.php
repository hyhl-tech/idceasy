<?php
/**
 * 魔方财务 / Bridge v1 对接
 * 出站：调用对方 Bridge v1（/api/cube）拉取 / 推送客户
 * 入站：对方调用本系统 /api/cube
 */
return new class extends \plugins\Integration {
    public function info(): array
    {
        return [
            'id' => 'cubecp', 'title' => '魔方财务对接（Bridge v1）', 'version' => '1.0', 'author' => 'idceasy',
            'description' => '与魔方财务或任意实现 Bridge v1 的财务系统对接：客户双向同步，订单与账单可通过 Bridge 动作读取。',
            'note' => '入站地址：POST /api/cube，请求头 X-ICE-KEY。出站使用同一套 Bridge v1 协议，接口说明见 integration/cubecp/README.md。',
            'fields' => [
                ['name' => 'api_url', 'label' => '对方地址', 'type' => 'text', 'required' => true, 'help' => '如 https://finance.example.com，自动拼接 /api/cube'],
                ['name' => 'key', 'label' => 'Bridge Key', 'type' => 'password', 'required' => true],
                ['name' => 'auto_pull', 'label' => '计划任务自动拉取客户', 'type' => 'checkbox'],
                ['name' => 'auto_push', 'label' => '新用户自动推送', 'type' => 'checkbox'],
            ],
        ];
    }

    protected function call(string $action, array $data = []): array
    {
        $url = rtrim((string)$this->config('api_url', ''), '/') . '/api/cube';
        $res = $this->http('POST', $url, ['action' => $action, 'data' => $data], [
            'X-ICE-KEY: ' . (string)$this->config('key', ''),
            'Content-Type: application/json',
        ], ['json' => true]);
        if ($res['error'] !== '') return ['ok' => false, 'error' => '网络错误：' . $res['error']];
        $json = $res['json'];
        if (!is_array($json)) return ['ok' => false, 'error' => '返回格式异常'];
        if ((int)($json['code'] ?? -1) !== 0) {
            return ['ok' => false, 'error' => (string)($json['msg'] ?? 'Bridge 调用失败')];
        }
        return ['ok' => true, 'data' => $json['data'] ?? []];
    }

    public function test(): array
    {
        if ((string)$this->config('api_url', '') === '') return ['ok' => false, 'error' => '请先填写对方地址'];
        $res = $this->call('ping');
        if (!$res['ok']) return $res;
        $v = $res['data']['version'] ?? '';
        return ['ok' => true, 'msg' => 'Bridge 连接成功' . ($v !== '' ? '，对方版本 ' . $v : '')];
    }

    public function pull(): array
    {
        $res = $this->call('client.list', ['limit' => 500]);
        if (!$res['ok']) return $res;
        $rows = $res['data'];
        if (isset($rows['items'])) $rows = $rows['items'];
        if (!is_array($rows)) $rows = [];
        $created = 0; $skipped = 0;
        foreach ($rows as $c) {
            $email = strtolower(trim((string)($c['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }
            if (\think\Db::table('user')->where('email', $email)->find()) { $skipped++; continue; }
            \think\Db::table('user')->insert([
                'email' => $email,
                'password' => password_hash(generate_password(18), PASSWORD_DEFAULT),
                'name' => (string)($c['name'] ?? $email),
                'company' => (string)($c['company'] ?? ''),
                'phone' => (string)($c['phone'] ?? ''),
                'country' => strtoupper((string)($c['country'] ?? 'CN')) ?: 'CN',
                'currency' => (string)($c['currency'] ?? cfg('currency', 'CNY')),
                'balance' => (float)($c['balance'] ?? 0),
                'status' => 1, 'email_verified' => 1,
                'api_key' => 'ice_' . random_str(40), 'affiliate_code' => random_str(10),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $created++;
        }
        $this->log('Bridge 拉取完成，新增 ' . $created . '，跳过 ' . $skipped);
        return ['ok' => true, 'msg' => '拉取完成：新增 ' . $created . ' 个客户，跳过 ' . $skipped . ' 个', 'created' => $created, 'skipped' => $skipped, 'total' => count($rows)];
    }

    public function push(array $user): array
    {
        $res = $this->call('client.create', [
            'email' => (string)($user['email'] ?? ''),
            'name' => (string)($user['name'] ?? ''),
            'company' => (string)($user['company'] ?? ''),
            'phone' => (string)($user['phone'] ?? ''),
            'country' => (string)($user['country'] ?? 'CN'),
            'currency' => (string)($user['currency'] ?? cfg('currency', 'CNY')),
        ]);
        if (!$res['ok']) return $res;
        return ['ok' => true, 'msg' => '已推送到财务系统', 'remote_id' => $res['data']['id'] ?? null];
    }

    public function stats(): array
    {
        $res = $this->call('stats');
        return $res['ok'] && is_array($res['data']) ? $res['data'] : [];
    }

    public function boot(): void
    {
        if ((string)$this->config('auto_push', '0') === '1') {
            \think\Plugin::listen('user.registered', function ($data) {
                try {
                    $user = \think\Db::table('user')->where('id', (int)($data['user_id'] ?? 0))->find();
                    if ($user) $this->push($user);
                } catch (\Throwable $e) {
                    $this->log('Bridge 自动推送失败: ' . $e->getMessage());
                }
            });
        }
        if ((string)$this->config('auto_pull', '0') === '1') {
            \think\Plugin::listen('cron.run', function () {
                try { $this->pull(); } catch (\Throwable $e) { $this->log('Bridge 定时拉取失败: ' . $e->getMessage()); }
            });
        }
    }
};

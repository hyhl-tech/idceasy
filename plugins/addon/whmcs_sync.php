<?php
/**
 * WHMCS 双向同步扩展
 * - cron.run：从远端 WHMCS 拉取客户，缺失的自动在 idceasy 建立账户
 * - user.registered：把 idceasy 新注册用户推送到远端 WHMCS
 * 配置：WHMCS API 地址、Identifier、Secret。
 */
use think\Db;
use think\Plugin;

return [
    'info' => [
        'title' => 'WHMCS 双向同步',
        'version' => '1.0',
        'author' => 'idceasy',
        'description' => '定时从 WHMCS 拉取客户，并把新注册用户推送到 WHMCS（可选）。',
        'fields' => [
            ['name' => 'api_url', 'label' => 'WHMCS 地址', 'type' => 'text', 'required' => true, 'help' => '如 https://whmcs.example.com'],
            ['name' => 'identifier', 'label' => 'Identifier', 'type' => 'text', 'required' => true],
            ['name' => 'secret', 'label' => 'Secret', 'type' => 'password', 'required' => true],
            ['name' => 'pull', 'label' => '拉取客户', 'type' => 'checkbox'],
            ['name' => 'push', 'label' => '推送新用户', 'type' => 'checkbox'],
        ],
    ],
    'hooks' => [
        'cron.run' => function () {
            $cfg = Plugin::config('addon', 'whmcs_sync');
            if (($cfg['pull'] ?? '0') !== '1' || empty($cfg['api_url'])) return;
            $res = \app\service\WhmcsSync::call($cfg, 'GetClients', ['limitnum' => 100]);
            if (($res['result'] ?? '') !== 'success') return;
            $created = 0;
            foreach ((array)($res['clients'] ?? []) as $c) {
                $email = strtolower(trim((string)($c['email'] ?? '')));
                if ($email === '' || Db::table('user')->where('email', $email)->exists()) continue;
                Db::table('user')->insert([
                    'email' => $email,
                    'password' => password_hash(generate_password(16), PASSWORD_DEFAULT),
                    'name' => trim(($c['firstname'] ?? '') . ' ' . ($c['lastname'] ?? '')),
                    'company' => (string)($c['companyname'] ?? ''), 'phone' => (string)($c['phonenumber'] ?? ''),
                    'country' => (string)($c['country'] ?? 'CN'), 'currency' => (string)($c['currency_code'] ?? cfg('currency', 'CNY')),
                    'balance' => (float)($c['credit'] ?? 0), 'status' => 1, 'email_verified' => 1,
                    'api_key' => 'ice_' . random_str(40), 'affiliate_code' => random_str(10),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $created++;
            }
            log_write('plugin', 'WHMCS 同步完成，新增客户 ' . $created . ' 个');
        },
        'user.registered' => function ($data) {
            $cfg = Plugin::config('addon', 'whmcs_sync');
            if (($cfg['push'] ?? '0') !== '1' || empty($cfg['api_url'])) return;
            $user = Db::table('user')->where('id', (int)($data['user_id'] ?? 0))->find();
            if (!$user) return;
            $name = preg_split('/\s+/', trim($user['name'] ?: 'Client'));
            \app\service\WhmcsSync::call($cfg, 'AddClient', [
                'email' => $user['email'], 'firstname' => $name[0] ?? 'Client',
                'lastname' => $name[1] ?? 'Client', 'companyname' => $user['company'],
                'phonenumber' => $user['phone'], 'country' => $user['country'],
                'password2' => generate_password(16), 'noemail' => 1,
            ]);
        },
    ],
];

<?php
require_once __DIR__ . '/_Cpanel.php';
return new class extends CpanelServer {
    public function info(): array
    {
        return ['id' => 'whm', 'title' => 'WHM / cPanel', 'version' => '1.0', 'author' => 'idceasy',
            'description' => '通过 WHM API1 创建 cPanel 虚拟主机账户，使用 API Token 或 root 密码。',
            'fields' => [
                ['name' => 'host', 'label' => 'WHM 地址', 'type' => 'text', 'required' => true],
                ['name' => 'port', 'label' => '端口', 'type' => 'number', 'help' => '默认 2087'],
                ['name' => 'username', 'label' => '用户名', 'type' => 'text', 'help' => '通常为 root'],
                ['name' => 'password', 'label' => 'API Token / 密码', 'type' => 'password', 'required' => true],
                ['name' => 'plan', 'label' => '默认套餐', 'type' => 'text', 'help' => 'WHM 中的 hosting plan 名称'],
                ['name' => 'contact_email', 'label' => '联系邮箱', 'type' => 'text'],
            ]];
    }
};

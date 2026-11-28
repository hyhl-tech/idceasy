<?php
require_once __DIR__ . '/_Cpanel.php';
return new class extends CpanelServer {
    public function info(): array
    {
        return ['id' => 'cpanel', 'title' => 'cPanel 主机（WHM）', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'cPanel 产品线专用，底层仍调用 WHM API 创建账户，可配置不同的默认套餐。',
            'fields' => [
                ['name' => 'host', 'label' => 'WHM 地址', 'type' => 'text', 'required' => true],
                ['name' => 'port', 'label' => '端口', 'type' => 'number', 'help' => '默认 2087'],
                ['name' => 'username', 'label' => '用户名', 'type' => 'text', 'help' => '通常为 root'],
                ['name' => 'password', 'label' => 'API Token / 密码', 'type' => 'password', 'required' => true],
                ['name' => 'plan', 'label' => '默认套餐', 'type' => 'text'],
                ['name' => 'contact_email', 'label' => '联系邮箱', 'type' => 'text'],
            ]];
    }
};

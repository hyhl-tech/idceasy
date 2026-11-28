<?php
require_once __DIR__ . '/_IspApi.php';
return new class extends IspApiRegistrar {
    public function info(): array
    {
        return ['id' => 'hexonet', 'title' => 'Hexonet (1API)', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'Hexonet / 1API 注册商，支持 1000+ 后缀，需联系人 Handle。',
            'fields' => [
                ['name' => 'login', 'label' => '登录名', 'type' => 'text', 'required' => true],
                ['name' => 'password', 'label' => '密码', 'type' => 'password', 'required' => true],
                ['name' => 'contact_handle', 'label' => '联系人 Handle', 'type' => 'text', 'required' => true],
            ]];
    }
};

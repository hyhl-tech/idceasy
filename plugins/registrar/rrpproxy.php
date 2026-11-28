<?php
require_once __DIR__ . '/_IspApi.php';
return new class extends IspApiRegistrar {
    public function info(): array
    {
        return ['id' => 'rrpproxy', 'title' => 'RRPproxy', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'RRPproxy (Key-Systems) 白标注册商，API 与 ISPAPI 兼容。',
            'fields' => [
                ['name' => 'login', 'label' => '登录名', 'type' => 'text', 'required' => true],
                ['name' => 'password', 'label' => '密码', 'type' => 'password', 'required' => true],
                ['name' => 'contact_handle', 'label' => '联系人 Handle', 'type' => 'text', 'required' => true],
            ]];
    }
    protected function endpoint(): string { return rtrim((string)$this->config('base_url', 'https://api.rrpproxy.net/api/call.cgi'), '/'); }
};

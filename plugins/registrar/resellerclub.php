<?php
require_once __DIR__ . '/_LogicBoxes.php';
return new class extends LogicBoxesRegistrar {
    public function info(): array
    {
        return [
            'id' => 'resellerclub', 'title' => 'ResellerClub', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'LogicBoxes 平台，支持 1000+ 后缀，需 ResellerClub 账户与 API Key。',
            'fields' => [
                ['name' => 'reseller_id', 'label' => 'Reseller ID', 'type' => 'text', 'required' => true],
                ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                ['name' => 'customer_id', 'label' => '默认 Customer ID', 'type' => 'text', 'help' => '留空则自动创建联系人'],
                ['name' => 'contact_id', 'label' => '默认联系人 ID', 'type' => 'text', 'help' => '可留空，首次注册时自动创建'],
                ['name' => 'privacy', 'label' => '默认开启隐私保护', 'type' => 'checkbox'],
                ['name' => 'base_url', 'label' => 'API 地址', 'type' => 'text', 'help' => '留空使用 https://httpapi.com/api'],
            ],
        ];
    }
};

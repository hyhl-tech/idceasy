<?php
require_once __DIR__ . '/_LogicBoxes.php';
return new class extends LogicBoxesRegistrar {
    public function info(): array
    {
        return [
            'id' => 'resellbiz', 'title' => 'ResellBiz', 'version' => '1.0', 'author' => 'idceasy',
            'description' => 'LogicBoxes 白标平台，API 端点 api.resell.biz。',
            'fields' => [
                ['name' => 'reseller_id', 'label' => 'Reseller ID', 'type' => 'text', 'required' => true],
                ['name' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                ['name' => 'customer_id', 'label' => '默认 Customer ID', 'type' => 'text'],
                ['name' => 'contact_id', 'label' => '默认联系人 ID', 'type' => 'text'],
                ['name' => 'privacy', 'label' => '默认开启隐私保护', 'type' => 'checkbox'],
            ],
        ];
    }
    protected function apiBase(): string { return rtrim((string)$this->config('base_url', 'https://api.resell.biz/api'), '/'); }
};

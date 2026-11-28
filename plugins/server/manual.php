<?php
return new class extends \plugins\Server {
    public function info(): array
    {
        return ['id' => 'manual', 'title' => '人工开通', 'version' => '1.0', 'author' => 'idceasy',
            'description' => '不调用外部接口，订单支付后进入待处理，由管理员手动开通与维护。',
            'fields' => []];
    }
    public function create(array $params): array
    {
        return ['ok' => true, 'manual' => true, 'msg' => '等待人工开通'];
    }
    public function suspend(array $params): array { return ['ok' => true, 'manual' => true]; }
    public function unsuspend(array $params): array { return ['ok' => true, 'manual' => true]; }
    public function terminate(array $params): array { return ['ok' => true, 'manual' => true]; }
    public function changePassword(array $params, string $password): array { return ['ok' => true, 'manual' => true]; }
    public function test(): array { return ['ok' => true, 'msg' => '人工模块无需测试']; }
};

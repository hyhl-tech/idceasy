<?php
/**
 * 线下汇款 / 对公转账（人工确认）
 */
return new class extends \plugins\Gateway {
    public function info(): array
    {
        return ['id' => 'offline', 'title' => '线下汇款', 'version' => '1.0', 'author' => 'idceasy',
            'description' => '展示银行账户或收款信息，客户转账后由管理员手动入账。',
            'fields' => [
                ['name' => 'bank_name', 'label' => '开户银行', 'type' => 'text'],
                ['name' => 'account_name', 'label' => '户名', 'type' => 'text'],
                ['name' => 'account_no', 'label' => '账号', 'type' => 'text'],
                ['name' => 'note', 'label' => '补充说明', 'type' => 'textarea', 'help' => '可填写 USDT 地址、对公流程等'],
            ]];
    }
    public function pay(array $invoice, string $returnUrl, string $notifyUrl): array
    {
        $due = round((float)$invoice['total'] - (float)$invoice['paid'], 2);
        $html = '<div style="font-size:14px;line-height:1.9">'
            . '<p>请将 <strong>' . e(money($due, $invoice['currency'])) . '</strong> 转入以下账户，并在备注中填写账单号 <code>' . e($invoice['invoice_no']) . '</code>。</p>'
            . '<p>开户行：' . e($this->config('bank_name', '-')) . '<br>户名：' . e($this->config('account_name', '-'))
            . '<br>账号：' . e($this->config('account_no', '-')) . '</p>'
            . ($this->config('note', '') ? '<p class="muted">' . nl2br(e($this->config('note', ''))) . '</p>' : '')
            . '<p class="muted">到账后管理员会在 1 个工作日内确认，账单状态将自动更新。</p></div>';
        return $this->ok(['type' => 'html', 'html' => $html]);
    }
    public function notify(array $params, string $raw = ''): array
    {
        return $this->fail('线下汇款需人工确认');
    }
    public function ack(array $result): string { return 'success'; }
};

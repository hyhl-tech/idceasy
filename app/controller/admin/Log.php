<?php
/**
 * 后台：系统日志
 */
namespace app\controller\admin;

use think\Db;
use think\Response;

class Log extends Base
{
    protected string $perm = 'log';

    public function index(string $type = 'admin'): Response
    {
        $types = [
            'admin' => [
                'title' => '操作日志', 'table' => 'admin_log', 'search' => ['action', 'detail'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'admin_id', 'label' => '管理员'],
                    ['field' => 'action', 'label' => '操作'],
                    ['field' => 'detail', 'label' => '详情', 'type' => 'excerpt'],
                    ['field' => 'ip', 'label' => 'IP'],
                    ['field' => 'created_at', 'label' => '时间', 'type' => 'date'],
                ],
            ],
            'email' => [
                'title' => '邮件日志', 'table' => 'email_log', 'search' => ['to_email', 'subject'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'to_email', 'label' => '收件人'],
                    ['field' => 'subject', 'label' => '主题'],
                    ['field' => 'status', 'label' => '状态', 'type' => 'status'],
                    ['field' => 'error', 'label' => '错误', 'type' => 'excerpt'],
                    ['field' => 'created_at', 'label' => '时间', 'type' => 'date'],
                ],
            ],
            'payment' => [
                'title' => '支付日志', 'table' => 'payment_log', 'search' => ['gateway', 'action'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'invoice_id', 'label' => '账单'],
                    ['field' => 'gateway', 'label' => '网关'],
                    ['field' => 'action', 'label' => '动作'],
                    ['field' => 'request', 'label' => '请求', 'type' => 'excerpt'],
                    ['field' => 'ip', 'label' => 'IP'],
                    ['field' => 'created_at', 'label' => '时间', 'type' => 'date'],
                ],
            ],
            'api' => [
                'title' => 'API 日志', 'table' => 'api_log', 'search' => ['path', 'key_name'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'user_id', 'label' => '用户'],
                    ['field' => 'path', 'label' => '路径'],
                    ['field' => 'ip', 'label' => 'IP'],
                    ['field' => 'created_at', 'label' => '时间', 'type' => 'date'],
                ],
            ],
            'cron' => [
                'title' => '任务日志', 'table' => 'cron_log', 'search' => ['task', 'output'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'task', 'label' => '任务'],
                    ['field' => 'status', 'label' => '状态', 'type' => 'status'],
                    ['field' => 'output', 'label' => '输出', 'type' => 'excerpt'],
                    ['field' => 'duration', 'label' => '耗时(s)'],
                    ['field' => 'created_at', 'label' => '时间', 'type' => 'date'],
                ],
            ],
            'plugin' => [
                'title' => '插件日志', 'table' => 'plugin_log', 'search' => ['plugin', 'event'],
                'columns' => [
                    ['field' => 'id', 'label' => 'ID', 'width' => '60px'],
                    ['field' => 'plugin', 'label' => '插件'],
                    ['field' => 'event', 'label' => '事件'],
                    ['field' => 'data', 'label' => '数据', 'type' => 'excerpt'],
                    ['field' => 'created_at', 'label' => '时间', 'type' => 'date'],
                ],
            ],
        ];
        if (!isset($types[$type])) $type = 'admin';
        $cfg = $types[$type];
        $q = Db::table($cfg['table']);
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $parts = [];
            $binds = [];
            foreach ($cfg['search'] as $f) {
                $parts[] = "`{$f}` LIKE ?";
                $binds[] = '%' . $keyword . '%';
            }
            $q->whereRaw('(' . implode(' OR ', $parts) . ')', $binds);
        }
        $pagination = $q->order('id', 'desc')->paginate(30, $this->pageNo());
        return $this->view('table', [
            'title' => $cfg['title'],
            'columns' => $cfg['columns'],
            'rows' => $pagination,
            'filters' => [[
                'type' => 'text', 'name' => 'keyword', 'placeholder' => '关键词', 'value' => $keyword,
            ]],
            'tabs' => array_map(fn($k, $v) => ['title' => $v['title'], 'url' => admin_url('/logs/' . $k), 'active' => $k === $type],
                array_keys($types), $types),
            'baseUrl' => admin_url('/logs/' . $type),
        ]);
    }
}

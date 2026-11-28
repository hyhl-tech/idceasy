<?php
/**
 * 示例扩展插件：新订单 / 新工单时通知管理员
 * 演示 addon 插件的 info / fields / hooks 结构，可作为二次开发模板。
 */
use think\Plugin;
use app\service\Mailer;

return [
    'info' => [
        'title' => '管理员通知',
        'version' => '1.0',
        'author' => 'idceasy',
        'description' => '新订单、新工单时向指定邮箱发送提醒，演示钩子与插件配置。',
        'fields' => [
            ['name' => 'notify_email', 'label' => '通知邮箱', 'type' => 'text', 'required' => true],
            ['name' => 'notify_order', 'label' => '新订单提醒', 'type' => 'checkbox'],
            ['name' => 'notify_ticket', 'label' => '新工单提醒', 'type' => 'checkbox'],
        ],
    ],
    'hooks' => [
        'order.created' => function ($data) {
            $cfg = Plugin::config('addon', 'admin_notify');
            if (empty($cfg['notify_email']) || ($cfg['notify_order'] ?? '0') !== '1') return;
            $order = \think\Db::table('order')->where('id', (int)($data['order_id'] ?? 0))->find();
            if (!$order) return;
            $user = \think\Db::table('user')->where('id', $order['user_id'])->find();
            Mailer::send($cfg['notify_email'], '新订单 ' . $order['order_no'],
                '<p>客户：' . e($user['email'] ?? '-') . '<br>金额：' . money($order['total'], $order['currency'])
                . '<br>订单：' . e($order['order_no']) . '</p>', false);
        },
        'ticket.created' => function ($data) {
            $cfg = Plugin::config('addon', 'admin_notify');
            if (empty($cfg['notify_email']) || ($cfg['notify_ticket'] ?? '0') !== '1') return;
            $ticket = \think\Db::table('ticket')->where('id', (int)($data['ticket_id'] ?? 0))->find();
            if (!$ticket) return;
            Mailer::send($cfg['notify_email'], '新工单 #' . $ticket['id'] . ' ' . $ticket['subject'],
                '<p>' . e($ticket['subject']) . '</p><p>请登录后台处理。</p>', false);
        },
    ],
];

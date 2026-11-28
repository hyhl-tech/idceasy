<?php
/**
 * 计划任务集合：续费账单、暂停、终止、域名过期、邮件队列、状态同步、清理
 */
namespace app\service;

use think\Db;
use think\Plugin;

class Tasks
{
    public static function definitions(): array
    {
        return [
            'invoice' => ['title' => '生成续费账单', 'desc' => '为即将到期的服务与域名生成账单'],
            'suspend' => ['title' => '暂停逾期服务', 'desc' => '逾期超过设定天数的服务自动暂停'],
            'terminate' => ['title' => '终止长期欠费', 'desc' => '暂停超过设定天数的服务自动删除'],
            'expire' => ['title' => '标记过期域名', 'desc' => '将已过期的域名标记为 expired'],
            'mail' => ['title' => '发送邮件队列', 'desc' => '处理待发送邮件，最多 100 封'],
            'domain_sync' => ['title' => '同步域名状态', 'desc' => '从注册商拉取到期时间与状态（最多 20 个）'],
            'ticket_close' => ['title' => '关闭闲置工单', 'desc' => '关闭超过设定天数无回复的已回复工单'],
            'cleanup' => ['title' => '清理历史日志', 'desc' => '删除 90 天前的邮件/API 日志与 180 天前的任务日志'],
            'hook' => ['title' => '触发插件定时钩子', 'desc' => '执行 addon 插件的 cron.run 钩子'],
        ];
    }

    public static function run(string $task): array
    {
        $defs = self::definitions();
        if (!isset($defs[$task])) return ['ok' => false, 'output' => '未知任务'];
        $start = microtime(true);
        try {
            $output = self::execute($task);
            $status = 'success';
        } catch (\Throwable $e) {
            $output = '失败：' . $e->getMessage();
            $status = 'failed';
            log_write('cron', $task . ' ' . $e->getMessage());
        }
        $duration = round(microtime(true) - $start, 3);
        Db::table('cron_log')->insert([
            'task' => $task, 'status' => $status, 'output' => $output,
            'duration' => $duration, 'created_at' => now(),
        ]);
        return ['ok' => $status === 'success', 'output' => $output, 'duration' => $duration];
    }

    public static function runAll(): array
    {
        $results = [];
        foreach (array_keys(self::definitions()) as $task) {
            $results[$task] = self::run($task);
        }
        return $results;
    }

    protected static function execute(string $task): string
    {
        switch ($task) {
            case 'invoice':
                $days = (int)cfg('renewal_days', 14);
                $res = Billing::generateRenewals($days);
                return '生成续费账单 ' . $res['created'] . ' 张（提前 ' . $days . ' 天）';
            case 'suspend':
                return '暂停服务 ' . Billing::suspendOverdue() . ' 个';
            case 'terminate':
                return '终止服务 ' . Billing::terminateOverdue() . ' 个';
            case 'expire':
                return '标记过期域名 ' . Billing::expireDomains() . ' 个';
            case 'mail':
                $res = Mailer::processQueue(100);
                return '邮件发送成功 ' . $res['sent'] . ' 封，失败 ' . $res['failed'] . ' 封';
            case 'domain_sync':
                $rows = Db::table('domain')->where('status', 'active')->order('updated_at')->limit(20)->select();
                $ok = 0;
                $fail = 0;
                foreach ($rows as $row) {
                    $res = DomainService::sync((int)$row['id']);
                    $res['ok'] ? $ok++ : $fail++;
                }
                return '同步域名 ' . $ok . ' 个，失败 ' . $fail . ' 个';
            case 'ticket_close':
                $days = max(1, (int)cfg('ticket_auto_close_days', 7));
                $n = Db::table('ticket')->where('status', 'answered')
                    ->whereRaw('last_reply_at < ?', [date('Y-m-d H:i:s', strtotime('-' . $days . ' days'))])
                    ->update(['status' => 'closed']);
                return '关闭闲置工单 ' . $n . ' 个';
            case 'cleanup':
                $e = Db::table('email_log')->whereRaw('created_at < ?', [date('Y-m-d H:i:s', strtotime('-90 days'))])->delete();
                $a = Db::table('api_log')->whereRaw('created_at < ?', [date('Y-m-d H:i:s', strtotime('-90 days'))])->delete();
                $c = Db::table('cron_log')->whereRaw('created_at < ?', [date('Y-m-d H:i:s', strtotime('-180 days'))])->delete();
                return '清理日志：邮件 ' . $e . '、API ' . $a . '、任务 ' . $c;
            case 'hook':
                Plugin::hook('cron.run', ['time' => now()]);
                return '插件定时钩子已触发';
        }
        return '无操作';
    }
}

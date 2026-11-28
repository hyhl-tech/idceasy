<?php
/**
 * 后台：定时任务
 */
namespace app\controller\admin;

use app\service\Tasks;
use think\Db;
use think\Response;

class Cron extends Base
{
    protected string $perm = 'cron';

    public function index(): Response
    {
        $defs = Tasks::definitions();
        foreach ($defs as $key => &$def) {
            $def['last'] = Db::table('cron_log')->where('task', $key)->order('id', 'desc')->find();
        }
        unset($def);
        $logs = Db::table('cron_log')->order('id', 'desc')->limit(20)->select();
        $cronUrl = abs_url('/cron.php?token=' . cfg('cron_token', ''));
        return $this->view('cron', compact('defs', 'logs', 'cronUrl'));
    }

    public function run(): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/cron'));
        $task = (string)$this->request->param('task');
        if ($task === 'all') {
            $results = Tasks::runAll();
            $failed = 0;
            foreach ($results as $r) if (empty($r['ok'])) $failed++;
            $this->logAction('运行全部定时任务', ['failed' => $failed]);
            return $this->success('任务已执行，失败 ' . $failed . ' 项');
        }
        $res = Tasks::run($task);
        $this->logAction('运行定时任务', ['task' => $task, 'output' => $res['output']]);
        return !empty($res['ok']) ? $this->success($res['output']) : $this->error($res['output']);
    }
}

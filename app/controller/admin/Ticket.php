<?php
/**
 * 后台：工单
 */
namespace app\controller\admin;

use app\service\Mailer;
use think\Db;
use think\Response;

class Ticket extends Base
{
    protected string $perm = 'ticket';

    public function index(): Response
    {
        $q = Db::table('ticket');
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $uid = (int)Db::table('user')->where('email', $keyword)->value('id', 0);
            $q->whereRaw('(subject LIKE ? OR id = ? OR user_id = ?)', ['%' . $keyword . '%', (int)$keyword, $uid]);
        }
        $status = (string)$this->request->param('status', '');
        if ($status !== '') $q->where('status', $status);
        $pagination = $q->order('last_reply_at', 'desc')->paginate(20, $this->pageNo());
        foreach ($pagination['data'] as &$row) {
            $row['user'] = Db::table('user')->where('id', $row['user_id'])->find();
            $row['dept'] = Db::table('ticket_dept')->where('id', $row['dept_id'])->find();
        }
        unset($row);
        return $this->view('table', [
            'title' => '工单管理',
            'columns' => [
                ['field' => 'id', 'label' => '#', 'width' => '60px'],
                ['field' => 'subject', 'label' => '主题'],
                ['field' => 'user.email', 'label' => '客户'],
                ['field' => 'dept.name', 'label' => '部门'],
                ['field' => 'status', 'label' => '状态', 'type' => 'status'],
                ['field' => 'last_reply_at', 'label' => '最后回复', 'type' => 'date'],
            ],
            'rows' => $pagination,
            'rowLinks' => [['label' => '处理', 'url' => admin_url('/ticket/__ID__'), 'class' => 'btn-sm']],
            'filters' => [
                ['type' => 'text', 'name' => 'keyword', 'placeholder' => '主题 / 邮箱 / ID', 'value' => $keyword],
                ['type' => 'select', 'name' => 'status', 'options' => [
                    'open' => '待回复', 'answered' => '已回复', 'customer_reply' => '客户回复', 'closed' => '已关闭',
                ], 'value' => $status],
            ],
            'baseUrl' => admin_url('/tickets'),
        ]);
    }

    public function detail(int $id): Response
    {
        $ticket = Db::table('ticket')->where('id', $id)->find();
        if (!$ticket) return $this->error('工单不存在', admin_url('/tickets'));
        $user = Db::table('user')->where('id', $ticket['user_id'])->find();
        $dept = Db::table('ticket_dept')->where('id', $ticket['dept_id'])->find();
        $depts = Db::table('ticket_dept')->order('sort')->select();
        $replies = Db::table('ticket_reply')->where('ticket_id', $id)->order('id')->select();
        foreach ($replies as &$r) {
            $r['admin'] = $r['admin_id'] ? Db::table('admin')->where('id', $r['admin_id'])->find() : null;
            $r['user'] = $r['user_id'] ? Db::table('user')->where('id', $r['user_id'])->find() : null;
        }
        unset($r);
        $canned = Db::table('canned_reply')->order('id')->select();
        return $this->view('ticket', compact('ticket', 'user', 'dept', 'depts', 'replies', 'canned'));
    }

    public function reply(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/ticket/' . $id));
        $ticket = Db::table('ticket')->where('id', $id)->find();
        if (!$ticket) return $this->error('工单不存在');
        $message = trim((string)$this->request->param('message'));
        if ($message === '') return $this->error('请输入回复内容');
        $admin = current_admin();
        Db::table('ticket_reply')->insert([
            'ticket_id' => $id, 'user_id' => 0, 'admin_id' => (int)$admin['id'],
            'message' => $message, 'created_at' => now(),
        ]);
        $status = (string)$this->request->param('status', 'answered');
        if (!in_array($status, ['answered', 'open', 'closed'], true)) $status = 'answered';
        Db::table('ticket')->where('id', $id)->update(['status' => $status, 'last_reply_at' => now()]);
        $user = Db::table('user')->where('id', $ticket['user_id'])->find();
        if ($user) {
            Mailer::sendTemplate('ticket_reply', $user['email'], [
                'name' => $user['name'] ?: $user['email'], 'ticket_id' => $id,
                'subject' => $ticket['subject'], 'message' => nl2br(e($message)),
                'url' => abs_url('/user/ticket/' . $id),
            ], false);
        }
        $this->logAction('回复工单', ['ticket_id' => $id]);
        hook('ticket.replied', ['ticket_id' => $id, 'admin_id' => (int)$admin['id']]);
        return $this->success('回复已发送');
    }

    public function status(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/ticket/' . $id));
        $status = (string)$this->request->param('status');
        if (!in_array($status, ['open', 'answered', 'customer_reply', 'closed'], true)) return $this->error('无效状态');
        $data = ['status' => $status];
        $deptId = $this->request->int('dept_id');
        if ($deptId) $data['dept_id'] = $deptId;
        Db::table('ticket')->where('id', $id)->update($data);
        $this->logAction('工单状态', ['ticket_id' => $id, 'status' => $status, 'dept_id' => $deptId]);
        return $this->success('工单已更新');
    }
}

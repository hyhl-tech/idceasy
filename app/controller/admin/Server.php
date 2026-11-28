<?php
/**
 * 后台：服务器节点
 */
namespace app\controller\admin;

use app\service\Provision;
use think\Db;
use think\Plugin;
use think\Response;

class Server extends Base
{
    protected string $perm = 'server';

    public function index(): Response
    {
        $servers = Db::table('server')->order('id')->select();
        $modules = Plugin::discover('server');
        foreach ($servers as &$s) {
            $s['group'] = Db::table('server_group')->where('id', $s['group_id'])->find();
            $s['load'] = Db::table('host')->where('server_id', $s['id'])->whereIn('status', ['active', 'suspended'])->count();
            $s['module_title'] = $modules[$s['module']]['title'] ?? $s['module'];
        }
        unset($s);
        $groups = Db::table('server_group')->order('id')->select();
        return $this->view('server_list', compact('servers', 'groups', 'modules'));
    }

    public function edit(int $id = 0): Response
    {
        $server = $id ? Db::table('server')->where('id', $id)->find() : null;
        if ($id && !$server) return $this->error('服务器不存在', admin_url('/servers'));
        if ($this->request->isPost()) {
            $data = [
                'name' => trim((string)$this->request->param('name')),
                'module' => (string)$this->request->param('module', 'manual'),
                'group_id' => $this->request->int('group_id'),
                'host' => trim((string)$this->request->param('host')),
                'port' => $this->request->int('port'),
                'username' => trim((string)$this->request->param('username')),
                'secure' => $this->request->bool('secure') ? 1 : 0,
                'status' => $this->request->bool('status') ? 1 : 0,
                'max_accounts' => $this->request->int('max_accounts'),
                'notes' => (string)$this->request->param('notes'),
            ];
            if ($data['name'] === '') return $this->error('请填写服务器名称');
            $password = (string)$this->request->param('password');
            if ($password !== '') $data['password'] = encrypt_str($password);
            elseif (!$id) $data['password'] = '';
            $apiKey = (string)$this->request->param('api_key');
            if ($apiKey !== '') $data['api_key'] = encrypt_str($apiKey);
            elseif (!$id) $data['api_key'] = '';
            if ($id) {
                Db::table('server')->where('id', $id)->update($data);
                $this->logAction('编辑服务器', ['id' => $id]);
            } else {
                $data['created_at'] = now();
                $id = Db::table('server')->insert($data);
                $this->logAction('添加服务器', ['id' => $id]);
            }
            return $this->success('服务器已保存', admin_url('/servers'));
        }
        $modules = Plugin::discover('server');
        $groups = Db::table('server_group')->order('id')->select();
        return $this->view('server_form', compact('server', 'modules', 'groups'));
    }

    public function test(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/servers'));
        $res = Provision::testServer($id);
        $this->logAction('测试服务器', ['id' => $id, 'result' => $res]);
        return !empty($res['ok']) ? $this->success($res['msg'] ?? '连接正常') : $this->error($res['error'] ?? '连接失败');
    }

    public function delete(int $id): Response
    {
        if (!$this->request->isPost()) return $this->redirect(admin_url('/servers'));
        if (Db::table('host')->where('server_id', $id)->exists()) {
            return $this->error('该节点下仍有服务，无法删除');
        }
        Db::table('server')->where('id', $id)->delete();
        $this->logAction('删除服务器', ['id' => $id]);
        return $this->success('服务器已删除');
    }
}

<?php
/**
 * 后台登录
 */
namespace app\controller\admin;

use think\Controller;
use think\Db;
use think\Response;
use think\Session;

class Login extends Controller
{
    protected string $theme = 'admin';

    public function index(): Response
    {
        if (current_admin()) return Response::redirect(admin_url('/'));
        if ($this->request->isPost()) {
            $username = trim((string)$this->request->param('username'));
            $password = (string)$this->request->param('password');
            $tries = (int)Session::get('admin_tries', 0);
            $until = (int)Session::get('admin_until', 0);
            if ($tries >= 5 && time() < $until) return $this->error('尝试次数过多，请 15 分钟后再试');
            $admin = Db::table('admin')->where('username', $username)->find();
            if (!$admin || !password_verify($password, $admin['password'])) {
                Session::set('admin_tries', $tries + 1);
                Session::set('admin_until', time() + 900);
                return $this->error('用户名或密码错误');
            }
            if ((int)$admin['status'] !== 1) return $this->error('该账户已被禁用');
            Session::regenerate();
            Session::set('aid', (int)$admin['id']);
            Session::forget('admin_tries');
            Session::forget('admin_until');
            Db::table('admin')->where('id', $admin['id'])->update([
                'last_login_at' => now(), 'last_login_ip' => client_ip(),
            ]);
            Db::table('admin_log')->insert([
                'admin_id' => $admin['id'], 'action' => '登录后台', 'detail' => '',
                'ip' => client_ip(), 'created_at' => now(),
            ]);
            hook('admin.login', ['admin_id' => (int)$admin['id']]);
            return Response::redirect(admin_url('/'));
        }
        return $this->view('login');
    }

    public function logout(): Response
    {
        Session::forget('aid');
        Session::regenerate();
        return Response::redirect(admin_url('/login'));
    }
}

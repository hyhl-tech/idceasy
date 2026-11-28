<?php
/**
 * 前台：注册、登录、找回密码、邮箱验证
 */
namespace app\controller;

use app\service\Mailer;
use think\Db;
use think\Response;
use think\Session;

class Auth extends Front
{
    public function login(): Response
    {
        if (is_logged_in()) return $this->redirect(url('/user'));
        if ($this->request->isPost()) {
            $email = strtolower(trim((string)$this->request->param('email')));
            $password = (string)$this->request->param('password');
            $tries = (int)Session::get('login_tries', 0);
            $until = (int)Session::get('login_until', 0);
            if ($tries >= 5 && time() < $until) {
                return $this->error('尝试次数过多，请 10 分钟后再试');
            }
            $user = Db::table('user')->where('email', $email)->find();
            if (!$user || !password_verify($password, $user['password'])) {
                Session::set('login_tries', $tries + 1);
                Session::set('login_until', time() + 600);
                return $this->error('邮箱或密码错误');
            }
            if ((int)$user['status'] !== 1) return $this->error('账户已被禁用，请联系客服');
            Session::regenerate();
            Session::set('uid', (int)$user['id']);
            Session::forget('login_tries');
            Db::table('user')->where('id', $user['id'])->update([
                'last_login_at' => now(), 'last_login_ip' => client_ip(),
            ]);
            Db::table('user_login_log')->insert([
                'user_id' => $user['id'], 'ip' => client_ip(), 'ua' => $this->request->ua(), 'created_at' => now(),
            ]);
            hook('user.login', ['user_id' => (int)$user['id'], 'ip' => client_ip()]);
            $redirect = (string)$this->request->param('redirect', '');
            if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) $redirect = url('/user');
            return $this->redirect($redirect);
        }
        return $this->view('login', ['redirect' => (string)$this->request->param('redirect', '')]);
    }

    public function register(): Response
    {
        if (is_logged_in()) return $this->redirect(url('/user'));
        if ((string)cfg('signup_enabled', '1') !== '1') {
            return $this->view('register', ['closed' => true]);
        }
        if ($this->request->isPost()) {
            $email = strtolower(trim((string)$this->request->param('email')));
            $password = (string)$this->request->param('password');
            $name = trim((string)$this->request->param('name'));
            $phone = trim((string)$this->request->param('phone'));
            $company = trim((string)$this->request->param('company'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->error('请输入有效的邮箱地址');
            if (strlen($password) < 8) return $this->error('密码至少 8 位');
            if ((string)$this->request->param('password2') !== $password) return $this->error('两次输入的密码不一致');
            if (!$this->request->bool('agree')) return $this->error('请阅读并同意服务条款');
            if (Db::table('user')->where('email', $email)->exists()) return $this->error('该邮箱已注册，请直接登录');

            $refCode = trim((string)$this->request->param('ref'));
            $referredBy = 0;
            if ($refCode !== '') {
                $aff = Db::table('affiliate')->where('code', $refCode)->where('status', 1)->find();
                if ($aff) {
                    $referredBy = (int)$aff['user_id'];
                    Db::table('affiliate')->where('id', $aff['id'])->increment('signups');
                }
            }
            $uid = Db::table('user')->insert([
                'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT),
                'name' => $name ?: explode('@', $email)[0], 'company' => $company, 'phone' => $phone,
                'currency' => cfg('currency', 'CNY'), 'balance' => 0, 'status' => 1,
                'email_verified' => 0, 'verify_token' => random_str(48), 'api_key' => 'ice_' . random_str(40),
                'affiliate_code' => random_str(10), 'referred_by' => $referredBy,
                'last_login_at' => now(), 'last_login_ip' => client_ip(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $user = Db::table('user')->where('id', $uid)->find();
            Mailer::sendTemplate('welcome', $email, ['name' => $user['name']]);
            hook('user.registered', ['user_id' => $uid, 'email' => $email]);
            if ((string)cfg('require_email_verify', '0') === '1') {
                Mailer::sendTemplate('verify', $email, [
                    'name' => $user['name'], 'url' => abs_url('/verify/' . $user['verify_token']),
                ]);
                return $this->success('注册成功，请查收验证邮件', url('/login'))->header('X-Robots-Tag', 'noindex');
            }
            Session::regenerate();
            Session::set('uid', $uid);
            return $this->redirect(url('/user'));
        }
        $ref = (string)$this->request->param('ref', '');
        return $this->view('register', ['ref' => $ref, 'closed' => false]);
    }

    public function logout(): Response
    {
        Session::forget('uid');
        Session::regenerate();
        return $this->redirect(url('/'));
    }

    public function forgot(): Response
    {
        if ($this->request->isPost()) {
            $email = strtolower(trim((string)$this->request->param('email')));
            $user = Db::table('user')->where('email', $email)->find();
            if ($user) {
                $token = random_str(48);
                Db::table('user')->where('id', $user['id'])->update([
                    'reset_token' => $token, 'reset_expire' => date('Y-m-d H:i:s', time() + 3600),
                ]);
                Mailer::sendTemplate('reset', $email, [
                    'name' => $user['name'] ?: $email, 'url' => abs_url('/reset/' . $token),
                ]);
            }
            return $this->success('如果该邮箱已注册，重置链接已发送，请查收', url('/login'));
        }
        return $this->view('forgot');
    }

    public function reset(string $token): Response
    {
        $user = Db::table('user')->where('reset_token', $token)->find();
        if (!$user || !$user['reset_expire'] || strtotime($user['reset_expire']) < time()) {
            return $this->view('reset', ['invalid' => true, 'token' => $token]);
        }
        if ($this->request->isPost()) {
            $password = (string)$this->request->param('password');
            if (strlen($password) < 8) return $this->error('密码至少 8 位');
            if ((string)$this->request->param('password2') !== $password) return $this->error('两次密码不一致');
            Db::table('user')->where('id', $user['id'])->update([
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'reset_token' => '', 'reset_expire' => null, 'updated_at' => now(),
            ]);
            return $this->success('密码已重置，请使用新密码登录', url('/login'));
        }
        return $this->view('reset', ['invalid' => false, 'token' => $token]);
    }

    public function verify(string $token): Response
    {
        $user = Db::table('user')->where('verify_token', $token)->find();
        if (!$user) return $this->view('message', ['title' => '验证失败', 'message' => '链接无效或已使用']);
        Db::table('user')->where('id', $user['id'])->update(['email_verified' => 1, 'verify_token' => '']);
        return $this->view('message', ['title' => '邮箱已验证', 'message' => '您的邮箱已验证，现在可以正常使用全部功能。']);
    }
}

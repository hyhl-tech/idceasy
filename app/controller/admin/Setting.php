<?php
/**
 * 后台：系统设置
 */
namespace app\controller\admin;

use app\service\Mailer;
use think\Db;
use think\Plugin;
use think\Response;

class Setting extends Base
{
    protected string $perm = 'setting';

    public function index(): Response
    {
        $tab = (string)$this->request->param('tab', 'general');
        $tabs = $this->tabs();
        if (!isset($tabs[$tab])) $tab = 'general';

        if ($this->request->isPost()) {
            $action = (string)$this->request->param('action', 'save');
            if ($action === 'test_mail') {
                $to = (string)$this->request->param('to', current_admin()['email'] ?? '');
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return $this->error('请填写有效的收件邮箱');
                $res = Mailer::test($to);
                return !empty($res['ok']) ? $this->success($res['msg']) : $this->error($res['msg']);
            }
            if ($action === 'regen_cron') {
                cfg_set('cron_token', random_str(32));
                $this->logAction('重置定时任务令牌');
                return $this->success('令牌已重置', admin_url('/settings?tab=api'));
            }
            if ($action === 'admin_password') {
                $admin = current_admin();
                if (!password_verify((string)$this->request->param('old_password'), $admin['password'])) {
                    return $this->error('当前密码错误');
                }
                $new = (string)$this->request->param('new_password');
                if (strlen($new) < 8) return $this->error('新密码至少 8 位');
                if ($new !== (string)$this->request->param('new_password2')) return $this->error('两次密码不一致');
                Db::table('admin')->where('id', $admin['id'])->update(['password' => password_hash($new, PASSWORD_DEFAULT)]);
                $this->logAction('修改管理员密码');
                return $this->success('密码已修改');
            }
            foreach ($tabs[$tab]['fields'] as $field) {
                if (!empty($field['readonly'])) continue;
                $name = $field['name'];
                if (($field['type'] ?? '') === 'password') {
                    $v = (string)$this->request->param($name);
                    if ($v !== '') cfg_set($name, $v);
                    continue;
                }
                if (($field['type'] ?? '') === 'checkbox') {
                    cfg_set($name, $this->request->bool($name) ? '1' : '0');
                    continue;
                }
                cfg_set($name, (string)$this->request->param($name, ''));
            }
            $this->logAction('保存设置', ['tab' => $tab]);
            return $this->success('设置已保存', admin_url('/settings?tab=' . $tab));
        }

        $values = cfg();
        $admin = current_admin();
        $cronUrl = abs_url('/cron.php?token=' . cfg('cron_token', ''));
        return $this->view('setting', [
            'tabs' => $tabs, 'tab' => $tab, 'values' => $values, 'admin' => $admin, 'cronUrl' => $cronUrl,
            'fields' => $tabs[$tab]['fields'], 'tabTitle' => $tabs[$tab]['title'],
        ]);
    }

    protected function tabs(): array
    {
        $themes = [];
        foreach ((array)glob(BASE_PATH . '/template/*', GLOB_ONLYDIR) as $dir) $themes[basename($dir)] = basename($dir);
        $registrars = [];
        foreach (Plugin::discover('registrar') as $id => $info) $registrars[$id] = $info['title'] ?? $id;
        $currencies = array_column(Db::table('currency')->select(), 'code', 'code');

        return [
            'general' => ['title' => '站点设置', 'icon' => 'settings', 'fields' => [
                ['name' => 'site_name', 'label' => '站点名称', 'type' => 'text'],
                ['name' => 'site_url', 'label' => '站点地址', 'type' => 'text', 'help' => '如 https://idceasy.site，用于邮件链接与回调'],
                ['name' => 'site_slogan', 'label' => '站点标语', 'type' => 'text'],
                ['name' => 'company_name', 'label' => '公司名称', 'type' => 'text'],
                ['name' => 'company_email', 'label' => '联系邮箱', 'type' => 'text'],
                ['name' => 'company_phone', 'label' => '联系电话', 'type' => 'text'],
                ['name' => 'company_address', 'label' => '公司地址', 'type' => 'text'],
                ['name' => 'icp', 'label' => 'ICP 备案号', 'type' => 'text'],
                ['name' => 'footer_html', 'label' => '页脚附加 HTML', 'type' => 'textarea'],
                ['name' => 'currency', 'label' => '默认货币', 'type' => 'select', 'options' => $currencies],
                ['name' => 'theme', 'label' => '前台主题', 'type' => 'select', 'options' => $themes],
                ['name' => 'announce_home_count', 'label' => '首页公告数量', 'type' => 'number'],
                ['name' => 'signup_enabled', 'label' => '允许注册', 'type' => 'checkbox'],
                ['name' => 'require_email_verify', 'label' => '注册需验证邮箱', 'type' => 'checkbox'],
                ['name' => 'maintenance', 'label' => '维护模式', 'type' => 'checkbox', 'help' => '开启后前台显示维护提示'],
            ]],
            'mail' => ['title' => '邮件设置', 'icon' => 'mail', 'fields' => [
                ['name' => 'mail_driver', 'label' => '发送方式', 'type' => 'select',
                    'options' => ['smtp' => '原生 SMTP', 'mail' => 'PHP mail()', 'log' => '仅写日志（调试）']],
                ['name' => 'mail_queue', 'label' => '使用队列异步发送', 'type' => 'checkbox'],
                ['name' => 'smtp_host', 'label' => 'SMTP 服务器', 'type' => 'text'],
                ['name' => 'smtp_port', 'label' => 'SMTP 端口', 'type' => 'number', 'help' => 'SSL 常用 465，STARTTLS 常用 587'],
                ['name' => 'smtp_user', 'label' => 'SMTP 用户名', 'type' => 'text'],
                ['name' => 'smtp_pass', 'label' => 'SMTP 密码', 'type' => 'password', 'help' => '留空表示不修改'],
                ['name' => 'smtp_secure', 'label' => '加密方式', 'type' => 'select', 'options' => ['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => '不加密']],
                ['name' => 'smtp_from', 'label' => '发件邮箱', 'type' => 'text'],
                ['name' => 'smtp_from_name', 'label' => '发件人名称', 'type' => 'text'],
            ]],
            'billing' => ['title' => '计费设置', 'icon' => 'file', 'fields' => [
                ['name' => 'invoice_due_days', 'label' => '账单有效期（天）', 'type' => 'number'],
                ['name' => 'renewal_days', 'label' => '提前生成续费账单（天）', 'type' => 'number'],
                ['name' => 'suspend_days', 'label' => '逾期暂停（天）', 'type' => 'number'],
                ['name' => 'terminate_days', 'label' => '逾期终止（天）', 'type' => 'number'],
                ['name' => 'tax_enabled', 'label' => '启用税费', 'type' => 'checkbox'],
                ['name' => 'affiliate_enabled', 'label' => '启用联盟推广', 'type' => 'checkbox'],
                ['name' => 'affiliate_rate', 'label' => '默认佣金比例（%）', 'type' => 'number'],
                ['name' => 'ticket_auto_close_days', 'label' => '工单自动关闭（天）', 'type' => 'number'],
            ]],
            'domain' => ['title' => '域名设置', 'icon' => 'globe', 'fields' => [
                ['name' => 'domain_default_registrar', 'label' => '默认注册商', 'type' => 'select', 'options' => $registrars],
                ['name' => 'domain_markup', 'label' => '溢价域名加价（%）', 'type' => 'number'],
            ]],
            'api' => ['title' => '接口与自动化', 'icon' => 'plug', 'fields' => [
                ['name' => 'api_whmcs_enabled', 'label' => '启用 WHMCS 兼容 API', 'type' => 'checkbox'],
                ['name' => 'api_whmcs_identifier', 'label' => 'WHMCS API 标识', 'type' => 'text'],
                ['name' => 'api_whmcs_secret', 'label' => 'WHMCS API 密钥', 'type' => 'password'],
                ['name' => 'api_cube_enabled', 'label' => '启用魔方财务桥接 API', 'type' => 'checkbox'],
                ['name' => 'api_cube_key', 'label' => '魔方桥接密钥', 'type' => 'password'],
                ['name' => 'cron_token', 'label' => '定时任务令牌', 'type' => 'text', 'readonly' => true],
            ]],
        ];
    }
}

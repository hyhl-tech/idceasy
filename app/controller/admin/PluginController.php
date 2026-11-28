<?php
/**
 * 后台：插件管理
 */
namespace app\controller\admin;

use think\Db;
use think\Plugin;
use think\Response;

class PluginController extends Base
{
    protected string $perm = 'plugin';

    public function index(): Response
    {
        $types = [
            'registrar' => '域名注册商', 'gateway' => '支付网关',
            'server' => '服务器 / 主机模块', 'addon' => '扩展插件（钩子）',
        ];
        $data = array_fill_keys(array_keys($types), []);
        foreach ($types as $type => $label) {
            foreach (Plugin::discover($type, true) as $id => $info) {
                $record = Plugin::record($type, $id);
                $info['id'] = $id;
                $info['type'] = $type;
                $info['enabled'] = $record ? (int)$record['status'] === 1 : false;
                $info['installed'] = (bool)$record;
                $info['config'] = $record ? (json_decode((string)$record['config'], true) ?: []) : [];
                $data[$type][] = $info;
            }
        }
        return $this->view('plugin_list', compact('types', 'data'));
    }

    public function config(string $type, string $name): Response
    {
        $this->validate($type, $name);
        $info = Plugin::discover($type)[$name] ?? null;
        if (!$info) return $this->error('插件不存在', admin_url('/plugins'));
        $record = Plugin::record($type, $name);
        $config = $record ? (json_decode((string)$record['config'], true) ?: []) : [];
        $fields = $info['fields'] ?? [];

        if ($this->request->isPost()) {
            $new = $config;
            foreach ($fields as $field) {
                $key = $field['name'];
                if (($field['type'] ?? 'text') === 'password') {
                    $value = (string)$this->request->param($key);
                    if ($value !== '') $new[$key] = $value;
                    continue;
                }
                if (($field['type'] ?? '') === 'checkbox') {
                    $new[$key] = $this->request->bool($key) ? '1' : '0';
                    continue;
                }
                $new[$key] = (string)$this->request->param($key, $new[$key] ?? '');
            }
            $new['_enabled'] = $this->request->bool('enabled') ? '1' : '0';
            Plugin::saveConfig($type, $name, $new);
            Plugin::toggle($type, $name, $this->request->bool('enabled'));
            $this->logAction('配置插件', ['type' => $type, 'name' => $name]);
            return $this->success('插件配置已保存', admin_url('/plugins'));
        }
        return $this->view('plugin_config', compact('type', 'name', 'info', 'config', 'fields', 'record'));
    }

    public function toggle(string $type, string $name): Response
    {
        $this->validate($type, $name);
        if (!$this->request->isPost()) return $this->redirect(admin_url('/plugins'));
        $record = Plugin::record($type, $name);
        $on = $record ? (int)$record['status'] !== 1 : true;
        Plugin::toggle($type, $name, $on);
        $this->logAction('切换插件', ['type' => $type, 'name' => $name, 'enabled' => $on]);
        return $this->success($on ? '插件已启用' : '插件已停用');
    }

    public function test(string $type, string $name): Response
    {
        $this->validate($type, $name);
        if (!$this->request->isPost()) return $this->redirect(admin_url('/plugins'));
        if ($type === 'addon') return $this->success('扩展插件随请求加载，无需单独测试');
        $record = Plugin::record($type, $name);
        if (!$record) return $this->error('请先保存配置并启用插件');
        try {
            $plugin = Plugin::make($type, $name, json_decode((string)$record['config'], true) ?: []);
            $res = $plugin->test();
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        $this->logAction('测试插件', ['type' => $type, 'name' => $name, 'result' => $res]);
        return !empty($res['ok']) ? $this->success($res['msg'] ?? '测试通过') : $this->error($res['error'] ?? '测试失败');
    }

    protected function validate(string $type, string $name): void
    {
        if (!in_array($type, Plugin::TYPES, true)) throw new \think\HttpException('无效插件类型', 404);
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) throw new \think\HttpException('无效插件名称', 404);
    }
}

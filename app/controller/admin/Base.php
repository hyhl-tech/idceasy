<?php
/**
 * 后台控制器基类
 */
namespace app\controller\admin;

use think\Controller;
use think\Db;
use think\HttpException;
use think\Response;

class Base extends Controller
{
    protected string $theme = 'admin';
    protected string $perm = '';

    public function __construct()
    {
        parent::__construct();
        if (!current_admin()) {
            Response::redirect(admin_url('/login'))->send();
            exit;
        }
    }

    public function authorize(string $method): void
    {
        if (str_starts_with($method, '__')) return;
        $prefix = $this->perm !== '' ? $this->perm : strtolower(class_basename($this));
        $map = ['index' => 'view', 'detail' => 'view', 'show' => 'view'];
        $action = $map[$method] ?? $method;
        if (!can($prefix . '.' . $action)) {
            throw new HttpException('无权执行该操作（' . $prefix . '.' . $action . '）', 403);
        }
    }

    protected function logAction(string $action, $detail = ''): void
    {
        $admin = current_admin();
        try {
            Db::table('admin_log')->insert([
                'admin_id' => (int)($admin['id'] ?? 0), 'action' => $action,
                'detail' => is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE),
                'ip' => client_ip(), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {}
    }

    /** 批量保存配置（白名单） */
    protected function saveSettings(array $allowed): int
    {
        $saved = 0;
        foreach ($allowed as $key) {
            $value = $this->request->param($key);
            if ($value === null) continue;
            if (is_array($value)) $value = implode(',', $value);
            cfg_set($key, (string)$value);
            $saved++;
        }
        return $saved;
    }
}

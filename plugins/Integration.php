<?php
/**
 * 对接插件基类
 * 用于与外部财务 / 面板系统双向同步：本系统可以调用对方，对方也可以调用本系统的 API。
 */
namespace plugins;

require_once __DIR__ . '/PluginBase.php';
require_once __DIR__ . '/Http.php';

abstract class Integration extends PluginBase
{
    use Http;

    /** 连通性测试 */
    public function test(): array
    {
        return ['ok' => false, 'error' => '该对接未实现测试接口'];
    }

    /** 从远端拉取客户，返回 created/skipped/total */
    public function pull(): array
    {
        return ['ok' => false, 'error' => '该对接未实现拉取接口'];
    }

    /** 推送单个客户到远端 */
    public function push(array $user): array
    {
        return ['ok' => false, 'error' => '该对接未实现推送接口'];
    }

    /** 对接概况 */
    public function stats(): array
    {
        return [];
    }

    /** 启用后注册钩子 */
    public function boot(): void
    {
    }
}

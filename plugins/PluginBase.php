<?php
/**
 * idceasy 插件基类
 */
namespace plugins;

abstract class PluginBase
{
    protected array $config = [];

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function config(string $key = '', $default = null)
    {
        if ($key === '') return $this->config;
        return array_key_exists($key, $this->config) && $this->config[$key] !== ''
            ? $this->config[$key] : $default;
    }

    /** 插件元信息：id/title/version/author/description/fields[] */
    abstract public function info(): array;

    public function fields(): array
    {
        return $this->info()['fields'] ?? [];
    }

    public function test(): array
    {
        return ['ok' => true, 'msg' => '该插件无需连通性测试'];
    }

    protected function log(string $message, array $context = []): void
    {
        log_write('plugin', $message, $context);
    }
}

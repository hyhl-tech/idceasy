<?php
/**
 * 应用配置
 * 生产环境请通过环境变量 IDCEASY_DEBUG=0 或删除 debug 开启静默错误页
 */
return [
    'app' => [
        'name'     => 'idceasy',
        'debug'    => (bool)(getenv('IDCEASY_DEBUG') ?: false),
        'timezone' => 'Asia/Shanghai',
        'charset'  => 'utf-8',
        'version'  => '1.0.0',
    ],
];

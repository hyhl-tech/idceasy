<?php
/**
 * idceasy WHMCS 服务器模块
 * 安装：将本目录复制到 WHMCS 的 modules/servers/idceasy/，然后在产品设置中选择
 *      模块 idceasy，并填写 API 地址、Identifier、Secret 与 idceasy 产品 ID。
 * 作用：WHMCS 开通 / 暂停 / 删除服务时，调用 idceasy 的 WHMCS 兼容 API。
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function idceasy_MetaData()
{
    return [
        'DisplayName' => 'idceasy',
        'APIVersion' => '1.0',
        'RequiresServer' => false,
    ];
}

function idceasy_ConfigOptions()
{
    return [
        'API 地址' => ['Type' => 'text', 'Size' => '50', 'Default' => 'https://idceasy.site', 'Description' => '不含 /api/whmcs'],
        'Identifier' => ['Type' => 'text', 'Size' => '40'],
        'Secret' => ['Type' => 'password', 'Size' => '40'],
        '产品 ID' => ['Type' => 'text', 'Size' => '10', 'Description' => 'idceasy 后台的产品 ID'],
        '自动开通' => ['Type' => 'yesno', 'Default' => 'yes'],
    ];
}

function idceasy_Call(array $params, string $action, array $extra = []): array
{
    $url = rtrim((string)($params['configoption1'] ?? ''), '/') . '/api/whmcs';
    $post = array_merge([
        'identifier' => (string)($params['configoption2'] ?? ''),
        'secret' => (string)($params['configoption3'] ?? ''),
        'action' => $action,
        'responsetype' => 'json',
    ], $extra);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return ['result' => 'error', 'message' => '网络错误：' . $error];
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['result' => 'error', 'message' => '返回格式异常：' . substr($body, 0, 200)];
    }
    return $json;
}

function idceasy_CreateAccount(array $params)
{
    $extra = [
        'email' => (string)($params['clientsdetails']['email'] ?? ''),
        'firstname' => (string)($params['clientsdetails']['firstname'] ?? ''),
        'lastname' => (string)($params['clientsdetails']['lastname'] ?? ''),
        'productid' => (string)($params['configoption4'] ?? ''),
        'domain' => (string)($params['domain'] ?? ''),
        'username' => (string)($params['username'] ?? ''),
        'password' => (string)($params['password'] ?? ''),
        'billingcycle' => (string)($params['billingcycle'] ?? 'monthly'),
        'provision' => ($params['configoption5'] ?? '') === 'on' ? 1 : 0,
    ];
    $res = idceasy_Call($params, 'CreateHost', $extra);
    if (($res['result'] ?? '') !== 'success') {
        return 'idceasy: ' . ($res['message'] ?? '开通失败');
    }
    return 'success';
}

function idceasy_SuspendAccount(array $params)
{
    $res = idceasy_Call($params, 'ModuleSuspend', ['domain' => $params['domain'], 'username' => $params['username']]);
    return ($res['result'] ?? '') === 'success' ? 'success' : ('idceasy: ' . ($res['message'] ?? '暂停失败'));
}

function idceasy_UnsuspendAccount(array $params)
{
    $res = idceasy_Call($params, 'ModuleUnsuspend', ['domain' => $params['domain'], 'username' => $params['username']]);
    return ($res['result'] ?? '') === 'success' ? 'success' : ('idceasy: ' . ($res['message'] ?? '解除暂停失败'));
}

function idceasy_TerminateAccount(array $params)
{
    $res = idceasy_Call($params, 'ModuleTerminate', ['domain' => $params['domain'], 'username' => $params['username']]);
    return ($res['result'] ?? '') === 'success' ? 'success' : ('idceasy: ' . ($res['message'] ?? '删除失败'));
}

function idceasy_ChangePassword(array $params)
{
    $res = idceasy_Call($params, 'ModuleChangePassword', [
        'domain' => $params['domain'], 'username' => $params['username'], 'password' => $params['password'],
    ]);
    return ($res['result'] ?? '') === 'success' ? 'success' : ('idceasy: ' . ($res['message'] ?? '修改密码失败'));
}

function idceasy_TestConnection(array $params)
{
    $res = idceasy_Call($params, 'Ping');
    return [
        'success' => ($res['result'] ?? '') === 'success',
        'error' => $res['message'] ?? '',
    ];
}

function idceasy_AdminLink(array $params)
{
    $url = rtrim((string)($params['configoption1'] ?? ''), '/') . '/admin';
    return '<a href="' . htmlspecialchars($url) . '" target="_blank">打开 idceasy 后台</a>';
}

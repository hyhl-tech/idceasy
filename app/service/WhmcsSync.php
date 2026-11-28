<?php
/**
 * WHMCS 远程调用客户端（供同步插件使用）
 */
namespace app\service;

class WhmcsSync
{
    public static function call(array $cfg, string $action, array $params = []): array
    {
        $url = rtrim((string)($cfg['api_url'] ?? ''), '/') . '/includes/api.php';
        $post = array_merge([
            'identifier' => (string)($cfg['identifier'] ?? ''),
            'secret' => (string)($cfg['secret'] ?? ''),
            'action' => $action,
            'responsetype' => 'json',
        ], $params);
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
        if ($body === false) return ['result' => 'error', 'message' => '网络错误：' . $error];
        $json = json_decode($body, true);
        return is_array($json) ? $json : ['result' => 'error', 'message' => mb_substr($body, 0, 200)];
    }
}

<?php
/**
 * 插件 HTTP 客户端（curl）
 */
namespace plugins;

trait Http
{
    protected int $timeout = 30;

    /**
     * @return array{code:int,body:string,json:?array,error:string}
     */
    protected function http(string $method, string $url, array $data = [], array $headers = [], array $options = []): array
    {
        $method = strtoupper($method);
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => $options['follow'] ?? true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => $options['verify'] ?? true,
            CURLOPT_SSL_VERIFYHOST => ($options['verify'] ?? true) ? 2 : 0,
            CURLOPT_USERAGENT      => 'idceasy/' . (defined('IDCEASY_VERSION') ? IDCEASY_VERSION : '1.0'),
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if (isset($options['raw'])) {
                $opts[CURLOPT_POSTFIELDS] = $options['raw'];
            } elseif (isset($options['json'])) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers[] = 'Content-Type: application/json';
            } else {
                $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        } elseif ($method === 'PUT' || $method === 'DELETE' || $method === 'PATCH') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if (isset($options['raw'])) {
                $opts[CURLOPT_POSTFIELDS] = $options['raw'];
                $headers[] = 'Content-Type: application/json';
            } elseif (isset($options['json'])) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers[] = 'Content-Type: application/json';
            } else {
                $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
            }
        } elseif ($data) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($data);
            $opts[CURLOPT_URL] = $url;
        }
        $opts[CURLOPT_HTTPHEADER] = array_merge(['Expect:'], $headers);
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = null;
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) $json = $decoded;
        }
        return ['code' => $code, 'body' => (string)$body, 'json' => $json, 'error' => $error];
    }

    protected function postJson(string $url, array $data, array $headers = [], array $options = []): array
    {
        $options['json'] = true;
        return $this->http('POST', $url, $data, $headers, $options);
    }

    protected function parseXml(string $xml): ?\SimpleXMLElement
    {
        if (trim($xml) === '') return null;
        $prev = libxml_use_internal_errors(true);
        $obj = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $obj === false ? null : $obj;
    }

    /** 从 XML 节点安全取值 */
    protected function xmlValue($node, string $path, string $default = ''): string
    {
        if (!$node instanceof \SimpleXMLElement) return $default;
        $found = $node->xpath($path);
        if (!$found) return $default;
        return trim((string)$found[0]);
    }

    protected function ok(array $data = []): array
    {
        return array_merge(['ok' => true, 'error' => ''], $data);
    }

    protected function fail(string $error, array $data = []): array
    {
        return array_merge(['ok' => false, 'error' => $error], $data);
    }
}

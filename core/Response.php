<?php
/**
 * idceasy - 响应对象
 */
namespace think;

class Response
{
    public int $code = 200;
    public array $headers = [];
    public string $body = '';

    public function __construct(string $body = '', int $code = 200, array $headers = [])
    {
        $this->body = $body;
        $this->code = $code;
        $this->headers = $headers;
    }

    public static function html(string $body, int $code = 200): Response
    {
        return new self($body, $code, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json($data, int $code = 200): Response
    {
        return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $code,
            ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function text(string $body, int $code = 200): Response
    {
        return new self($body, $code, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $url, int $code = 302): Response
    {
        return new self('', $code, ['Location' => $url]);
    }

    public static function download(string $content, string $filename, string $mime = 'application/octet-stream'): Response
    {
        return new self($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
        ]);
    }

    public function header(string $name, string $value): Response
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->code);
            foreach ($this->headers as $k => $v) header($k . ': ' . $v);
        }
        echo $this->body;
    }
}

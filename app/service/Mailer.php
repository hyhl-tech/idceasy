<?php
/**
 * 原生 SMTP 邮件服务（支持 SSL / STARTTLS / AUTH LOGIN / 队列）
 */
namespace app\service;

use think\Db;

class Mailer
{
    public static function send(string $to, string $subject, string $body, ?bool $queue = null): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        $queue = $queue ?? ((string)cfg('mail_queue', '1') === '1');
        if ($queue) {
            Db::table('email_queue')->insert([
                'to_email' => $to, 'subject' => $subject, 'body' => $body,
                'status' => 'pending', 'attempts' => 0, 'created_at' => now(),
            ]);
            return true;
        }
        return self::deliver($to, $subject, $body);
    }

    public static function sendTemplate(string $code, string $to, array $vars = [], ?bool $queue = null): bool
    {
        $tpl = mail_template($code, $vars);
        return self::send($to, $tpl['subject'], $tpl['body'], $queue);
    }

    public static function deliver(string $to, string $subject, string $body): bool
    {
        $driver = (string)cfg('mail_driver', 'smtp');
        $error = '';
        try {
            if ($driver === 'log') {
                log_write('mail', 'MAIL(to=' . $to . ', subject=' . $subject . ')', ['body' => $body]);
                $ok = true;
            } elseif ($driver === 'mail') {
                $headers = ['MIME-Version' => '1.0', 'Content-Type' => 'text/html; charset=UTF-8',
                    'From' => self::fromHeader()];
                $h = '';
                foreach ($headers as $k => $v) $h .= $k . ': ' . $v . "\r\n";
                $ok = @mail($to, self::encodeHeader($subject), $body, $h);
                if (!$ok) $error = 'mail() 发送失败';
            } else {
                $ok = self::smtpSend($to, $subject, $body, $error);
            }
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        }
        try {
            Db::table('email_log')->insert([
                'to_email' => $to, 'subject' => $subject, 'body' => $body,
                'status' => $ok ? 'success' : 'failed', 'error' => mb_substr($error, 0, 250),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {}
        if (!$ok) log_write('mail', '发送失败 -> ' . $to . ': ' . $error);
        return $ok;
    }

    public static function processQueue(int $limit = 50): array
    {
        $rows = Db::table('email_queue')->where('status', 'pending')->order('id')->limit($limit)->select();
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $ok = self::deliver($row['to_email'], $row['subject'], $row['body']);
            if ($ok) {
                $sent++;
                Db::table('email_queue')->where('id', $row['id'])->update(['status' => 'sent', 'sent_at' => now()]);
            } else {
                $failed++;
                $attempts = (int)$row['attempts'] + 1;
                Db::table('email_queue')->where('id', $row['id'])->update([
                    'attempts' => $attempts,
                    'status' => $attempts >= 3 ? 'failed' : 'pending',
                ]);
            }
        }
        return ['sent' => $sent, 'failed' => $failed, 'total' => count($rows)];
    }

    public static function test(string $to): array
    {
        $ok = self::deliver($to, 'idceasy SMTP 测试', '<p>这是一封来自 idceasy 的测试邮件，SMTP 配置正常。</p>');
        return ['ok' => $ok, 'msg' => $ok ? '测试邮件已发送，请查收' : '发送失败，请查看 data/logs/mail-*.log'];
    }

    protected static function fromHeader(): string
    {
        $from = (string)cfg('smtp_from', cfg('company_email', 'noreply@localhost'));
        $name = (string)cfg('smtp_from_name', cfg('site_name', 'idceasy'));
        return self::encodeHeader($name) . ' <' . $from . '>';
    }

    protected static function encodeHeader(string $text): string
    {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    protected static function smtpSend(string $to, string $subject, string $body, string &$error): bool
    {
        $host = trim((string)cfg('smtp_host', ''));
        $port = (int)cfg('smtp_port', 465);
        $user = (string)cfg('smtp_user', '');
        $pass = (string)cfg('smtp_pass', '');
        $secure = strtolower((string)cfg('smtp_secure', 'ssl'));
        $from = (string)cfg('smtp_from', $user);
        if ($host === '' || $from === '') {
            $error = 'SMTP 未配置（服务器地址或发件人）';
            return false;
        }
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
        ]]);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            $error = '无法连接 SMTP: ' . $errstr . ' (' . $errno . ')';
            return false;
        }
        stream_set_timeout($fp, 15);
        try {
            self::expect($fp, 220);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            self::command($fp, $ehlo, [250]);
            if ($secure === 'tls') {
                self::command($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS 握手失败');
                }
                self::command($fp, $ehlo, [250]);
            }
            if ($user !== '') {
                $resp = self::command($fp, 'AUTH LOGIN', [334]);
                self::command($fp, base64_encode($user), [334]);
                self::command($fp, base64_encode($pass), [235]);
            }
            self::command($fp, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($fp, 'DATA', [354]);
            $headers = [
                'From' => self::fromHeader(),
                'To' => '<' . $to . '>',
                'Subject' => self::encodeHeader($subject),
                'Date' => date('r'),
                'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . (parse_url(abs_url('/'), PHP_URL_HOST) ?: 'idceasy') . '>',
                'MIME-Version' => '1.0',
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Transfer-Encoding' => 'base64',
                'X-Mailer' => 'idceasy',
            ];
            $data = '';
            foreach ($headers as $k => $v) $data .= $k . ': ' . $v . "\r\n";
            $data .= "\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
            fwrite($fp, $data . "\r\n.\r\n");
            self::expect($fp, 250);
            self::command($fp, 'QUIT', [221]);
            fclose($fp);
            return true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            @fclose($fp);
            return false;
        }
    }

    protected static function expect($fp, int $code): string
    {
        $response = '';
        while (($line = fgets($fp, 2048)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $got = (int)substr($response, 0, 3);
        if ($got !== $code) {
            throw new \RuntimeException('SMTP 期望 ' . $code . '，实际: ' . trim($response));
        }
        return $response;
    }

    protected static function command($fp, string $cmd, array $codes): string
    {
        fwrite($fp, $cmd . "\r\n");
        $response = '';
        while (($line = fgets($fp, 2048)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $got = (int)substr($response, 0, 3);
        if (!in_array($got, $codes, true)) {
            throw new \RuntimeException('SMTP 命令失败 [' . $cmd . ']: ' . trim($response));
        }
        return $response;
    }
}

<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Mail;

final class SmtpMailer implements Mailer
{
    /** @param array{host:string,port:int,username:string,password:string,from_email:string,from_name?:string,encryption:string,timeout?:int} $config */
    public function __construct(private array $config)
    {
        foreach (['host','username','password','from_email','encryption'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                throw new \InvalidArgumentException('SMTP configuration is incomplete.');
            }
        }
        if (!preg_match('/^[a-zA-Z0-9.-]{1,253}$/D', $config['host']) ||
            !filter_var($config['from_email'], FILTER_VALIDATE_EMAIL) ||
            !in_array($config['encryption'], ['tls','ssl'], true)) {
            throw new \InvalidArgumentException('SMTP host, sender address or encryption mode is invalid.');
        }
        $port = (int) ($config['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('SMTP port is invalid.');
        }
    }

    public function send(string $recipient, string $subject, string $textBody): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $recipient) ||
            $subject === '' || preg_match('/[\r\n]/', $subject) || strlen($subject) > 500 ||
            strlen($textBody) > 100000) {
            throw new \InvalidArgumentException('Email recipient, subject or body is invalid.');
        }
        $host = $this->config['host'];
        $port = (int) $this->config['port'];
        $timeout = max(3, min(30, (int) ($this->config['timeout'] ?? 10)));
        $transport = $this->config['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create(['ssl'=>[
            'verify_peer'=>true,
            'verify_peer_name'=>true,
            'peer_name'=>$host,
            'SNI_enabled'=>true,
        ]]);
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException('SMTP connection failed (' . $errno . ').');
        }
        stream_set_timeout($socket, $timeout);
        try {
            self::expect($socket, [220]);
            self::command($socket, 'EHLO talyvane.local', [250]);
            if ($this->config['encryption'] === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    throw new \RuntimeException('SMTP TLS negotiation failed.');
                }
                self::command($socket, 'EHLO talyvane.local', [250]);
            }
            self::command($socket, 'AUTH LOGIN', [334]);
            self::command($socket, base64_encode($this->config['username']), [334]);
            self::command($socket, base64_encode($this->config['password']), [235]);
            self::command($socket, 'MAIL FROM:<' . $this->config['from_email'] . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $recipient . '>', [250,251]);
            self::command($socket, 'DATA', [354]);
            $fromName = (string) ($this->config['from_name'] ?? 'Talyvane');
            if (preg_match('/[\r\n]/', $fromName)) {
                throw new \InvalidArgumentException('SMTP sender display name is invalid.');
            }
            $headers = [
                'From: ' . self::encodeHeader($fromName) . ' <' . $this->config['from_email'] . '>',
                'To: <' . $recipient . '>',
                'Subject: ' . self::encodeHeader($subject),
                'Date: ' . gmdate(DATE_RFC2822),
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $host . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            $body = str_replace(["\r\n","\r"], "\n", $textBody);
            $body = str_replace("\n", "\r\n", $body);
            $body = preg_replace('/(^|\r\n)\./', '$1..', $body) ?? $body;
            $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
            self::writeAll($socket, $message);
            self::expect($socket, [250]);
            self::command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** @param list<int> $expected */
    private static function command($socket, string $command, array $expected): void
    {
        self::writeAll($socket, $command . "\r\n");
        self::expect($socket, $expected);
    }

    /** @param list<int> $expected */
    private static function expect($socket, array $expected): void
    {
        $code = null;
        $lines = 0;
        do {
            $line = fgets($socket, 2048);
            if (!is_string($line) || strlen($line) < 3 || ++$lines > 30) {
                throw new \RuntimeException('SMTP server returned an invalid response.');
            }
            if (!preg_match('/^[0-9]{3}([ -])/', $line, $matches)) {
                throw new \RuntimeException('SMTP server returned an invalid response.');
            }
            $lineCode = (int) substr($line, 0, 3);
            $code ??= $lineCode;
            if ($lineCode !== $code) {
                throw new \RuntimeException('SMTP server returned an inconsistent response.');
            }
            $continued = ($matches[1] ?? ' ') === '-';
        } while ($continued);
        if (!in_array((int) $code, $expected, true)) {
            throw new \RuntimeException('SMTP operation failed with response code ' . $code . '.');
        }
    }

    private static function writeAll($socket, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($socket, substr($data, $offset));
            if (!is_int($written) || $written < 1) {
                throw new \RuntimeException('SMTP data write failed.');
            }
            $offset += $written;
        }
    }
}

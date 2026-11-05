<?php

class SmtpMailer
{
    public static function send(array $account, array $recipients, string $subject, string $body): void
    {
        $host = (string)$account['smtp_host'];
        $port = (int)$account['smtp_port'];
        $security = (string)$account['smtp_security'];
        $remote = ($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
        $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new RuntimeException('Could not connect to the mail server.');
        }
        stream_set_timeout($socket, 20);
        try {
            self::expect($socket, [220]);
            self::command($socket, 'EHLO danaco.local', [250]);
            if ($security === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (!stream_socket_enable_crypto($socket, true, $crypto)) {
                    throw new RuntimeException('Could not start TLS.');
                }
                self::command($socket, 'EHLO danaco.local', [250]);
            }
            self::command($socket, 'AUTH LOGIN', [334]);
            self::command($socket, base64_encode((string)$account['username']), [334]);
            self::command($socket, base64_encode((string)$account['password']), [235]);
            self::command($socket, 'MAIL FROM:<' . $account['from_email'] . '>', [250]);
            foreach ($recipients as $recipient) {
                self::command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            self::command($socket, 'DATA', [354]);
            fwrite($socket, self::message($account, $recipients, $subject, $body) . "\r\n.\r\n");
            self::expect($socket, [250]);
            self::command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private static function message(array $account, array $recipients, string $subject, string $body): string
    {
        $from = self::headerName((string)$account['from_name']) . ' <' . $account['from_email'] . '>';
        $encodedSubject = preg_match('/[^\x20-\x7E]/', $subject)
            ? '=?UTF-8?B?' . base64_encode($subject) . '?='
            : $subject;
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        $text = preg_replace('/^\./m', '..', $text) ?? $text;
        $text = str_replace("\n", "\r\n", $text);
        $headers = [
            'From: ' . $from,
            'To: ' . implode(', ', $recipients),
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . $text;
    }

    private static function headerName(string $name): string
    {
        if (preg_match('/[^\x20-\x7E]/', $name) || strpbrk($name, "\\\"<>") !== false) {
            return '=?UTF-8?B?' . base64_encode($name) . '?=';
        }
        return '"' . addcslashes($name, '\\"') . '"';
    }

    private static function command($socket, string $line, array $ok): void
    {
        fwrite($socket, $line . "\r\n");
        self::expect($socket, $ok);
    }

    private static function expect($socket, array $ok): void
    {
        $code = 0;
        while (($line = fgets($socket, 2048)) !== false) {
            if (!preg_match('/^(\d{3})([ \-])/', $line, $match)) {
                break;
            }
            $code = (int)$match[1];
            if ($match[2] === ' ') {
                break;
            }
        }
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException('The mail server refused the message.');
        }
    }
}

<?php
declare(strict_types=1);

function send_mail(string $to, string $subject, string $text, string $html): bool
{
    $from = config('mail_from', 'The Magick Muse <no-reply@' . (parse_url(config('base_url'), PHP_URL_HOST) ?: 'localhost') . '>');
    $boundary = 'mm' . bin2hex(random_bytes(8));
    $headers = [
        'From' => $from,
        'MIME-Version' => '1.0',
        'Content-Type' => "multipart/alternative; boundary=\"$boundary\"",
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "--$boundary--\r\n";
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    // Local testing: write mail to a file instead of sending.
    if (config('mail_log')) {
        file_put_contents(config('mail_log'), "To: $to\nSubject: $subject\n\n$text\n\n----\n", FILE_APPEND);
        return true;
    }
    if (!config('smtp_host')) {
        $h = '';
        foreach ($headers as $k => $v) $h .= "$k: $v\r\n";
        return mail($to, $subject, $body, $h);
    }
    return smtp_send($to, $subject, $headers, $body);
}

function smtp_send(string $to, string $subject, array $headers, string $body): bool
{
    $host = config('smtp_host');
    $port = (int) config('smtp_port', 465);
    $prefix = $port === 465 ? 'ssl://' : 'tcp://';
    $fp = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 15);
    if (!$fp) { error_log("SMTP connect failed: $errstr"); return false; }
    $read = function () use ($fp) {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) { $out .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
        return $out;
    };
    $cmd = function (string $c, string $expect) use ($fp, $read) {
        fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!str_starts_with($r, $expect)) throw new RuntimeException("SMTP: '$c' got '$r'");
    };
    try {
        $read();
        $ehlo = 'EHLO ' . (parse_url(config('base_url'), PHP_URL_HOST) ?: 'localhost');
        $cmd($ehlo, '250');
        if ($port === 587) {
            $cmd('STARTTLS', '220');
            // If encryption fails, stop: otherwise the SMTP password below would be sent in plain text.
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP: STARTTLS failed');
            $cmd($ehlo, '250');
        }
        $cmd('AUTH LOGIN', '334');
        $cmd(base64_encode((string) config('smtp_user')), '334');
        $cmd(base64_encode((string) config('smtp_pass')), '235');
        preg_match('/<([^>]+)>/', $headers['From'], $m);
        $cmd('MAIL FROM:<' . ($m[1] ?? $headers['From']) . '>', '250');
        $cmd('RCPT TO:<' . $to . '>', '25');
        $cmd('DATA', '354');
        $msg = "To: $to\r\nSubject: $subject\r\nDate: " . date('r') . "\r\n";
        foreach ($headers as $k => $v) $msg .= "$k: $v\r\n";
        $msg .= "\r\n" . preg_replace('/^\./m', '..', $body);
        $cmd($msg . "\r\n.", '250');
        fwrite($fp, "QUIT\r\n");
        return true;
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
        return false;
    } finally {
        fclose($fp);
    }
}

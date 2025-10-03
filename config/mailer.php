<?php

/**
 * Simple mail helper: supports logging, PHP mail(), or basic SMTP via fsockopen.
 * This is intentionally minimal; for production use a library like PHPMailer.
 */
require_once __DIR__ . '/helpers.php';

if (!function_exists('send_app_email')) {
    function send_app_email(string $to, string $subject, string $html, string $textFallback = ''): array
    {
        $cfg = require __DIR__ . '/mail.php';
        // Support array of drivers for fallback, or single driver string
        $drivers = [];
        if (isset($cfg['drivers']) && is_array($cfg['drivers']) && $cfg['drivers']) {
            $drivers = $cfg['drivers'];
        } else {
            $drivers[] = $cfg['driver'] ?? 'log';
        }
        // Always ensure log fallback present last
        if (!in_array('log', $drivers, true)) $drivers[] = 'log';

        $from = sprintf('%s <%s>', $cfg['from_name'] ?? 'BarberSure', $cfg['from_address'] ?? 'no-reply@barbersure.local');
        $textFallback = $textFallback ?: strip_tags($html);
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        $logFile = $logDir . '/mail.log';

        $attempts = [];
        $final = ['sent' => false, 'driver' => null, 'error' => null, 'attempts' => []];

        foreach ($drivers as $driver) {
            $driver = strtolower($driver);
            $res = ['driver' => $driver, 'sent' => false, 'error' => null];
            if ($driver === 'log') {
                $line = "[" . date('c') . "] LOG MAIL to=$to subj=" . str_replace(["\n", "\r"], ' ', $subject) . "\n";
                file_put_contents($logFile, $line, FILE_APPEND);
                $res['sent'] = true; // treat as success; no error
                $attempts[] = $res;
                $final = ['sent' => true, 'driver' => 'log', 'error' => null, 'attempts' => $attempts];
                break; // stop after log fallback
            }
            if ($driver === 'mail') {
                $headers = [];
                $headers[] = 'From: ' . $from;
                $headers[] = 'MIME-Version: 1.0';
                $boundary = 'bnd_' . bin2hex(random_bytes(8));
                $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
                $body = "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n$textFallback\r\n" .
                    "--$boundary\r\nContent-Type: text/html; charset=utf-8\r\n\r\n$html\r\n--$boundary--";
                $ok = @mail($to, $subject, $body, implode("\r\n", $headers));
                $res['sent'] = $ok;
                if (!$ok) {
                    $res['error'] = 'mail() failed';
                    file_put_contents($logFile, '[' . date('c') . "] MAIL FAIL to=$to subj=$subject\n", FILE_APPEND);
                }
                $attempts[] = $res;
                if ($ok) {
                    $final = ['sent' => true, 'driver' => 'mail', 'error' => null, 'attempts' => $attempts];
                    break;
                }
                continue;
            }
            if ($driver === 'smtp') {
                $host = $cfg['host'] ?? '';
                $port = (int)($cfg['port'] ?? 587);
                $enc = $cfg['encryption'] ?? 'tls';
                $timeout = (int)($cfg['timeout'] ?? 12);
                $username = $cfg['username'] ?? '';
                $password = $cfg['password'] ?? '';
                $socketHost = ($enc === 'ssl') ? 'ssl://' . $host : $host;
                $fp = @fsockopen($socketHost, $port, $errno, $errstr, $timeout);
                if (!$fp) {
                    $res['error'] = 'SMTP connect fail: ' . $errstr;
                    file_put_contents($logFile, '[' . date('c') . "] SMTP CONNECT FAIL to=$to subj=$subject err=$errstr\n", FILE_APPEND);
                    $attempts[] = $res;
                    continue;
                }
                stream_set_timeout($fp, $timeout);
                $transcript = '';
                $read = function () use ($fp, &$transcript) {
                    $data = '';
                    while ($line = fgets($fp, 515)) {
                        $data .= $line;
                        if (preg_match('/^\d{3} /', $line)) break;
                    }
                    $transcript .= $data;
                    return $data;
                };
                $send = function ($cmd) use ($fp, &$transcript) {
                    fwrite($fp, $cmd . "\r\n");
                    $transcript .= '> ' . $cmd . "\n";
                };
                $expect = function ($prefix, $data) {
                    return str_starts_with($data, $prefix);
                };
                $greet = $read();
                $send('EHLO barbersure.local');
                $ehlo = $read();
                if ($enc === 'tls') {
                    $send('STARTTLS');
                    $start = $read();
                    if (!str_starts_with($start, '220')) { /* fail soft */
                    } else {
                        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                        $send('EHLO barbersure.local');
                        $read();
                    }
                }
                if ($username && $password) {
                    $send('AUTH LOGIN');
                    $read();
                    $send(base64_encode($username));
                    $read();
                    $send(base64_encode($password));
                    $authResp = $read();
                    if (!str_starts_with($authResp, '235')) {
                        $result['error'] = 'SMTP auth failed';
                    }
                }
                $send('MAIL FROM: <' . ($cfg['from_address'] ?? 'no-reply@barbersure.local') . '>');
                $read();
                $send('RCPT TO: <' . $to . '>');
                $read();
                $send('DATA');
                $read();
                $boundary = 'bnd_' . bin2hex(random_bytes(8));
                $data = 'From: ' . $from . "\r\n" . 'To: ' . $to . "\r\n" . 'Subject: ' . $subject . "\r\n" . 'MIME-Version: 1.0' . "\r\n" . 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n\r\n";
                $data .= "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n$textFallback\r\n";
                $data .= "--$boundary\r\nContent-Type: text/html; charset=utf-8\r\n\r\n$html\r\n--$boundary--\r\n.";
                $send($data);
                $read();
                $send('QUIT');
                $read();
                fclose($fp);
                if (empty($res['error'])) {
                    $res['sent'] = true;
                    $attempts[] = $res;
                    $final = ['sent' => true, 'driver' => 'smtp', 'error' => null, 'attempts' => $attempts];
                    break;
                } else {
                    file_put_contents($logFile, '[' . date('c') . "] SMTP FAIL to=$to subj=$subject transcript=" . str_replace("\n", "|", $transcript) . "\n", FILE_APPEND);
                    $attempts[] = $res;
                    continue;
                }
            }
            // Unknown driver name
            if (!in_array($driver, ['smtp', 'mail', 'log'], true)) {
                $res['error'] = 'Unknown driver';
                $attempts[] = $res;
                continue;
            }
        }
        if (!$final['driver']) {
            // Should not happen because log is appended, but safeguard
            $final = ['sent' => false, 'driver' => null, 'error' => 'All drivers failed', 'attempts' => $attempts];
        }
        return $final;
    }
}

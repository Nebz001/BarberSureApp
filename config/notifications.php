<?php
// SMS sending utility: supports 'log' provider (default) and Firebase HTTP function when configured.

function _sms_cfg(): array
{
    static $cfg;
    if (!$cfg) $cfg = require __DIR__ . '/config.php';
    return $cfg['sms'] ?? ['provider' => 'log'];
}

function _sms_log(string $to, string $message): bool
{
    $baseDir = dirname(__DIR__);
    $storage = $baseDir . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($storage)) {
        @mkdir($storage, 0775, true);
    }
    $logFile = $storage . DIRECTORY_SEPARATOR . 'sms.log';
    $line = sprintf("[%s] TO:%s | %s\n", date('Y-m-d H:i:s'), $to, $message);
    return (bool)@file_put_contents($logFile, $line, FILE_APPEND);
}

function _normalize_phone_e164(string $to): string
{
    $p = preg_replace('/\s+/', '', $to);
    // If starts with '+630', collapse to '+63'
    if (preg_match('/^\+630(\d+)/', $p, $m)) {
        $p = '+63' . $m[1];
    }
    // If starts with '09' (local PH mobile), convert to +639...
    if (preg_match('/^0(9\d{9})$/', $p, $m)) {
        $p = '+63' . $m[1];
    }
    // If starts with '63' without plus, add it
    if (preg_match('/^63\d+$/', $p)) {
        $p = '+' . $p;
    }
    // Ensure plus if numeric only
    if ($p !== '' && $p[0] !== '+') {
        // As a last resort, assume it's PH local without leading 0
        if (preg_match('/^(9\d{9})$/', $p, $m)) {
            $p = '+63' . $m[1];
        } else {
            $p = '+' . $p;
        }
    }
    return $p;
}

function _sms_firebase(string $to, string $message, array $fb): bool
{
    $url = trim($fb['function_url'] ?? '');
    if ($url === '') return false;

    $payload = json_encode([
        'to' => _normalize_phone_e164($to),
        'message' => $message
    ], JSON_UNESCAPED_SLASHES);

    $headers = [
        'Content-Type: application/json'
    ];
    $apiKey = trim($fb['api_key'] ?? '');
    $hdrKey = trim($fb['auth_header'] ?? 'Authorization');
    if ($apiKey !== '') {
        $headers[] = $hdrKey . ': Bearer ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        error_log('[SMS] Firebase cURL error: ' . $err);
        return false;
    }
    if ($code < 200 || $code >= 300) {
        error_log('[SMS] Firebase HTTP ' . $code . ' response: ' . $resp);
        return false;
    }
    return true;
}

function send_sms(string $to, string $message): bool
{
    $cfg = _sms_cfg();
    $provider = strtolower(trim($cfg['provider'] ?? 'log'));
    if ($provider === 'firebase') {
        $ok = _sms_firebase($to, $message, $cfg['firebase'] ?? []);
        if ($ok) {
            // Mirror to log for audit/debugging without removing actual send
            _sms_log($to, '[FIREBASE SENT] ' . $message);
            return true;
        }
        return _sms_log($to, '[FALLBACK] ' . $message);
    }
    // Default: log provider
    return _sms_log($to, $message);
}

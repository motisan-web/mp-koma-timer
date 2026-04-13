<?php
/**
 * Hook dispatcher
 * Receives { event, payload } and fires the configured external URL.
 * Called internally by timer.php via cURL.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/logger.php';

header('Content-Type: application/json; charset=utf-8');

function hook_ok(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function hook_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$req = json_decode($raw, true) ?? [];

$event   = $req['event']   ?? '';
$payload = $req['payload'] ?? [];

if ($event === '') hook_error('event is required');

$config  = load_config();
$hookCfg = $config['hooks'][$event] ?? null;

if (!$hookCfg) hook_error('unknown event: ' . $event);

if (!$hookCfg['enabled'] || empty($hookCfg['url'])) {
    hook_ok(['skipped' => true, 'reason' => 'disabled or no URL']);
}

$url    = $hookCfg['url'];
$method = strtoupper($hookCfg['method'] ?? 'GET');

$fullPayload = array_merge($payload, [
    'event'     => $event,
    'user_id'   => $payload['user_id'] ?? 'moti',
    'timestamp' => (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('c'),
]);

$ch = curl_init();
$responseCode = 0;
$error        = '';

try {
    if ($method === 'POST') {
        $body = json_encode($fullPayload, JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: ' . strlen($body)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
    } else {
        // GET — append payload as query string
        $qs  = http_build_query($fullPayload);
        $sep = str_contains($url, '?') ? '&' : '?';
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url . $sep . $qs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
    }

    $response     = curl_exec($ch);
    $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError    = curl_error($ch);

    if ($response === false || $curlError) {
        $error = $curlError ?: 'curl returned false';
        koma_error('hook dispatch failed', [
            'event'  => $event,
            'url'    => $url,
            'error'  => $error,
        ]);
        hook_error('hook dispatch failed: ' . $error, 502);
    }

    koma_info('hook dispatched', [
        'event'         => $event,
        'url'           => $url,
        'method'        => $method,
        'response_code' => $responseCode,
    ]);

    hook_ok(['response_code' => $responseCode]);

} finally {
    curl_close($ch);
}

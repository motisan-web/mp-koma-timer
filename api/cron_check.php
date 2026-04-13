<?php
/**
 * Cron job: auto-complete komas that exceeded max_duration_minutes.
 * Run every 1-5 minutes via cron or Windows Task Scheduler.
 *
 * Usage (CLI):  php /path/to/api/cron_check.php
 * Usage (HTTP): GET /api/cron_check.php  (Xserver cron URL指定の場合)
 */

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/user.php';
require_once __DIR__ . '/../includes/logger.php';

// Hook dispatch (inline — hook.phpへの内部cURLを避けてシンプルに)
function dispatch_hook(string $event, array $payload, array $config): void {
    $hookCfg = $config['hooks'][$event] ?? null;
    if (!$hookCfg || !$hookCfg['enabled'] || empty($hookCfg['url'])) return;

    $url    = $hookCfg['url'];
    $method = strtoupper($hookCfg['method'] ?? 'GET');
    $fullPayload = array_merge($payload, [
        'event'     => $event,
        'timestamp' => (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('c'),
    ]);

    $ch = curl_init();
    if ($method === 'POST') {
        $body = json_encode($fullPayload, JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
    } else {
        $sep = str_contains($url, '?') ? '&' : '?';
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url . $sep . http_build_query($fullPayload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
    }
    $resp    = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        koma_error('cron hook dispatch failed', ['event' => $event, 'error' => $curlErr]);
    } else {
        koma_info('cron hook dispatched', ['event' => $event, 'http' => $code]);
    }
}

function calc_elapsed_cron(array $segments): int {
    $total = 0;
    $tz    = new DateTimeZone('Asia/Tokyo');
    foreach ($segments as $seg) {
        $start = new DateTime($seg['start'], $tz);
        $end   = isset($seg['end']) ? new DateTime($seg['end'], $tz) : new DateTime('now', $tz);
        $diff  = $end->getTimestamp() - $start->getTimestamp();
        if ($diff > 0) $total += $diff;
    }
    return $total;
}

// --- Main ---

$config     = load_config();
$komaDurSec = (int)$config['koma_duration_minutes'] * 60;
$maxSec     = (int)$config['max_duration_minutes'] * 60;
$tz         = new DateTimeZone('Asia/Tokyo');

// Scan sessions from the past 2 days (covers day-spanning komas)
$checked   = 0;
$completed = 0;

for ($d = 0; $d <= 1; $d++) {
    $date = (new DateTime("-{$d} days", $tz))->format('Y-m-d');
    $path = session_data_path($date);
    if (!file_exists($path)) continue;

    $session = load_session($date);
    $changed = false;

    foreach ($session['koma'] as &$k) {
        $status = $k['status'] ?? 'idle';
        if (!in_array($status, ['running', 'overtime', 'paused'])) continue;

        $checked++;
        $elapsed = calc_elapsed_cron($k['segments']);

        // Auto-complete at max duration
        if ($elapsed >= $maxSec) {
            // Close open segment
            if (!empty($k['segments'])) {
                $last = &$k['segments'][count($k['segments']) - 1];
                if (!isset($last['end'])) {
                    // Cap end at start + maxSec
                    $startTs = (new DateTime($last['start'], $tz))->getTimestamp();
                    $capTs   = $startTs + $maxSec;
                    $now     = time();
                    $endTs   = min($capTs, $now);
                    $last['end'] = (new DateTime('@' . $endTs))->setTimezone($tz)->format('c');
                }
                unset($last);
            }

            $finalElapsed              = calc_elapsed_cron($k['segments']);
            $k['total_seconds']        = $finalElapsed;
            $k['overtime_seconds']     = max(0, $finalElapsed - $komaDurSec);
            $k['status']               = 'completed';
            $k['completed_at']         = (new DateTime('now', $tz))->format('c');
            $changed                   = true;
            $completed++;

            koma_info('cron: auto-completed koma', [
                'date'    => $date,
                'slot'    => $k['id'],
                'elapsed' => $finalElapsed,
            ]);

            dispatch_hook('koma_100min', ['slot' => $k['id'], 'user_id' => 'moti'], $config);
            dispatch_hook('koma_complete', [
                'slot'             => $k['id'],
                'user_id'          => 'moti',
                'total_seconds'    => $k['total_seconds'],
                'overtime_seconds' => $k['overtime_seconds'],
            ], $config);

            // break_notify if flagged
            if (!empty($k['break_after'])) {
                dispatch_hook('break_notify', ['slot' => $k['id'], 'user_id' => 'moti'], $config);
            }
        }
    }
    unset($k);

    if ($changed) {
        save_session($session);
    }
}

$msg = "cron_check done. checked={$checked} completed={$completed}";
koma_info($msg);

// Output (visible in cron mail / HTTP response)
if (PHP_SAPI === 'cli') {
    echo $msg . PHP_EOL;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg . PHP_EOL;
}

<?php
/**
 * Timer API
 * POST JSON: { action, slot, ...params }
 * Returns JSON response.
 */

require_once __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/user.php';
require_once __DIR__ . '/../includes/logger.php';

header('Content-Type: application/json; charset=utf-8');

// --- Helpers ---

function api_ok(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function now_iso(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('c');
}

function today_str(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

/**
 * Calculate total elapsed seconds from segments array.
 * An open segment (no 'end') counts up to now.
 */
function calc_elapsed(array $segments): int {
    $total = 0;
    $tz = new DateTimeZone('Asia/Tokyo');
    foreach ($segments as $seg) {
        $start = new DateTime($seg['start'], $tz);
        $end   = isset($seg['end']) ? new DateTime($seg['end'], $tz) : new DateTime('now', $tz);
        $diff  = $end->getTimestamp() - $start->getTimestamp();
        if ($diff > 0) $total += $diff;
    }
    return $total;
}

/**
 * Ensure koma slot exists in session, creating it if needed.
 */
function ensure_koma(array &$session, int $slot): int {
    $idx = find_koma_index($session, $slot);
    if ($idx === -1) {
        $session['koma'][] = [
            'id'               => $slot,
            'name'             => '',
            'project_id'       => '',
            'status'           => 'idle',
            'break_after'      => false,
            'segments'         => [],
            'total_seconds'    => 0,
            'overtime_seconds' => 0,
            'completed_at'     => null,
            'date'             => $session['date'],
        ];
        $idx = count($session['koma']) - 1;
    }
    return $idx;
}

/**
 * Fire a hook event asynchronously (best-effort).
 * Defers to hook.php via cURL.
 */
function fire_hook(string $event, array $payload = []): void {
    $config = load_config();
    $hookCfg = $config['hooks'][$event] ?? null;
    if (!$hookCfg || !$hookCfg['enabled'] || empty($hookCfg['url'])) return;

    $hookApiUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST']
        . dirname($_SERVER['SCRIPT_NAME']) . '/hook.php';

    $ch = curl_init($hookApiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['event' => $event, 'payload' => $payload]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// --- Request parsing ---

$raw = file_get_contents('php://input');
$req = json_decode($raw, true) ?? [];

$action = $req['action'] ?? '';
$slot   = isset($req['slot']) ? (int)$req['slot'] : 0;

if ($action === '') api_error('action is required');

$config = load_config();
$koma_count    = (int)$config['koma_count'];
$koma_duration = (int)$config['koma_duration_minutes'] * 60;  // seconds
$max_duration  = (int)$config['max_duration_minutes'] * 60;   // seconds

// --- Actions that don't need a slot ---

if ($action === 'get_state') {
    $date    = $req['date'] ?? today_str();
    $session = load_session($date);

    // Compute live total_seconds for running komas
    foreach ($session['koma'] as &$k) {
        if ($k['status'] === 'running' || $k['status'] === 'overtime') {
            $k['total_seconds'] = calc_elapsed($k['segments']);
            $elapsed = $k['total_seconds'];
            if ($elapsed >= $max_duration) {
                $k['status'] = 'overtime_max';
            } elseif ($elapsed >= $koma_duration) {
                $k['status'] = 'overtime';
            }
        }
    }
    unset($k);

    api_ok([
        'session'     => $session,
        'config'      => $config,
        'today'       => today_str(),
        'user'        => get_current_user(),
        'koma_duration_sec' => $koma_duration,
        'max_duration_sec'  => $max_duration,
    ]);
}

// Remaining actions need a slot
if ($slot < 1 || $slot > $koma_count) {
    api_error("slot must be 1–{$koma_count}");
}

// Determine which date's session to use.
// If the koma already exists on a prior date (day-spanning), load that session.
$today   = today_str();
$session = load_session($today);
$idx     = find_koma_index($session, $slot);

// Check if there's a running/paused koma from a previous date
if ($idx === -1) {
    // Check previous few days
    $tz = new DateTimeZone('Asia/Tokyo');
    for ($d = 1; $d <= 7; $d++) {
        $prevDate = (new DateTime('-' . $d . ' days', $tz))->format('Y-m-d');
        $prevSession = load_session($prevDate);
        $prevIdx = find_koma_index($prevSession, $slot);
        if ($prevIdx !== -1) {
            $prevKoma = $prevSession['koma'][$prevIdx];
            if (in_array($prevKoma['status'], ['running', 'paused'])) {
                // Use the previous session
                $session = $prevSession;
                $idx     = $prevIdx;
            }
            break;
        }
    }
}

switch ($action) {

    case 'start':
        $idx = ensure_koma($session, $slot);
        $k   = &$session['koma'][$idx];

        if ($k['status'] === 'completed') {
            api_error('このコマはすでに完了しています');
        }

        $elapsed = calc_elapsed($k['segments']);
        if ($elapsed >= $max_duration) {
            api_error('最大時間（100分）に達しています');
        }

        // Close any open segment (safety)
        if (!empty($k['segments'])) {
            $last = &$k['segments'][count($k['segments']) - 1];
            if (!isset($last['end'])) {
                $last['end'] = now_iso();
            }
            unset($last);
        }

        $k['segments'][] = ['start' => now_iso()];
        $k['status']     = $elapsed >= $koma_duration ? 'overtime' : 'running';
        $k['total_seconds'] = $elapsed;

        save_session($session);
        fire_hook('koma_start', ['slot' => $slot, 'user_id' => CURRENT_USER_ID]);
        api_ok(['koma' => $k]);

    case 'pause':
        if ($idx === -1) api_error('コマが見つかりません');
        $k = &$session['koma'][$idx];

        if (!in_array($k['status'], ['running', 'overtime'])) {
            api_error('実行中ではありません');
        }

        // Close open segment
        if (!empty($k['segments'])) {
            $last = &$k['segments'][count($k['segments']) - 1];
            if (!isset($last['end'])) {
                $last['end'] = now_iso();
            }
            unset($last);
        }

        $elapsed = calc_elapsed($k['segments']);
        $k['total_seconds']    = $elapsed;
        $k['overtime_seconds'] = max(0, $elapsed - $koma_duration);
        $k['status']           = 'paused';

        save_session($session);
        api_ok(['koma' => $k]);

    case 'complete':
        $idx = ensure_koma($session, $slot);
        $k   = &$session['koma'][$idx];

        if ($k['status'] === 'completed') {
            api_error('すでに完了しています');
        }

        // Close open segment
        if (!empty($k['segments'])) {
            $last = &$k['segments'][count($k['segments']) - 1];
            if (!isset($last['end'])) {
                $last['end'] = now_iso();
            }
            unset($last);
        }

        $elapsed = calc_elapsed($k['segments']);
        $k['total_seconds']    = $elapsed;
        $k['overtime_seconds'] = max(0, $elapsed - $koma_duration);
        $k['status']           = 'completed';
        $k['completed_at']     = now_iso();

        save_session($session);

        // Push project history
        if (!empty($k['project_id'])) {
            push_project_history($k['project_id']);
        }

        fire_hook('koma_complete', [
            'slot'             => $slot,
            'user_id'          => CURRENT_USER_ID,
            'total_seconds'    => $k['total_seconds'],
            'overtime_seconds' => $k['overtime_seconds'],
        ]);

        $breakAfter = (bool)($k['break_after'] ?? false);
        if ($breakAfter) {
            // Schedule break_notify hook after 10 min (fire immediately; the API endpoint handles the delay)
            fire_hook('break_notify', ['slot' => $slot, 'user_id' => CURRENT_USER_ID, 'delay_minutes' => 10]);
        }

        api_ok(['koma' => $k]);

    case 'update_meta':
        $idx = ensure_koma($session, $slot);
        $k   = &$session['koma'][$idx];

        if (isset($req['name'])) {
            $k['name'] = mb_substr(trim($req['name']), 0, 200);
        }
        if (isset($req['project_id'])) {
            $oldProject = $k['project_id'];
            $k['project_id'] = mb_substr(trim($req['project_id']), 0, 100);
            if ($k['project_id'] !== $oldProject && $k['project_id'] !== '') {
                push_project_history($k['project_id']);
            }
        }

        save_session($session);
        api_ok(['koma' => $k]);

    case 'set_break':
        $idx = ensure_koma($session, $slot);
        $k   = &$session['koma'][$idx];
        $k['break_after'] = (bool)($req['value'] ?? false);
        save_session($session);
        api_ok(['koma' => $k]);

    case 'notify_80min':
        // Called by JS when 80-min mark is hit on a running koma
        if ($idx === -1) api_error('コマが見つかりません');
        $k = $session['koma'][$idx];
        fire_hook('koma_80min', ['slot' => $slot, 'user_id' => CURRENT_USER_ID]);
        api_ok();

    case 'notify_100min':
        // Called by JS when 100-min mark is hit — also auto-completes the koma
        $idx = ensure_koma($session, $slot);
        $k   = &$session['koma'][$idx];

        if ($k['status'] !== 'completed') {
            if (!empty($k['segments'])) {
                $last = &$k['segments'][count($k['segments']) - 1];
                if (!isset($last['end'])) {
                    $last['end'] = now_iso();
                }
                unset($last);
            }
            $elapsed = calc_elapsed($k['segments']);
            $k['total_seconds']    = $elapsed;
            $k['overtime_seconds'] = max(0, $elapsed - $koma_duration);
            $k['status']           = 'completed';
            $k['completed_at']     = now_iso();
            save_session($session);
        }

        fire_hook('koma_100min', ['slot' => $slot, 'user_id' => CURRENT_USER_ID]);
        api_ok(['koma' => $k]);

    default:
        api_error('unknown action: ' . $action);
}

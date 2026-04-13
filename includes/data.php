<?php
/**
 * JSON data read/write layer
 * All file I/O goes through here with file locking.
 */

require_once __DIR__ . '/logger.php';

/**
 * Read a JSON file, returns array or null on failure.
 */
function json_read(string $path): ?array {
    if (!file_exists($path)) {
        return null;
    }
    $fp = fopen($path, 'r');
    if (!$fp) {
        koma_error('json_read: cannot open file', ['path' => $path]);
        return null;
    }
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        koma_error('json_read: decode error', ['path' => $path, 'error' => json_last_error_msg()]);
        return null;
    }
    return $data;
}

/**
 * Write array as JSON to a file (creates dirs if needed).
 */
function json_write(string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            koma_error('json_write: cannot create directory', ['dir' => $dir]);
            return false;
        }
    }
    $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $fp = fopen($path, 'c');
    if (!$fp) {
        koma_error('json_write: cannot open file for writing', ['path' => $path]);
        return false;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $content);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/**
 * Get path to a session data file for a given date.
 * Date defaults to today (Asia/Tokyo).
 */
function session_data_path(string $date = ''): string {
    if ($date === '') {
        $tz = new DateTimeZone('Asia/Tokyo');
        $date = (new DateTime('now', $tz))->format('Y-m-d');
    }
    [$year, $month, $day] = explode('-', $date);
    return __DIR__ . "/../data/sessions/{$year}/{$month}-{$day}/data.json";
}

/**
 * Load session data for a given date. Creates a fresh structure if not found.
 */
function load_session(string $date = ''): array {
    if ($date === '') {
        $tz = new DateTimeZone('Asia/Tokyo');
        $date = (new DateTime('now', $tz))->format('Y-m-d');
    }
    $path = session_data_path($date);
    $data = json_read($path);
    if ($data === null) {
        $data = [
            'date'    => $date,
            'user_id' => 'moti',
            'koma'    => [],
        ];
    }
    return $data;
}

/**
 * Save session data for a given date.
 */
function save_session(array $data): bool {
    $path = session_data_path($data['date']);
    return json_write($path, $data);
}

/**
 * Find a koma by slot number (1-based) within session data.
 * Returns reference index or -1 if not found.
 */
function find_koma_index(array &$session, int $slot): int {
    foreach ($session['koma'] as $i => $k) {
        if ((int)$k['id'] === $slot) {
            return $i;
        }
    }
    return -1;
}

/**
 * Get all session dates within a date range.
 * Returns array of 'Y-m-d' strings.
 */
function get_session_dates(string $from, string $to): array {
    $dates = [];
    $dir = __DIR__ . '/../data/sessions';
    if (!is_dir($dir)) return $dates;

    $tz = new DateTimeZone('Asia/Tokyo');
    $start = new DateTime($from, $tz);
    $end   = new DateTime($to, $tz);

    foreach (glob($dir . '/*/') as $yearDir) {
        foreach (glob($yearDir . '*/data.json') as $file) {
            // Extract date from path: .../YYYY/MM-DD/data.json
            preg_match('#(\d{4})/(\d{2}-\d{2})/data\.json$#', $file, $m);
            if (!$m) continue;
            $date = $m[1] . '-' . str_replace('-', '-', $m[2]);
            // MM-DD => YYYY-MM-DD
            $date = $m[1] . '-' . $m[2];
            $dt = new DateTime($date, $tz);
            if ($dt >= $start && $dt <= $end) {
                $dates[] = $date;
            }
        }
    }
    sort($dates);
    return $dates;
}

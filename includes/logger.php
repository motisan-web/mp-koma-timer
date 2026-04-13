<?php
/**
 * Error/event logger
 * Writes JSON lines to logs/error.log
 */

define('LOG_FILE', __DIR__ . '/../logs/error.log');

function koma_log(string $level, string $message, array $context = []): void {
    $entry = json_encode([
        'time'    => date('c'),
        'level'   => $level,
        'message' => $message,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE);

    $fp = fopen(LOG_FILE, 'a');
    if ($fp) {
        flock($fp, LOCK_EX);
        fwrite($fp, $entry . PHP_EOL);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function koma_error(string $message, array $context = []): void {
    koma_log('error', $message, $context);
}

function koma_info(string $message, array $context = []): void {
    koma_log('info', $message, $context);
}

<?php
/**
 * App configuration loader
 */

require_once __DIR__ . '/data.php';

define('CONFIG_PATH', __DIR__ . '/../data/config.json');

function load_config(): array {
    $defaults = [
        'koma_count'              => 6,
        'koma_duration_minutes'   => 80,
        'max_duration_minutes'    => 100,
        'theme'                   => 'dark',
        'hooks' => [
            'koma_start'    => ['url' => '', 'method' => 'GET', 'enabled' => false],
            'koma_complete' => ['url' => '', 'method' => 'GET', 'enabled' => false],
            'koma_80min'    => ['url' => '', 'method' => 'GET', 'enabled' => false],
            'koma_100min'   => ['url' => '', 'method' => 'GET', 'enabled' => false],
            'break_notify'  => ['url' => '', 'method' => 'GET', 'enabled' => false],
        ],
    ];

    $stored = json_read(CONFIG_PATH);
    if ($stored === null) {
        json_write(CONFIG_PATH, $defaults);
        return $defaults;
    }
    // Merge to ensure all keys exist
    return array_replace_recursive($defaults, $stored);
}

function save_config(array $config): bool {
    return json_write(CONFIG_PATH, $config);
}

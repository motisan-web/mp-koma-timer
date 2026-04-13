<?php
/**
 * User context — hardcoded to moti for now.
 * Designed to support multiple users in the future by swapping CURRENT_USER_ID.
 */

require_once __DIR__ . '/data.php';

define('CURRENT_USER_ID', 'moti');

function users_dir(): string {
    return __DIR__ . '/../data/users';
}

function user_path(string $user_id): string {
    return users_dir() . '/' . $user_id . '.json';
}

function load_user(string $user_id = CURRENT_USER_ID): array {
    $defaults = [
        'id'              => $user_id,
        'nickname'        => $user_id,
        'project_history' => [],
    ];
    $data = json_read(user_path($user_id));
    if ($data === null) {
        json_write(user_path($user_id), $defaults);
        return $defaults;
    }
    return array_replace_recursive($defaults, $data);
}

function save_user(array $user): bool {
    return json_write(user_path($user['id']), $user);
}

/**
 * Add a project_id to the user's history (deduplicated, most recent first).
 */
function push_project_history(string $project_id, string $user_id = CURRENT_USER_ID): void {
    if (trim($project_id) === '') return;
    $user = load_user($user_id);
    $history = $user['project_history'] ?? [];
    // Remove existing, prepend
    $history = array_values(array_filter($history, fn($p) => $p !== $project_id));
    array_unshift($history, $project_id);
    // Keep last 50
    $user['project_history'] = array_slice($history, 0, 50);
    save_user($user);
}

function get_current_user(): array {
    return load_user(CURRENT_USER_ID);
}

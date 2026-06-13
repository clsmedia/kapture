<?php

declare(strict_types=1);

$required = ['ADMIN_PASSWORD', 'LOG_DIR', 'ROTATE_DAYS'];
$missing = array_values(array_filter($required, fn(string $k): bool => !isset($_ENV[$k])));
if ($missing !== []) {
    http_response_code(500);
    echo 'Kapture: missing required env var(s): ' . implode(', ', $missing) . "\n";
    exit(1);
}

$raw = trim($_ENV['STORAGE_DRIVER'] ?? 'filesystem');
$storageDriver = $raw === '' ? 'filesystem' : strtolower($raw);
if (!in_array($storageDriver, ['filesystem', 'sqlite'], true)) {
    http_response_code(500);
    echo "Kapture: STORAGE_DRIVER must be 'filesystem' or 'sqlite', got: {$storageDriver}\n";
    exit(1);
}
if ($storageDriver === 'sqlite' && !extension_loaded('sqlite3')) {
    http_response_code(500);
    echo "Kapture: STORAGE_DRIVER=sqlite requires ext-sqlite3. Install php-sqlite3 or switch to STORAGE_DRIVER=filesystem.\n";
    exit(1);
}

$rawForward = trim($_ENV['FORWARD_URL'] ?? '');
$forwardUrl = $rawForward !== '' ? $rawForward : null;
if ($forwardUrl !== null && !str_starts_with($forwardUrl, 'http://') && !str_starts_with($forwardUrl, 'https://')) {
    http_response_code(500);
    echo "Kapture: FORWARD_URL must be a valid HTTP or HTTPS URL, got: {$forwardUrl}\n";
    exit(1);
}

return [
    'admin_password' => $_ENV['ADMIN_PASSWORD'],
    'log_dir' => $_ENV['LOG_DIR'],
    'rotate_days' => (int) $_ENV['ROTATE_DAYS'],
    'storage_driver' => $storageDriver,
    'forward_url' => $forwardUrl,
];

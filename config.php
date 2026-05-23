<?php

declare(strict_types=1);

$required = ['ADMIN_PASSWORD', 'LOG_DIR', 'ROTATE_DAYS'];
$missing = array_values(array_filter($required, fn(string $k): bool => !isset($_ENV[$k])));
if ($missing !== []) {
    http_response_code(500);
    echo 'Kapture: missing required env var(s): ' . implode(', ', $missing) . "\n";
    exit(1);
}

return [
    'admin_password' => $_ENV['ADMIN_PASSWORD'],
    'log_dir' => $_ENV['LOG_DIR'],
    'rotate_days' => (int) $_ENV['ROTATE_DAYS'],
];

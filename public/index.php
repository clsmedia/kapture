<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';
loadEnvFile(__DIR__ . '/../.env');

use App\Application\CaptureWebhook;
use App\Application\ListCapturedRequests;
use App\Infrastructure\Persistence\FilesystemCapturedRequestRepository;
use App\Infrastructure\Persistence\SqliteCapturedRequestRepository;
use App\Presentation\Http\AdminController;
use App\Presentation\Http\Router;
use App\Presentation\Http\WebhookController;

$config = require __DIR__ . '/../config.php';

$isHttps = ($_SERVER['HTTPS'] ?? '') === 'on'
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
if (!$isHttps) {
    error_log('Kapture: WARNING — Admin password is transmitted in plaintext via Basic Auth. HTTPS is strongly recommended.');
}

$logDir = resolveLogDir($config['log_dir'], __DIR__ . '/../');
$repo = match ($config['storage_driver']) {
    'sqlite' => new SqliteCapturedRequestRepository($logDir),
    default => new FilesystemCapturedRequestRepository($logDir, $config['rotate_days']),
};

$router = new Router(
    new WebhookController(new CaptureWebhook($repo)),
    new AdminController(
        new ListCapturedRequests($repo),
        $config['admin_password'],
        $config['log_dir'],
    ),
);

$uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$router->dispatch((string)($uri ?? '/'));

function loadEnvFile(string $path): void
{
    if (!file_exists($path)) {
        http_response_code(500);
        echo "Kapture: .env not found at {$path}. Copy .env.example to .env and set your values.\n";
        exit(1);
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        if ((str_starts_with($val, '"') && str_ends_with($val, '"'))
            || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }
        $_ENV[$key] = $val;
        putenv("$key=$val");
    }
}

function resolveLogDir(string $raw, string $projectRoot): string
{
    if (!str_starts_with($raw, '/')) {
        return $projectRoot . $raw;
    }

    $resolvedRoot = realpath($projectRoot);
    if ($resolvedRoot !== false && !str_starts_with($raw, rtrim($resolvedRoot, '/') . '/')) {
        error_log('Kapture: WARNING — LOG_DIR is outside the project root. Captured request data may be written to an unexpected location.');
    }

    return $raw;
}

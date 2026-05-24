<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    http_response_code(500);
    echo "Kapture: .env not found at {$envFile}. Copy .env.example to .env and set your values.\n";
    exit(1);
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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

use App\Application\CaptureWebhook;
use App\Application\ListCapturedRequests;
use App\Infrastructure\Persistence\FilesystemCapturedRequestRepository;
use App\Presentation\Http\AdminController;
use App\Presentation\Http\Router;
use App\Presentation\Http\WebhookController;

$config = require __DIR__ . '/../config.php';

$logDir = $config['log_dir'];
if (!str_starts_with($logDir, '/')) {
    $logDir = __DIR__ . '/../' . $logDir;
}
$repo = new FilesystemCapturedRequestRepository($logDir, $config['rotate_days']);

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

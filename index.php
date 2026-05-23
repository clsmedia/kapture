<?php

declare(strict_types=1);

require __DIR__ . '/autoload.php';

use App\Application\CaptureWebhook;
use App\Application\ListCapturedRequests;
use App\Infrastructure\Persistence\FilesystemCapturedRequestRepository;
use App\Presentation\Http\AdminController;
use App\Presentation\Http\Router;
use App\Presentation\Http\WebhookController;

$config = require __DIR__ . '/config.php';

$repo = new FilesystemCapturedRequestRepository($config['log_dir'], $config['rotate_days']);

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

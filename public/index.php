<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';
\App\Infrastructure\Bootstrap::loadEnvFile(__DIR__ . '/../.env');

use App\Application\CaptureWebhook;
use App\Application\GenerateReplayFile;
use App\Application\GetCapturedRequest;
use App\Application\QueryCapturedRequests;
use App\Application\RunRecurringAnalysis;
use App\Infrastructure\Http\StreamForwardingClient;
use App\Infrastructure\Persistence\FilesystemCapturedRequestRepository;
use App\Infrastructure\Persistence\SqliteCapturedRequestRepository;
use App\Presentation\Html\AdminView;
use App\Presentation\Html\AnalysisView;
use App\Presentation\Http\AdminController;
use App\Presentation\Http\AnalysisController;
use App\Presentation\Http\ApiController;
use App\Presentation\Http\Router;
use App\Presentation\Http\SecurityHeaders;
use App\Presentation\Http\WebhookController;

$config = require __DIR__ . '/../config.php';

$isHttps = ($_SERVER['HTTPS'] ?? '') === 'on'
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
if (!$isHttps) {
    error_log('Kapture: WARNING — Admin password is transmitted in plaintext via Basic Auth. HTTPS is strongly recommended.');
}

SecurityHeaders::send($isHttps);

$logDir = \App\Infrastructure\Bootstrap::resolveLogDir($config['log_dir'], __DIR__ . '/../');
$repo = match ($config['storage_driver']) {
    'sqlite' => new SqliteCapturedRequestRepository($logDir, $config['rotate_days']),
    default => new FilesystemCapturedRequestRepository($logDir, $config['rotate_days']),
};

$queries = new QueryCapturedRequests($repo);

$forwardingClient = $config['forward_url'] !== null
    ? new StreamForwardingClient($config['forward_url'])
    : null;

$recurringAnalysis = new RunRecurringAnalysis($repo, $logDir, $config['rotate_days']);

$router = new Router(
    new WebhookController(
        new CaptureWebhook($repo),
        $repo,
        $forwardingClient,
        '',
        $recurringAnalysis,
    ),
    new AdminController(
        $queries,
        $repo,
        new GenerateReplayFile(new GetCapturedRequest($repo)),
        new AdminView(),
        $config['admin_password'],
    ),
    new ApiController(
        new GetCapturedRequest($repo),
        $queries,
        $config['api_token'],
        $config['api_auth_required'],
    ),
    new AnalysisController(
        $recurringAnalysis,
        new AnalysisView(),
        $config['admin_password'],
    ),
);

$uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$router->dispatch((string)($uri ?? '/'));



<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final readonly class Router
{
    public function __construct(
        private WebhookController $webhookController,
        private AdminController $adminController,
    )
    {
    }

    public function dispatch(string $uri): void
    {
        match (true) {
            $uri === '/assets/style.css' => self::serveAsset('style.css', 'text/css'),
            $uri === '/assets/admin.js' => self::serveAsset('admin.js', 'text/javascript'),
            str_starts_with($uri, '/capture') => $this->webhookController->handle(),
            str_starts_with($uri, '/kapture') => $this->webhookController->handle(),
            str_starts_with($uri, '/admin') => $this->adminController->handle(),
            default => self::jsonError(404, 'not found'),
        };
    }

    private static function serveAsset(string $file, string $mime): void
    {
        $path = __DIR__ . '/../Html/assets/' . basename($file);
        if (file_exists($path)) {
            header('Content-Type: ' . $mime);
            readfile($path);
            return;
        }
        http_response_code(404);
    }

    private static function jsonError(int $code, string $msg): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['error' => $msg], JSON_THROW_ON_ERROR) . "\n";
    }
}

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
        $uriLower = strtolower($uri);

        match (true) {
            str_starts_with($uriLower, '/capture') => $this->webhookController->handle(),
            str_starts_with($uriLower, '/kapture') => $this->webhookController->handle(),
            str_starts_with($uriLower, '/admin') => $this->adminController->handle(),
            default => self::jsonError(404, 'not found'),
        };
    }

    private static function jsonError(int $code, string $msg): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['error' => $msg], JSON_THROW_ON_ERROR) . "\n";
    }
}

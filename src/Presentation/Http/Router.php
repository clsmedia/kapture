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
            default => HttpResponse::error(404, 'not found'),
        };
    }
}

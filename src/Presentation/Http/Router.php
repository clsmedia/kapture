<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final readonly class Router
{
    public function __construct(
        private WebhookController $webhookController,
        private AdminController $adminController,
        private ApiController $apiController,
        private AnalysisController $analysisController,
    )
    {
    }

    public function dispatch(string $uri, ?string $method = null): void
    {
        $uriLower = strtolower($uri);
        $httpMethod = strtoupper((string) ($method ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'));

        match (true) {
            $uriLower === '/' && in_array($httpMethod, ['GET', 'HEAD'], true) => $this->health(),
            $uriLower === '/capture' || str_starts_with($uriLower, '/capture/') => $this->webhookController->handle(),
            $uriLower === '/kapture' || str_starts_with($uriLower, '/kapture/') => $this->webhookController->handle(),
            str_starts_with($uriLower, '/api/') => $this->apiController->handle(),
            $uriLower === '/admin/analysis' || str_starts_with($uriLower, '/admin/api/analysis') => $this->analysisController->handle(),
            $uriLower === '/admin' || str_starts_with($uriLower, '/admin/') => $this->adminController->handle(),
            default => HttpResponse::error(404, 'not found'),
        };
    }

    private function health(): void
    {
        header('Cache-Control: no-store');
        HttpResponse::json(200, [
            'status' => 'ok',
            'time' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\CaptureWebhook;

final readonly class WebhookController
{
    private const MAX_BODY_BYTES = 1_048_576;

    public function __construct(
        private CaptureWebhook $captureWebhook,
    )
    {
    }

    public function handle(): void
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > self::MAX_BODY_BYTES) {
            self::jsonError(413, 'Request body too large');
            return;
        }

        $headers = getallheaders();
        $rawBody = file_get_contents('php://input');

        $entry = $this->captureWebhook->handle(
            method: $_SERVER['REQUEST_METHOD'] ?? 'GET',
            uri: self::normalizeRequestUri($_SERVER['REQUEST_URI'] ?? '/'),
            query: $_GET,
            headers: $headers ?: [],
            body: $rawBody === false ? '' : $rawBody,
            ip: $_SERVER['REMOTE_ADDR'] ?? '',
        );

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'uid' => $entry->captureId], JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Strip the /capture or /kapture routing prefix from a captured URI,
     * so the logged path shows just the caller's intended endpoint.
     *
     *   /capture/test-one?p=1  →  /test-one?p=1
     *   /kapture/foo/bar       →  /foo/bar
     *   /capture               →  /
     *   /kapture/              →  /
     */
    public static function normalizeRequestUri(string $requestUri): string
    {
        $parsed = parse_url($requestUri);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';

        $cleanPath = preg_replace('#^/(capture|kapture)(/|$)#i', '/', $path);

        return $cleanPath . $query;
    }

    private static function jsonError(int $code, string $msg): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['error' => $msg], JSON_THROW_ON_ERROR) . "\n";
    }
}

<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\CaptureWebhook;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestRepository;
use App\Domain\ForwardingClient;
use OpenApi\Attributes as OA;

final readonly class WebhookController
{
    private const MAX_BODY_BYTES = 1_048_576;
    private const RATE_LIMIT_MAX = 60;
    private const RATE_LIMIT_WINDOW = 60;

    public function __construct(
        private CaptureWebhook $captureWebhook,
        private CapturedRequestRepository $repository,
        private readonly ?ForwardingClient $forwardingClient = null,
        private readonly string $rateLimitPrefix = '',
    )
    {
    }

    #[OA\Post(
        path: '/capture/{path}',
        operationId: 'captureWebhook',
        tags: ['captures'],
        summary: 'Capture a webhook request',
        description: 'Stores the request and returns its capture id. When FORWARD_URL is configured the request is forwarded and the upstream response is returned instead.',
    )]
    #[OA\Post(
        path: '/kapture/{path}',
        operationId: 'captureWebhookAlias',
        tags: ['captures'],
        summary: 'Capture a webhook request (alias of /capture)',
    )]
    #[OA\Parameter(name: 'path', in: 'path', required: true, description: 'Any path', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'X-Kapture-Correlation-Id', in: 'header', required: false, description: 'Correlation id stored with the capture', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(
        response: 200,
        description: 'Capture stored',
        content: new OA\JsonContent(
            required: ['ok', 'captureId'],
            properties: [
                new OA\Property(property: 'ok', type: 'boolean', example: true),
                new OA\Property(property: 'captureId', type: 'string'),
            ],
        ),
    )]
    #[OA\Response(response: 413, description: 'Request body too large', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
    #[OA\Response(response: 429, description: 'Rate limit exceeded', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
    public function handle(?ServerRequest $request = null): void
    {
        if ($request === null) {
            $cl = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($cl > self::MAX_BODY_BYTES) {
                HttpResponse::error(413, 'Request body too large');
                return;
            }

            $request = ServerRequest::fromGlobals(self::MAX_BODY_BYTES);
            // Content-Length may be absent (chunked bodies) — the read above
            // is capped, so verify the actual size too.
            if (strlen($request->body) > self::MAX_BODY_BYTES) {
                HttpResponse::error(413, 'Request body too large');
                return;
            }
        }

        if (!$this->checkRateLimit($request->ip)) {
            HttpResponse::error(429, 'Too many requests');
            return;
        }

        $entry = $this->captureWebhook->handle(
            method: $request->method,
            uri: self::normalizeRequestUri($request->uri),
            query: $request->query,
            headers: getallheaders() ?: [],
            body: $request->body,
            ip: $request->ip,
            correlationId: self::resolveCorrelationId(),
        );

        if ($this->forwardingClient !== null) {
            $this->forwardAndRespond($this->forwardingClient, $entry, $request);

            return;
        }

        HttpResponse::json(200, ['ok' => true, 'captureId' => $entry->captureId]);
    }

    private function forwardAndRespond(ForwardingClient $client, CapturedRequest $entry, ServerRequest $request): void
    {
        $result = $client->send($request->method, $entry->uri, $entry->body, getallheaders() ?: []);

        if (!$result->delivered) {
            HttpResponse::error($result->statusCode, $result->error);
            return;
        }

        foreach ($result->relayHeaders as $headerLine) {
            header($headerLine);
        }
        http_response_code($result->statusCode);
        echo $result->body;

        $this->repository->save($entry->withForwardResult($client->baseUrl(), $result->statusCode));
    }

    /**
     * Extract the optional X-Kapture-Correlation-Id header. Checks the
     * headers list (getallheaders) first, then falls back to the $_SERVER
     * mapping (HTTP_X_KAPTURE_CORRELATION_ID) which is easier to exercise
     * in tests and is what the built-in server populates.
     */
    private static function resolveCorrelationId(): ?string
    {
        $fromHeaders = self::extractCorrelationId(getallheaders() ?: []);
        if ($fromHeaders !== null) {
            return $fromHeaders;
        }

        $server = $_SERVER['HTTP_X_KAPTURE_CORRELATION_ID'] ?? '';
        $server = trim((string) $server);

        return $server !== '' ? $server : null;
    }

    /**
     * Find the correlation id in a header map, matching the header name
     * case-insensitively. Returns null when absent or empty.
     *
     * @param array<string, string> $headers
     */
    public static function extractCorrelationId(array $headers): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'x-kapture-correlation-id') {
                $value = trim((string) $value);
                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    private function checkRateLimit(string $ip): bool
    {
        $key = $ip !== '' ? $ip : 'unknown';
        return RateLimiter::record($key, self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW, $this->rateLimitPrefix);
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

        $lower = strtolower($path);
        foreach (['/capture/', '/capture', '/kapture/', '/kapture'] as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                $path = '/' . ltrim(substr($path, strlen($prefix)), '/');
                break;
            }
        }

        return $path . $query;
    }
}

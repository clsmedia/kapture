<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\CaptureWebhook;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestRepository;

final readonly class WebhookController
{
    private const MAX_BODY_BYTES = 1_048_576;
    private const RATE_LIMIT_MAX = 60;
    private const RATE_LIMIT_WINDOW = 60;
    private const FORWARD_TIMEOUT = 10;

    public function __construct(
        private CaptureWebhook $captureWebhook,
        private CapturedRequestRepository $repository,
        private readonly ?string $forwardUrl = null,
    )
    {
    }

    public function handle(?ServerRequest $request = null): void
    {
        if ($request === null) {
            $cl = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($cl > self::MAX_BODY_BYTES) {
                HttpResponse::error(413, 'Request body too large');
                return;
            }

            $request = ServerRequest::fromGlobals();
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
        );

        if ($this->forwardUrl !== null) {
            $statusCode = $this->forwardRequest($request, $entry);

            if ($statusCode !== null) {
                $this->repository->delete($entry->captureId);
                $entry = $entry->withForwardResult($this->forwardUrl, $statusCode);
                $this->repository->save($entry);
            }

            return;
        }

        HttpResponse::json(200, ['ok' => true, 'captureId' => $entry->captureId]);
    }

    public static function buildForwardUrl(string $baseUrl, string $capturedUri): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($capturedUri, '/');
    }

    private function forwardRequest(ServerRequest $request, CapturedRequest $entry): ?int
    {
        $target = self::buildForwardUrl($this->forwardUrl ?? '', $entry->uri);

        $forwardHeaders = [];
        foreach (getallheaders() ?: [] as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, ['host', 'content-length', 'transfer-encoding', 'connection'], true)) {
                continue;
            }
            $forwardHeaders[] = $key . ': ' . $value;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => $request->method,
                'header' => implode("\r\n", $forwardHeaders),
                'content' => $entry->body !== '' ? $entry->body : null,
                'timeout' => self::FORWARD_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($target, false, $ctx);

        if ($responseBody === false) {
            HttpResponse::error(502, sprintf('Forward request failed for %s', $target));
            return null;
        }

        $responseHeaders = http_get_last_response_headers() ?? [];

        $statusCode = 502;
        if (isset($responseHeaders[0]) && preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $responseHeaders[0], $m)) {
            $statusCode = (int) $m[1];
        }

        foreach ($responseHeaders as $header) {
            $lower = strtolower($header);
            if (str_starts_with($lower, 'http/') || str_starts_with($lower, 'transfer-encoding:')) {
                continue;
            }
            header($header);
        }

        http_response_code($statusCode);
        echo $responseBody;

        return $statusCode;
    }

    private function checkRateLimit(string $ip): bool
    {
        $key = $ip !== '' ? $ip : 'unknown';
        $tmp = sys_get_temp_dir() . '/kapture_rl_' . md5($key);
        $now = time();

        $window = @unserialize(@file_get_contents($tmp) ?: '');
        if (!is_array($window) || ($window['reset'] ?? 0) < $now) {
            $window = ['reset' => $now + self::RATE_LIMIT_WINDOW, 'count' => 0];
        }

        $window['count']++;
        file_put_contents($tmp, serialize($window), LOCK_EX);

        return $window['count'] <= self::RATE_LIMIT_MAX;
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

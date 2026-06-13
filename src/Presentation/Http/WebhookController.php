<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\CaptureWebhook;

final readonly class WebhookController
{
    private const MAX_BODY_BYTES = 1_048_576;
    private const RATE_LIMIT_MAX = 60;
    private const RATE_LIMIT_WINDOW = 60;

    public function __construct(
        private CaptureWebhook $captureWebhook,
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

        HttpResponse::json(200, ['ok' => true, 'captureId' => $entry->captureId]);
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

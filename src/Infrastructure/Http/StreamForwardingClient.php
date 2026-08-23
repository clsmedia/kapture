<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\ForwardingClient;
use App\Domain\ForwardResult;

final readonly class StreamForwardingClient implements ForwardingClient
{
    private const TIMEOUT = 10;
    private const STRIPPED_REQUEST_HEADERS = [
        'host',
        'content-length',
        'transfer-encoding',
        'connection',
        'authorization',
        'cookie',
        'proxy-authorization',
    ];
    private const RELAYABLE_RESPONSE_HEADERS = [
        'content-type',
        'content-encoding',
        'content-length',
        'cache-control',
        'etag',
        'last-modified',
        'expires',
        'vary',
    ];

    public function __construct(
        private string $baseUrl,
    )
    {
    }

    #[\Override]
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    #[\Override]
    public function send(string $method, string $uri, string $body, array $requestHeaders): ForwardResult
    {
        if (self::uriHasTraversal($uri)) {
            return ForwardResult::failed(400, 'Forward target rejected: captured URI contains path traversal');
        }

        $target = self::buildForwardUrl($this->baseUrl, $uri);

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", self::forwardableRequestHeaderLines($requestHeaders)),
                'content' => $body !== '' ? $body : null,
                'timeout' => self::TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($target, false, $context);

        if ($responseBody === false) {
            return ForwardResult::failed(502, sprintf('Forward request failed for %s', $target));
        }

        $responseHeaders = http_get_last_response_headers() ?? [];

        return ForwardResult::delivered(
            self::statusCodeFromHeadline($responseHeaders),
            $responseBody,
            self::relayableResponseHeaderLines($responseHeaders),
        );
    }

    public static function buildForwardUrl(string $baseUrl, string $capturedUri): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($capturedUri, '/');
    }

    /**
     * Format request headers as wire lines, dropping hop-by-hop headers and
     * credentials so they never reach the forward target.
     *
     * @param array<string, string> $headers
     * @return list<string>
     */
    public static function forwardableRequestHeaderLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $key => $value) {
            if (in_array(strtolower((string) $key), self::STRIPPED_REQUEST_HEADERS, true)) {
                continue;
            }
            $lines[] = $key . ': ' . $value;
        }
        return $lines;
    }

    /**
     * Reject URIs that could redirect the forward target off its configured
     * base: absolute URLs or `..` path segments.
     */
    private static function uriHasTraversal(string $uri): bool
    {
        $parsed = parse_url($uri);
        if (isset($parsed['scheme']) || isset($parsed['host'])) {
            return true;
        }
        foreach (explode('/', $parsed['path'] ?? '') as $segment) {
            // rawurldecode: HTTP clients decode %2e%2e before resolving the
            // path, so the raw segment alone would miss encoded traversal.
            if (rawurldecode($segment) === '..') {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $responseHeaders */
    private static function statusCodeFromHeadline(array $responseHeaders): int
    {
        if (isset($responseHeaders[0]) && preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $responseHeaders[0], $m) === 1) {
            return (int) $m[1];
        }
        return 502;
    }

    /**
     * @param list<string> $responseHeaders
     * @return list<string>
     */
    private static function relayableResponseHeaderLines(array $responseHeaders): array
    {
        $lines = [];
        foreach ($responseHeaders as $header) {
            $lower = strtolower($header);
            $name = strtok($lower, ':');
            if (str_starts_with($lower, 'http/')
                || str_starts_with($lower, 'transfer-encoding:')
                || !in_array($name, self::RELAYABLE_RESPONSE_HEADERS, true)) {
                continue;
            }
            $lines[] = $header;
        }
        return $lines;
    }
}

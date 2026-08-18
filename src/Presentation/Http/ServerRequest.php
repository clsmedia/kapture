<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final readonly class ServerRequest
{
    /** @param array<string, string> $query */
    public function __construct(
        public string $method,
        public string $uri,
        public string $ip,
        /** @var array<string, string> */
        public array $query,
        public string $body,
    )
    {
    }

    /**
     * @param int $maxBodyBytes Cap on the body read; 0 = unlimited. A body
     *                          larger than the cap is truncated to cap+1 bytes
     *                          so callers can detect the overflow.
     */
    public static function fromGlobals(int $maxBodyBytes = 0): self
    {
        $fp = fopen('php://input', 'r');
        $rawBody = $fp === false
            ? ''
            : stream_get_contents($fp, $maxBodyBytes > 0 ? $maxBodyBytes + 1 : -1);

        return new self(
            method: $_SERVER['REQUEST_METHOD'] ?? 'GET',
            uri: $_SERVER['REQUEST_URI'] ?? '/',
            ip: $_SERVER['REMOTE_ADDR'] ?? '',
            query: $_GET,
            body: $rawBody === false ? '' : $rawBody,
        );
    }

    public function contentLength(): int
    {
        return (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    }
}

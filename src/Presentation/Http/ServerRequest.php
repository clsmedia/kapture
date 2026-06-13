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

    public static function fromGlobals(): self
    {
        $rawBody = file_get_contents('php://input');

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

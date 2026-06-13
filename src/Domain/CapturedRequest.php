<?php

declare(strict_types=1);

namespace App\Domain;

readonly class CapturedRequest
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function __construct(
        public CapturedAt $capturedAt,
        public HttpMethod $method,
        public string $uri,
        public array $query,
        public array $headers,
        public string $body,
        public string $ip,
        public string $captureId,
        public ?string $forwardUrl = null,
        public ?int $forwardStatusCode = null,
    )
    {
    }

    public function withForwardResult(string $forwardUrl, int $statusCode): self
    {
        return new self(
            $this->capturedAt,
            $this->method,
            $this->uri,
            $this->query,
            $this->headers,
            $this->body,
            $this->ip,
            $this->captureId,
            $forwardUrl,
            $statusCode,
        );
    }

    private const SENSITIVE_HEADERS = ['authorization', 'cookie', 'set-cookie'];

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public static function capture(
        string $method,
        string $uri,
        array $query,
        array $headers,
        string $body,
        string $ip,
    ): self
    {
        return new self(
            CapturedAt::now(),
            HttpMethod::tryFromMethod($method) ?? HttpMethod::GET,
            $uri,
            $query,
            self::stripSensitiveHeaders($headers),
            $body,
            $ip,
            bin2hex(random_bytes(8)),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $capturedAt = isset($data['capturedAt'])
            ? CapturedAt::fromString((string)$data['capturedAt'])
            : (isset($data['ts'])
                ? CapturedAt::fromString((string)$data['ts'])
                : CapturedAt::now());
        $captureId = (string)($data['captureId'] ?? $data['uid'] ?? '');
        $method = HttpMethod::tryFromMethod((string)($data['method'] ?? '')) ?? HttpMethod::GET;

        return new self(
            $capturedAt,
            $method,
            (string)($data['uri'] ?? ''),
            (array)($data['query'] ?? []),
            (array)($data['headers'] ?? []),
            (string)($data['body'] ?? ''),
            (string)($data['ip'] ?? ''),
            $captureId,
            forwardUrl: isset($data['forwardUrl']) ? (string) $data['forwardUrl'] : null,
            forwardStatusCode: isset($data['forwardStatusCode']) ? (int) $data['forwardStatusCode'] : null,
        );
    }

    /**
     * @return array{capturedAt: string, method: string, uri: string, query: array<string, string>, headers: array<string, string>, body: string, ip: string, captureId: string, forwardUrl?: string, forwardStatusCode?: int|null}
     */
    public function toArray(): array
    {
        $data = [
            'capturedAt' => $this->capturedAt->toIso8601(),
            'method' => $this->method->value,
            'uri' => $this->uri,
            'query' => $this->query,
            'headers' => $this->headers,
            'body' => $this->body,
            'ip' => $this->ip,
            'captureId' => $this->captureId,
        ];

        if ($this->forwardUrl !== null) {
            $data['forwardUrl'] = $this->forwardUrl;
            $data['forwardStatusCode'] = $this->forwardStatusCode;
        }

        return $data;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function stripSensitiveHeaders(array $headers): array
    {
        $safe = [];
        foreach ($headers as $key => $value) {
            if (!in_array(strtolower((string)$key), self::SENSITIVE_HEADERS, true)) {
                $safe[$key] = $value;
            }
        }
        return $safe;
    }
}

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
        public string $method,
        public string $uri,
        public array $query,
        public array $headers,
        public string $body,
        public string $ip,
        public string $captureId,
    )
    {
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
        $safeHeaders = [];
        foreach ($headers as $key => $value) {
            if (!in_array(strtolower((string)$key), self::SENSITIVE_HEADERS, true)) {
                $safeHeaders[$key] = $value;
            }
        }
        return new self(
            CapturedAt::now(),
            $method,
            $uri,
            $query,
            $safeHeaders,
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

        return new self(
            $capturedAt,
            (string)($data['method'] ?? ''),
            (string)($data['uri'] ?? ''),
            (array)($data['query'] ?? []),
            (array)($data['headers'] ?? []),
            (string)($data['body'] ?? ''),
            (string)($data['ip'] ?? ''),
            $captureId,
        );
    }

    /**
     * @return array{capturedAt: string, method: string, uri: string, query: array<string, string>, headers: array<string, string>, body: string, ip: string, captureId: string}
     */
    public function toArray(): array
    {
        return [
            'capturedAt' => $this->capturedAt->toIso8601(),
            'method' => $this->method,
            'uri' => $this->uri,
            'query' => $this->query,
            'headers' => $this->headers,
            'body' => $this->body,
            'ip' => $this->ip,
            'captureId' => $this->captureId,
        ];
    }

    /**
     * @return array{ts: string, method: string, uri: string, query: array<string, string>, headers: array<string, string>, body: string, ip: string, uid: string}
     */
    public function toLegacyArray(): array
    {
        return [
            'ts' => $this->capturedAt->toIso8601(),
            'method' => $this->method,
            'uri' => $this->uri,
            'query' => $this->query,
            'headers' => $this->headers,
            'body' => $this->body,
            'ip' => $this->ip,
            'uid' => $this->captureId,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

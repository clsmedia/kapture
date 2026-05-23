<?php

declare(strict_types=1);

namespace App\Domain;

readonly class Entry
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $ts,
        public string $method,
        public string $uri,
        public array $query,
        public array $headers,
        public string $body,
        public string $ip,
        public string $uid,
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
            if (!in_array(strtolower((string) $key), self::SENSITIVE_HEADERS, true)) {
                $safeHeaders[$key] = $value;
            }
        }
        return new self(
            ts: gmdate('Y-m-d\TH:i:s\Z'),
            method: $method,
            uri: $uri,
            query: $query,
            headers: $safeHeaders,
            body: $body,
            ip: $ip,
            uid: bin2hex(random_bytes(8)),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ts: (string) ($data['ts'] ?? ''),
            method: (string) ($data['method'] ?? ''),
            uri: (string) ($data['uri'] ?? ''),
            query: (array) ($data['query'] ?? []),
            headers: (array) ($data['headers'] ?? []),
            body: (string) ($data['body'] ?? ''),
            ip: (string) ($data['ip'] ?? ''),
            uid: (string) ($data['uid'] ?? ''),
        );
    }

    /**
     * @return array{ts: string, method: string, uri: string, query: array<string, string>, headers: array<string, string>, body: string, ip: string, uid: string}
     */
    public function toArray(): array
    {
        return [
            'ts' => $this->ts,
            'method' => $this->method,
            'uri' => $this->uri,
            'query' => $this->query,
            'headers' => $this->headers,
            'body' => $this->body,
            'ip' => $this->ip,
            'uid' => $this->uid,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

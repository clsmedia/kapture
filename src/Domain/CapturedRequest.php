<?php

declare(strict_types=1);

namespace App\Domain;

use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'A captured HTTP request',
    required: ['capturedAt', 'method', 'uri', 'query', 'headers', 'body', 'ip', 'captureId'],
)]
readonly class CapturedRequest
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function __construct(
        #[OA\Property(type: 'string', format: 'date-time', description: 'Receipt time, ISO8601 UTC', example: '2026-05-24T12:00:00Z')]
        public CapturedAt $capturedAt,
        #[OA\Property(type: 'string', enum: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], description: 'HTTP method')]
        public HttpMethod $method,
        #[OA\Property(type: 'string', description: 'Normalized path + query (webhook prefix stripped)')]
        public string $uri,
        #[OA\Property(type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), description: 'Query parameters')]
        public array $query,
        #[OA\Property(type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), description: 'Request headers. Sensitive headers (authorization, cookie) are stripped.')]
        public array $headers,
        #[OA\Property(type: 'string', description: 'Raw request body')]
        public string $body,
        #[OA\Property(type: 'string', description: 'Client IP address')]
        public string $ip,
        #[OA\Property(type: 'string', description: 'Capture id')]
        public string $captureId,
        #[OA\Property(type: 'string', description: 'Present when FORWARD_URL forwarding ran')]
        public ?string $forwardUrl = null,
        #[OA\Property(type: 'integer', description: 'Status code returned by the forward target')]
        public ?int $forwardStatusCode = null,
        #[OA\Property(type: 'string', description: 'From the X-Kapture-Correlation-Id header, if sent')]
        public ?string $correlationId = null,
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
            correlationId: $this->correlationId,
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
        ?string $correlationId = null,
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
            correlationId: $correlationId,
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
        $correlationId = isset($data['correlationId']) ? (string) $data['correlationId'] : null;

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
            correlationId: $correlationId !== null && $correlationId !== '' ? $correlationId : null,
        );
    }

    /**
     * @return array{capturedAt: string, method: string, uri: string, query: array<string, string>, headers: array<string, string>, body: string, ip: string, captureId: string, forwardUrl?: string, forwardStatusCode?: int|null, correlationId?: string}
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

        if ($this->correlationId !== null) {
            $data['correlationId'] = $this->correlationId;
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

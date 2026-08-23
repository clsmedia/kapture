<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Http\StreamForwardingClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamForwardingClient::class)]
final class StreamForwardingClientTest extends TestCase
{
    /** @param array{string, string} $args */
    #[DataProvider('normalizeProvider')]
    public function test_build_forward_url(string $baseUrl, string $capturedUri, string $expected): void
    {
        self::assertSame($expected, StreamForwardingClient::buildForwardUrl($baseUrl, $capturedUri));
    }

    /** @return iterable<array{string, string, string}> */
    public static function normalizeProvider(): iterable
    {
        yield 'appends path' => ['http://localhost:3000', '/stripe/charge', 'http://localhost:3000/stripe/charge'];
        yield 'handles trailing slash on base' => ['http://localhost:3000/', '/stripe/charge', 'http://localhost:3000/stripe/charge'];
        yield 'preserves query string' => ['http://localhost:3000', '/stripe/charge?ev=created', 'http://localhost:3000/stripe/charge?ev=created'];
        yield 'root path' => ['http://localhost:3000', '/', 'http://localhost:3000/'];
        yield 'https scheme' => ['https://app.example.com', '/test', 'https://app.example.com/test'];
        yield 'nested path' => ['http://localhost:3000/webhooks', '/stripe/charge', 'http://localhost:3000/webhooks/stripe/charge'];
    }

    public function test_base_url_returns_configured_base(): void
    {
        $client = new StreamForwardingClient('https://target.example');

        self::assertSame('https://target.example', $client->baseUrl());
    }

    public function test_send_rejects_traversal_uri_without_dialing(): void
    {
        $client = new StreamForwardingClient('https://target.example');

        foreach (['/stripe/../admin', '/%2e%2e/secret', 'http://evil.example/x', '//evil.example/x'] as $uri) {
            $result = $client->send('POST', $uri, '{}', []);

            self::assertFalse($result->delivered, "URI {$uri} should be rejected");
            self::assertSame(400, $result->statusCode);
            self::assertSame('Forward target rejected: captured URI contains path traversal', $result->error);
        }
    }

    public function test_send_returns_failed_verdict_when_target_unreachable(): void
    {
        $client = new StreamForwardingClient('http://127.0.0.1:1');

        $result = $client->send('POST', '/test', '{"key":"val"}', ['Content-Type' => 'application/json']);

        self::assertFalse($result->delivered);
        self::assertSame(502, $result->statusCode);
        self::assertSame('Forward request failed for http://127.0.0.1:1/test', $result->error);
    }

    public function test_forwardable_request_header_lines_strips_hop_by_hop_and_credentials(): void
    {
        $lines = StreamForwardingClient::forwardableRequestHeaderLines([
            'Host' => 'kapture.local',
            'Content-Length' => '13',
            'Transfer-Encoding' => 'chunked',
            'Connection' => 'keep-alive',
            'Authorization' => 'Bearer secret',
            'Cookie' => 'session=xyz',
            'Proxy-Authorization' => 'Basic zzz',
            'Content-Type' => 'application/json',
            'X-Stripe-Signature' => 'sig-123',
        ]);

        self::assertSame([
            'Content-Type: application/json',
            'X-Stripe-Signature: sig-123',
        ], $lines);
    }

    public function test_forwardable_request_header_lines_is_case_insensitive(): void
    {
        $lines = StreamForwardingClient::forwardableRequestHeaderLines([
            'AUTHORIZATION' => 'Bearer secret',
            'cookie' => 'a=b',
            'X-Custom' => 'kept',
        ]);

        self::assertSame(['X-Custom: kept'], $lines);
    }

    public function test_forwardable_request_header_lines_handles_empty_input(): void
    {
        self::assertSame([], StreamForwardingClient::forwardableRequestHeaderLines([]));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\CapturedRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CapturedRequest::class)]
final class CapturedRequestTest extends TestCase
{
    public function test_capture_filters_sensitive_headers(): void
    {
        $request = CapturedRequest::capture(
            method: 'POST',
            uri: '/test',
            query: ['foo' => 'bar'],
            headers: [
                'Authorization' => 'Bearer secret',
                'Cookie' => 'session=abc',
                'Set-Cookie' => 'track=123',
                'Content-Type' => 'application/json',
            ],
            body: '{"ok":true}',
            ip: '127.0.0.1',
        );

        self::assertArrayNotHasKey('Authorization', $request->headers);
        self::assertArrayNotHasKey('Cookie', $request->headers);
        self::assertArrayNotHasKey('Set-Cookie', $request->headers);
        self::assertSame('application/json', $request->headers['Content-Type']);
    }

    public function test_capture_generates_capture_id(): void
    {
        $request = CapturedRequest::capture('GET', '/', [], [], '', '');
        self::assertNotEmpty($request->captureId);
        self::assertSame(16, strlen($request->captureId));
    }

    public function test_capture_sets_timestamp(): void
    {
        $request = CapturedRequest::capture('GET', '/', [], [], '', '');
        self::assertInstanceOf(\DateTimeImmutable::class, $request->capturedAt->toDateTimeImmutable());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $request->capturedAt->toIso8601());
    }

    public function test_capture_assigns_capture_id_uniquely(): void
    {
        $a = CapturedRequest::capture('GET', '/', [], [], '', '');
        $b = CapturedRequest::capture('GET', '/', [], [], '', '');
        self::assertNotSame($a->captureId, $b->captureId);
    }

    public function test_fromArray_toArray_symmetric(): void
    {
        $data = [
            'capturedAt' => '2025-01-01T00:00:00Z',
            'method' => 'POST',
            'uri' => '/hook',
            'query' => ['key' => 'val'],
            'headers' => ['X-Custom' => 'val'],
            'body' => 'payload',
            'ip' => '10.0.0.1',
            'captureId' => 'abc123',
        ];
        $request = CapturedRequest::fromArray($data);
        self::assertSame($data, $request->toArray());
    }

    public function test_fromArray_with_empty_data_defaults_to_now(): void
    {
        $request = CapturedRequest::fromArray([]);
        self::assertSame('', $request->method);
        self::assertSame('', $request->uri);
        self::assertSame([], $request->query);
        self::assertSame([], $request->headers);
        self::assertSame('', $request->body);
        self::assertSame('', $request->ip);
        self::assertSame('', $request->captureId);
        // capturedAt defaults to now, so it will always be set
        self::assertNotNull($request->capturedAt);
    }

    public function test_fromArray_handles_legacy_ts_and_uid_keys(): void
    {
        $request = CapturedRequest::fromArray([
            'ts' => '2025-01-01T00:00:00Z',
            'method' => 'GET',
            'uri' => '/legacy',
            'query' => [],
            'headers' => [],
            'body' => '',
            'ip' => '',
            'uid' => 'u1',
        ]);

        self::assertSame('2025-01-01T00:00:00Z', $request->capturedAt->toIso8601());
        self::assertSame('u1', $request->captureId);
    }

    public function test_fromArray_prefers_new_keys_over_legacy(): void
    {
        $request = CapturedRequest::fromArray([
            'capturedAt' => '2025-06-01T00:00:00Z',
            'ts' => '2024-01-01T00:00:00Z',
            'method' => 'GET',
            'uri' => '/',
            'query' => [],
            'headers' => [],
            'body' => '',
            'ip' => '',
            'captureId' => 'new',
            'uid' => 'old',
        ]);

        self::assertSame('2025-06-01T00:00:00Z', $request->capturedAt->toIso8601());
        self::assertSame('new', $request->captureId);
    }

    public function test_toJson_emits_new_keys(): void
    {
        $request = CapturedRequest::fromArray([
            'capturedAt' => '2025-01-01T00:00:00Z',
            'method' => 'GET',
            'uri' => '/',
            'query' => [],
            'headers' => [],
            'body' => '',
            'ip' => '',
            'captureId' => 'u1',
        ]);
        $json = $request->toJson();
        self::assertJson($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('GET', $decoded['method']);
        self::assertArrayHasKey('capturedAt', $decoded);
        self::assertArrayHasKey('captureId', $decoded);
        self::assertArrayNotHasKey('ts', $decoded);
        self::assertArrayNotHasKey('uid', $decoded);
    }

    public function test_toLegacyArray_emits_old_keys(): void
    {
        $request = CapturedRequest::fromArray([
            'capturedAt' => '2025-01-01T00:00:00Z',
            'method' => 'GET',
            'uri' => '/',
            'query' => [],
            'headers' => [],
            'body' => '',
            'ip' => '',
            'captureId' => 'u1',
        ]);
        $legacy = $request->toLegacyArray();
        self::assertSame('2025-01-01T00:00:00Z', $legacy['ts']);
        self::assertSame('u1', $legacy['uid']);
        self::assertArrayNotHasKey('capturedAt', $legacy);
        self::assertArrayNotHasKey('captureId', $legacy);
    }

    public function test_sensitive_headers_case_insensitive(): void
    {
        $request = CapturedRequest::capture(
            method: 'POST',
            uri: '/',
            query: [],
            headers: [
                'authorization' => 'x',
                'AUTHORIZATION' => 'y',
                'Authorization' => 'z',
                'X-Custom' => 'keep',
            ],
            body: '',
            ip: '',
        );

        self::assertCount(1, $request->headers);
        self::assertSame('keep', $request->headers['X-Custom']);
    }
}

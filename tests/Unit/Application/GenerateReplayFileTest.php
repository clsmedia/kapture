<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\GenerateReplayFile;
use App\Application\GetCapturedRequest;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenerateReplayFile::class)]
#[UsesClass(GetCapturedRequest::class)]
final class GenerateReplayFileTest extends TestCase
{
    public function test_handle_returns_http_format(): void
    {
        $entry = CapturedRequest::capture(
            'POST',
            '/webhook/events',
            ['source' => 'test'],
            ['Content-Type' => 'application/json', 'X-Custom' => 'value'],
            '{"event":"test"}',
            '10.0.0.1',
        );

        $useCase = $this->createUseCase($entry);
        $result = $useCase->handle($entry->captureId, 'http');

        self::assertNotNull($result);
        self::assertStringContainsString('### Replay captured request', $result);
        self::assertStringContainsString('POST /webhook/events', $result);
        self::assertStringContainsString('Content-Type: application/json', $result);
        self::assertStringContainsString('X-Custom: value', $result);
        self::assertStringContainsString('{"event":"test"}', $result);
    }

    public function test_handle_returns_curl_format(): void
    {
        $entry = CapturedRequest::capture(
            'GET',
            '/api/users',
            ['page' => '1'],
            ['Accept' => 'application/json'],
            '',
            '10.0.0.1',
        );

        $useCase = $this->createUseCase($entry);
        $result = $useCase->handle($entry->captureId, 'curl');

        self::assertNotNull($result);
        self::assertStringContainsString('curl', $result);
        self::assertStringContainsString("'/api/users'", $result);
        self::assertStringContainsString("-H 'Accept: application/json'", $result);
    }

    public function test_handle_curl_omits_x_method_for_get(): void
    {
        $entry = CapturedRequest::capture('GET', '/health', [], [], '', '10.0.0.1');

        $useCase = $this->createUseCase($entry);
        $result = $useCase->handle($entry->captureId, 'curl');

        self::assertNotNull($result);
        self::assertStringNotContainsString('-X GET', $result);
    }

    public function test_handle_curl_includes_x_method_for_post(): void
    {
        $entry = CapturedRequest::capture('POST', '/submit', [], [], 'data', '10.0.0.1');

        $useCase = $this->createUseCase($entry);
        $result = $useCase->handle($entry->captureId, 'curl');

        self::assertNotNull($result);
        self::assertStringContainsString('-X POST', $result);
        self::assertStringContainsString("-d 'data'", $result);
    }

    public function test_handle_escapes_single_quotes_in_curl(): void
    {
        $entry = CapturedRequest::capture(
            'POST',
            '/webhook',
            [],
            ['Content-Type' => 'application/json'],
            "{\"msg\":\"it's broken\"}",
            '10.0.0.1',
        );

        $useCase = $this->createUseCase($entry);
        $result = $useCase->handle($entry->captureId, 'curl');

        self::assertNotNull($result);
        self::assertStringNotContainsString("-d 'it's", $result);
        self::assertStringContainsString("'\\''", $result);
    }

    public function test_handle_escapes_single_quotes_in_uri(): void
    {
        $entry = CapturedRequest::capture(
            'GET',
            "/events?name=o'brien",
            [],
            [],
            '',
            '10.0.0.1',
        );

        $useCase = $this->createUseCase($entry);
        $result = $useCase->handle($entry->captureId, 'curl');

        self::assertNotNull($result);
        // POSIX single-quote escaping: quote is closed, escaped, reopened.
        self::assertSame(
            "curl \\\n  '/events?name=o'\\''brien'\n",
            $result,
        );
    }

    public function test_handle_returns_null_when_not_found(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->method('findByCriteria')->willReturn([]);

        $useCase = new GenerateReplayFile(new GetCapturedRequest($repo));
        $result = $useCase->handle('nonexistent-id', 'http');

        self::assertNull($result);
    }

    public function test_handle_defaults_to_http_format(): void
    {
        $entry = CapturedRequest::capture('DELETE', '/item/1', [], [], '', '10.0.0.1');

        $useCase = $this->createUseCase($entry);
        $result = $useCase->handle($entry->captureId);

        self::assertNotNull($result);
        self::assertStringContainsString('DELETE /item/1', $result);
    }

    private function createUseCase(CapturedRequest $entry): GenerateReplayFile
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->method('findByCriteria')->willReturn([$entry]);

        return new GenerateReplayFile(new GetCapturedRequest($repo));
    }
}
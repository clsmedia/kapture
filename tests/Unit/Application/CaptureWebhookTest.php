<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\CaptureWebhook;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestRepository;
use App\Domain\HttpMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CaptureWebhook::class)]
final class CaptureWebhookTest extends TestCase
{
    public function test_handle_creates_entry_and_saves(): void
    {
        $request = CapturedRequest::capture('POST', '/webhook', ['q' => '1'], ['X-Foo' => 'bar'], 'body', '10.0.0.1');

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())
            ->method('save')
            ->with(self::callback(fn(CapturedRequest $r): bool => $r->uri === '/webhook'));

        $useCase = new CaptureWebhook($repo);
        $result = $useCase->handle('POST', '/webhook', ['q' => '1'], ['X-Foo' => 'bar'], 'body', '10.0.0.1');

        self::assertSame('/webhook', $result->uri);
        self::assertSame(HttpMethod::POST, $result->method);
    }
}

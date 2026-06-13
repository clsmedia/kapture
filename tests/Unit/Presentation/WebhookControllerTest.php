<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Application\CaptureWebhook;
use App\Domain\CapturedRequestRepository;
use App\Presentation\Http\ServerRequest;
use App\Presentation\Http\WebhookController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookController::class)]
#[UsesClass(CaptureWebhook::class)]
#[UsesClass(ServerRequest::class)]
final class WebhookControllerTest extends TestCase
{
    /** @param array{string, string} $args */
    #[DataProvider('normalizeProvider')]
    public function test_normalize_request_uri(string $input, string $expected): void
    {
        self::assertSame($expected, WebhookController::normalizeRequestUri($input));
    }

    /** @return iterable<array{string, string}> */
    public static function normalizeProvider(): iterable
    {
        yield 'capture with path and query' => ['/capture/test-one?p=1', '/test-one?p=1'];
        yield 'capture with path only' => ['/capture/foo/bar', '/foo/bar'];
        yield 'capture root' => ['/capture', '/'];
        yield 'capture root with query' => ['/capture?param=123', '/?param=123'];
        yield 'capture root slash' => ['/capture/', '/'];
        yield 'kapture with path' => ['/kapture/test-one', '/test-one'];
        yield 'kapture root' => ['/kapture', '/'];
        yield 'kapture root slash' => ['/kapture/', '/'];
        yield 'no prefix, normal path' => ['/test-one?p=1', '/test-one?p=1'];
        yield 'no prefix, root' => ['/', '/'];
        yield 'admin path unchanged' => ['/admin/something', '/admin/something'];
        yield 'uppercase capture with path' => ['/CAPTURE/test-one', '/test-one'];
        yield 'uppercase kapture with path' => ['/KAPTURE/foo/bar', '/foo/bar'];
        yield 'mixed case capture root' => ['/Capture/', '/'];
    }

    #[DataProvider('forwardUrlProvider')]
    public function test_buildForwardUrl(string $baseUrl, string $capturedUri, string $expected): void
    {
        self::assertSame($expected, WebhookController::buildForwardUrl($baseUrl, $capturedUri));
    }

    /** @return iterable<array{string, string, string}> */
    public static function forwardUrlProvider(): iterable
    {
        yield 'appends path' => ['http://localhost:3000', '/stripe/charge', 'http://localhost:3000/stripe/charge'];
        yield 'handles trailing slash on base' => ['http://localhost:3000/', '/stripe/charge', 'http://localhost:3000/stripe/charge'];
        yield 'preserves query string' => ['http://localhost:3000', '/stripe/charge?ev=created', 'http://localhost:3000/stripe/charge?ev=created'];
        yield 'root path' => ['http://localhost:3000', '/', 'http://localhost:3000/'];
        yield 'https scheme' => ['https://app.example.com', '/test', 'https://app.example.com/test'];
        yield 'nested path' => ['http://localhost:3000/webhooks', '/stripe/charge', 'http://localhost:3000/webhooks/stripe/charge'];
    }

    public function test_forward_returns_error_when_target_unreachable(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $controller = new WebhookController(
            new CaptureWebhook($repo),
            $repo,
            'http://127.0.0.1:1/',
        );

        $request = new ServerRequest('POST', '/kapture/test', '10.0.0.1', [], '{"key":"val"}');

        ob_start();
        $controller->handle($request);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('Forward request failed', $data['error']);
    }

    public function test_no_forward_returns_normal_response(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $controller = new WebhookController(new CaptureWebhook($repo), $repo);

        $request = new ServerRequest('POST', '/kapture/test', '10.0.0.1', [], '{"key":"val"}');

        ob_start();
        $controller->handle($request);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        self::assertSame(true, $data['ok']);
        self::assertArrayHasKey('captureId', $data);
    }
}

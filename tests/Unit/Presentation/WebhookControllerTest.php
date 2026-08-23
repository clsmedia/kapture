<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Application\CaptureWebhook;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestRepository;
use App\Domain\ForwardResult;
use App\Domain\ForwardingClient;
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

    public function test_forward_failure_returns_error_response_without_resaving(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('save');
        $repo->expects(self::never())->method('delete');

        $client = self::scriptedClient(ForwardResult::failed(502, 'Forward request failed for http://target.example/test'));
        $controller = new WebhookController(new CaptureWebhook($repo), $repo, $client, bin2hex(random_bytes(4)));

        $request = new ServerRequest('POST', '/kapture/test', '10.0.0.1', [], '{"key":"val"}');

        ob_start();
        $controller->handle($request);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('Forward request failed', $data['error']);
    }

    public function test_forward_success_relays_body_and_saves_forward_result_atomically(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $saved = [];
        $repo->expects(self::exactly(2))->method('save')->willReturnCallback(
            function (CapturedRequest $entry) use (&$saved): void {
                $saved[] = $entry;
            },
        );
        $repo->expects(self::never())->method('delete');

        $client = self::scriptedClient(ForwardResult::delivered(200, 'upstream-body', ['Content-Type: text/plain']));
        $controller = new WebhookController(new CaptureWebhook($repo), $repo, $client, bin2hex(random_bytes(4)));

        $request = new ServerRequest('POST', '/kapture/test', '10.0.0.1', [], '{"key":"val"}');

        ob_start();
        $controller->handle($request);
        $output = ob_get_clean();

        self::assertSame('upstream-body', $output);
        self::assertCount(2, $saved);
        self::assertNull($saved[0]->forwardUrl);
        self::assertSame('http://target.example', $saved[1]->forwardUrl);
        self::assertSame(200, $saved[1]->forwardStatusCode);
        self::assertSame($saved[0]->captureId, $saved[1]->captureId);
    }

    public function test_forward_rejection_returns_verdict_error(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('save');
        $repo->expects(self::never())->method('delete');

        $client = self::scriptedClient(ForwardResult::failed(400, 'Forward target rejected: captured URI contains path traversal'));
        $controller = new WebhookController(new CaptureWebhook($repo), $repo, $client, bin2hex(random_bytes(4)));

        $request = new ServerRequest('POST', '/kapture/../secret', '10.0.0.1', [], '');

        ob_start();
        $controller->handle($request);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('path traversal', $data['error']);
    }

    private static function scriptedClient(ForwardResult $result): ForwardingClient
    {
        return new class ($result) implements ForwardingClient {
            public function __construct(
                private readonly ForwardResult $result,
            )
            {
            }

            #[\Override]
            public function send(string $method, string $uri, string $body, array $requestHeaders): ForwardResult
            {
                return $this->result;
            }

            #[\Override]
            public function baseUrl(): string
            {
                return 'http://target.example';
            }
        };
    }

    public function test_no_forward_returns_normal_response(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $controller = new WebhookController(new CaptureWebhook($repo), $repo, null, bin2hex(random_bytes(4)));

        $request = new ServerRequest('POST', '/kapture/test', '10.0.0.1', [], '{"key":"val"}');

        ob_start();
        $controller->handle($request);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        self::assertSame(true, $data['ok']);
        self::assertArrayHasKey('captureId', $data);
    }

    public function test_capture_stores_correlation_id_from_header(): void
    {
        $_SERVER['HTTP_X_KAPTURE_CORRELATION_ID'] = 'corr-123';
        try {
            $repo = $this->createMock(CapturedRequestRepository::class);
            $saved = null;
            $repo->expects(self::once())->method('save')->willReturnCallback(function (CapturedRequest $entry) use (&$saved): void {
                $saved = $entry;
            });

            $controller = new WebhookController(new CaptureWebhook($repo), $repo, null, bin2hex(random_bytes(4)));
            $request = new ServerRequest('POST', '/kapture/test', '10.0.0.1', [], '{"key":"val"}');

            ob_start();
            $controller->handle($request);
            ob_get_clean();

            self::assertSame('corr-123', $saved?->correlationId);
        } finally {
            unset($_SERVER['HTTP_X_KAPTURE_CORRELATION_ID']);
        }
    }

    public function test_capture_without_correlation_header_stores_null(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $saved = null;
        $repo->expects(self::once())->method('save')->willReturnCallback(function (CapturedRequest $entry) use (&$saved): void {
            $saved = $entry;
        });

        $controller = new WebhookController(new CaptureWebhook($repo), $repo, null, bin2hex(random_bytes(4)));
        $request = new ServerRequest('POST', '/kapture/test', '10.0.0.1', [], '{"key":"val"}');

        ob_start();
        $controller->handle($request);
        ob_get_clean();

        self::assertNull($saved?->correlationId);
    }

    public function test_extract_correlation_id_matches_header_case_insensitively(): void
    {
        self::assertSame('corr-1', WebhookController::extractCorrelationId(['X-Kapture-Correlation-Id' => 'corr-1']));
        self::assertSame('corr-2', WebhookController::extractCorrelationId(['x-kapture-correlation-id' => 'corr-2']));
        self::assertSame('corr-3', WebhookController::extractCorrelationId(['X-KAPTURE-CORRELATION-ID' => 'corr-3']));
    }

    public function test_extract_correlation_id_ignores_other_headers(): void
    {
        self::assertNull(WebhookController::extractCorrelationId([
            'Content-Type' => 'application/json',
            'X-Other' => 'value',
        ]));
        self::assertNull(WebhookController::extractCorrelationId([]));
    }

    public function test_extract_correlation_id_trims_and_rejects_empty(): void
    {
        self::assertSame('corr-1', WebhookController::extractCorrelationId(['X-Kapture-Correlation-Id' => '  corr-1  ']));
        self::assertNull(WebhookController::extractCorrelationId(['X-Kapture-Correlation-Id' => '']));
        self::assertNull(WebhookController::extractCorrelationId(['X-Kapture-Correlation-Id' => '   ']));
    }
}

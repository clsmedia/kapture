<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Presentation\Http\WebhookController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookController::class)]
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
}

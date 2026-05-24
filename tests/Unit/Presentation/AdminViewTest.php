<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Application\ListCapturedRequestsResult;
use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\HttpMethod;
use App\Presentation\Html\AdminView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminView::class)]
#[UsesClass(CapturedRequest::class)]
#[UsesClass(CapturedAt::class)]
#[UsesClass(HttpMethod::class)]
#[UsesClass(ListCapturedRequestsResult::class)]
final class AdminViewTest extends TestCase
{
    public function test_json_body_is_pretty_printed(): void
    {
        $entry = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::POST,
            '/test',
            [],
            [],
            '{"event":"order.created","data":{"id":"ord_123","amount":2999}}',
            '127.0.0.1',
            'abc123',
        );

        $result = new ListCapturedRequestsResult([$entry], [], null, 'all files');

        ob_start();
        AdminView::render($result);
        $html = ob_get_clean();

        self::assertStringContainsString('order.created', $html);
        self::assertStringContainsString('ord_123', $html);
        self::assertStringContainsString('2999', $html);
        self::assertStringContainsString('data', $html);
        self::assertStringNotContainsString('{"event":', $html);
    }

    public function test_non_json_body_left_unchanged(): void
    {
        $entry = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::POST,
            '/test',
            [],
            [],
            'plain text body',
            '127.0.0.1',
            'abc123',
        );

        $result = new ListCapturedRequestsResult([$entry], [], null, 'all files');

        ob_start();
        AdminView::render($result);
        $html = ob_get_clean();

        self::assertStringContainsString('plain text body', $html);
    }

    public function test_empty_body_shows_empty_placeholder(): void
    {
        $entry = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::GET,
            '/test',
            [],
            [],
            '',
            '127.0.0.1',
            'abc123',
        );

        $result = new ListCapturedRequestsResult([$entry], [], null, 'all files');

        ob_start();
        AdminView::render($result);
        $html = ob_get_clean();

        self::assertStringContainsString('(empty)', $html);
    }
}

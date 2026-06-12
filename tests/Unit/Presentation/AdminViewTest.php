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

    public function test_single_entry_group_shows_plain_uri(): void
    {
        $entry = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::POST,
            '/my-app/orders/123',
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

        self::assertStringNotContainsString('uri-group', $html);
        self::assertStringNotContainsString('uri-path', $html);
        self::assertStringContainsString('/my-app/orders/123', $html);
    }

    public function test_root_uri_has_no_group(): void
    {
        $entry = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::GET,
            '/',
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

        self::assertStringNotContainsString('uri-group', $html);
        self::assertStringContainsString('class="uri">/', $html);
    }

    public function test_multiple_entries_same_group_shows_group_ui(): void
    {
        $entry1 = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::POST,
            '/webhook/test?source=shopify',
            [],
            [],
            '',
            '127.0.0.1',
            'abc123',
        );
        $entry2 = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::GET,
            '/webhook/ping',
            [],
            [],
            '',
            '127.0.0.1',
            'def456',
        );

        $result = new ListCapturedRequestsResult([$entry1, $entry2], [], null, 'all files');

        ob_start();
        AdminView::render($result);
        $html = ob_get_clean();

        self::assertStringContainsString('uri-group', $html);
        self::assertStringContainsString('data-group="webhook"', $html);
        self::assertStringContainsString('uri-path', $html);
        self::assertStringContainsString('?source=shopify', $html);
        self::assertStringContainsString('/ping', $html);
    }

    public function test_common_query_param_shows_badge(): void
    {
        $entry1 = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::POST,
            '/webhook',
            ['event' => 'charge.completed'],
            [],
            '',
            '127.0.0.1',
            'abc123',
        );
        $entry2 = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::POST,
            '/webhook',
            ['event' => 'charge.completed'],
            [],
            '',
            '127.0.0.1',
            'def456',
        );

        $result = new ListCapturedRequestsResult([$entry1, $entry2], [], null, 'all files');

        ob_start();
        AdminView::render($result);
        $html = ob_get_clean();

        self::assertStringContainsString('uri-qgroup', $html);
        self::assertStringContainsString('data-qgroup="event=charge.completed"', $html);
    }

    public function test_single_entry_with_query_shows_uri_qgroup(): void
    {
        $entry = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::POST,
            '/webhook',
            ['event' => 'charge.completed'],
            [],
            '',
            '127.0.0.1',
            'abc123',
        );

        $result = new ListCapturedRequestsResult([$entry], [], null, 'all files');

        ob_start();
        AdminView::render($result);
        $html = ob_get_clean();

        self::assertStringContainsString('uri-qgroup', $html);
    }

    public function test_data_qgroups_attribute_on_row(): void
    {
        $entry1 = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::POST,
            '/webhook',
            ['event' => 'charge.completed', 'type' => 'payment'],
            [],
            '',
            '127.0.0.1',
            'abc123',
        );
        $entry2 = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::POST,
            '/webhook',
            ['event' => 'charge.completed', 'type' => 'payment'],
            [],
            '',
            '127.0.0.1',
            'def456',
        );

        $result = new ListCapturedRequestsResult([$entry1, $entry2], [], null, 'all files');

        ob_start();
        AdminView::render($result);
        $html = ob_get_clean();

        self::assertStringContainsString('data-qgroups="|event=charge.completed|type=payment|"', $html);
    }

    public function test_data_group_attribute_on_row(): void
    {
        $entry1 = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::GET,
            '/stripe/checkout',
            [],
            [],
            '',
            '127.0.0.1',
            'abc123',
        );
        $entry2 = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::POST,
            '/stripe/webhook',
            [],
            [],
            '',
            '127.0.0.1',
            'def456',
        );

        $result = new ListCapturedRequestsResult([$entry1, $entry2], [], null, 'all files');

        ob_start();
        AdminView::render($result);
        $html = ob_get_clean();

        self::assertStringContainsString('data-group="stripe"', $html);
    }

    public function test_multiple_groups_each_has_own_group_ui(): void
    {
        $entry1 = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::GET,
            '/stripe/invoice',
            [],
            [],
            '',
            '127.0.0.1',
            'abc123',
        );
        $entry2 = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::POST,
            '/stripe/payment',
            [],
            [],
            '',
            '127.0.0.1',
            'def456',
        );
        $entry3 = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::POST,
            '/github/push',
            [],
            [],
            '',
            '127.0.0.1',
            'ghi789',
        );
        $entry4 = new CapturedRequest(
            CapturedAt::now(),
            HttpMethod::GET,
            '/shopify/order',
            [],
            [],
            '',
            '127.0.0.1',
            'jkl012',
        );

        $result = new ListCapturedRequestsResult([$entry1, $entry2, $entry3, $entry4], [], null, 'all files');

        ob_start();
        AdminView::render($result);
        $html = ob_get_clean();

        self::assertStringContainsString('data-group="stripe"', $html);
        self::assertStringNotContainsString('data-group="github"', $html);
        self::assertStringNotContainsString('data-group="shopify"', $html);
    }
}

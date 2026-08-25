<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestCriteria;
use App\Domain\HttpMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CapturedRequestCriteria::class)]
#[CoversClass(CapturedRequest::class)]
final class CapturedRequestCriteriaTest extends TestCase
{
    private function request(
        string $method = 'POST',
        string $uri = '/watering',
        string $captureId = 'c1',
        ?string $correlationId = 'corr-1',
        string $capturedAt = '2026-05-24T12:00:00Z',
    ): CapturedRequest {
        return new CapturedRequest(
            CapturedAt::fromString($capturedAt),
            HttpMethod::tryFromMethod($method) ?? HttpMethod::GET,
            $uri,
            [],
            [],
            '',
            '10.0.0.1',
            $captureId,
            correlationId: $correlationId,
        );
    }
    public function test_defaults_are_all_null(): void
    {
        $criteria = new CapturedRequestCriteria();

        self::assertNull($criteria->captureId);
        self::assertNull($criteria->correlationId);
        self::assertNull($criteria->method);
        self::assertNull($criteria->uri);
        self::assertNull($criteria->capturedAfter);
        self::assertNull($criteria->capturedBefore);
        self::assertNull($criteria->limit);
        self::assertNull($criteria->offset);
        self::assertNull($criteria->order);
    }

    public function test_holds_provided_values(): void
    {
        $after = CapturedAt::fromString('2025-01-01T00:00:00Z');
        $before = CapturedAt::fromString('2025-01-02T00:00:00Z');

        $criteria = new CapturedRequestCriteria(
            captureId: 'c1',
            correlationId: 'corr-1',
            method: HttpMethod::POST,
            uri: '/watering',
            capturedAfter: $after,
            capturedBefore: $before,
            limit: 10,
            offset: 5,
            order: 'desc',
        );

        self::assertSame('c1', $criteria->captureId);
        self::assertSame('corr-1', $criteria->correlationId);
        self::assertSame(HttpMethod::POST, $criteria->method);
        self::assertSame('/watering', $criteria->uri);
        self::assertSame($after, $criteria->capturedAfter);
        self::assertSame($before, $criteria->capturedBefore);
        self::assertSame(10, $criteria->limit);
        self::assertSame(5, $criteria->offset);
        self::assertSame('desc', $criteria->order);
    }

    public function test_holds_offset_value(): void
    {
        $criteria = new CapturedRequestCriteria(offset: 20);

        self::assertSame(20, $criteria->offset);
    }

    public function test_matches_without_filters_accepts_any_capture(): void
    {
        $criteria = new CapturedRequestCriteria();

        self::assertTrue($criteria->matches($this->request()));
    }

    public function test_matches_by_correlation_id(): void
    {
        $criteria = new CapturedRequestCriteria(correlationId: 'corr-1');

        self::assertTrue($criteria->matches($this->request(correlationId: 'corr-1')));
        self::assertFalse($criteria->matches($this->request(correlationId: 'corr-2')));
        self::assertFalse($criteria->matches($this->request(correlationId: null)));
    }

    public function test_matches_by_capture_id_and_method(): void
    {
        $byId = new CapturedRequestCriteria(captureId: 'c1');
        self::assertTrue($byId->matches($this->request(captureId: 'c1')));
        self::assertFalse($byId->matches($this->request(captureId: 'other')));

        $byMethod = new CapturedRequestCriteria(method: HttpMethod::POST);
        self::assertTrue($byMethod->matches($this->request(method: 'POST')));
        self::assertFalse($byMethod->matches($this->request(method: 'GET')));
    }

    public function test_matches_by_uri_substring(): void
    {
        $criteria = new CapturedRequestCriteria(uri: '/watering');

        self::assertTrue($criteria->matches($this->request(uri: '/watering?zone=1')));
        self::assertFalse($criteria->matches($this->request(uri: '/fertilizing')));
    }

    public function test_matches_by_time_range_is_exclusive(): void
    {
        $criteria = new CapturedRequestCriteria(
            capturedAfter: CapturedAt::fromString('2026-05-24T12:00:00Z'),
            capturedBefore: CapturedAt::fromString('2026-05-24T13:00:00Z'),
        );

        self::assertTrue($criteria->matches($this->request(capturedAt: '2026-05-24T12:30:00Z')));
        self::assertFalse($criteria->matches($this->request(capturedAt: '2026-05-24T12:00:00Z')));
        self::assertFalse($criteria->matches($this->request(capturedAt: '2026-05-24T13:00:00Z')));
    }

    public function test_matches_combines_all_filters(): void
    {
        $criteria = new CapturedRequestCriteria(
            correlationId: 'corr-1',
            method: HttpMethod::POST,
            uri: '/watering',
        );

        self::assertTrue($criteria->matches($this->request()));
        self::assertFalse($criteria->matches($this->request(method: 'GET')));
        self::assertFalse($criteria->matches($this->request(correlationId: 'corr-2')));
    }

    public function test_with_search_term_holds_value(): void
    {
        $criteria = (new CapturedRequestCriteria(order: 'desc'))->withSearchTerm('stripe');

        self::assertSame('stripe', $criteria->searchTerm);
        self::assertSame('desc', $criteria->order);
    }

    public function test_with_search_term_normalizes_blank_to_null(): void
    {
        self::assertNull((new CapturedRequestCriteria())->withSearchTerm('')->searchTerm);
        self::assertNull((new CapturedRequestCriteria())->withSearchTerm(null)->searchTerm);
    }

    public function test_matches_by_search_term_in_body_case_insensitive(): void
    {
        $criteria = (new CapturedRequestCriteria())->withSearchTerm('ORDER.CREATED');

        self::assertTrue($criteria->matches($this->searchable(body: '{"event":"order.created"}')));
        self::assertFalse($criteria->matches($this->searchable(body: '{"event":"order.updated"}')));
    }

    public function test_matches_by_search_term_in_header(): void
    {
        $criteria = (new CapturedRequestCriteria())->withSearchTerm('shopify');

        self::assertTrue($criteria->matches($this->searchable(headers: ['User-Agent' => 'Shopify-Hook/1.0'])));
        self::assertFalse($criteria->matches($this->searchable(headers: ['User-Agent' => 'GitHub-Hook/1.0'])));
    }

    public function test_matches_by_search_term_in_query_value(): void
    {
        $criteria = (new CapturedRequestCriteria())->withSearchTerm('github');

        self::assertTrue($criteria->matches($this->searchable(query: ['source' => 'github'])));
        self::assertFalse($criteria->matches($this->searchable(query: ['source' => 'gitlab'])));
    }

    public function test_matches_by_search_term_in_query_pair_via_uri(): void
    {
        $criteria = (new CapturedRequestCriteria())->withSearchTerm('source=github');

        self::assertTrue($criteria->matches($this->searchable(uri: '/hook?source=github')));
        self::assertFalse($criteria->matches($this->searchable(uri: '/hook?source=gitlab')));
    }

    public function test_matches_by_search_term_in_uri_and_ip(): void
    {
        $byUri = (new CapturedRequestCriteria())->withSearchTerm('/watering');
        self::assertTrue($byUri->matches($this->searchable(uri: '/watering?zone=1')));
        self::assertFalse($byUri->matches($this->searchable(uri: '/fertilizing')));

        $byIp = (new CapturedRequestCriteria())->withSearchTerm('203.0.113');
        self::assertTrue($byIp->matches($this->searchable(ip: '203.0.113.42')));
        self::assertFalse($byIp->matches($this->searchable(ip: '10.0.0.1')));
    }

    public function test_null_search_term_accepts_any_capture(): void
    {
        $criteria = new CapturedRequestCriteria();

        self::assertTrue($criteria->matches($this->searchable()));
    }

    private function searchable(
        string $uri = '/hook',
        array $query = [],
        array $headers = [],
        string $body = '',
        string $ip = '10.0.0.1',
    ): CapturedRequest {
        return new CapturedRequest(
            CapturedAt::fromString('2026-05-24T12:00:00Z'),
            HttpMethod::POST,
            $uri,
            $query,
            $headers,
            $body,
            $ip,
            'probe-1',
        );
    }
}

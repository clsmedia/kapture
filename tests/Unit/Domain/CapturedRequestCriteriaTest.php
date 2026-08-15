<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\CapturedAt;
use App\Domain\CapturedRequestCriteria;
use App\Domain\HttpMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CapturedRequestCriteria::class)]
final class CapturedRequestCriteriaTest extends TestCase
{
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
        );

        self::assertSame('c1', $criteria->captureId);
        self::assertSame('corr-1', $criteria->correlationId);
        self::assertSame(HttpMethod::POST, $criteria->method);
        self::assertSame('/watering', $criteria->uri);
        self::assertSame($after, $criteria->capturedAfter);
        self::assertSame($before, $criteria->capturedBefore);
        self::assertSame(10, $criteria->limit);
    }
}

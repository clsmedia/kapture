<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\CapturedAt;
use App\Domain\PatternType;
use App\Domain\RecurringPattern;
use App\Domain\RecurringReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecurringReport::class)]
#[CoversClass(RecurringPattern::class)]
final class RecurringReportTest extends TestCase
{
    public function test_construction(): void
    {
        $runAt = CapturedAt::fromString('2026-09-08T07:00:00Z');
        $report = new RecurringReport(
            runAt: $runAt,
            windowDays: 7,
            scannedEntries: 120,
            periodicPatterns: [],
            topOffenders: [],
        );

        self::assertSame($runAt, $report->runAt);
        self::assertSame(7, $report->windowDays);
        self::assertSame(120, $report->scannedEntries);
        self::assertSame([], $report->periodicPatterns);
        self::assertSame([], $report->topOffenders);
    }

    public function test_to_array_with_patterns(): void
    {
        $runAt = CapturedAt::fromString('2026-09-08T07:00:00Z');
        $first = CapturedAt::fromString('2026-09-01T10:00:00Z');
        $last  = CapturedAt::fromString('2026-09-07T10:00:00Z');

        $periodic = new RecurringPattern(
            fingerprint: 'GET /health 10.0.0.1',
            type: PatternType::PERIODIC,
            occurrences: 7,
            periodSeconds: 3600,
            suggestedCron: '0 * * * *',
            firstSeen: $first,
            lastSeen: $last,
        );
        $offender = new RecurringPattern(
            fingerprint: 'POST /api 10.0.0.2',
            type: PatternType::OFFENDER,
            occurrences: 30,
            periodSeconds: null,
            suggestedCron: null,
            firstSeen: $first,
            lastSeen: $last,
        );

        $report = new RecurringReport(
            runAt: $runAt,
            windowDays: 7,
            scannedEntries: 50,
            periodicPatterns: [$periodic],
            topOffenders: [$offender],
        );

        $arr = $report->toArray();

        self::assertSame('2026-09-08T07:00:00Z', $arr['runAt']);
        self::assertSame(7, $arr['windowDays']);
        self::assertSame(50, $arr['scannedEntries']);
        self::assertCount(1, $arr['periodicPatterns']);
        self::assertCount(1, $arr['topOffenders']);
        self::assertSame('GET /health 10.0.0.1', $arr['periodicPatterns'][0]['fingerprint']);
    }

    public function test_to_json_is_valid(): void
    {
        $runAt = CapturedAt::fromString('2026-09-08T07:00:00Z');
        $report = new RecurringReport(
            runAt: $runAt,
            windowDays: 7,
            scannedEntries: 0,
            periodicPatterns: [],
            topOffenders: [],
        );

        $json = $report->toJson();
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('2026-09-08T07:00:00Z', $decoded['runAt']);
        self::assertSame(7, $decoded['windowDays']);
        self::assertSame(0, $decoded['scannedEntries']);
        self::assertSame([], $decoded['periodicPatterns']);
        self::assertSame([], $decoded['topOffenders']);
    }
}

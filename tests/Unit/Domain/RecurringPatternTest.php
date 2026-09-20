<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\CapturedAt;
use App\Domain\PatternType;
use App\Domain\RecurringPattern;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecurringPattern::class)]
#[CoversClass(PatternType::class)]
final class RecurringPatternTest extends TestCase
{
    public function test_construction_with_all_fields(): void
    {
        $first = CapturedAt::fromString('2026-09-01T10:00:00Z');
        $last  = CapturedAt::fromString('2026-09-07T10:00:00Z');

        $pattern = new RecurringPattern(
            fingerprint: 'GET /status 10.0.0.1',
            type: PatternType::PERIODIC,
            occurrences: 14,
            periodSeconds: 3600,
            suggestedCron: '0 * * * *',
            firstSeen: $first,
            lastSeen: $last,
        );

        self::assertSame('GET /status 10.0.0.1', $pattern->fingerprint);
        self::assertSame(PatternType::PERIODIC, $pattern->type);
        self::assertSame(14, $pattern->occurrences);
        self::assertSame(3600, $pattern->periodSeconds);
        self::assertSame('0 * * * *', $pattern->suggestedCron);
        self::assertSame($first, $pattern->firstSeen);
        self::assertSame($last, $pattern->lastSeen);
    }

    public function test_offender_has_null_period_and_cron(): void
    {
        $now = CapturedAt::fromString('2026-09-07T12:00:00Z');

        $pattern = new RecurringPattern(
            fingerprint: 'POST /api/events 10.0.0.1',
            type: PatternType::OFFENDER,
            occurrences: 42,
            periodSeconds: null,
            suggestedCron: null,
            firstSeen: $now,
            lastSeen: $now,
        );

        self::assertNull($pattern->periodSeconds);
        self::assertNull($pattern->suggestedCron);
    }

    public function test_to_array_contains_all_fields(): void
    {
        $first = CapturedAt::fromString('2026-09-01T08:00:00Z');
        $last  = CapturedAt::fromString('2026-09-07T08:00:00Z');

        $pattern = new RecurringPattern(
            fingerprint: 'GET /health 10.0.0.1',
            type: PatternType::DAILY,
            occurrences: 7,
            periodSeconds: 86400,
            suggestedCron: '0 8 * * *',
            firstSeen: $first,
            lastSeen: $last,
        );

        $arr = $pattern->toArray();

        self::assertSame('GET /health 10.0.0.1', $arr['fingerprint']);
        self::assertSame('daily', $arr['type']);
        self::assertSame(7, $arr['occurrences']);
        self::assertSame(86400, $arr['periodSeconds']);
        self::assertSame('0 8 * * *', $arr['suggestedCron']);
        self::assertSame('2026-09-01T08:00:00Z', $arr['firstSeen']);
        self::assertSame('2026-09-07T08:00:00Z', $arr['lastSeen']);
    }

    public function test_to_json_is_valid_json(): void
    {
        $now = CapturedAt::fromString('2026-09-07T12:00:00Z');

        $pattern = new RecurringPattern(
            fingerprint: 'POST /hook 1.2.3.4',
            type: PatternType::OFFENDER,
            occurrences: 5,
            periodSeconds: null,
            suggestedCron: null,
            firstSeen: $now,
            lastSeen: $now,
        );

        $json = $pattern->toJson();
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('POST /hook 1.2.3.4', $decoded['fingerprint']);
        self::assertSame('offender', $decoded['type']);
        self::assertNull($decoded['periodSeconds']);
    }

    public function test_from_array_round_trip(): void
    {
        $data = [
            'fingerprint'   => 'POST /api 10.0.0.1',
            'type'          => 'periodic',
            'occurrences'   => 10,
            'periodSeconds' => 300,
            'suggestedCron' => '*/5 * * * *',
            'firstSeen'     => '2026-09-01T12:00:00Z',
            'lastSeen'      => '2026-09-07T12:00:00Z',
        ];

        $pattern = RecurringPattern::fromArray($data);
        $roundTripped = $pattern->toArray();

        self::assertSame($data, $roundTripped);
    }
}

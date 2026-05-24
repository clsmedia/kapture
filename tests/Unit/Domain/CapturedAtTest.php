<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\CapturedAt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CapturedAt::class)]
final class CapturedAtTest extends TestCase
{
    #[TestDox('now() creates a CapturedAt for the current moment in UTC')]
    public function test_now(): void
    {
        $before = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();
        $captured = CapturedAt::now();
        $after = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();

        $ts = $captured->toDateTimeImmutable()->getTimestamp();
        self::assertGreaterThanOrEqual($before, $ts);
        self::assertLessThanOrEqual($after, $ts);
    }

    #[TestDox('now() returns UTC timezone')]
    public function test_now_timezone(): void
    {
        $captured = CapturedAt::now();
        self::assertSame('UTC', $captured->toDateTimeImmutable()->getTimezone()->getName());
    }

    #[TestDox('fromString parses ISO8601 Z-suffixed format')]
    public function test_from_string_iso8601(): void
    {
        $captured = CapturedAt::fromString('2026-05-23T14:30:00Z');
        self::assertSame('2026-05-23T14:30:00Z', $captured->toIso8601());
        self::assertSame('UTC', $captured->toDateTimeImmutable()->getTimezone()->getName());
    }

    #[TestDox('fromString parses other date formats by converting to UTC')]
    public function test_from_string_other_format(): void
    {
        $captured = CapturedAt::fromString('2026-05-23 14:30:00');
        self::assertSame('2026-05-23T14:30:00Z', $captured->toIso8601());
    }

    #[TestDox('fromString with non-UTC input converts to UTC')]
    public function test_from_string_with_timezone_offset(): void
    {
        $captured = CapturedAt::fromString('2026-05-23T14:30:00+02:00');
        // 14:30 +02:00 → 12:30 UTC
        self::assertSame('2026-05-23T12:30:00Z', $captured->toIso8601());
    }

    #[TestDox('fromString throws on garbage input')]
    public function test_from_string_garbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CapturedAt::fromString('not-a-date');
    }

    #[TestDox('fromString throws on empty string')]
    public function test_from_string_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CapturedAt::fromString('');
    }

    #[TestDox('fromDateTime normalizes to UTC')]
    public function test_from_date_time_normalizes_to_utc(): void
    {
        $nyTz = new \DateTimeZone('America/New_York');
        $dt = new \DateTimeImmutable('2026-05-23 14:30:00', $nyTz);
        $captured = CapturedAt::fromDateTime($dt);

        self::assertSame('UTC', $captured->toDateTimeImmutable()->getTimezone()->getName());
        // 14:30 EDT → 18:30 UTC
        self::assertSame('2026-05-23T18:30:00Z', $captured->toIso8601());
    }

    #[TestDox('toIso8601 always outputs Z-suffixed UTC')]
    public function test_to_iso8601_format(): void
    {
        $captured = CapturedAt::fromString('2026-05-23T14:30:00Z');
        $iso = $captured->toIso8601();
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $iso);
        self::assertStringEndsWith('Z', $iso);
    }

    #[TestDox('__toString returns ISO8601 string')]
    public function test_to_string(): void
    {
        $captured = CapturedAt::fromString('2026-05-23T14:30:00Z');
        self::assertSame('2026-05-23T14:30:00Z', (string) $captured);
    }

    #[TestDox('toDateTimeImmutable returns a UTC instance')]
    public function test_returns_utc(): void
    {
        $dt = new \DateTimeImmutable('2026-05-23 14:30:00', new \DateTimeZone('Europe/Warsaw'));
        $captured = CapturedAt::fromDateTime($dt);
        self::assertSame('UTC', $captured->toDateTimeImmutable()->getTimezone()->getName());
    }

    #[TestDox('toString round-trips through fromString')]
    public function test_round_trip(): void
    {
        $original = CapturedAt::now();
        $string = (string) $original;
        $restored = CapturedAt::fromString($string);

        self::assertEquals(
            $original->toDateTimeImmutable()->getTimestamp(),
            $restored->toDateTimeImmutable()->getTimestamp(),
        );
    }

    #[TestDox('fromTimestamp creates CapturedAt from Unix timestamp')]
    public function test_from_timestamp(): void
    {
        $ts = 1716489000;
        $captured = CapturedAt::fromTimestamp($ts);
        self::assertSame($ts, $captured->toTimestamp());
        self::assertSame('UTC', $captured->toDateTimeImmutable()->getTimezone()->getName());
    }

    #[TestDox('toTimestamp returns Unix timestamp')]
    public function test_to_timestamp(): void
    {
        $captured = CapturedAt::fromString('2026-05-23T14:30:00Z');
        $dt = $captured->toDateTimeImmutable();
        self::assertSame($dt->getTimestamp(), $captured->toTimestamp());
    }

    #[TestDox('fromTimestamp round-trips through toTimestamp')]
    public function test_timestamp_round_trip(): void
    {
        $original = CapturedAt::now();
        $ts = $original->toTimestamp();
        $restored = CapturedAt::fromTimestamp($ts);
        self::assertSame($ts, $restored->toTimestamp());
    }

    #[TestDox('two CapturedAt instances with same time have same timestamp')]
    public function test_equality(): void
    {
        $a = CapturedAt::fromString('2026-05-23T14:30:00Z');
        $b = CapturedAt::fromString('2026-05-23T14:30:00Z');

        self::assertSame($a->toIso8601(), $b->toIso8601());
        self::assertSame(
            $a->toDateTimeImmutable()->getTimestamp(),
            $b->toDateTimeImmutable()->getTimestamp(),
        );
    }
}

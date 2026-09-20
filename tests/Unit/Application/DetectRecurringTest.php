<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\DetectRecurring;
use App\Domain\CapturedRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DetectRecurring::class)]
final class DetectRecurringTest extends TestCase
{
    public function test_empty_entries_returns_empty_report(): void
    {
        $analyzer = new DetectRecurring();
        $report = $analyzer->analyze([], 7);

        self::assertSame(0, $report->scannedEntries);
        self::assertSame(7, $report->windowDays);
        self::assertSame([], $report->periodicPatterns);
        self::assertSame([], $report->topOffenders);
    }

    public function test_single_entry_is_neither_periodic_nor_offender(): void
    {
        $entries = [$this->makeEntry('POST', '/hook', '10.0.0.1', '2026-09-01T10:00:00Z')];
        $report = (new DetectRecurring())->analyze($entries, 7);

        self::assertSame(1, $report->scannedEntries);
        self::assertSame([], $report->periodicPatterns);
        // Offenders require ≥ 3 entries
        self::assertSame([], $report->topOffenders);
    }

    public function test_two_entries_are_not_enough_for_periodic(): void
    {
        $entries = [
            $this->makeEntry('GET', '/status', '10.0.0.1', '2026-09-01T10:00:00Z'),
            $this->makeEntry('GET', '/status', '10.0.0.1', '2026-09-01T11:00:00Z'),
        ];
        $report = (new DetectRecurring())->analyze($entries, 7);

        self::assertSame([], $report->periodicPatterns);
    }

    public function test_regular_hourly_pattern_is_detected(): void
    {
        // 7 entries, exactly 1 hour apart
        $entries = [];
        foreach (range(0, 6) as $i) {
            $ts = sprintf('2026-09-01T%02d:00:00Z', $i);
            $entries[] = $this->makeEntry('GET', '/status', '10.0.0.1', $ts);
        }
        $report = (new DetectRecurring())->analyze($entries, 7);

        self::assertCount(1, $report->periodicPatterns);
        $pattern = $report->periodicPatterns[0];
        self::assertSame('GET /status 10.0.0.1', $pattern->fingerprint);
        self::assertSame(3600, $pattern->periodSeconds);
        self::assertSame(7, $pattern->occurrences);
        self::assertSame('0 * * * *', $pattern->suggestedCron);
    }

    public function test_regular_5_minute_pattern_suggests_correct_cron(): void
    {
        $entries = [];
        foreach (range(0, 9) as $i) {
            $ts = date('Y-m-d\TH:i:s\Z', strtotime('2026-09-01T12:00:00Z') + ($i * 300));
            $entries[] = $this->makeEntry('GET', '/ping', '10.0.0.1', $ts);
        }
        $report = (new DetectRecurring())->analyze($entries, 7);

        self::assertCount(1, $report->periodicPatterns);
        self::assertSame(300, $report->periodicPatterns[0]->periodSeconds);
        self::assertSame('*/5 * * * *', $report->periodicPatterns[0]->suggestedCron);
    }

    public function test_irregular_pattern_is_not_detected_as_periodic(): void
    {
        // Gaps: 1h, 3h, 1h, 5h → very irregular
        $offsets = [0, 3600, 14400, 18000, 36000]; // 0, 1h, 4h, 5h, 10h
        $entries = [];
        foreach ($offsets as $s) {
            $ts = date('Y-m-d\TH:i:s\Z', strtotime('2026-09-01T00:00:00Z') + $s);
            $entries[] = $this->makeEntry('POST', '/api', '10.0.0.1', $ts);
        }
        $report = (new DetectRecurring())->analyze($entries, 7);

        self::assertSame([], $report->periodicPatterns);
    }

    public function test_pattern_with_small_tolerance_variations_is_detected(): void
    {
        // 5 entries, ~3600s gaps with ±5% jitter (within 20% tolerance)
        $base = strtotime('2026-09-01T00:00:00Z');
        $gaps = [3500, 3700, 3600, 3550, 3650];
        $entries = [];
        $ts = $base;
        for ($i = 0; $i < 6; $i++) {
            $entries[] = $this->makeEntry('GET', '/health', '10.0.0.1', date('Y-m-d\TH:i:s\Z', $ts));
            $ts += $gaps[$i] ?? 3600;
        }
        $report = (new DetectRecurring())->analyze($entries, 7);

        self::assertCount(1, $report->periodicPatterns);
        self::assertSame(3600, $report->periodicPatterns[0]->periodSeconds);
    }

    public function test_daily_same_hour_pattern_detected_even_with_non_uniform_gaps(): void
    {
        // Same time on 5 consecutive days — gaps are ~86400 but may vary by hours
        // so this is detected by the daily detector, not necessarily by the periodic one
        $entries = [
            $this->makeEntry('POST', '/cron', '10.0.0.1', '2026-09-01T08:00:00Z'),
            $this->makeEntry('POST', '/cron', '10.0.0.1', '2026-09-02T08:05:00Z'), // +5min within daily window
            $this->makeEntry('POST', '/cron', '10.0.0.1', '2026-09-03T08:10:00Z'), // +10min
            $this->makeEntry('POST', '/cron', '10.0.0.1', '2026-09-04T08:02:00Z'),
            $this->makeEntry('POST', '/cron', '10.0.0.1', '2026-09-05T08:08:00Z'),
        ];
        $report = (new DetectRecurring())->analyze($entries, 7);

        // Should be detected as daily at ~08:00 UTC
        $dailyPatterns = array_filter(
            $report->periodicPatterns,
            static fn($p): bool => $p->suggestedCron !== null && str_contains((string) $p->suggestedCron, '* * *'),
        );
        self::assertNotEmpty($dailyPatterns, 'Expected a daily pattern to be detected');
    }

    public function test_daily_requires_entries_on_different_days(): void
    {
        // 4 entries on the same day, same hour → not daily
        $entries = [
            $this->makeEntry('POST', '/hook', '10.0.0.1', '2026-09-01T10:00:00Z'),
            $this->makeEntry('POST', '/hook', '10.0.0.1', '2026-09-01T10:05:00Z'),
            $this->makeEntry('POST', '/hook', '10.0.0.1', '2026-09-01T10:10:00Z'),
            $this->makeEntry('POST', '/hook', '10.0.0.1', '2026-09-01T10:15:00Z'),
        ];
        $report = (new DetectRecurring())->analyze($entries, 7);

        // Might be detected as 5-min periodic, but NOT daily
        foreach ($report->periodicPatterns as $p) {
            self::assertNotSame('daily', $p->type->value);
        }
    }

    public function test_offender_tracks_first_and_last_seen(): void
    {
        $entries = [
            $this->makeEntry('GET', '/poll', '10.0.0.1', '2026-09-01T10:00:00Z'),
            $this->makeEntry('GET', '/poll', '10.0.0.1', '2026-09-01T11:00:00Z'),
            $this->makeEntry('GET', '/poll', '10.0.0.1', '2026-09-01T12:00:00Z'),
        ];
        $report = (new DetectRecurring())->analyze($entries, 7);

        self::assertCount(1, $report->topOffenders);
        self::assertSame('2026-09-01T10:00:00Z', $report->topOffenders[0]->firstSeen->toIso8601());
        self::assertSame('2026-09-01T12:00:00Z', $report->topOffenders[0]->lastSeen->toIso8601());
    }

    public function test_daily_pattern_has_daily_type(): void
    {
        $entries = [
            $this->makeEntry('POST', '/job', '10.0.0.1', '2026-09-01T08:00:00Z'),
            $this->makeEntry('POST', '/job', '10.0.0.1', '2026-09-02T08:00:00Z'),
            $this->makeEntry('POST', '/job', '10.0.0.1', '2026-09-03T08:00:00Z'),
        ];
        $report = (new DetectRecurring())->analyze($entries, 7);

        self::assertCount(1, $report->periodicPatterns);
        self::assertSame('daily', $report->periodicPatterns[0]->type->value);
    }

    public function test_top_offenders_returns_top_10_by_count(): void
    {
        $entries = [];
        // 10 different fingerprints with varying counts
        foreach (range(1, 10) as $n) {
            $count = $n * 5; // 5, 10, 15, ..., 50
            for ($i = 0; $i < $count; $i++) {
                $entries[] = $this->makeEntry('GET', "/endpoint-$n", '10.0.0.1', '2026-09-01T00:00:00Z');
            }
        }
        $report = (new DetectRecurring())->analyze($entries, 7);

        self::assertCount(10, $report->topOffenders);
        // Descending order
        for ($i = 0; $i < 9; $i++) {
            self::assertGreaterThanOrEqual(
                $report->topOffenders[$i + 1]->occurrences,
                $report->topOffenders[$i]->occurrences,
            );
        }
        self::assertSame(50, $report->topOffenders[0]->occurrences);
    }

    public function test_fingerprint_includes_ip_so_different_ips_are_separate(): void
    {
        $entries = [
            $this->makeEntry('GET', '/status', '10.0.0.1', '2026-09-01T10:00:00Z'),
            $this->makeEntry('GET', '/status', '10.0.0.1', '2026-09-01T11:00:00Z'),
            $this->makeEntry('GET', '/status', '10.0.0.1', '2026-09-01T12:00:00Z'),
            $this->makeEntry('GET', '/status', '10.0.0.2', '2026-09-01T10:00:00Z'),
            $this->makeEntry('GET', '/status', '10.0.0.2', '2026-09-01T11:00:00Z'),
            $this->makeEntry('GET', '/status', '10.0.0.2', '2026-09-01T12:00:00Z'),
        ];
        $report = (new DetectRecurring())->analyze($entries, 7);

        // Two separate fingerprints, each with 3 entries → 2 periodic patterns
        self::assertCount(2, $report->periodicPatterns);
        self::assertSame('GET /status 10.0.0.1', $report->periodicPatterns[0]->fingerprint);
        self::assertSame('GET /status 10.0.0.2', $report->periodicPatterns[1]->fingerprint);
    }

    public function test_suggested_cron_for_daily_at_8am(): void
    {
        $entries = [
            $this->makeEntry('POST', '/job', '10.0.0.1', '2026-09-01T08:00:00Z'),
            $this->makeEntry('POST', '/job', '10.0.0.1', '2026-09-02T08:00:00Z'),
            $this->makeEntry('POST', '/job', '10.0.0.1', '2026-09-03T08:00:00Z'),
        ];
        $report = (new DetectRecurring())->analyze($entries, 7);

        self::assertCount(1, $report->periodicPatterns);
        self::assertSame(86400, $report->periodicPatterns[0]->periodSeconds);
        self::assertSame('0 8 * * *', $report->periodicPatterns[0]->suggestedCron);
    }

    public function test_min_occurrences_boundary_exactly_three(): void
    {
        // Exactly 3 entries, regular → detected
        $entries = [
            $this->makeEntry('GET', '/r', '10.0.0.1', '2026-09-01T00:00:00Z'),
            $this->makeEntry('GET', '/r', '10.0.0.1', '2026-09-01T01:00:00Z'),
            $this->makeEntry('GET', '/r', '10.0.0.1', '2026-09-01T02:00:00Z'),
        ];
        $report = (new DetectRecurring())->analyze($entries, 7);

        self::assertCount(1, $report->periodicPatterns);
    }

    private function makeEntry(string $method, string $uri, string $ip, string $capturedAt): CapturedRequest
    {
        return CapturedRequest::fromArray([
            'capturedAt' => $capturedAt,
            'method'     => $method,
            'uri'        => $uri,
            'query'      => [],
            'headers'    => [],
            'body'       => '',
            'ip'         => $ip,
            'captureId'  => bin2hex(random_bytes(16)),
        ]);
    }
}

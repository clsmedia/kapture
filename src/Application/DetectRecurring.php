<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\PatternType;
use App\Domain\RecurringPattern;
use App\Domain\RecurringReport;

final readonly class DetectRecurring
{
    private const MIN_OCCURRENCES = 3;
    private const PERIOD_TOLERANCE = 0.20;
    private const TOP_OFFENDERS = 10;
    private const DAILY_MIN_DAYS = 2;
    private const DAILY_BUCKET_SECONDS = 300;

    /**
     * @param CapturedRequest[] $entries
     */
    public function analyze(array $entries, int $windowDays, ?\DateTimeImmutable $now = null): RecurringReport
    {
        $runAt = $now !== null
            ? CapturedAt::fromDateTime($now)
            : CapturedAt::now();

        if ($entries === []) {
            return new RecurringReport(
                runAt: $runAt,
                windowDays: $windowDays,
                scannedEntries: 0,
                periodicPatterns: [],
                topOffenders: [],
            );
        }

        $groups = $this->groupByFingerprint($entries);

        $periodicPatterns = [];
        $allCandidateGroups = [];

        foreach ($groups as $fingerprint => $groupEntries) {
            if (count($groupEntries) < self::MIN_OCCURRENCES) {
                continue;
            }
            $allCandidateGroups[$fingerprint] = $groupEntries;

            $daily = $this->detectDailyPattern($fingerprint, $groupEntries);
            if ($daily !== null) {
                $periodicPatterns[] = $daily;
                continue;
            }

            $periodic = $this->detectPeriodicPattern($fingerprint, $groupEntries);
            if ($periodic !== null) {
                $periodicPatterns[] = $periodic;
            }
        }

        usort($periodicPatterns, static fn(RecurringPattern $a, RecurringPattern $b): int =>
            $b->occurrences <=> $a->occurrences,
        );

        $topOffenders = $this->computeTopOffenders($allCandidateGroups);

        return new RecurringReport(
            runAt: $runAt,
            windowDays: $windowDays,
            scannedEntries: count($entries),
            periodicPatterns: $periodicPatterns,
            topOffenders: $topOffenders,
        );
    }

    /**
     * @param CapturedRequest[] $entries
     * @return array<string, CapturedRequest[]>
     */
    private function groupByFingerprint(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $fp = self::computeFingerprint($entry);
            $groups[$fp][] = $entry;
        }
        return $groups;
    }

    private static function computeFingerprint(CapturedRequest $entry): string
    {
        return $entry->method->value . ' ' . $entry->uri . ' ' . $entry->ip;
    }

    /**
     * @param CapturedRequest[] $entries
     */
    private function detectPeriodicPattern(string $fingerprint, array $entries): ?RecurringPattern
    {
        $timestamps = $this->extractTimestamps($entries);
        sort($timestamps);

        $gaps = $this->computeGaps($timestamps);
        if ($gaps === []) {
            return null;
        }

        $median = $this->median($gaps);

        if ($median <= 0) {
            return null;
        }

        foreach ($gaps as $gap) {
            if (abs($gap - $median) / $median > self::PERIOD_TOLERANCE) {
                return null;
            }
        }

        $period = (int) round($median);

        $firstSeen = CapturedAt::fromTimestamp((int) $timestamps[0]);
        $lastSeen  = CapturedAt::fromTimestamp((int) end($timestamps));

        return new RecurringPattern(
            fingerprint: $fingerprint,
            type: PatternType::PERIODIC,
            occurrences: count($entries),
            periodSeconds: $period,
            suggestedCron: self::suggestCron($period),
            firstSeen: $firstSeen,
            lastSeen: $lastSeen,
        );
    }

    /**
     * @param CapturedRequest[] $entries
     */
    private function detectDailyPattern(string $fingerprint, array $entries): ?RecurringPattern
    {
        $buckets = [];
        foreach ($entries as $entry) {
            $dt = $entry->capturedAt->toDateTimeImmutable();
            $day = $dt->format('Y-m-d');
            $minuteOfDay = (int) $dt->format('H') * 60 + (int) $dt->format('i');
            $bucketKey = (int) round($minuteOfDay / (self::DAILY_BUCKET_SECONDS / 60)) * (self::DAILY_BUCKET_SECONDS / 60);

            $buckets[$bucketKey]['days'][$day] = true;
            $buckets[$bucketKey]['minuteOfDay'] = $bucketKey;
        }

        foreach ($buckets as $bucket) {
            $distinctDays = count($bucket['days']);
            if ($distinctDays >= self::DAILY_MIN_DAYS) {
                $minuteOfDay = (int) $bucket['minuteOfDay'];
                $hour = intdiv($minuteOfDay, 60);
                $minute = $minuteOfDay % 60;
                $cron = sprintf('%d %d * * *', $minute, $hour);
                $period = 86400;

                $timestamps = $this->extractTimestamps($entries);
                sort($timestamps);

                return new RecurringPattern(
                    fingerprint: $fingerprint,
                    type: PatternType::DAILY,
                    occurrences: count($entries),
                    periodSeconds: $period,
                    suggestedCron: $cron,
                    firstSeen: CapturedAt::fromTimestamp((int) $timestamps[0]),
                    lastSeen: CapturedAt::fromTimestamp((int) end($timestamps)),
                );
            }
        }

        return null;
    }

    /**
     * @param CapturedRequest[] $entries
     * @return int[]
     */
    private function extractTimestamps(array $entries): array
    {
        return array_map(
            static fn(CapturedRequest $e): int => $e->capturedAt->toTimestamp(),
            $entries,
        );
    }

    /**
     * @param int[] $timestamps sorted ascending
     * @return int[]
     */
    private function computeGaps(array $timestamps): array
    {
        $gaps = [];
        for ($i = 1, $c = count($timestamps); $i < $c; $i++) {
            $gaps[] = $timestamps[$i] - $timestamps[$i - 1];
        }
        return $gaps;
    }

    /**
     * @param int[] $values
     */
    private function median(array $values): float
    {
        $sorted = $values;
        sort($sorted);
        $n = count($sorted);
        $mid = intdiv($n, 2);
        if ($n % 2 === 0) {
            return ($sorted[$mid - 1] + $sorted[$mid]) / 2.0;
        }
        return (float) $sorted[$mid];
    }

    private static function suggestCron(int $periodSeconds): string
    {
        if ($periodSeconds >= 86400 && $periodSeconds % 86400 === 0) {
            return '0 0 * * *';
        }
        if ($periodSeconds >= 3600 && $periodSeconds % 3600 === 0) {
            $hours = intdiv($periodSeconds, 3600);
            if ($hours === 1) {
                return '0 * * * *';
            }
            return "0 */{$hours} * * *";
        }
        if ($periodSeconds >= 60 && $periodSeconds % 60 === 0) {
            $minutes = intdiv($periodSeconds, 60);
            if ($minutes === 1) {
                return '* * * * *';
            }
            return "*/{$minutes} * * * *";
        }
        return "every {$periodSeconds}s";
    }

    /**
     * @param array<string, CapturedRequest[]> $groups
     * @return RecurringPattern[]
     */
    private function computeTopOffenders(array $groups): array
    {
        $ranked = [];
        foreach ($groups as $fingerprint => $groupEntries) {
            $timestamps = $this->extractTimestamps($groupEntries);
            sort($timestamps);

            $ranked[] = new RecurringPattern(
                fingerprint: $fingerprint,
                type: PatternType::OFFENDER,
                occurrences: count($groupEntries),
                periodSeconds: null,
                suggestedCron: null,
                firstSeen: CapturedAt::fromTimestamp((int) $timestamps[0]),
                lastSeen: CapturedAt::fromTimestamp((int) end($timestamps)),
            );
        }

        usort($ranked, static fn(RecurringPattern $a, RecurringPattern $b): int =>
            $b->occurrences <=> $a->occurrences,
        );

        return array_slice($ranked, 0, self::TOP_OFFENDERS);
    }
}

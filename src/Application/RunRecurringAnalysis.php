<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedAt;
use App\Domain\CapturedRequestRepository;
use App\Domain\RecurringReport;

final readonly class RunRecurringAnalysis
{
    private const INTERVAL_SECONDS = 86400;
    private const STATE_FILENAME = 'recurring-state.json';
    private const LOCK_FILENAME = '.recurring.lock';
    private const REPORT_FILENAME = 'recurring-report.jsonl';
    private const PERIOD_CHANGE_TOLERANCE = 0.20;

    public function __construct(
        private CapturedRequestRepository $repository,
        private string $logDir,
        private int $windowDays,
    ) {
    }

    /** @phpstan-impure */
    public function isDue(\DateTimeImmutable $now): bool
    {
        $lastRun = $this->readLastRunAt();

        if ($lastRun === null) {
            return true;
        }

        return ($now->getTimestamp() - $lastRun->getTimestamp()) >= self::INTERVAL_SECONDS;
    }

    /**
     * Read the authoritative last-run timestamp from the state file. A
     * missing, empty, unreadable or corrupt state does not count as a run —
     * otherwise a stale zero-byte file would silently block analysis for a
     * full interval.
     */
    private function readLastRunAt(): ?\DateTimeImmutable
    {
        $statePath = $this->logDir . '/' . self::STATE_FILENAME;

        if (!is_file($statePath)) {
            return null;
        }

        $content = @file_get_contents($statePath);
        if ($content === false || trim($content) === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['lastRunAt']) || !is_string($data['lastRunAt'])) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $data['lastRunAt'], new \DateTimeZone('UTC'));

        return $parsed !== false ? $parsed : null;
    }

    public function run(\DateTimeImmutable $now): ?RecurringReport
    {
        if (!$this->isDue($now)) {
            return null;
        }

        $statePath = $this->logDir . '/' . self::STATE_FILENAME;
        $lockPath = $this->logDir . '/' . self::LOCK_FILENAME;
        $fp = fopen($lockPath, 'c');
        if ($fp === false) {
            error_log('Kapture: recurring analysis — cannot open lock file');
            return null;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            error_log('Kapture: recurring analysis — another process is running, skipping');
            return null;
        }

        // Re-check after acquiring lock (state may have been refreshed by another process)
        if (!$this->isDue($now)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return null;
        }

        try {
            $cutoff = $now->modify("-{$this->windowDays} days");
            $criteria = new \App\Domain\CapturedRequestCriteria(
                capturedAfter: CapturedAt::fromDateTime($cutoff),
                order: 'asc',
            );
            $entries = $this->repository->findByCriteria($criteria);

            $analyzer = new DetectRecurring();
            $report = $analyzer->analyze($entries, $this->windowDays, $now);

            $previousPatterns = $this->loadPreviousPatterns($statePath);
            $newOrChanged = $this->filterNewOrChanged($report->periodicPatterns, $previousPatterns);

            $reportWithChanges = new \App\Domain\RecurringReport(
                runAt: $report->runAt,
                windowDays: $report->windowDays,
                scannedEntries: $report->scannedEntries,
                periodicPatterns: $newOrChanged,
                topOffenders: $report->topOffenders,
            );

            $this->writeState($statePath, $now, $report->periodicPatterns);
            $this->appendReport($reportWithChanges);

            return $reportWithChanges;
        } catch (\Throwable $e) {
            error_log('Kapture: recurring analysis failed: ' . $e->getMessage());
            // Still update lastRunAt to avoid retry-hammering
            $this->touchState($statePath, $now);
            return null;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockPath);
        }
    }

    /**
     * @param \App\Domain\RecurringPattern[] $patterns
     * @param array<string, array{period: int|null, lastOccurrenceTs: int}> $previousPatterns
     * @return \App\Domain\RecurringPattern[]
     */
    private function filterNewOrChanged(array $patterns, array $previousPatterns): array
    {
        $result = [];
        foreach ($patterns as $pattern) {
            $fp = $pattern->fingerprint;
            if (!isset($previousPatterns[$fp])) {
                $result[] = $pattern;
                continue;
            }

            $oldPeriod = $previousPatterns[$fp]['period'];
            $newPeriod = $pattern->periodSeconds;

            if ($oldPeriod !== null && $newPeriod !== null) {
                $diff = abs($newPeriod - $oldPeriod) / max($oldPeriod, 1);
                if ($diff <= self::PERIOD_CHANGE_TOLERANCE) {
                    continue;
                }
            }

            $result[] = $pattern;
        }

        return $result;
    }

    /** @return array<string, array{period: int|null, lastOccurrenceTs: int}> */
    private function loadPreviousPatterns(string $statePath): array
    {
        if (!file_exists($statePath)) {
            return [];
        }

        $content = file_get_contents($statePath);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['patterns']) || !is_array($data['patterns'])) {
            return [];
        }

        return $data['patterns'];
    }

    /**
     * @param \App\Domain\RecurringPattern[] $patterns
     */
    private function writeState(string $statePath, \DateTimeImmutable $now, array $patterns): void
    {
        $patternsData = [];
        foreach ($patterns as $p) {
            $patternsData[$p->fingerprint] = [
                'period'              => $p->periodSeconds,
                'lastOccurrenceTs'    => $p->lastSeen->toTimestamp(),
            ];
        }

        $state = [
            'lastRunAt' => $now->format('Y-m-d\TH:i:s\Z'),
            'patterns'  => $patternsData,
        ];

        file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
    }

    private function touchState(string $statePath, \DateTimeImmutable $now): void
    {
        if (file_exists($statePath)) {
            $content = file_get_contents($statePath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $data['lastRunAt'] = $now->format('Y-m-d\TH:i:s\Z');
                    file_put_contents($statePath, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
                    return;
                }
            }
        }
        // Fallback: just write a minimal state
        $this->writeState($statePath, $now, []);
    }

    private function appendReport(\App\Domain\RecurringReport $report): void
    {
        $reportPath = $this->logDir . '/' . self::REPORT_FILENAME;
        file_put_contents($reportPath, $report->toJson() . "\n", FILE_APPEND | LOCK_EX);
    }
}

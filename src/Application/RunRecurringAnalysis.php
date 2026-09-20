<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedAt;
use App\Domain\CapturedRequestCriteria;
use App\Domain\CapturedRequestRepository;
use App\Domain\RecurringPattern;
use App\Domain\RecurringReport;

final readonly class RunRecurringAnalysis
{
    public const INTERVAL_SECONDS = 86400;
    private const STATE_FILENAME = 'recurring-state.json';
    private const SNAPSHOT_FILENAME = 'recurring-report.json';
    private const LOCK_FILENAME = '.recurring.lock';

    public function __construct(
        private CapturedRequestRepository $repository,
        private string $logDir,
        private int $windowDays,
    ) {
    }

    /** @phpstan-impure */
    public function isDue(\DateTimeImmutable $now): bool
    {
        $lastRun = $this->lastRunAt();

        if ($lastRun === null) {
            return true;
        }

        return ($now->getTimestamp() - $lastRun->getTimestamp()) >= self::INTERVAL_SECONDS;
    }

    public function lastRunAt(): ?\DateTimeImmutable
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

    public function latestReport(): ?RecurringReport
    {
        $snapshotPath = $this->logDir . '/' . self::SNAPSHOT_FILENAME;

        if (!is_file($snapshotPath)) {
            return null;
        }

        $content = @file_get_contents($snapshotPath);
        if ($content === false || trim($content) === '') {
            return null;
        }

        try {
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        try {
            return RecurringReport::fromArray($data);
        } catch (\Throwable) {
            return null;
        }
    }

    public function run(\DateTimeImmutable $now, bool $force = false): ?RecurringReport
    {
        if (!$force && !$this->isDue($now)) {
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
        if (!$force && !$this->isDue($now)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return null;
        }

        try {
            $cutoff = $now->modify("-{$this->windowDays} days");
            $criteria = new CapturedRequestCriteria(
                capturedAfter: CapturedAt::fromDateTime($cutoff),
                order: 'asc',
            );
            $entries = $this->repository->findByCriteria($criteria);

            $analyzer = new DetectRecurring();
            $report = $analyzer->analyze($entries, $this->windowDays, $now);

            $report = $this->decorateFirstDetectedAt($report, $now);

            $this->writeState($statePath, $now, $report->periodicPatterns);
            $this->writeSnapshot($report);

            return $report;
        } catch (\Throwable $e) {
            error_log('Kapture: recurring analysis failed: ' . $e->getMessage());
            // Still update lastRunAt to avoid retry-hammering
            $this->touchState($statePath, $now);
            return null;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Stamp each periodic pattern with when it was first detected: the
     * previous detection time when the fingerprint is already known, or
     * the current run otherwise.
     */
    private function decorateFirstDetectedAt(RecurringReport $report, \DateTimeImmutable $now): RecurringReport
    {
        $previous = $this->readPreviousPatterns();

        $decorated = [];
        foreach ($report->periodicPatterns as $pattern) {
            $firstDetectedAt = $previous[$pattern->fingerprint]['firstDetectedAt']
                ?? $now->format('Y-m-d\TH:i:s\Z');
            $decorated[] = $pattern->withFirstDetectedAt(CapturedAt::fromString($firstDetectedAt));
        }

        return new RecurringReport(
            runAt: $report->runAt,
            windowDays: $report->windowDays,
            scannedEntries: $report->scannedEntries,
            periodicPatterns: $decorated,
            topOffenders: $report->topOffenders,
            uniqueFingerprints: $report->uniqueFingerprints,
        );
    }

    /**
     * @return array<string, array{period: int|null, lastOccurrenceTs: int, firstDetectedAt?: string}>
     */
    private function readPreviousPatterns(): array
    {
        $statePath = $this->logDir . '/' . self::STATE_FILENAME;

        if (!is_file($statePath)) {
            return [];
        }

        $content = @file_get_contents($statePath);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['patterns']) || !is_array($data['patterns'])) {
            return [];
        }

        /** @var array<string, array{period: int|null, lastOccurrenceTs: int, firstDetectedAt?: string}> $patterns */
        $patterns = $data['patterns'];

        return $patterns;
    }

    /**
     * @param RecurringPattern[] $patterns
     */
    private function writeState(string $statePath, \DateTimeImmutable $now, array $patterns): void
    {
        $patternsData = [];
        foreach ($patterns as $p) {
            $entry = [
                'period'           => $p->periodSeconds,
                'lastOccurrenceTs' => $p->lastSeen->toTimestamp(),
            ];
            if ($p->firstDetectedAt !== null) {
                $entry['firstDetectedAt'] = $p->firstDetectedAt->toIso8601();
            }
            $patternsData[$p->fingerprint] = $entry;
        }

        $state = [
            'lastRunAt' => $now->format('Y-m-d\TH:i:s\Z'),
            'patterns'  => $patternsData,
        ];

        file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }

    private function touchState(string $statePath, \DateTimeImmutable $now): void
    {
        if (file_exists($statePath)) {
            $content = file_get_contents($statePath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $data['lastRunAt'] = $now->format('Y-m-d\TH:i:s\Z');
                    file_put_contents($statePath, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
                    return;
                }
            }
        }
        // Fallback: just write a minimal state
        $this->writeState($statePath, $now, []);
    }

    private function writeSnapshot(RecurringReport $report): void
    {
        $snapshotPath = $this->logDir . '/' . self::SNAPSHOT_FILENAME;
        file_put_contents($snapshotPath, $report->toJson() . "\n");
    }
}

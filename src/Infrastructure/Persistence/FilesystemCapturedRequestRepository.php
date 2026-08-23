<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestCriteria;
use App\Domain\CapturedRequestRepository;

final class FilesystemCapturedRequestRepository implements CapturedRequestRepository
{
    public function __construct(
        private readonly string $logDir,
        private readonly int $retentionDays,
    )
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    #[\Override]
    public function save(CapturedRequest $entry): void
    {
        $file = $this->todayPath();
        $this->prune();
        file_put_contents($file, $entry->toJson() . "\n", FILE_APPEND | LOCK_EX);
    }

    #[\Override]
    public function findAll(): array
    {
        $entries = [];
        $files = glob($this->logDir . '/webhooks-*.jsonl') ?: [];
        rsort($files);
        foreach ($files as $file) {
            array_push($entries, ...$this->readFile($file));
        }
        return $entries;
    }

    #[\Override]
    public function findByDate(\DateTimeImmutable $date): array
    {
        $path = $this->logDir . '/webhooks-' . $date->format('Y-m-d') . '.jsonl';
        if (!file_exists($path)) {
            return [];
        }
        return $this->readFile($path);
    }

    #[\Override]
    public function findByCriteria(CapturedRequestCriteria $criteria): array
    {
        return $this->findWithTotal($criteria)[0];
    }

    #[\Override]
    public function countByCriteria(CapturedRequestCriteria $criteria): int
    {
        return $this->findWithTotal($criteria)[1];
    }

    #[\Override]
    public function findWithTotal(CapturedRequestCriteria $criteria): array
    {
        $filtered = [];
        if ($criteria->capturedOn !== null) {
            // Day-granular filters only need the day's archive file.
            $path = $this->logDir . '/webhooks-' . $criteria->capturedOn->format('Y-m-d') . '.jsonl';
            $files = file_exists($path) ? [$path] : [];
        } else {
            $files = glob($this->logDir . '/webhooks-*.jsonl') ?: [];
            sort($files);
        }
        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $line) {
                // Cheap pre-filter: unique-ID filters can only match when the
                // raw line contains the ID — skip the expensive decode otherwise.
                if ($criteria->captureId !== null && !str_contains($line, $criteria->captureId)) {
                    continue;
                }
                if ($criteria->correlationId !== null && !str_contains($line, $criteria->correlationId)) {
                    continue;
                }
                try {
                    $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    error_log(\sprintf('Kapture: skipping corrupt JSON line in %s', $file));
                    continue;
                }
                $entry = CapturedRequest::fromArray($data);
                if ($criteria->matches($entry)) {
                    $filtered[] = $entry;
                }
            }
        }

        // Stable sort by receipt time (PHP 8+ sorts are stable), so captures
        // with equal timestamps keep insertion order.
        usort(
            $filtered,
            static fn (CapturedRequest $a, CapturedRequest $b): int =>
                $a->capturedAt->toTimestamp() <=> $b->capturedAt->toTimestamp(),
        );

        if ($criteria->order === 'desc') {
            $filtered = array_reverse($filtered);
        }

        $total = count($filtered);

        if ($criteria->offset !== null) {
            $filtered = array_slice($filtered, $criteria->offset);
        }

        if ($criteria->limit !== null) {
            $filtered = array_slice($filtered, 0, $criteria->limit);
        }

        return [$filtered, $total];
    }

    #[\Override]
    public function getAvailableDates(): array
    {
        /** @var list<string> $files */
        $files = glob($this->logDir . '/webhooks-*.jsonl') ?: [];
        rsort($files);

        $dates = [];
        foreach ($files as $file) {
            $fileDate = self::dateFromFilename(basename($file));
            if ($fileDate !== null) {
                $dates[] = $fileDate;
            }
        }
        return $dates;
    }

    #[\Override]
    public function getEntryCounts(): array
    {
        $files = glob($this->logDir . '/webhooks-*.jsonl') ?: [];
        $counts = [];

        foreach ($files as $file) {
            $fileDate = self::dateFromFilename(basename($file));
            if ($fileDate === null) {
                continue;
            }
            $dateStr = $fileDate->format('Y-m-d');
            $content = file_get_contents($file);
            $counts[$dateStr] = $content === false ? 0 : substr_count($content, "\n");
        }

        krsort($counts);

        return $counts;
    }

    #[\Override]
    public function delete(string $captureId): void
    {
        $this->deleteMany([$captureId]);
    }

    #[\Override]
    public function deleteMany(array $captureIds): void
    {
        if ($captureIds === []) {
            return;
        }

        foreach (glob($this->logDir . '/webhooks-*.jsonl') ?: [] as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            $filtered = [];
            $removed = false;
            foreach ($lines as $line) {
                // Cheap pre-filter before the expensive decode.
                if (!$this->lineContainsAnyId($line, $captureIds)) {
                    $filtered[] = $line;
                    continue;
                }
                try {
                    $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $filtered[] = $line;
                    continue;
                }

                $storedId = $data['captureId'] ?? $data['capture_id'] ?? $data['uid'] ?? null;
                if ($storedId !== null && in_array($storedId, $captureIds, true)) {
                    $removed = true;
                    continue;
                }

                $filtered[] = $line;
            }

            if ($removed) {
                $content = implode("\n", $filtered);
                $content .= $content !== '' ? "\n" : '';
                file_put_contents($file, $content, LOCK_EX);
            }
        }
    }

    /**
     * @param list<string> $captureIds
     */
    private function lineContainsAnyId(string $line, array $captureIds): bool
    {
        foreach ($captureIds as $captureId) {
            if (str_contains($line, $captureId)) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function getRawContent(\DateTimeImmutable $date): ?string
    {
        $path = $this->logDir . '/webhooks-' . $date->format('Y-m-d') . '.jsonl';
        if (!file_exists($path)) {
            return null;
        }
        $content = file_get_contents($path);
        return $content !== false ? $content : null;
    }

    private const PRUNE_MIN_INTERVAL = 3600;

    private static function dateFromFilename(string $basename): ?\DateTimeImmutable
    {
        $datePart = str_replace(['webhooks-', '.jsonl'], '', $basename);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d|', $datePart);
        return $dt !== false ? $dt : null;
    }

    private function todayPath(): string
    {
        return $this->logDir . '/webhooks-' . date('Y-m-d') . '.jsonl';
    }

    /** @return CapturedRequest[] */
    private function readFile(string $path): array
    {
        $entries = $this->decodeFile($path);
        usort($entries, static fn(CapturedRequest $a, CapturedRequest $b) =>
            $b->capturedAt->toTimestamp() <=> $a->capturedAt->toTimestamp(),
        );
        return $entries;
    }

    /** @return CapturedRequest[] */
    private function decodeFile(string $path): array
    {
        $entries = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        foreach ($lines as $line) {
            try {
                $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                error_log(\sprintf('Kapture: skipping corrupt JSON line in %s', $path));
                continue;
            }
            $entries[] = CapturedRequest::fromArray($data);
        }
        return $entries;
    }

    private function prune(): void
    {
        $marker = $this->logDir . '/.prune-timestamp';

        if (file_exists($marker) && (time() - filemtime($marker)) < self::PRUNE_MIN_INTERVAL) {
            return;
        }

        $this->removeExpiredFiles(
            new \DateTimeImmutable("-{$this->retentionDays} days"),
            'webhooks-' . date('Y-m-d') . '.jsonl',
        );

        touch($marker);
    }

    private function removeExpiredFiles(\DateTimeImmutable $cutoff, string $todayBasename): void
    {
        foreach (glob($this->logDir . '/webhooks-*.jsonl') ?: [] as $old) {
            $basename = basename($old);
            if ($basename === $todayBasename) {
                continue;
            }
            $fileDate = self::dateFromFilename($basename);
            if ($fileDate !== null && $fileDate < $cutoff) {
                unlink($old);
            }
        }
    }
}

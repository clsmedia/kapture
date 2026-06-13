<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\CapturedRequest;
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
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $counts[$dateStr] = $lines !== false ? count($lines) : 0;
        }

        krsort($counts);

        return $counts;
    }

    #[\Override]
    public function delete(string $captureId): void
    {
        $files = glob($this->logDir . '/webhooks-*.jsonl') ?: [];
        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            $filtered = [];
            $removed = false;
            foreach ($lines as $line) {
                try {
                    $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $filtered[] = $line;
                    continue;
                }

                if (($data['captureId'] ?? '') === $captureId || ($data['capture_id'] ?? '') === $captureId || ($data['uid'] ?? '') === $captureId) {
                    $removed = true;
                    continue;
                }

                $filtered[] = $line;
            }

            if ($removed) {
                $content = implode("\n", $filtered);
                $content .= $content !== '' ? "\n" : '';
                file_put_contents($file, $content, LOCK_EX);
                return;
            }
        }
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
        usort($entries, static fn(CapturedRequest $a, CapturedRequest $b) =>
            $b->capturedAt->toTimestamp() <=> $a->capturedAt->toTimestamp(),
        );
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

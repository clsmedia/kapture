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
        foreach ($this->getAvailableDates() as $date) {
            array_push($entries, ...$this->findByDate($date));
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
            $basename = basename($file);
            $datePart = str_replace(['webhooks-', '.jsonl'], '', $basename);
            $dates[] = new \DateTimeImmutable($datePart);
        }
        return $dates;
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
                continue;
            }
            $entries[] = CapturedRequest::fromArray($data);
        }
        return $entries;
    }

    private const PRUNE_MIN_INTERVAL = 3600;

    private function prune(): void
    {
        $marker = $this->logDir . '/.prune-timestamp';

        if (file_exists($marker) && (time() - filemtime($marker)) < self::PRUNE_MIN_INTERVAL) {
            return;
        }

        $today = 'webhooks-' . date('Y-m-d') . '.jsonl';
        $cutoff = time() - ($this->retentionDays * 86400);
        foreach (glob($this->logDir . '/webhooks-*.jsonl') ?: [] as $old) {
            if (basename($old) !== $today && filemtime($old) < $cutoff) {
                unlink($old);
            }
        }

        touch($marker);
    }
}

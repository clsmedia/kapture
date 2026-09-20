<?php

declare(strict_types=1);

namespace App\Domain;

readonly class RecurringReport
{
    /**
     * @param RecurringPattern[] $periodicPatterns
     * @param RecurringPattern[] $topOffenders
     */
    public function __construct(
        public CapturedAt $runAt,
        public int $windowDays,
        public int $scannedEntries,
        public array $periodicPatterns,
        public array $topOffenders,
    ) {
    }

    /**
     * @return array{runAt: string, windowDays: int, scannedEntries: int, periodicPatterns: list<array>, topOffenders: list<array>}
     */
    public function toArray(): array
    {
        return [
            'runAt'            => $this->runAt->toIso8601(),
            'windowDays'       => $this->windowDays,
            'scannedEntries'   => $this->scannedEntries,
            'periodicPatterns' => array_values(array_map(
                static fn(RecurringPattern $p): array => $p->toArray(),
                $this->periodicPatterns,
            )),
            'topOffenders'     => array_values(array_map(
                static fn(RecurringPattern $p): array => $p->toArray(),
                $this->topOffenders,
            )),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

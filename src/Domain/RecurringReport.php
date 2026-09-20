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
        public int $uniqueFingerprints = 0,
    ) {
    }

    /**
     * @return array{runAt: string, windowDays: int, scannedEntries: int, uniqueFingerprints: int, periodicPatterns: list<array>, topOffenders: list<array>}
     */
    public function toArray(): array
    {
        return [
            'runAt'              => $this->runAt->toIso8601(),
            'windowDays'         => $this->windowDays,
            'scannedEntries'     => $this->scannedEntries,
            'uniqueFingerprints' => $this->uniqueFingerprints,
            'periodicPatterns'   => array_values(array_map(
                static fn(RecurringPattern $p): array => $p->toArray(),
                $this->periodicPatterns,
            )),
            'topOffenders'       => array_values(array_map(
                static fn(RecurringPattern $p): array => $p->toArray(),
                $this->topOffenders,
            )),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            runAt: CapturedAt::fromString((string) $data['runAt']),
            windowDays: (int) $data['windowDays'],
            scannedEntries: (int) $data['scannedEntries'],
            periodicPatterns: self::patternsFrom($data['periodicPatterns'] ?? null),
            topOffenders: self::patternsFrom($data['topOffenders'] ?? null),
            uniqueFingerprints: (int) ($data['uniqueFingerprints'] ?? 0),
        );
    }

    /**
     * @return list<RecurringPattern>
     */
    private static function patternsFrom(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $patterns = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $patterns[] = RecurringPattern::fromArray($item);
            }
        }

        return $patterns;
    }
}

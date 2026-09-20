<?php

declare(strict_types=1);

namespace App\Domain;

readonly class RecurringPattern
{
    public function __construct(
        public string $fingerprint,
        public PatternType $type,
        public int $occurrences,
        public ?int $periodSeconds,
        public ?string $suggestedCron,
        public CapturedAt $firstSeen,
        public CapturedAt $lastSeen,
        public ?CapturedAt $firstDetectedAt = null,
    ) {
    }

    public function withFirstDetectedAt(CapturedAt $firstDetectedAt): self
    {
        return new self(
            fingerprint: $this->fingerprint,
            type: $this->type,
            occurrences: $this->occurrences,
            periodSeconds: $this->periodSeconds,
            suggestedCron: $this->suggestedCron,
            firstSeen: $this->firstSeen,
            lastSeen: $this->lastSeen,
            firstDetectedAt: $firstDetectedAt,
        );
    }

    /**
     * @return array{fingerprint: string, type: string, occurrences: int, periodSeconds: ?int, suggestedCron: ?string, firstSeen: string, lastSeen: string, firstDetectedAt?: string}
     */
    public function toArray(): array
    {
        $data = [
            'fingerprint'   => $this->fingerprint,
            'type'          => $this->type->value,
            'occurrences'   => $this->occurrences,
            'periodSeconds' => $this->periodSeconds,
            'suggestedCron' => $this->suggestedCron,
            'firstSeen'     => $this->firstSeen->toIso8601(),
            'lastSeen'      => $this->lastSeen->toIso8601(),
        ];

        if ($this->firstDetectedAt !== null) {
            $data['firstDetectedAt'] = $this->firstDetectedAt->toIso8601();
        }

        return $data;
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
            fingerprint: (string) $data['fingerprint'],
            type: PatternType::from((string) $data['type']),
            occurrences: (int) $data['occurrences'],
            periodSeconds: isset($data['periodSeconds']) ? (int) $data['periodSeconds'] : null,
            suggestedCron: isset($data['suggestedCron']) ? (string) $data['suggestedCron'] : null,
            firstSeen: CapturedAt::fromString((string) $data['firstSeen']),
            lastSeen: CapturedAt::fromString((string) $data['lastSeen']),
            firstDetectedAt: isset($data['firstDetectedAt'])
                ? CapturedAt::fromString((string) $data['firstDetectedAt'])
                : null,
        );
    }
}

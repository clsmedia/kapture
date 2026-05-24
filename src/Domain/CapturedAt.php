<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class CapturedAt
{
    private const ISO8601_UTC = 'Y-m-d\TH:i:s\Z';

    public static function now(): self
    {
        return new self(
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public static function fromString(string $iso8601): self
    {
        if ($iso8601 === '') {
            throw new \InvalidArgumentException('Cannot parse date string: empty string. Expected ISO8601 UTC format.');
        }

        $parsed = \DateTimeImmutable::createFromFormat(self::ISO8601_UTC, $iso8601, new \DateTimeZone('UTC'));

        if ($parsed === false) {
            try {
                $parsed = new \DateTimeImmutable($iso8601, new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException(
                    \sprintf('Cannot parse date string: "%s". Expected ISO8601 UTC format.', $iso8601),
                );
            }
        }

        return new self($parsed->setTimezone(new \DateTimeZone('UTC')));
    }

    public static function fromDateTime(\DateTimeImmutable $dateTime): self
    {
        return new self($dateTime->setTimezone(new \DateTimeZone('UTC')));
    }

    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        return $this->utcDateTime;
    }

    /**
     * Format as ISO8601 in UTC: 2026-05-23T14:30:00Z
     */
    public function toIso8601(): string
    {
        return $this->utcDateTime->format(self::ISO8601_UTC);
    }

    public function toHumanReadable(): string
    {
        return $this->utcDateTime->format('Y-m-d H:i:s') . ' UTC';
    }

    public function __toString(): string
    {
        return $this->toIso8601();
    }

    private function __construct(
        private \DateTimeImmutable $utcDateTime,
    ) {
    }
}

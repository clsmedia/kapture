<?php

declare(strict_types=1);

namespace App\Domain;

interface CapturedRequestRepository
{
    public function save(CapturedRequest $entry): void;

    /** @return CapturedRequest[] */
    public function findAll(): array;

    /** @return CapturedRequest[] */
    public function findByDate(\DateTimeImmutable $date): array;

    /** @return \DateTimeImmutable[] */
    public function getAvailableDates(): array;

    /** @return array<string, int> */
    public function getEntryCounts(): array;
}

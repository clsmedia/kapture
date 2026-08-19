<?php

declare(strict_types=1);

namespace App\Domain;

interface CapturedRequestRepository
{
    public function save(CapturedRequest $entry): void;

    /** @return CapturedRequest[] */
    public function findAll(): array;

    /** @return CapturedRequest[] */
    public function findByCriteria(CapturedRequestCriteria $criteria): array;

    /** Number of captures matching the criteria, ignoring the limit. */
    public function countByCriteria(CapturedRequestCriteria $criteria): int;

    /**
     * Matching captures (limit applied) together with the total number of
     * captures matching the criteria ignoring the limit. Implementations
     * should compute both in a single pass to avoid scanning the data twice.
     *
     * @return array{CapturedRequest[], int}
     */
    public function findWithTotal(CapturedRequestCriteria $criteria): array;

    /** @return CapturedRequest[] */
    public function findByDate(\DateTimeImmutable $date): array;

    /** @return \DateTimeImmutable[] */
    public function getAvailableDates(): array;

    /** @return array<string, int> */
    public function getEntryCounts(): array;

    public function getRawContent(\DateTimeImmutable $date): ?string;

    public function delete(string $captureId): void;

    /**
     * Delete all captures with the given capture IDs in a single pass.
     *
     * @param list<string> $captureIds
     */
    public function deleteMany(array $captureIds): void;
}

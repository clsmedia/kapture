<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedRequestCriteria;
use App\Domain\CapturedRequestRepository;

final readonly class ListCapturedRequests
{
    public function __construct(
        private CapturedRequestRepository $repository,
    )
    {
    }

    public function handle(?string $date = null, int $page = 1, int $perPage = 100): ListCapturedRequestsResult
    {
        $dates = $this->repository->getAvailableDates();
        $dailyArchives = array_map(fn(\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates);
        $archiveCounts = $this->repository->getEntryCounts();

        if ($date !== null) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d|', $date);
            if ($dt === false) {
                return new ListCapturedRequestsResult([], $dailyArchives, $date, $date, $archiveCounts, totalEntries: 0, currentPage: $page, perPage: $perPage);
            }
            $all = $this->repository->findByDate($dt);
            $totalEntries = count($all);
            $result = array_slice($all, ($page - 1) * $perPage, $perPage);
            $label = $date;
        } else {
            $criteria = new CapturedRequestCriteria(
                limit: $perPage,
                offset: ($page - 1) * $perPage,
                order: 'desc',
            );
            [$result, $totalEntries] = $this->repository->findWithTotal($criteria);
            $label = 'all files (' . count($dates) . ')';
        }

        return new ListCapturedRequestsResult(
            $result,
            $dailyArchives,
            $date,
            $label,
            $archiveCounts,
            totalEntries: $totalEntries,
            currentPage: $page,
            perPage: $perPage,
        );
    }
}

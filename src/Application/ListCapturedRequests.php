<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedRequestRepository;

final readonly class ListCapturedRequests
{
    public function __construct(
        private CapturedRequestRepository $repository,
    )
    {
    }

    public function handle(?string $date = null): ListCapturedRequestsResult
    {
        $dates = $this->repository->getAvailableDates();
        $dailyArchives = array_map(fn(\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates);
        $archiveCounts = $this->repository->getEntryCounts();

        if ($date !== null) {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d|', $date);
        if ($dt === false) {
            return new ListCapturedRequestsResult([], $dailyArchives, $date, $date, $archiveCounts);
        }
        $result = $this->repository->findByDate($dt);
        $label = $date;
        } else {
            $result = $this->repository->findAll();
            $label = 'all files (' . count($dates) . ')';
        }

        usort(
            $result,
            fn(\App\Domain\CapturedRequest $a, \App\Domain\CapturedRequest $b) =>
                $b->capturedAt->toIso8601() <=> $a->capturedAt->toIso8601(),
        );

        return new ListCapturedRequestsResult(
            $result,
            $dailyArchives,
            $date,
            $label,
            $archiveCounts,
        );
    }
}

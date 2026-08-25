<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedRequestCriteria;
use App\Domain\CapturedRequestRepository;

final readonly class QueryCapturedRequests
{
    private const PER_PAGE = 100;

    public function __construct(
        private CapturedRequestRepository $repository,
    )
    {
    }

    /**
     * The one capture-query interface: criteria in, page out.
     */
    public function page(CapturedRequestCriteria $criteria): CapturedRequestPage
    {
        [$entries, $total] = $this->repository->findWithTotal($criteria);

        return new CapturedRequestPage(
            entries: $entries,
            totalEntries: $total,
            currentPage: $this->currentPage($criteria),
            perPage: $criteria->limit ?? self::PER_PAGE,
        );
    }

    /**
     * The admin dashboard listing: a page of captures plus the archive
     * navigation metadata the dashboard view model needs.
     */
    public function dashboard(?string $date = null, int $page = 1, int $perPage = self::PER_PAGE, ?string $search = null): ListCapturedRequestsResult
    {
        $dates = $this->repository->getAvailableDates();
        $dailyArchives = array_map(fn(\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates);
        $archiveCounts = $this->repository->getEntryCounts();

        if ($date !== null) {
            $day = \DateTimeImmutable::createFromFormat('Y-m-d|', $date);
            if ($day === false) {
                return new ListCapturedRequestsResult(
                    page: new CapturedRequestPage([], 0, $page, $perPage),
                    dailyArchives: $dailyArchives,
                    selectedArchive: $date,
                    label: $date,
                    archiveCounts: $archiveCounts,
                );
            }
            $result = $this->page(CapturedRequestCriteria::onDate($day, $page, $perPage)->withSearchTerm($search));
            $label = $date;
        } else {
            $result = $this->page(CapturedRequestCriteria::paginated($page, $perPage)->withSearchTerm($search));
            $label = 'all files (' . count($dates) . ')';
        }

        return new ListCapturedRequestsResult(
            page: $result,
            dailyArchives: $dailyArchives,
            selectedArchive: $date,
            label: $label,
            archiveCounts: $archiveCounts,
        );
    }

    private function currentPage(CapturedRequestCriteria $criteria): int
    {
        if ($criteria->limit === null || $criteria->limit === 0) {
            return 1;
        }

        return (int) floor(($criteria->offset ?? 0) / $criteria->limit) + 1;
    }
}

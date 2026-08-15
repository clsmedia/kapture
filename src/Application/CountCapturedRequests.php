<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedRequestCriteria;
use App\Domain\CapturedRequestRepository;

final readonly class CountCapturedRequests
{
    public function __construct(
        private CapturedRequestRepository $repository,
    )
    {
    }

    /** Number of captures matching the criteria, ignoring the limit. */
    public function handle(CapturedRequestCriteria $criteria): int
    {
        return $this->repository->countByCriteria($criteria);
    }
}

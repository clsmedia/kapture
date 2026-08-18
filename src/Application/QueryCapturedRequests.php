<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestCriteria;
use App\Domain\CapturedRequestRepository;

final readonly class QueryCapturedRequests
{
    public function __construct(
        private CapturedRequestRepository $repository,
    )
    {
    }

    /** @return CapturedRequest[] */
    public function handle(CapturedRequestCriteria $criteria): array
    {
        return $this->repository->findByCriteria($criteria);
    }

    /**
     * @return array{CapturedRequest[], int} [entries, total]
     */
    public function handleWithTotal(CapturedRequestCriteria $criteria): array
    {
        return $this->repository->findWithTotal($criteria);
    }
}

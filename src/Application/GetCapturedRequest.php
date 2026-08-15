<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestCriteria;
use App\Domain\CapturedRequestRepository;

final readonly class GetCapturedRequest
{
    public function __construct(
        private CapturedRequestRepository $repository,
    )
    {
    }

    public function handle(string $captureId): ?CapturedRequest
    {
        $entries = $this->repository->findByCriteria(new CapturedRequestCriteria(captureId: $captureId));
        return $entries[0] ?? null;
    }
}

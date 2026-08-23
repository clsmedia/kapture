<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedRequest;

final readonly class CapturedRequestPage
{
    /**
     * @param CapturedRequest[] $entries
     */
    public function __construct(
        public array $entries,
        public int $totalEntries,
        public int $currentPage,
        public int $perPage,
    )
    {
    }
}

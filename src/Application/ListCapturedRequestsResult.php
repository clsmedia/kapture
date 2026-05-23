<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedRequest;

final readonly class ListCapturedRequestsResult
{
    /**
     * @param CapturedRequest[] $entries
     * @param string[] $dailyArchives
     */
    public function __construct(
        public array $entries,
        public array $dailyArchives,
        public ?string $selectedArchive,
        public string $label,
    )
    {
    }
}

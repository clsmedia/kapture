<?php

declare(strict_types=1);

namespace App\Application;

final readonly class ListCapturedRequestsResult
{
    /**
     * @param string[] $dailyArchives
     * @param array<string, int> $archiveCounts
     */
    public function __construct(
        public CapturedRequestPage $page,
        public array $dailyArchives,
        public ?string $selectedArchive,
        public string $label,
        public array $archiveCounts = [],
    )
    {
    }
}

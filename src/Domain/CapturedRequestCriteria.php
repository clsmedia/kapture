<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class CapturedRequestCriteria
{
    public function __construct(
        public ?string $captureId = null,
        public ?string $correlationId = null,
        public ?HttpMethod $method = null,
        public ?string $uri = null,
        public ?CapturedAt $capturedAfter = null,
        public ?CapturedAt $capturedBefore = null,
        public ?int $limit = null,
    )
    {
    }
}

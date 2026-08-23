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
        public ?\DateTimeImmutable $capturedOn = null,
        public ?int $limit = null,
        public ?int $offset = null,
        public ?string $order = null,
    )
    {
    }

    /**
     * A full page of captures, newest first — the admin dashboard listing.
     */
    public static function paginated(int $page, int $perPage): self
    {
        return new self(
            limit: $perPage,
            offset: ($page - 1) * $perPage,
            order: 'desc',
        );
    }

    /**
     * A full page of captures from a single calendar day, newest first.
     * Day granularity is adapter-native: the filesystem adapter selects the
     * day's archive file, SQLite matches the captured_at_date column.
     */
    public static function onDate(\DateTimeImmutable $day, int $page, int $perPage): self
    {
        return new self(
            capturedOn: $day,
            limit: $perPage,
            offset: ($page - 1) * $perPage,
            order: 'desc',
        );
    }

    /**
     * Whether a single capture satisfies every set filter. The filesystem
     * storage adapter uses this predicate directly; SQLite expresses the
     * same semantics natively in SQL.
     */
    public function matches(CapturedRequest $request): bool
    {
        if ($this->captureId !== null && $request->captureId !== $this->captureId) {
            return false;
        }

        if ($this->correlationId !== null && $request->correlationId !== $this->correlationId) {
            return false;
        }

        if ($this->method !== null && $request->method !== $this->method) {
            return false;
        }

        if ($this->uri !== null && !str_contains($request->uri, $this->uri)) {
            return false;
        }

        if ($this->capturedAfter !== null && $request->capturedAt->toTimestamp() <= $this->capturedAfter->toTimestamp()) {
            return false;
        }

        if ($this->capturedBefore !== null && $request->capturedAt->toTimestamp() >= $this->capturedBefore->toTimestamp()) {
            return false;
        }

        return true;
    }
}

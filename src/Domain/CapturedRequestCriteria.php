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
        public ?string $searchTerm = null,
    )
    {
    }

    public function withSearchTerm(?string $searchTerm): self
    {
        return new self(
            captureId: $this->captureId,
            correlationId: $this->correlationId,
            method: $this->method,
            uri: $this->uri,
            capturedAfter: $this->capturedAfter,
            capturedBefore: $this->capturedBefore,
            capturedOn: $this->capturedOn,
            limit: $this->limit,
            offset: $this->offset,
            order: $this->order,
            searchTerm: $searchTerm !== null && $searchTerm !== '' ? $searchTerm : null,
        );
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

        if ($this->searchTerm !== null && !str_contains(self::searchHaystack($request), strtolower($this->searchTerm))) {
            return false;
        }

        return true;
    }

    /**
     * Everything the dashboard filter box searches, lowercased — mirrors the
     * client-side entrySearchText() in public/assets/admin.js.
     */
    private static function searchHaystack(CapturedRequest $request): string
    {
        return strtolower(implode(' ', [
            $request->uri,
            $request->captureId,
            (string) $request->correlationId,
            $request->ip,
            $request->body,
            json_encode($request->query, JSON_THROW_ON_ERROR),
            json_encode($request->headers, JSON_THROW_ON_ERROR),
        ]));
    }
}

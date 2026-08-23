<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\CapturedRequestPage;
use App\Application\QueryCapturedRequests;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestCriteria;
use App\Domain\CapturedRequestRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryCapturedRequests::class)]
#[CoversClass(CapturedRequestPage::class)]
final class QueryCapturedRequestsTest extends TestCase
{
    public function test_page_maps_repository_result(): void
    {
        $entry = CapturedRequest::capture('GET', '/a', [], [], '', '');

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findWithTotal')
            ->with(self::callback(fn(CapturedRequestCriteria $c): bool =>
                $c->limit === 100 && $c->offset === 0 && $c->order === 'desc'
            ))
            ->willReturn([[$entry], 150]);

        $page = (new QueryCapturedRequests($repo))->page(
            CapturedRequestCriteria::paginated(1, 100),
        );

        self::assertCount(1, $page->entries);
        self::assertSame(150, $page->totalEntries);
        self::assertSame(1, $page->currentPage);
        self::assertSame(100, $page->perPage);
    }

    public function test_page_derives_current_page_from_offset(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->method('findWithTotal')->willReturn([[], 120]);

        $page = (new QueryCapturedRequests($repo))->page(
            CapturedRequestCriteria::paginated(2, 50),
        );

        self::assertSame(2, $page->currentPage);
        self::assertSame(50, $page->perPage);
    }

    public function test_dashboard_without_date_returns_paginated_entries(): void
    {
        $entry = CapturedRequest::capture('GET', '/a', [], [], '', '');

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findWithTotal')
            ->with(self::callback(fn(CapturedRequestCriteria $c): bool =>
                $c->limit === 100 && $c->offset === 0 && $c->order === 'desc' && $c->capturedOn === null
            ))
            ->willReturn([[$entry], 150]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([
            new \DateTimeImmutable('2025-01-02'),
            new \DateTimeImmutable('2025-01-01'),
        ]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([
            '2025-01-02' => 1,
            '2025-01-01' => 1,
        ]);

        $result = (new QueryCapturedRequests($repo))->dashboard();

        self::assertCount(1, $result->page->entries);
        self::assertNull($result->selectedArchive);
        self::assertStringContainsString('all files', $result->label);
        self::assertSame(['2025-01-02', '2025-01-01'], $result->dailyArchives);
        self::assertSame(['2025-01-02' => 1, '2025-01-01' => 1], $result->archiveCounts);
        self::assertSame(150, $result->page->totalEntries);
        self::assertSame(1, $result->page->currentPage);
        self::assertSame(100, $result->page->perPage);
    }

    public function test_dashboard_with_page_2_uses_correct_offset(): void
    {
        $entry = CapturedRequest::capture('GET', '/b', [], [], '', '');

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findWithTotal')
            ->with(self::callback(fn(CapturedRequestCriteria $c): bool =>
                $c->limit === 50 && $c->offset === 50 && $c->order === 'desc'
            ))
            ->willReturn([[$entry], 120]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([]);

        $result = (new QueryCapturedRequests($repo))->dashboard(page: 2, perPage: 50);

        self::assertCount(1, $result->page->entries);
        self::assertSame(120, $result->page->totalEntries);
        self::assertSame(2, $result->page->currentPage);
        self::assertSame(50, $result->page->perPage);
    }

    public function test_dashboard_with_date_filters_by_day_through_criteria(): void
    {
        $entry = CapturedRequest::capture('GET', '/c', [], [], '', '');
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findWithTotal')
            ->with(self::callback(function (CapturedRequestCriteria $c): bool {
                $day = \DateTimeImmutable::createFromFormat('Y-m-d|', '2025-01-01');
                return $c->capturedOn !== null
                    && $c->capturedOn == $day
                    && $c->limit === 100
                    && $c->offset === 0
                    && $c->order === 'desc';
            }))
            ->willReturn([[$entry], 1]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([
            new \DateTimeImmutable('2025-01-01'),
        ]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([
            '2025-01-01' => 1,
        ]);

        $result = (new QueryCapturedRequests($repo))->dashboard('2025-01-01');

        self::assertCount(1, $result->page->entries);
        self::assertSame('2025-01-01', $result->selectedArchive);
        self::assertSame('2025-01-01', $result->label);
        self::assertSame(1, $result->page->totalEntries);
    }

    public function test_dashboard_with_date_paginates_by_page(): void
    {
        $entries = [];
        for ($i = 0; $i < 50; $i++) {
            $entries[] = CapturedRequest::capture('GET', '/d' . $i, [], [], '', '');
        }

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findWithTotal')
            ->with(self::callback(fn(CapturedRequestCriteria $c): bool =>
                $c->capturedOn !== null && $c->limit === 100 && $c->offset === 100
            ))
            ->willReturn([$entries, 150]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([
            new \DateTimeImmutable('2025-01-01'),
        ]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([
            '2025-01-01' => 150,
        ]);

        $result = (new QueryCapturedRequests($repo))->dashboard('2025-01-01', page: 2, perPage: 100);

        self::assertCount(50, $result->page->entries);
        self::assertSame(150, $result->page->totalEntries);
        self::assertSame(2, $result->page->currentPage);
        self::assertSame(100, $result->page->perPage);
    }

    public function test_dashboard_returns_empty_entries_for_empty_repo(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findWithTotal')
            ->willReturn([[], 0]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([]);

        $result = (new QueryCapturedRequests($repo))->dashboard();

        self::assertCount(0, $result->page->entries);
        self::assertCount(0, $result->dailyArchives);
        self::assertSame(0, $result->page->totalEntries);
    }

    public function test_dashboard_invalid_date_returns_empty_without_querying(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([]);
        $repo->expects(self::never())->method('findWithTotal');

        $result = (new QueryCapturedRequests($repo))->dashboard('not-a-date');

        self::assertCount(0, $result->page->entries);
        self::assertSame('not-a-date', $result->selectedArchive);
        self::assertSame(0, $result->page->totalEntries);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\ListCapturedRequests;
use App\Application\ListCapturedRequestsResult;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestCriteria;
use App\Domain\CapturedRequestRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListCapturedRequests::class)]
final class ListCapturedRequestsTest extends TestCase
{
    public function test_handle_without_date_returns_paginated_entries(): void
    {
        $entry = CapturedRequest::capture('GET', '/a', [], [], '', '');

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findWithTotal')
            ->with(self::callback(fn(CapturedRequestCriteria $c): bool =>
                $c->limit === 100 && $c->offset === 0 && $c->order === 'desc'
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

        $useCase = new ListCapturedRequests($repo);
        $result = $useCase->handle();

        self::assertCount(1, $result->entries);
        self::assertNull($result->selectedArchive);
        self::assertStringContainsString('all files', $result->label);
        self::assertSame(['2025-01-02', '2025-01-01'], $result->dailyArchives);
        self::assertSame(['2025-01-02' => 1, '2025-01-01' => 1], $result->archiveCounts);
        self::assertSame(150, $result->totalEntries);
        self::assertSame(1, $result->currentPage);
        self::assertSame(100, $result->perPage);
    }

    public function test_handle_with_page_2_uses_correct_offset(): void
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

        $useCase = new ListCapturedRequests($repo);
        $result = $useCase->handle(page: 2, perPage: 50);

        self::assertCount(1, $result->entries);
        self::assertSame(120, $result->totalEntries);
        self::assertSame(2, $result->currentPage);
        self::assertSame(50, $result->perPage);
    }

    public function test_handle_with_date_returns_filtered_entries(): void
    {
        $entry = CapturedRequest::capture('GET', '/c', [], [], '', '');
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findByDate')
            ->with(new \DateTimeImmutable('2025-01-01'))
            ->willReturn([$entry]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([
            new \DateTimeImmutable('2025-01-01'),
        ]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([
            '2025-01-01' => 1,
        ]);

        $useCase = new ListCapturedRequests($repo);
        $result = $useCase->handle('2025-01-01');

        self::assertCount(1, $result->entries);
        self::assertSame('2025-01-01', $result->selectedArchive);
        self::assertSame('2025-01-01', $result->label);
        self::assertSame(1, $result->totalEntries);
    }

    public function test_handle_with_date_paginates_by_page(): void
    {
        $entries = [];
        for ($i = 0; $i < 150; $i++) {
            $entries[] = CapturedRequest::capture('GET', '/d' . $i, [], [], '', '');
        }

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findByDate')
            ->with(new \DateTimeImmutable('2025-01-01'))
            ->willReturn($entries);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([
            new \DateTimeImmutable('2025-01-01'),
        ]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([
            '2025-01-01' => 150,
        ]);

        $useCase = new ListCapturedRequests($repo);
        $result = $useCase->handle('2025-01-01', page: 2, perPage: 100);

        self::assertCount(50, $result->entries);
        self::assertSame(150, $result->totalEntries);
        self::assertSame(2, $result->currentPage);
        self::assertSame(100, $result->perPage);
    }

    public function test_handle_returns_empty_entries_for_empty_repo(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findWithTotal')
            ->willReturn([[], 0]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([]);

        $result = (new ListCapturedRequests($repo))->handle();

        self::assertCount(0, $result->entries);
        self::assertCount(0, $result->dailyArchives);
        self::assertSame(0, $result->totalEntries);
    }

    public function test_handle_invalid_date_returns_empty(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([]);
        $repo->expects(self::never())->method('findByDate');

        $result = (new ListCapturedRequests($repo))->handle('not-a-date');

        self::assertCount(0, $result->entries);
        self::assertSame('not-a-date', $result->selectedArchive);
        self::assertSame(0, $result->totalEntries);
    }
}

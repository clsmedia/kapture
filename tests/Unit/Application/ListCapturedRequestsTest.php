<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\ListCapturedRequests;
use App\Application\ListCapturedRequestsResult;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListCapturedRequests::class)]
final class ListCapturedRequestsTest extends TestCase
{
    public function test_handle_without_date_returns_all_entries(): void
    {
        $entries = [
            CapturedRequest::capture('GET', '/a', [], [], '', ''),
            CapturedRequest::capture('POST', '/b', [], [], '', ''),
        ];

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn($entries);
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

        self::assertCount(2, $result->entries);
        self::assertNull($result->selectedArchive);
        self::assertStringContainsString('all files', $result->label);
        self::assertSame(['2025-01-02', '2025-01-01'], $result->dailyArchives);
        self::assertSame(['2025-01-02' => 1, '2025-01-01' => 1], $result->archiveCounts);
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
    }

    public function test_handle_returns_empty_entries_for_empty_repo(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn([]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([]);

        $result = (new ListCapturedRequests($repo))->handle();

        self::assertCount(0, $result->entries);
        self::assertCount(0, $result->dailyArchives);
    }
}

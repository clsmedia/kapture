<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\CapturedRequestPage;
use App\Application\ListCapturedRequestsResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListCapturedRequestsResult::class)]
final class ListCapturedRequestsResultTest extends TestCase
{
    public function test_constructor_assigns_properties(): void
    {
        $result = new ListCapturedRequestsResult(
            page: new CapturedRequestPage([], 0, 1, 100),
            dailyArchives: ['2026-05-23'],
            selectedArchive: null,
            label: 'all files',
        );

        self::assertCount(0, $result->page->entries);
        self::assertSame(['2026-05-23'], $result->dailyArchives);
        self::assertNull($result->selectedArchive);
        self::assertSame('all files', $result->label);
    }
}

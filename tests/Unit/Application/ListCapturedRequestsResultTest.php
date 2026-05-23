<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\ListCapturedRequestsResult;
use App\Domain\CapturedRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListCapturedRequestsResult::class)]
final class ListCapturedRequestsResultTest extends TestCase
{
    public function test_constructor_assigns_properties(): void
    {
        $entry = CapturedRequest::capture('GET', '/', [], [], '', '');
        $result = new ListCapturedRequestsResult(
            entries: [$entry],
            dailyArchives: ['2026-05-23'],
            selectedArchive: null,
            label: 'all files',
        );

        self::assertCount(1, $result->entries);
        self::assertSame(['2026-05-23'], $result->dailyArchives);
        self::assertNull($result->selectedArchive);
        self::assertSame('all files', $result->label);
    }
}

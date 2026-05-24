<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\CaptureWebhook;
use App\Application\ListCapturedRequests;
use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\HttpMethod;
use App\Infrastructure\Persistence\FilesystemCapturedRequestRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CaptureWebhook::class)]
#[CoversClass(ListCapturedRequests::class)]
#[UsesClass(FilesystemCapturedRequestRepository::class)]
#[UsesClass(CapturedRequest::class)]
#[UsesClass(HttpMethod::class)]
final class FeatureIntegrationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kapture_feat_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);
    }

    public function test_capture_then_list_all(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $capture = new CaptureWebhook($repo);
        $list = new ListCapturedRequests($repo);

        $capture->handle('POST', '/first', [], [], '', '');
        $capture->handle('GET', '/second', [], [], '', '');

        $result = $list->handle();

        self::assertCount(2, $result->entries);
        $byUri = [];
        foreach ($result->entries as $e) {
            $byUri[$e->uri] = $e->method;
        }
        self::assertSame(HttpMethod::POST, $byUri['/first']);
        self::assertSame(HttpMethod::GET, $byUri['/second']);
    }

    public function test_capture_preserves_all_fields(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $capture = new CaptureWebhook($repo);
        $list = new ListCapturedRequests($repo);

        $capture->handle(
            'PUT',
            '/api/data?debug=1',
            ['debug' => '1'],
            ['Authorization' => 'Bearer tok', 'Content-Type' => 'application/json'],
            '{"status":"ok"}',
            '192.168.1.1',
        );

        $result = $list->handle();
        self::assertCount(1, $result->entries);

        $entry = $result->entries[0];
        self::assertSame(HttpMethod::PUT, $entry->method);
        self::assertSame('/api/data?debug=1', $entry->uri);
        self::assertSame(['debug' => '1'], $entry->query);
        self::assertArrayNotHasKey('authorization', $entry->headers);
        self::assertSame('application/json', $entry->headers['Content-Type']);
        self::assertSame('{"status":"ok"}', $entry->body);
        self::assertSame('192.168.1.1', $entry->ip);
    }

    public function test_capture_then_find_by_date(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $capture = new CaptureWebhook($repo);
        $list = new ListCapturedRequests($repo);

        $capture->handle('GET', '/first', [], [], '', '');
        $capture->handle('GET', '/second', [], [], '', '');

        $result = $list->handle(date('Y-m-d'));

        self::assertCount(2, $result->entries);
        $uris = array_map(fn($e) => $e->uri, $result->entries);
        self::assertContains('/first', $uris);
        self::assertContains('/second', $uris);
    }

    public function test_multiple_dates_sort_cross_file(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $list = new ListCapturedRequests($repo);

        $yesterday = new CapturedRequest(
            CapturedAt::fromString((new \DateTimeImmutable('-1 day'))->format('Y-m-d\T00:00:00\Z')),
            HttpMethod::GET,
            '/yesterday',
            [], [], '', '10.0.0.1',
            'y-day',
        );
        $today = new CapturedRequest(
            CapturedAt::fromString((new \DateTimeImmutable('now'))->format('Y-m-d\T00:00:00\Z')),
            HttpMethod::GET,
            '/today',
            [], [], '', '10.0.0.2',
            't-day',
        );

        $yesterdayFile = $this->tmpDir . '/webhooks-' . (new \DateTimeImmutable('-1 day'))->format('Y-m-d') . '.jsonl';
        $todayFile = $this->tmpDir . '/webhooks-' . date('Y-m-d') . '.jsonl';

        file_put_contents($yesterdayFile, $yesterday->toJson() . "\n", LOCK_EX);
        file_put_contents($todayFile, $today->toJson() . "\n", LOCK_EX);

        $result = $list->handle();

        self::assertCount(2, $result->entries);
        self::assertSame('t-day', $result->entries[0]->captureId);
        self::assertSame('y-day', $result->entries[1]->captureId);
    }

    public function test_empty_repo_returns_empty(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $list = new ListCapturedRequests($repo);

        $result = $list->handle();

        self::assertCount(0, $result->entries);
        self::assertCount(0, $result->dailyArchives);
    }

    public function test_invalid_date_returns_empty(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $list = new ListCapturedRequests($repo);

        $result = $list->handle('not-a-date');

        self::assertCount(0, $result->entries);
        self::assertSame('not-a-date', $result->selectedArchive);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir, SCANDIR_SORT_NONE) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

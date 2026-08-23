<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\CaptureWebhook;
use App\Application\QueryCapturedRequests;
use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\HttpMethod;
use App\Infrastructure\Persistence\SqliteCapturedRequestRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CaptureWebhook::class)]
#[CoversClass(QueryCapturedRequests::class)]
#[UsesClass(SqliteCapturedRequestRepository::class)]
#[UsesClass(CapturedRequest::class)]
#[UsesClass(HttpMethod::class)]
final class SqliteIntegrationTest extends TestCase
{
    private string $tmpDir = '';
    private SqliteCapturedRequestRepository $repo;
    private CaptureWebhook $capture;
    private QueryCapturedRequests $list;

    protected function setUp(): void
    {
        if (!extension_loaded('sqlite3')) {
            self::markTestSkipped('ext-sqlite3 not available');
        }

        $this->tmpDir = \sys_get_temp_dir() . '/kapture_sqlite_int_' . \bin2hex(\random_bytes(4));
        \mkdir($this->tmpDir, 0755, true);
        $this->repo = new SqliteCapturedRequestRepository($this->tmpDir, 99999);
        $this->capture = new CaptureWebhook($this->repo);
        $this->list = new QueryCapturedRequests($this->repo);
    }

    protected function tearDown(): void
    {
        if (!isset($this->tmpDir)) {
            return;
        }
        $this->rmdir($this->tmpDir);
    }

    public function test_capture_then_list_all(): void
    {
        $this->capture->handle('POST', '/first', [], [], '', '');
        $this->capture->handle('GET', '/second', [], [], '', '');

        $result = $this->list->dashboard();

        self::assertCount(2, $result->page->entries);
        $byUri = [];
        foreach ($result->page->entries as $e) {
            $byUri[$e->uri] = $e->method;
        }
        self::assertSame(HttpMethod::POST, $byUri['/first']);
        self::assertSame(HttpMethod::GET, $byUri['/second']);
    }

    public function test_capture_preserves_all_fields(): void
    {
        $this->capture->handle(
            'PUT',
            '/api/data?debug=1',
            ['debug' => '1'],
            ['Authorization' => 'Bearer tok', 'Content-Type' => 'application/json'],
            '{"status":"ok"}',
            '192.168.1.1',
        );

        $result = $this->list->dashboard();
        self::assertCount(1, $result->page->entries);

        $entry = $result->page->entries[0];
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
        $this->capture->handle('GET', '/first', [], [], '', '');
        $this->capture->handle('GET', '/second', [], [], '', '');

        $result = $this->list->dashboard(\date('Y-m-d'));

        self::assertCount(2, $result->page->entries);
        $uris = \array_map(fn($e) => $e->uri, $result->page->entries);
        self::assertContains('/first', $uris);
        self::assertContains('/second', $uris);
    }

    public function test_multiple_dates_sort_newest_first(): void
    {
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

        $this->repo->save($yesterday);
        $this->repo->save($today);

        $result = $this->list->dashboard();

        self::assertCount(2, $result->page->entries);
        self::assertSame('t-day', $result->page->entries[0]->captureId);
        self::assertSame('y-day', $result->page->entries[1]->captureId);
    }

    public function test_empty_repo_returns_empty(): void
    {
        $result = $this->list->dashboard();

        self::assertCount(0, $result->page->entries);
        self::assertCount(0, $result->dailyArchives);
    }

    public function test_invalid_date_returns_empty(): void
    {
        $result = $this->list->dashboard('not-a-date');

        self::assertCount(0, $result->page->entries);
        self::assertSame('not-a-date', $result->selectedArchive);
    }

    private function rmdir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir, \SCANDIR_SORT_NONE) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            \is_dir($path) ? $this->rmdir($path) : \unlink($path);
        }
        \rmdir($dir);
    }
}

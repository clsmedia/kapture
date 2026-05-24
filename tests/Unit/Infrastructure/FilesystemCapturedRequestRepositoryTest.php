<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Domain\CapturedRequest;
use App\Domain\HttpMethod;
use App\Infrastructure\Persistence\FilesystemCapturedRequestRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilesystemCapturedRequestRepository::class)]
final class FilesystemCapturedRequestRepositoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kapture_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);
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

    public function test_save_and_findAll(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $request = CapturedRequest::capture('POST', '/hook', ['key' => 'val'], ['X-Foo' => 'bar'], 'payload', '10.0.0.1');

        $repo->save($request);

        $all = $repo->findAll();
        self::assertCount(1, $all);
        self::assertSame('/hook', $all[0]->uri);
        self::assertSame(HttpMethod::POST, $all[0]->method);
        self::assertSame('payload', $all[0]->body);
        self::assertSame('10.0.0.1', $all[0]->ip);
        self::assertSame(['key' => 'val'], $all[0]->query);
        self::assertArrayNotHasKey('Authorization', $all[0]->headers);
    }

    public function test_findByDate_returns_entries(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save(CapturedRequest::capture('GET', '/a', [], [], '', ''));
        $repo->save(CapturedRequest::capture('POST', '/b', [], [], '', ''));

        $today = new \DateTimeImmutable('today');
        $byDate = $repo->findByDate($today);
        self::assertCount(2, $byDate);
    }

    public function test_findByDate_returns_empty_for_missing(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        self::assertSame([], $repo->findByDate(new \DateTimeImmutable('2000-01-01')));
    }

    public function test_getAvailableDates_returns_sorted_desc(): void
    {
        touch($this->tmpDir . '/webhooks-2025-01-02.jsonl');
        touch($this->tmpDir . '/webhooks-2025-01-01.jsonl');
        touch($this->tmpDir . '/webhooks-2025-01-03.jsonl');

        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $dates = $repo->getAvailableDates();

        self::assertCount(3, $dates);
        self::assertSame('2025-01-03', $dates[0]->format('Y-m-d'));
        self::assertSame('2025-01-02', $dates[1]->format('Y-m-d'));
        self::assertSame('2025-01-01', $dates[2]->format('Y-m-d'));
    }

    public function test_findAll_skips_corrupt_json_lines(): void
    {
        $today = 'webhooks-' . date('Y-m-d') . '.jsonl';
        file_put_contents($this->tmpDir . '/' . $today, "{\"method\":\"GET\",\"uri\":\"/ok\"}\n{\"bad json\n", LOCK_EX);

        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $all = $repo->findAll();

        self::assertCount(1, $all);
    }

    public function test_prune_removes_old_files(): void
    {
        $old = $this->tmpDir . '/webhooks-2020-01-01.jsonl';
        file_put_contents($old, '{}');
        touch($old, time() - 365 * 86400);

        $today = $this->tmpDir . '/webhooks-' . date('Y-m-d') . '.jsonl';
        file_put_contents($today, "{\"method\":\"GET\"}\n");

        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save(CapturedRequest::capture('POST', '/hook', [], [], '', ''));

        self::assertFileDoesNotExist($old);
        self::assertFileExists($today);
    }

    public function test_getEntryCounts_returns_correct_counts(): void
    {
        file_put_contents($this->tmpDir . '/webhooks-2025-01-01.jsonl', "{\"a\":1}\n{\"a\":2}\n{\"a\":3}\n");
        file_put_contents($this->tmpDir . '/webhooks-2025-01-02.jsonl', "{\"b\":1}\n{\"b\":2}\n");
        file_put_contents($this->tmpDir . '/webhooks-2025-01-03.jsonl', "{\"c\":1}\n");

        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $counts = $repo->getEntryCounts();

        self::assertSame(['2025-01-03' => 1, '2025-01-02' => 2, '2025-01-01' => 3], $counts);
    }

    public function test_getEntryCounts_returns_empty_for_empty_dir(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        self::assertSame([], $repo->getEntryCounts());
    }

    public function test_constructor_creates_log_dir(): void
    {
        $newDir = sys_get_temp_dir() . '/kapture_new_' . bin2hex(random_bytes(4));
        try {
            $repo = new FilesystemCapturedRequestRepository($newDir, 7);
            self::assertDirectoryExists($newDir);
        } finally {
            $this->rmdir($newDir);
        }
    }
}

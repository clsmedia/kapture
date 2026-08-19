<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestCriteria;
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

    public function test_delete_removes_entry(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $a = CapturedRequest::capture('POST', '/a', [], [], '', '');
        $b = CapturedRequest::capture('POST', '/b', [], [], '', '');
        $repo->save($a);
        $repo->save($b);

        $repo->delete($a->captureId);

        $all = $repo->findAll();
        self::assertCount(1, $all);
        self::assertSame($b->captureId, $all[0]->captureId);
    }

    public function test_delete_nonexistent_id_is_harmless(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $a = CapturedRequest::capture('POST', '/a', [], [], '', '');
        $repo->save($a);

        $repo->delete('nonexistent-id');

        $all = $repo->findAll();
        self::assertCount(1, $all);
    }

    public function test_deleteMany_removes_multiple_entries(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $a = CapturedRequest::capture('POST', '/a', [], [], '', '');
        $b = CapturedRequest::capture('POST', '/b', [], [], '', '');
        $c = CapturedRequest::capture('POST', '/c', [], [], '', '');
        $repo->save($a);
        $repo->save($b);
        $repo->save($c);

        $repo->deleteMany([$a->captureId, $c->captureId]);

        $all = $repo->findAll();
        self::assertCount(1, $all);
        self::assertSame($b->captureId, $all[0]->captureId);
    }

    public function test_deleteMany_empty_list_is_harmless(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $a = CapturedRequest::capture('POST', '/a', [], [], '', '');
        $repo->save($a);

        $repo->deleteMany([]);

        $all = $repo->findAll();
        self::assertCount(1, $all);
    }

    public function test_deleteMany_removes_entries_across_files(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $a = CapturedRequest::capture('POST', '/a', [], [], '', '');
        $repo->save($a);

        file_put_contents(
            $this->tmpDir . '/webhooks-2025-01-01.jsonl',
            json_encode([
                'capturedAt' => '2025-01-01T00:00:00+00:00',
                'method' => 'GET',
                'uri' => '/old',
                'query' => [],
                'headers' => [],
                'body' => '',
                'ip' => '1.1.1.1',
                'captureId' => 'old-entry-id',
            ], JSON_THROW_ON_ERROR) . "\n",
        );

        $repo->deleteMany(['old-entry-id']);

        $all = $repo->findAll();
        self::assertCount(1, $all);
        self::assertSame($a->captureId, $all[0]->captureId);
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

    public function test_findByCriteria_without_filters_returns_all_ascending(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save($this->captureAt('2025-01-01T00:00:00Z', 'older', 'a'));
        $repo->save($this->captureAt('2025-01-02T00:00:00Z', 'newer', 'b'));

        $entries = $repo->findByCriteria(new CapturedRequestCriteria());

        self::assertCount(2, $entries);
        self::assertSame('older', $entries[0]->captureId);
        self::assertSame('newer', $entries[1]->captureId);
    }

    public function test_findByCriteria_filters_by_correlation_id(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save($this->captureAt('2025-01-01T00:00:00Z', 'a', 'corr-A'));
        $repo->save($this->captureAt('2025-01-01T00:00:01Z', 'b', 'corr-B'));

        $entries = $repo->findByCriteria(new CapturedRequestCriteria(correlationId: 'corr-A'));

        self::assertCount(1, $entries);
        self::assertSame('a', $entries[0]->captureId);
    }

    public function test_findByCriteria_returns_all_requests_sharing_correlation_id(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save($this->captureAt('2025-01-01T00:00:00Z', 'a', 'corr-A'));
        $repo->save($this->captureAt('2025-01-01T00:00:01Z', 'b', 'corr-A'));
        $repo->save($this->captureAt('2025-01-01T00:00:02Z', 'c', 'corr-A'));

        $entries = $repo->findByCriteria(new CapturedRequestCriteria(correlationId: 'corr-A'));

        self::assertCount(3, $entries);
        self::assertSame(['a', 'b', 'c'], array_map(fn($e) => $e->captureId, $entries));
    }

    public function test_findByCriteria_filters_by_capture_id(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save($this->captureAt('2025-01-01T00:00:00Z', 'a'));
        $repo->save($this->captureAt('2025-01-01T00:00:01Z', 'b'));

        $entries = $repo->findByCriteria(new CapturedRequestCriteria(captureId: 'b'));

        self::assertCount(1, $entries);
        self::assertSame('b', $entries[0]->captureId);
    }

    public function test_findByCriteria_filters_by_method(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save($this->request('POST', '/watering', 'a'));
        $repo->save($this->request('GET', '/watering', 'b'));

        $entries = $repo->findByCriteria(new CapturedRequestCriteria(method: HttpMethod::POST));

        self::assertCount(1, $entries);
        self::assertSame('a', $entries[0]->captureId);
    }

    public function test_findByCriteria_filters_by_uri_substring(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save($this->request('POST', '/watering?zone=1', 'a'));
        $repo->save($this->request('POST', '/fertilizing', 'b'));

        $entries = $repo->findByCriteria(new CapturedRequestCriteria(uri: '/watering'));

        self::assertCount(1, $entries);
        self::assertSame('a', $entries[0]->captureId);
    }

    public function test_findByCriteria_filters_by_captured_after_and_before(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save($this->captureAt('2025-01-01T00:00:00Z', 'a'));
        $repo->save($this->captureAt('2025-01-02T00:00:00Z', 'b'));
        $repo->save($this->captureAt('2025-01-03T00:00:00Z', 'c'));

        $entries = $repo->findByCriteria(new CapturedRequestCriteria(
            capturedAfter: CapturedAt::fromString('2025-01-01T00:00:00Z'),
            capturedBefore: CapturedAt::fromString('2025-01-03T00:00:00Z'),
        ));

        self::assertCount(1, $entries);
        self::assertSame('b', $entries[0]->captureId);
    }

    public function test_findByCriteria_applies_limit_after_sorting(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save($this->captureAt('2025-01-01T00:00:00Z', 'a'));
        $repo->save($this->captureAt('2025-01-02T00:00:00Z', 'b'));
        $repo->save($this->captureAt('2025-01-03T00:00:00Z', 'c'));

        $entries = $repo->findByCriteria(new CapturedRequestCriteria(limit: 2));

        self::assertCount(2, $entries);
        self::assertSame(['a', 'b'], array_map(fn($e) => $e->captureId, $entries));
    }

    public function test_findByCriteria_combines_filters(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save($this->request('POST', '/watering', 'a', 'corr-A'));
        $repo->save($this->request('GET', '/watering', 'b', 'corr-A'));
        $repo->save($this->request('POST', '/watering', 'c', 'corr-B'));

        $entries = $repo->findByCriteria(new CapturedRequestCriteria(
            correlationId: 'corr-A',
            method: HttpMethod::POST,
        ));

        self::assertCount(1, $entries);
        self::assertSame('a', $entries[0]->captureId);
    }

    public function test_findByCriteria_no_match_returns_empty(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save($this->captureAt('2025-01-01T00:00:00Z', 'a'));

        self::assertSame([], $repo->findByCriteria(new CapturedRequestCriteria(correlationId: 'nope')));
    }

    public function test_countByCriteria_counts_without_applying_limit(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save($this->captureAt('2025-01-01T00:00:00Z', 'a', 'corr-A'));
        $repo->save($this->captureAt('2025-01-02T00:00:00Z', 'b', 'corr-A'));
        $repo->save($this->captureAt('2025-01-03T00:00:00Z', 'c', 'corr-B'));

        self::assertSame(2, $repo->countByCriteria(new CapturedRequestCriteria(correlationId: 'corr-A')));
        self::assertSame(2, $repo->countByCriteria(new CapturedRequestCriteria(correlationId: 'corr-A', limit: 1)));
        self::assertSame(3, $repo->countByCriteria(new CapturedRequestCriteria()));
        self::assertSame(0, $repo->countByCriteria(new CapturedRequestCriteria(correlationId: 'nope')));
    }

    public function test_findByCriteria_order_desc_returns_newest_first(): void
    {
        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $repo->save($this->captureAt('2025-01-01T00:00:00Z', 'a'));
        $repo->save($this->captureAt('2025-01-02T00:00:00Z', 'b'));
        $repo->save($this->captureAt('2025-01-03T00:00:00Z', 'c'));

        $entries = $repo->findByCriteria(new CapturedRequestCriteria(order: 'desc'));

        self::assertSame(['c', 'b', 'a'], array_map(fn($e) => $e->captureId, $entries));
    }

    private function request(string $method, string $uri, string $captureId, ?string $correlationId = null): CapturedRequest
    {
        return new CapturedRequest(
            CapturedAt::fromString('2025-01-01T00:00:00Z'),
            HttpMethod::tryFromMethod($method) ?? HttpMethod::GET,
            $uri,
            [],
            [],
            '',
            '10.0.0.1',
            $captureId,
            correlationId: $correlationId,
        );
    }

    private function captureAt(string $iso, string $captureId, ?string $correlationId = null): CapturedRequest
    {
        return new CapturedRequest(
            CapturedAt::fromString($iso),
            HttpMethod::GET,
            '/',
            [],
            [],
            '',
            '10.0.0.1',
            $captureId,
            correlationId: $correlationId,
        );
    }
}

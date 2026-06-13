<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\HttpMethod;
use App\Infrastructure\Persistence\SqliteCapturedRequestRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqliteCapturedRequestRepository::class)]
#[UsesClass(CapturedRequest::class)]
#[UsesClass(CapturedAt::class)]
#[UsesClass(HttpMethod::class)]
final class SqliteCapturedRequestRepositoryTest extends TestCase
{
    private string $tmpDir;
    private SqliteCapturedRequestRepository $repo;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/kapture_sqlite_' . \bin2hex(\random_bytes(4));
        \mkdir($this->tmpDir, 0755, true);
        $this->repo = new SqliteCapturedRequestRepository($this->tmpDir, 99999);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);
    }

    public function test_save_and_find_all(): void
    {
        $entry = CapturedRequest::capture('POST', '/test', [], [], 'body', '10.0.0.1');
        $this->repo->save($entry);

        $all = $this->repo->findAll();
        self::assertCount(1, $all);
        self::assertSame($entry->captureId, $all[0]->captureId);
    }

    public function test_empty_db_returns_empty(): void
    {
        self::assertCount(0, $this->repo->findAll());
        self::assertCount(0, $this->repo->getAvailableDates());
    }

    public function test_find_by_date_returns_matching_entries(): void
    {
        $entry = CapturedRequest::capture('GET', '/today', [], [], '', '');
        $this->repo->save($entry);

        $today = new \DateTimeImmutable('now');
        $result = $this->repo->findByDate($today);
        self::assertCount(1, $result);
        self::assertSame($entry->captureId, $result[0]->captureId);
    }

    public function test_find_by_date_with_no_entries_returns_empty(): void
    {
        $result = $this->repo->findByDate(new \DateTimeImmutable('2020-01-01'));
        self::assertCount(0, $result);
    }

    public function test_entries_returned_newest_first(): void
    {
        $older = new CapturedRequest(
            CapturedAt::fromString('2025-01-01T00:00:00Z'),
            HttpMethod::GET,
            '/older',
            [], [], '', '10.0.0.1',
            'older-id',
        );
        $newer = new CapturedRequest(
            CapturedAt::fromString('2025-06-01T00:00:00Z'),
            HttpMethod::GET,
            '/newer',
            [], [], '', '10.0.0.2',
            'newer-id',
        );

        $this->repo->save($older);
        $this->repo->save($newer);

        $all = $this->repo->findAll();
        self::assertCount(2, $all);
        self::assertSame('newer-id', $all[0]->captureId);
        self::assertSame('older-id', $all[1]->captureId);
    }

    public function test_get_available_dates(): void
    {
        $jan = new CapturedRequest(
            CapturedAt::fromString('2025-01-15T00:00:00Z'),
            HttpMethod::GET,
            '/jan', [], [], '', '1.1.1.1',
            'jan-id',
        );
        $feb = new CapturedRequest(
            CapturedAt::fromString('2025-02-15T00:00:00Z'),
            HttpMethod::GET,
            '/feb', [], [], '', '2.2.2.2',
            'feb-id',
        );

        $this->repo->save($jan);
        $this->repo->save($feb);

        $dates = $this->repo->getAvailableDates();
        self::assertCount(2, $dates);
        // Newest first
        self::assertSame('2025-02-15', $dates[0]->format('Y-m-d'));
        self::assertSame('2025-01-15', $dates[1]->format('Y-m-d'));
    }

    public function test_preserves_all_fields(): void
    {
        $entry = CapturedRequest::capture(
            'PUT',
            '/api/data?debug=1',
            ['debug' => '1'],
            ['Content-Type' => 'application/json'],
            '{"status":"ok"}',
            '192.168.1.1',
        );
        $this->repo->save($entry);

        $all = $this->repo->findAll();
        self::assertCount(1, $all);

        $loaded = $all[0];
        self::assertSame(HttpMethod::PUT, $loaded->method);
        self::assertSame('/api/data?debug=1', $loaded->uri);
        self::assertSame(['debug' => '1'], $loaded->query);
        self::assertSame('application/json', $loaded->headers['Content-Type']);
        self::assertSame('{"status":"ok"}', $loaded->body);
        self::assertSame('192.168.1.1', $loaded->ip);
        self::assertSame($entry->captureId, $loaded->captureId);
    }

    public function test_find_by_date_respects_date_boundary(): void
    {
        $janEntry = new CapturedRequest(
            CapturedAt::fromString('2025-01-01T23:59:59Z'),
            HttpMethod::GET,
            '/jan-eve', [], [], '', '1.1.1.1',
            'jan-eve-id',
        );
        $febEntry = new CapturedRequest(
            CapturedAt::fromString('2025-02-01T00:00:00Z'),
            HttpMethod::GET,
            '/feb-start', [], [], '', '2.2.2.2',
            'feb-start-id',
        );

        $this->repo->save($janEntry);
        $this->repo->save($febEntry);

        $janResult = $this->repo->findByDate(new \DateTimeImmutable('2025-01-01'));
        self::assertCount(1, $janResult);
        self::assertSame('jan-eve-id', $janResult[0]->captureId);

        $febResult = $this->repo->findByDate(new \DateTimeImmutable('2025-02-01'));
        self::assertCount(1, $febResult);
        self::assertSame('feb-start-id', $febResult[0]->captureId);
    }

    public function test_getEntryCounts_returns_counts_grouped_by_date(): void
    {
        $jan = new CapturedRequest(
            CapturedAt::fromString('2025-01-15T00:00:00Z'),
            HttpMethod::GET,
            '/jan', [], [], '', '1.1.1.1',
            'jan-id',
        );
        $feb1 = new CapturedRequest(
            CapturedAt::fromString('2025-02-01T00:00:00Z'),
            HttpMethod::GET,
            '/feb-1', [], [], '', '2.2.2.2',
            'feb-1-id',
        );
        $feb2 = new CapturedRequest(
            CapturedAt::fromString('2025-02-15T00:00:00Z'),
            HttpMethod::GET,
            '/feb-2', [], [], '', '2.2.2.3',
            'feb-2-id',
        );

        $this->repo->save($jan);
        $this->repo->save($feb1);
        $this->repo->save($feb2);

        $counts = $this->repo->getEntryCounts();
        self::assertCount(3, $counts);
        self::assertSame(1, $counts['2025-01-15']);
        self::assertSame(1, $counts['2025-02-01']);
        self::assertSame(1, $counts['2025-02-15']);
    }

    public function test_getEntryCounts_returns_empty_for_empty_db(): void
    {
        $repo = new SqliteCapturedRequestRepository($this->tmpDir, 99999);
        self::assertSame([], $repo->getEntryCounts());
    }

    public function test_multiple_saves_increase_count(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $entry = CapturedRequest::capture('GET', "/path-{$i}", [], [], '', '');
            $this->repo->save($entry);
        }

        self::assertCount(5, $this->repo->findAll());
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
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

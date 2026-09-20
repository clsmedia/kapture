<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\RunRecurringAnalysis;
use App\Domain\CapturedRequest;
use App\Infrastructure\Persistence\FilesystemCapturedRequestRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunRecurringAnalysis::class)]
final class RunRecurringAnalysisTest extends TestCase
{
    private string $tmpDir;
    private FilesystemCapturedRequestRepository $repo;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kapture_recurring_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
        $this->repo = new FilesystemCapturedRequestRepository($this->tmpDir, 30);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);
    }

    public function test_is_due_when_no_state_file_exists(): void
    {
        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));

        self::assertTrue($analyzer->isDue($now));
    }

    public function test_is_due_when_state_file_is_empty(): void
    {
        file_put_contents($this->tmpDir . '/recurring-state.json', '');
        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));

        self::assertTrue($analyzer->isDue($now));
    }

    public function test_is_due_when_state_file_is_corrupt(): void
    {
        file_put_contents($this->tmpDir . '/recurring-state.json', '{not valid json');
        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));

        self::assertTrue($analyzer->isDue($now));
    }

    public function test_is_due_when_state_has_no_last_run_at(): void
    {
        file_put_contents($this->tmpDir . '/recurring-state.json', '{"patterns":{}}');
        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));

        self::assertTrue($analyzer->isDue($now));
    }

    public function test_run_recovers_from_stale_empty_state_file(): void
    {
        file_put_contents($this->tmpDir . '/recurring-state.json', '');
        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));

        $report = $analyzer->run($now);

        self::assertNotNull($report);
        $state = json_decode(file_get_contents($this->tmpDir . '/recurring-state.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-08T07:00:00Z', $state['lastRunAt']);
    }

    public function test_is_not_due_when_recently_run(): void
    {
        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));

        // Simulate a recent run by writing state and touching marker
        $analyzer->run($now);

        self::assertFalse($analyzer->isDue($now));
    }

    public function test_is_due_when_interval_expired(): void
    {
        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $firstRun = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));
        $analyzer->run($firstRun);

        // 25 hours later → due
        $later = new \DateTimeImmutable('2026-09-09T08:00:00Z', new \DateTimeZone('UTC'));
        self::assertTrue($analyzer->isDue($later));
    }

    public function test_run_with_no_entries_returns_empty_report(): void
    {
        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));

        $report = $analyzer->run($now);

        self::assertNotNull($report);
        self::assertSame(0, $report->scannedEntries);
        self::assertSame([], $report->periodicPatterns);
        self::assertSame([], $report->topOffenders);
    }

    public function test_run_writes_state_file(): void
    {
        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));

        $analyzer->run($now);

        $stateFile = $this->tmpDir . '/recurring-state.json';
        self::assertFileExists($stateFile);
        $state = json_decode(file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('lastRunAt', $state);
        self::assertSame('2026-09-08T07:00:00Z', $state['lastRunAt']);
    }

    public function test_run_appends_report_jsonl(): void
    {
        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));

        $analyzer->run($now);

        $reportFile = $this->tmpDir . '/recurring-report.jsonl';
        self::assertFileExists($reportFile);
        $line = trim(file_get_contents($reportFile));
        $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('2026-09-08T07:00:00Z', $decoded['runAt']);
        self::assertSame(7, $decoded['windowDays']);
    }

    public function test_run_returns_null_when_not_due(): void
    {
        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));

        $analyzer->run($now); // first run
        $result = $analyzer->run($now); // immediate re-run

        self::assertNull($result);
    }

    public function test_run_detects_periodic_pattern_and_persists(): void
    {
        // Seed 7 hourly entries — must be within the 7-day window from $now
        $base = strtotime('2026-09-02T00:00:00Z');
        foreach (range(0, 6) as $i) {
            $this->saveEntry('GET', '/status', '10.0.0.1', date('Y-m-d\TH:i:s\Z', $base + $i * 3600));
        }

        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));

        $report = $analyzer->run($now);

        self::assertNotNull($report);
        self::assertCount(1, $report->periodicPatterns);
        self::assertSame(3600, $report->periodicPatterns[0]->periodSeconds);

        // State records the pattern
        $state = json_decode(file_get_contents($this->tmpDir . '/recurring-state.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('GET /status 10.0.0.1', $state['patterns']);
    }

    public function test_run_only_reports_new_patterns_not_old(): void
    {
        // Seed 7 hourly entries within the window
        $base = strtotime('2026-09-02T00:00:00Z');
        foreach (range(0, 6) as $i) {
            $this->saveEntry('GET', '/status', '10.0.0.1', date('Y-m-d\TH:i:s\Z', $base + $i * 3600));
        }

        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));

        $report1 = $analyzer->run($now);
        self::assertNotNull($report1);
        self::assertCount(1, $report1->periodicPatterns);

        // Second run, same entries, same state → no new patterns
        $report2 = $analyzer->run(new \DateTimeImmutable('2026-09-09T07:00:00Z', new \DateTimeZone('UTC')));
        self::assertNotNull($report2);
        self::assertCount(0, $report2->periodicPatterns);
    }

    public function test_run_reports_changed_pattern_when_period_shifts(): void
    {
        // First run: 5-min pattern within the window
        $base = strtotime('2026-09-02T00:00:00Z');
        foreach (range(0, 6) as $i) {
            $this->saveEntry('GET', '/ping', '10.0.0.1', date('Y-m-d\TH:i:s\Z', $base + $i * 300));
        }

        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now1 = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));
        $report1 = $analyzer->run($now1);
        self::assertSame(300, $report1->periodicPatterns[0]->periodSeconds);

        // Second run: data now shows 10-min pattern (>20% change from 300s)
        $this->repo->deleteMany(array_map(
            static fn(CapturedRequest $e): string => $e->captureId,
            $this->repo->findAll(),
        ));
        $base2 = strtotime('2026-09-03T00:00:00Z');
        foreach (range(0, 6) as $i) {
            $this->saveEntry('GET', '/ping', '10.0.0.1', date('Y-m-d\TH:i:s\Z', $base2 + $i * 600));
        }

        $now2 = new \DateTimeImmutable('2026-09-09T07:00:00Z', new \DateTimeZone('UTC'));
        $report2 = $analyzer->run($now2);
        self::assertNotNull($report2);
        self::assertCount(1, $report2->periodicPatterns);
        self::assertSame(600, $report2->periodicPatterns[0]->periodSeconds);
    }

    public function test_state_file_survives_prune(): void
    {
        $analyzer = new RunRecurringAnalysis($this->repo, $this->tmpDir, 7);
        $now = new \DateTimeImmutable('2026-09-08T07:00:00Z', new \DateTimeZone('UTC'));
        $analyzer->run($now);

        // Trigger prune (save a new entry with old date to trigger prune)
        $this->saveEntry('GET', '/x', '1.2.3.4', '2020-01-01T00:00:00Z');

        self::assertFileExists($this->tmpDir . '/recurring-state.json');
        self::assertFileExists($this->tmpDir . '/recurring-report.jsonl');
    }

    private function saveEntry(string $method, string $uri, string $ip, string $capturedAt): void
    {
        $entry = CapturedRequest::fromArray([
            'capturedAt' => $capturedAt,
            'method'     => $method,
            'uri'        => $uri,
            'query'      => [],
            'headers'    => [],
            'body'       => '',
            'ip'         => $ip,
            'captureId'  => bin2hex(random_bytes(16)),
        ]);
        $this->repo->save($entry);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_merge(
            glob($dir . '/*') ?: [],
            glob($dir . '/.*') ?: [],
        );
        foreach ($items as $file) {
            if ($file === $dir || $file === $dir . '/.' || $file === $dir . '/..') {
                continue;
            }
            is_file($file) ? unlink($file) : $this->rmdir($file);
        }
        rmdir($dir);
    }
}

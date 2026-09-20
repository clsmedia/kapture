<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Application\DetectRecurring;
use App\Application\RunRecurringAnalysis;
use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\RecurringPattern;
use App\Domain\RecurringReport;
use App\Infrastructure\Persistence\FilesystemCapturedRequestRepository;
use App\Presentation\Html\AnalysisView;
use App\Presentation\Http\AnalysisController;
use App\Presentation\Http\BasicAuthGuard;
use App\Presentation\Http\CsrfToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalysisController::class)]
#[CoversClass(CsrfToken::class)]
#[UsesClass(RunRecurringAnalysis::class)]
#[UsesClass(DetectRecurring::class)]
#[UsesClass(AnalysisView::class)]
#[UsesClass(BasicAuthGuard::class)]
#[UsesClass(RecurringReport::class)]
#[UsesClass(RecurringPattern::class)]
#[UsesClass(CapturedRequest::class)]
#[UsesClass(CapturedAt::class)]
#[UsesClass(FilesystemCapturedRequestRepository::class)]
final class AnalysisControllerTest extends TestCase
{
    private string $tmpDir;
    private FilesystemCapturedRequestRepository $repo;
    private AnalysisController $controller;
    private array $savedGet;
    private array $savedServer;
    private array $savedCookie;

    protected function setUp(): void
    {
        $this->savedGet = $_GET;
        $this->savedServer = $_SERVER;
        $this->savedCookie = $_COOKIE;
        http_response_code(200);
        header_remove();

        $this->tmpDir = sys_get_temp_dir() . '/kapture_analysis_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);

        $this->repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $this->controller = new AnalysisController(
            new RunRecurringAnalysis($this->repo, $this->tmpDir, 7),
            new AnalysisView(),
            'secret',
        );

        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';
    }

    protected function tearDown(): void
    {
        $_GET = $this->savedGet;
        $_SERVER = $this->savedServer;
        $_COOKIE = $this->savedCookie;
        $this->rmdir($this->tmpDir);
        http_response_code(200);
        header_remove();
    }

    public function test_page_renders_analysis_shell(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/analysis';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        $this->controller->handle();
        $output = (string) ob_get_clean();

        self::assertSame(200, http_response_code());
        self::assertStringContainsString('kaptureAnalysis', $output);
        self::assertStringContainsString('/admin', $output);
    }

    public function test_api_analysis_runs_when_due_and_returns_report(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/api/analysis';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        $this->controller->handle();
        $output = (string) ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, http_response_code());
        self::assertNotNull($data['report']);
        self::assertFalse($data['due']);
        self::assertSame(0, $data['report']['scannedEntries']);
        self::assertArrayHasKey('csrfToken', $data);
        self::assertFileExists($this->tmpDir . '/recurring-report.json');
    }

    public function test_api_analysis_returns_cached_snapshot_when_not_due(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $yesterday = new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC'));

        file_put_contents($this->tmpDir . '/recurring-state.json', json_encode([
            'lastRunAt' => $now->format('Y-m-d\TH:i:s\Z'),
            'patterns'  => [],
        ], JSON_THROW_ON_ERROR));

        $report = new RecurringReport(
            runAt: CapturedAt::fromDateTime($yesterday),
            windowDays: 7,
            scannedEntries: 5,
            periodicPatterns: [],
            topOffenders: [],
            uniqueFingerprints: 2,
        );
        file_put_contents($this->tmpDir . '/recurring-report.json', $report->toJson() . "\n");

        $_SERVER['REQUEST_URI'] = '/admin/api/analysis';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        $this->controller->handle();
        $output = (string) ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($data['due']);
        self::assertSame(5, $data['report']['scannedEntries']);
        self::assertSame($yesterday->format('Y-m-d\TH:i:s\Z'), $data['report']['runAt']);
        self::assertSame($now->format('Y-m-d\TH:i:s\Z'), $data['lastRunAt']);
    }

    public function test_api_run_requires_post(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/api/analysis/run';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        $this->controller->handle();
        $output = (string) ob_get_clean();

        self::assertSame(405, http_response_code());
        self::assertSame('method not allowed', json_decode($output, true, flags: JSON_THROW_ON_ERROR)['error']);
    }

    public function test_api_run_rejects_missing_csrf_token(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/api/analysis/run';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        $this->controller->handle();
        $output = (string) ob_get_clean();

        self::assertSame(403, http_response_code());
        self::assertStringContainsString('CSRF', json_decode($output, true, flags: JSON_THROW_ON_ERROR)['error']);
    }

    public function test_api_run_forces_analysis_when_not_due(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Recent state → not due, with a stale snapshot
        file_put_contents($this->tmpDir . '/recurring-state.json', json_encode([
            'lastRunAt' => $now->format('Y-m-d\TH:i:s\Z'),
            'patterns'  => [],
        ], JSON_THROW_ON_ERROR));
        $stale = new RecurringReport(
            runAt: CapturedAt::fromDateTime(new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC'))),
            windowDays: 7,
            scannedEntries: 0,
            periodicPatterns: [],
            topOffenders: [],
        );
        file_put_contents($this->tmpDir . '/recurring-report.json', $stale->toJson() . "\n");

        $this->saveEntry();

        $_COOKIE['XSRF-TOKEN'] = 'csrf-token-123';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'csrf-token-123';
        $_SERVER['REQUEST_URI'] = '/admin/api/analysis/run';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        $this->controller->handle();
        $output = (string) ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, http_response_code());
        self::assertSame(1, $data['report']['scannedEntries']);
        self::assertSame($now->format('Y-m-d\TH:i:s\Z'), $data['lastRunAt']);
    }

    public function test_api_run_returns_409_when_analysis_locked(): void
    {
        // Hold the lock in this process
        $lockPath = $this->tmpDir . '/.recurring.lock';
        $fp = fopen($lockPath, 'c');
        self::assertIsResource($fp);
        flock($fp, LOCK_EX);

        $_COOKIE['XSRF-TOKEN'] = 'csrf-token-123';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'csrf-token-123';
        $_SERVER['REQUEST_URI'] = '/admin/api/analysis/run';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        $this->controller->handle();
        $output = (string) ob_get_clean();

        flock($fp, LOCK_UN);
        fclose($fp);

        self::assertSame(409, http_response_code());
        self::assertSame('analysis_busy', json_decode($output, true, flags: JSON_THROW_ON_ERROR)['code']);
    }

    private function saveEntry(): void
    {
        $this->repo->save(CapturedRequest::fromArray([
            'capturedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'method'     => 'GET',
            'uri'        => '/probe',
            'query'      => [],
            'headers'    => [],
            'body'       => '',
            'ip'         => '10.0.0.1',
            'captureId'  => bin2hex(random_bytes(16)),
        ]));
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir, SCANDIR_SORT_NONE) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

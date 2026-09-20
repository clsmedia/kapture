<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Application\CaptureWebhook;
use App\Application\DetectRecurring;
use App\Application\GenerateReplayFile;
use App\Application\GetCapturedRequest;
use App\Application\QueryCapturedRequests;
use App\Application\RunRecurringAnalysis;
use App\Infrastructure\Persistence\FilesystemCapturedRequestRepository;
use App\Presentation\Html\AdminView;
use App\Presentation\Html\AnalysisView;
use App\Presentation\Http\AdminController;
use App\Presentation\Http\AnalysisController;
use App\Presentation\Http\ApiController;
use App\Presentation\Http\BasicAuthGuard;
use App\Presentation\Http\CsrfToken;
use App\Presentation\Http\Router;
use App\Presentation\Http\WebhookController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[UsesClass(WebhookController::class)]
#[UsesClass(ApiController::class)]
#[UsesClass(AdminController::class)]
#[UsesClass(AnalysisController::class)]
#[UsesClass(CaptureWebhook::class)]
#[UsesClass(DetectRecurring::class)]
#[UsesClass(RunRecurringAnalysis::class)]
#[UsesClass(GenerateReplayFile::class)]
#[UsesClass(GetCapturedRequest::class)]
#[UsesClass(QueryCapturedRequests::class)]
#[UsesClass(FilesystemCapturedRequestRepository::class)]
#[UsesClass(AdminView::class)]
#[UsesClass(AnalysisView::class)]
#[UsesClass(BasicAuthGuard::class)]
#[UsesClass(CsrfToken::class)]
final class RouterTest extends TestCase
{
    private string $tmpDir;
    private Router $router;
    private array $savedServer;
    private array $savedGet;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $this->savedGet = $_GET;
        http_response_code(200);
        header_remove();

        $this->tmpDir = sys_get_temp_dir() . '/kapture_router_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);

        $repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $this->router = new Router(
            new WebhookController(new CaptureWebhook($repo), $repo, null, bin2hex(random_bytes(4))),
            new AdminController(new QueryCapturedRequests($repo), $repo, new GenerateReplayFile(new GetCapturedRequest($repo)), new AdminView(), 'admin-pass'),
            new ApiController(
                new GetCapturedRequest($repo),
                new QueryCapturedRequests($repo),
                'api-token',
                true,
            ),
            new AnalysisController(
                new RunRecurringAnalysis($repo, $this->tmpDir, 7),
                new AnalysisView(),
                'admin-pass',
            ),
        );
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_GET = $this->savedGet;
        $this->rmdir($this->tmpDir);
        http_response_code(200);
        header_remove();
    }

    public function test_api_route_dispatches_to_api_controller(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/captures?correlationId=x';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer api-token';

        ob_start();
        $this->router->dispatch('/api/v1/captures');
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertSame(200, http_response_code());
        self::assertSame([], $data['captures']);
        self::assertSame(0, $data['total']);
    }

    public function test_api_route_requires_bearer_token(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/captures';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_AUTHORIZATION']);

        ob_start();
        $this->router->dispatch('/api/v1/captures');
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertSame(401, http_response_code());
        self::assertSame('unauthorized', $data['error']);
    }

    public function test_capture_route_works_without_token(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/captures/some-id';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_AUTHORIZATION']);

        ob_start();
        $this->router->dispatch('/api/v1/captures/some-id');
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertSame(404, http_response_code());
        self::assertSame('capture_not_found', $data['code']);
    }

    public function test_webhook_route_still_dispatches(): void
    {
        $_SERVER['REQUEST_URI'] = '/kapture/test';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        $this->router->dispatch('/kapture/test');
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertSame(200, http_response_code());
        self::assertSame(true, $data['ok']);
        self::assertArrayHasKey('captureId', $data);
    }

    public function test_analysis_route_dispatches_to_analysis_controller(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/analysis';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'admin-pass';

        ob_start();
        $this->router->dispatch('/admin/analysis');
        $output = (string) ob_get_clean();

        self::assertSame(200, http_response_code());
        self::assertStringContainsString('kaptureAnalysis', $output);
    }

    public function test_root_returns_health_check(): void
    {
        ob_start();
        $this->router->dispatch('/', 'GET');
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertSame(200, http_response_code());
        self::assertSame('ok', $data['status']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $data['time']));
        self::assertStringEndsWith('+00:00', $data['time']);
        self::assertStringNotContainsStringIgnoringCase('kapture', (string) $output);
    }

    public function test_root_head_behaves_like_get(): void
    {
        ob_start();
        $this->router->dispatch('/', 'HEAD');
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertSame(200, http_response_code());
        self::assertSame('ok', $data['status']);
    }

    public function test_root_non_get_methods_and_path_variants_stay_404(): void
    {
        foreach (['POST', 'DELETE', 'OPTIONS'] as $method) {
            ob_start();
            $this->router->dispatch('/', $method);
            $output = ob_get_clean();

            $data = json_decode((string) $output, true);
            self::assertSame(404, http_response_code(), $method);
            self::assertSame('not found', $data['error'], $method);
        }

        ob_start();
        $this->router->dispatch('//', 'GET');
        $output = ob_get_clean();
        self::assertSame(404, http_response_code());
        self::assertSame('not found', json_decode((string) $output, true)['error']);
    }

    public function test_unknown_route_returns_404(): void
    {
        ob_start();
        $this->router->dispatch('/nonexistent');
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertSame(404, http_response_code());
        self::assertSame('not found', $data['error']);
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

<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Application\ListCapturedRequests;
use App\Application\ListCapturedRequestsResult;
use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestRepository;
use App\Domain\HttpMethod;
use App\Presentation\Html\AdminView;
use App\Presentation\Http\AdminController;
use App\Presentation\Http\BasicAuthGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminController::class)]
#[UsesClass(ListCapturedRequests::class)]
#[UsesClass(ListCapturedRequestsResult::class)]
#[UsesClass(CapturedRequest::class)]
#[UsesClass(CapturedAt::class)]
#[UsesClass(HttpMethod::class)]
#[UsesClass(BasicAuthGuard::class)]
#[UsesClass(AdminView::class)]
final class AdminControllerTest extends TestCase
{
    private array $savedGet;
    private array $savedServer;

    protected function setUp(): void
    {
        $this->savedGet = $_GET;
        $this->savedServer = $_SERVER;
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_GET = $this->savedGet;
        $_SERVER = $this->savedServer;
    }

    public function test_json_format_returns_entries(): void
    {
        $entry = new CapturedRequest(
            CapturedAt::fromString('2026-05-24T12:00:00Z'),
            HttpMethod::POST,
            '/test/endpoint',
            ['q' => '1'],
            ['X-Custom' => 'val'],
            '{"ok":true}',
            '10.0.0.1',
            'abc123',
        );

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn([$entry]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([]);

        $listUseCase = new ListCapturedRequests($repo);

        $_GET['format'] = 'json';
        $_SERVER['REQUEST_URI'] = '/admin?format=json';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        $controller = new AdminController($listUseCase, $repo, new AdminView(), 'secret');

        ob_start();
        $controller->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('entries', $data);
        self::assertCount(1, $data['entries']);
        self::assertSame('POST', $data['entries'][0]['method']);
        self::assertSame('/test/endpoint', $data['entries'][0]['uri']);
        self::assertSame(['q' => '1'], $data['entries'][0]['query']);
        self::assertSame(['X-Custom' => 'val'], $data['entries'][0]['headers']);
        self::assertSame('{"ok":true}', $data['entries'][0]['body']);
        self::assertSame('10.0.0.1', $data['entries'][0]['ip']);
        self::assertSame('abc123', $data['entries'][0]['captureId']);
        self::assertSame('2026-05-24 12:00:00 UTC', $data['entries'][0]['capturedAtHuman']);
        self::assertArrayHasKey('archive', $data);
        self::assertNull($data['archive']);
    }

    public function test_json_format_archive_is_set(): void
    {
        $dt = new \DateTimeImmutable('2026-05-24');

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findByDate')->willReturn([]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([$dt]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn(['2026-05-24' => 0]);

        $listUseCase = new ListCapturedRequests($repo);

        $_GET['format'] = 'json';
        $_GET['file'] = '2026-05-24';
        $_SERVER['REQUEST_URI'] = '/admin?file=2026-05-24&format=json';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        $controller = new AdminController($listUseCase, $repo, new AdminView(), 'secret');

        ob_start();
        $controller->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('2026-05-24', $data['archive']);
    }

    public function test_delete_calls_repository_and_redirects(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('delete')->with('abc123');

        $controller = new AdminController(
            new ListCapturedRequests($repo),
            $repo,
            new AdminView(),
            'secret',
        );

        $_GET['delete'] = 'abc123';
        $_SERVER['REQUEST_URI'] = '/admin?delete=abc123';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        header_remove();
        $controller->handle();

        self::assertSame(302, http_response_code());
    }

    public function test_delete_preserves_file_param(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('delete')->with('abc123');

        $controller = new AdminController(
            new ListCapturedRequests($repo),
            $repo,
            new AdminView(),
            'secret',
        );

        $_GET['delete'] = 'abc123';
        $_GET['file'] = '2026-05-24';
        $_SERVER['REQUEST_URI'] = '/admin?file=2026-05-24&delete=abc123';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        header_remove();
        $controller->handle();

        self::assertSame(302, http_response_code());
    }

    public function test_json_format_empty_repo(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn([]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([]);

        $listUseCase = new ListCapturedRequests($repo);

        $_GET['format'] = 'json';
        $_SERVER['REQUEST_URI'] = '/admin?format=json';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        $controller = new AdminController($listUseCase, $repo, new AdminView(), 'secret');

        ob_start();
        $controller->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(0, $data['entries']);
        self::assertNull($data['archive']);
    }
}

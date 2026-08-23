<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Application\GenerateReplayFile;
use App\Application\GetCapturedRequest;
use App\Application\QueryCapturedRequests;
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
#[UsesClass(GenerateReplayFile::class)]
#[UsesClass(QueryCapturedRequests::class)]
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
    private array $savedCookie;

    protected function setUp(): void
    {
        $this->savedGet = $_GET;
        $this->savedServer = $_SERVER;
        $this->savedCookie = $_COOKIE;
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_GET = $this->savedGet;
        $_SERVER = $this->savedServer;
        $_COOKIE = $this->savedCookie;
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
        $repo->expects(self::once())->method('findWithTotal')->willReturn([[$entry], 1]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([]);

        $listUseCase = new QueryCapturedRequests($repo);

        $_GET['format'] = 'json';
        $_SERVER['REQUEST_URI'] = '/admin?format=json';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        $controller = new AdminController($listUseCase, $repo, new GenerateReplayFile(new GetCapturedRequest($repo)), new AdminView(), 'secret');

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
        self::assertArrayHasKey('archive', $data);
        self::assertNull($data['archive']);
    }

    public function test_rows_format_returns_html_fragment(): void
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
        $repo->expects(self::once())->method('findWithTotal')->willReturn([[$entry], 1]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([]);

        $listUseCase = new QueryCapturedRequests($repo);

        $_GET['format'] = 'rows';
        $_SERVER['REQUEST_URI'] = '/admin?format=rows';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        $controller = new AdminController($listUseCase, $repo, new GenerateReplayFile(new GetCapturedRequest($repo)), new AdminView(), 'secret');

        ob_start();
        $controller->handle();
        $output = ob_get_clean();

        self::assertStringContainsString('class="row"', $output);
        self::assertStringContainsString('data-capture-id="abc123"', $output);
        self::assertStringContainsString('id="detail-0"', $output);
        self::assertStringNotContainsString('<!DOCTYPE', $output);
        self::assertStringNotContainsString('log-table', $output);
    }

    public function test_json_format_archive_is_set(): void
    {
        $dt = new \DateTimeImmutable('2026-05-24');

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findWithTotal')->willReturn([[], 0]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([$dt]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn(['2026-05-24' => 0]);

        $listUseCase = new QueryCapturedRequests($repo);

        $_GET['format'] = 'json';
        $_GET['file'] = '2026-05-24';
        $_SERVER['REQUEST_URI'] = '/admin?file=2026-05-24&format=json';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        $controller = new AdminController($listUseCase, $repo, new GenerateReplayFile(new GetCapturedRequest($repo)), new AdminView(), 'secret');

        ob_start();
        $controller->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('2026-05-24', $data['archive']);
    }

    public function test_delete_calls_repository_and_redirects(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('deleteMany')->with(['abc123']);

        $controller = new AdminController(
            new QueryCapturedRequests($repo),
            $repo,
            new GenerateReplayFile(new GetCapturedRequest($repo)),
            new AdminView(),
            'secret',
        );

        $_COOKIE['XSRF-TOKEN'] = 'valid-csrf-token';
        $_GET['delete'] = 'abc123';
        $_GET['_csrf'] = 'valid-csrf-token';
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
        $repo->expects(self::once())->method('deleteMany')->with(['abc123']);

        $controller = new AdminController(
            new QueryCapturedRequests($repo),
            $repo,
            new GenerateReplayFile(new GetCapturedRequest($repo)),
            new AdminView(),
            'secret',
        );

        $_COOKIE['XSRF-TOKEN'] = 'valid-csrf-token';
        $_GET['delete'] = 'abc123';
        $_GET['_csrf'] = 'valid-csrf-token';
        $_GET['file'] = '2026-05-24';
        $_SERVER['REQUEST_URI'] = '/admin?file=2026-05-24&delete=abc123';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        header_remove();
        $controller->handle();

        self::assertSame(302, http_response_code());
    }

    public function test_bulk_delete_calls_repository_with_all_ids(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('deleteMany')->with(['abc123', 'def456']);

        $controller = new AdminController(
            new QueryCapturedRequests($repo),
            $repo,
            new GenerateReplayFile(new GetCapturedRequest($repo)),
            new AdminView(),
            'secret',
        );

        $_COOKIE['XSRF-TOKEN'] = 'valid-csrf-token';
        $_GET['delete'] = ['abc123', 'def456'];
        $_GET['_csrf'] = 'valid-csrf-token';
        $_SERVER['REQUEST_URI'] = '/admin?delete=abc123&delete=def456';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        header_remove();
        $controller->handle();

        self::assertSame(302, http_response_code());
    }

    public function test_bulk_delete_filters_empty_ids(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('deleteMany')->with(['abc123']);

        $controller = new AdminController(
            new QueryCapturedRequests($repo),
            $repo,
            new GenerateReplayFile(new GetCapturedRequest($repo)),
            new AdminView(),
            'secret',
        );

        $_COOKIE['XSRF-TOKEN'] = 'valid-csrf-token';
        $_GET['delete'] = ['abc123', ''];
        $_GET['_csrf'] = 'valid-csrf-token';
        $_SERVER['REQUEST_URI'] = '/admin?delete=abc123&delete=';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        header_remove();
        $controller->handle();

        self::assertSame(302, http_response_code());
    }

    public function test_bulk_delete_parses_array_syntax_from_query_string(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('deleteMany')->with(['abc123', 'def456']);

        $controller = new AdminController(
            new QueryCapturedRequests($repo),
            $repo,
            new GenerateReplayFile(new GetCapturedRequest($repo)),
            new AdminView(),
            'secret',
        );

        $_COOKIE['XSRF-TOKEN'] = 'valid-csrf-token';
        parse_str('delete[]=abc123&delete[]=def456&_csrf=valid-csrf-token', $_GET);
        $_SERVER['REQUEST_URI'] = '/admin?delete[]=abc123&delete[]=def456';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        header_remove();
        $controller->handle();

        self::assertSame(302, http_response_code());
    }

    public function test_delete_rejects_missing_csrf_token(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('deleteMany');

        $controller = new AdminController(
            new QueryCapturedRequests($repo),
            $repo,
            new GenerateReplayFile(new GetCapturedRequest($repo)),
            new AdminView(),
            'secret',
        );

        $_GET['delete'] = 'abc123';
        $_SERVER['REQUEST_URI'] = '/admin?delete=abc123';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        ob_start();
        $controller->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, http_response_code());
        self::assertSame('Invalid or missing CSRF token', $data['error']);
    }

    public function test_delete_rejects_mismatched_csrf_token(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('deleteMany');

        $controller = new AdminController(
            new QueryCapturedRequests($repo),
            $repo,
            new GenerateReplayFile(new GetCapturedRequest($repo)),
            new AdminView(),
            'secret',
        );

        $_COOKIE['XSRF-TOKEN'] = 'valid-token';
        $_GET['delete'] = 'abc123';
        $_GET['_csrf'] = 'wrong-token';
        $_SERVER['REQUEST_URI'] = '/admin?delete=abc123';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        ob_start();
        $controller->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, http_response_code());
        self::assertSame('Invalid or missing CSRF token', $data['error']);
    }

    public function test_json_format_empty_repo(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findWithTotal')->willReturn([[], 0]);
        $repo->expects(self::once())->method('getAvailableDates')->willReturn([]);
        $repo->expects(self::once())->method('getEntryCounts')->willReturn([]);

        $listUseCase = new QueryCapturedRequests($repo);

        $_GET['format'] = 'json';
        $_SERVER['REQUEST_URI'] = '/admin?format=json';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        $controller = new AdminController($listUseCase, $repo, new GenerateReplayFile(new GetCapturedRequest($repo)), new AdminView(), 'secret');

        ob_start();
        $controller->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(0, $data['entries']);
        self::assertNull($data['archive']);
    }

    public function test_replay_returns_content(): void
    {
        $entry = new CapturedRequest(
            CapturedAt::fromString('2026-05-24T12:00:00Z'),
            HttpMethod::POST,
            '/webhook/events',
            ['q' => '1'],
            ['X-Custom' => 'val'],
            '{"ok":true}',
            '10.0.0.1',
            'abc123',
        );

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->method('findByCriteria')->willReturn([$entry]);

        $_GET['replay'] = 'abc123';
        $_GET['format'] = 'http';
        $_SERVER['REQUEST_URI'] = '/admin?replay=abc123&format=http';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        $controller = new AdminController(
            new QueryCapturedRequests($repo),
            $repo,
            new GenerateReplayFile(new GetCapturedRequest($repo)),
            new AdminView(),
            'secret',
        );

        ob_start();
        $controller->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, http_response_code());
        self::assertSame('http', $data['format']);
        self::assertStringContainsString('### Replay captured request', $data['content']);
        self::assertStringContainsString('POST /webhook/events', $data['content']);
    }

    public function test_replay_rejects_invalid_capture_id(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);

        $_GET['replay'] = 'invalid id with spaces';
        $_SERVER['REQUEST_URI'] = '/admin?replay=invalid%20id';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        $controller = new AdminController(
            new QueryCapturedRequests($repo),
            $repo,
            new GenerateReplayFile(new GetCapturedRequest($repo)),
            new AdminView(),
            'secret',
        );

        ob_start();
        $controller->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(400, http_response_code());
        self::assertSame('Invalid capture id', $data['error']);
    }

    public function test_replay_rejects_invalid_format(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);

        $_GET['replay'] = 'abc123';
        $_GET['format'] = 'yaml';
        $_SERVER['REQUEST_URI'] = '/admin?replay=abc123&format=yaml';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        $controller = new AdminController(
            new QueryCapturedRequests($repo),
            $repo,
            new GenerateReplayFile(new GetCapturedRequest($repo)),
            new AdminView(),
            'secret',
        );

        ob_start();
        $controller->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(400, http_response_code());
        self::assertSame('Invalid format', $data['error']);
    }

    public function test_replay_returns_404_when_capture_missing(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->method('findByCriteria')->willReturn([]);

        $_GET['replay'] = 'abc123';
        $_SERVER['REQUEST_URI'] = '/admin?replay=abc123';
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        $controller = new AdminController(
            new QueryCapturedRequests($repo),
            $repo,
            new GenerateReplayFile(new GetCapturedRequest($repo)),
            new AdminView(),
            'secret',
        );

        ob_start();
        $controller->handle();
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(404, http_response_code());
        self::assertSame('Capture not found', $data['error']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Application\GetCapturedRequest;
use App\Application\QueryCapturedRequests;
use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestCriteria;
use App\Domain\CapturedRequestRepository;
use App\Domain\HttpMethod;
use App\Presentation\Http\ApiController;
use App\Presentation\Http\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiController::class)]
#[UsesClass(GetCapturedRequest::class)]
#[UsesClass(QueryCapturedRequests::class)]
#[UsesClass(CapturedRequest::class)]
#[UsesClass(CapturedAt::class)]
#[UsesClass(HttpMethod::class)]
#[UsesClass(ServerRequest::class)]
final class ApiControllerTest extends TestCase
{
    private array $savedServer;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        http_response_code(200);
        header_remove();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        http_response_code(200);
        header_remove();
    }

    /** @return array<string, string> */
    private function captureArray(): array
    {
        return [
            'capturedAt' => '2026-05-24T12:00:00Z',
            'method' => 'POST',
            'uri' => '/watering',
            'query' => ['zone' => '1'],
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"ok":true}',
            'ip' => '10.0.0.1',
            'captureId' => 'abc123',
            'correlationId' => 'corr-A',
        ];
    }

    private function controller(CapturedRequestRepository $repo, bool $authRequired = true, string $token = 'secret'): ApiController
    {
        return new ApiController(
            new GetCapturedRequest($repo),
            new QueryCapturedRequests($repo),
            $token,
            $authRequired,
        );
    }

    public function test_list_returns_captures_as_json_array(): void
    {
        $entry = CapturedRequest::fromArray($this->captureArray());

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findByCriteria')->willReturn([$entry]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, http_response_code());
        self::assertIsArray($data);
        self::assertCount(1, $data);
        self::assertSame('abc123', $data[0]['captureId']);
        self::assertSame('corr-A', $data[0]['correlationId']);
        self::assertSame('/watering', $data[0]['uri']);
    }

    public function test_list_empty_returns_empty_json_array(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findByCriteria')->willReturn([]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        self::assertSame(200, http_response_code());
        self::assertSame('[]', trim($output));
    }

    public function test_list_accepts_trailing_slash(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findByCriteria')->willReturn([]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures/', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        self::assertSame(200, http_response_code());
        self::assertSame('[]', trim($output));
    }

    public function test_show_returns_capture_by_id(): void
    {
        $entry = CapturedRequest::fromArray($this->captureArray());

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())
            ->method('findByCriteria')
            ->willReturnCallback(function (CapturedRequestCriteria $criteria) use ($entry): array {
                self::assertSame('abc123', $criteria->captureId);
                return [$entry];
            });

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures/abc123', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, http_response_code());
        self::assertSame('abc123', $data['captureId']);
        self::assertSame('POST', $data['method']);
    }

    public function test_show_returns_404_when_not_found(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findByCriteria')->willReturn([]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures/unknown', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(404, http_response_code());
        self::assertSame('capture not found', $data['error']);
    }

    public function test_show_returns_400_for_invalid_id(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('findByCriteria');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures/bad/id', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(400, http_response_code());
        self::assertSame('invalid capture id', $data['error']);
        self::assertSame('invalid_capture_id', $data['code']);
    }

    public function test_disabled_api_and_unauthorized_errors_carry_codes(): void
    {
        $disabled = $this->controller($this->createMock(CapturedRequestRepository::class), authRequired: false);
        ob_start();
        $disabled->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        self::assertSame('not_found', json_decode($output ?: '', true)['code'] ?? null);

        $noAuth = $this->controller($this->createMock(CapturedRequestRepository::class));
        ob_start();
        $noAuth->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        self::assertSame(401, http_response_code());
        self::assertSame('unauthorized', json_decode($output ?: '', true)['code'] ?? null);
    }

    public function test_list_invalid_method_returns_400_with_code(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('findByCriteria');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', ['method' => 'nope'], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(400, http_response_code());
        self::assertSame('invalid method', $data['error']);
        self::assertSame('invalid_method', $data['code']);
    }

    public function test_list_invalid_capturedAfter_returns_400_with_code(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('findByCriteria');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', ['capturedAfter' => 'garbage'], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(400, http_response_code());
        self::assertSame('invalid capturedAfter', $data['error']);
        self::assertSame('invalid_captured_after', $data['code']);
    }

    public function test_disabled_api_returns_404(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('findByCriteria');

        $controller = $this->controller($repo, authRequired: false);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(404, http_response_code());
        self::assertSame('not found', $data['error']);
    }

    public function test_enabled_api_without_token_returns_401(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('findByCriteria');

        $controller = $this->controller($repo, authRequired: true);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(401, http_response_code());
        self::assertSame('unauthorized', $data['error']);
    }

    public function test_enabled_api_with_wrong_token_returns_401(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong';

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('findByCriteria');

        $controller = $this->controller($repo, authRequired: true);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(401, http_response_code());
        self::assertSame('unauthorized', $data['error']);
    }

    public function test_enabled_api_with_correct_token_returns_200(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())->method('findByCriteria')->willReturn([]);

        $controller = $this->controller($repo, authRequired: true);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        self::assertSame(200, http_response_code());
        self::assertSame('[]', trim($output));
    }

    public function test_list_passes_method_filter_to_repository(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())
            ->method('findByCriteria')
            ->willReturnCallback(function (CapturedRequestCriteria $criteria): array {
                self::assertSame(HttpMethod::POST, $criteria->method);
                return [];
            });

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', ['method' => 'post'], ''));
        $output = ob_get_clean();

        self::assertSame(200, http_response_code());
    }

    public function test_list_passes_all_filters_to_repository(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::once())
            ->method('findByCriteria')
            ->willReturnCallback(function (CapturedRequestCriteria $criteria): array {
                self::assertSame('corr-A', $criteria->correlationId);
                self::assertSame('c1', $criteria->captureId);
                self::assertSame('/watering', $criteria->uri);
                self::assertSame(10, $criteria->limit);
                self::assertNotNull($criteria->capturedAfter);
                self::assertSame('2026-05-24T00:00:00Z', $criteria->capturedAfter->toIso8601());
                self::assertNotNull($criteria->capturedBefore);
                self::assertSame('2026-05-25T00:00:00Z', $criteria->capturedBefore->toIso8601());
                return [];
            });

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        $query = [
            'correlationId' => 'corr-A',
            'captureId' => 'c1',
            'method' => 'GET',
            'uri' => '/watering',
            'capturedAfter' => '2026-05-24T00:00:00Z',
            'capturedBefore' => '2026-05-25T00:00:00Z',
            'limit' => '10',
        ];

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', $query, ''));
        $output = ob_get_clean();

        self::assertSame(200, http_response_code());
    }

    public function test_list_invalid_method_returns_400(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('findByCriteria');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', ['method' => 'nope'], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(400, http_response_code());
        self::assertSame('invalid method', $data['error']);
    }

    public function test_list_invalid_capturedAfter_returns_400(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('findByCriteria');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', ['capturedAfter' => 'garbage'], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(400, http_response_code());
        self::assertSame('invalid capturedAfter', $data['error']);
    }

    public function test_list_invalid_limit_returns_400(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('findByCriteria');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', ['limit' => '-5'], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(400, http_response_code());
        self::assertSame('invalid limit', $data['error']);
    }

    public function test_non_get_method_returns_405(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('findByCriteria');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('POST', '/api/v1/captures', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(405, http_response_code());
        self::assertSame('method not allowed', $data['error']);
    }

    public function test_unknown_api_path_returns_404(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->expects(self::never())->method('findByCriteria');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->controller($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/sessions', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(404, http_response_code());
        self::assertSame('not found', $data['error']);
    }
}

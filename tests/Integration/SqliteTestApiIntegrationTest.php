<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\CaptureWebhook;
use App\Application\CountCapturedRequests;
use App\Application\GetCapturedRequest;
use App\Application\QueryCapturedRequests;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestCriteria;
use App\Infrastructure\Persistence\SqliteCapturedRequestRepository;
use App\Presentation\Http\ApiController;
use App\Presentation\Http\ServerRequest;
use App\Presentation\Http\WebhookController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiController::class)]
#[CoversClass(WebhookController::class)]
#[UsesClass(CaptureWebhook::class)]
#[UsesClass(GetCapturedRequest::class)]
#[UsesClass(QueryCapturedRequests::class)]
#[UsesClass(SqliteCapturedRequestRepository::class)]
#[UsesClass(CapturedRequest::class)]
#[UsesClass(ServerRequest::class)]
final class SqliteTestApiIntegrationTest extends TestCase
{
    private string $tmpDir = '';
    private SqliteCapturedRequestRepository $repo;
    private WebhookController $webhookController;
    private ApiController $apiController;
    private array $savedServer = [];

    protected function setUp(): void
    {
        if (!extension_loaded('sqlite3')) {
            self::markTestSkipped('ext-sqlite3 not available');
        }

        $this->savedServer = $_SERVER;
        unset($_SERVER['HTTP_X_KAPTURE_CORRELATION_ID'], $_SERVER['HTTP_AUTHORIZATION']);

        $this->tmpDir = \sys_get_temp_dir() . '/kapture_testapi_sqlite_' . \bin2hex(\random_bytes(4));
        \mkdir($this->tmpDir, 0755, true);

        $this->repo = new SqliteCapturedRequestRepository($this->tmpDir, 99999);
        $this->webhookController = new WebhookController(new CaptureWebhook($this->repo), $this->repo);
        $this->apiController = new ApiController(
            new GetCapturedRequest($this->repo),
            new QueryCapturedRequests($this->repo),
            new CountCapturedRequests($this->repo),
            'test-token',
            true,
        );

        http_response_code(200);
        header_remove();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        if (isset($this->tmpDir)) {
            $this->rmdir($this->tmpDir);
        }
        http_response_code(200);
        header_remove();
    }

    private function sendWebhook(string $uri, string $body, string $correlationId): string
    {
        $_SERVER['HTTP_X_KAPTURE_CORRELATION_ID'] = $correlationId;
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        ob_start();
        $this->webhookController->handle(new ServerRequest('POST', $uri, '10.0.0.9', $query, $body));
        $output = ob_get_clean();

        $data = \json_decode((string) $output, true, flags: \JSON_THROW_ON_ERROR);
        return (string) $data['captureId'];
    }

    /** @param array<string, string> $query */
    private function apiGet(string $path, array $query = []): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token';
        ob_start();
        $this->apiController->handle(new ServerRequest('GET', $path, '10.0.0.9', $query, ''));
        $output = ob_get_clean();

        return [
            'code' => http_response_code(),
            'body' => \json_decode((string) $output, true),
        ];
    }

    private function apiGetUnauthenticated(string $path): array
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        ob_start();
        $this->apiController->handle(new ServerRequest('GET', $path, '10.0.0.9', [], ''));
        $output = ob_get_clean();

        return [
            'code' => http_response_code(),
            'body' => \json_decode((string) $output, true),
        ];
    }

    public function test_requests_sharing_correlation_are_all_returned_in_order(): void
    {
        $this->sendWebhook('/kapture/watering', 'r1', 'corr-A');
        $this->sendWebhook('/kapture/watering', 'r2', 'corr-A');
        $this->sendWebhook('/kapture/watering', 'r3', 'corr-A');

        $result = $this->apiGet('/api/v1/captures', ['correlationId' => 'corr-A']);

        self::assertSame(200, $result['code']);
        $captures = $result['body']['captures'];
        self::assertCount(3, $captures);
        self::assertSame(['r1', 'r2', 'r3'], \array_map(fn (array $c): string => $c['body'], $captures));
    }

    public function test_parallel_correlations_are_isolated(): void
    {
        $this->sendWebhook('/kapture/watering', 'from-A', 'corr-A');
        $this->sendWebhook('/kapture/watering', 'from-B', 'corr-B');

        $result = $this->apiGet('/api/v1/captures', ['correlationId' => 'corr-A']);

        self::assertSame(200, $result['code']);
        $captures = $result['body']['captures'];
        self::assertCount(1, $captures);
        self::assertSame('from-A', $captures[0]['body']);
        self::assertSame('corr-A', $captures[0]['correlationId']);
    }

    public function test_get_capture_by_id_returns_full_capture(): void
    {
        $captureId = $this->sendWebhook('/kapture/watering?zone=1', '{"ok":true}', 'corr-G');

        $result = $this->apiGet('/api/v1/captures/' . $captureId);

        self::assertSame(200, $result['code']);
        self::assertSame($captureId, $result['body']['captureId']);
        self::assertSame('/watering?zone=1', $result['body']['uri']);
        self::assertSame(['zone' => '1'], $result['body']['query']);
        self::assertSame('{"ok":true}', $result['body']['body']);
        self::assertSame('corr-G', $result['body']['correlationId']);
    }

    public function test_forward_result_fields_are_returned_by_api(): void
    {
        $captureId = $this->sendWebhook('/kapture/watering', 'payload', 'corr-F');
        $entry = $this->repo->findByCriteria(new CapturedRequestCriteria(captureId: $captureId))[0];
        $forwarded = $entry->withForwardResult('https://target.example/webhook', 500);
        $this->repo->delete($forwarded->captureId);
        $this->repo->save($forwarded);

        $result = $this->apiGet('/api/v1/captures/' . $captureId);

        self::assertSame(200, $result['code']);
        self::assertSame('https://target.example/webhook', $result['body']['forwardUrl']);
        self::assertSame(500, $result['body']['forwardStatusCode']);
        self::assertSame('corr-F', $result['body']['correlationId']);
    }

    public function test_filters_method_uri_and_limit(): void
    {
        $this->sendWebhook('/kapture/watering', 'w1', 'corr-L');
        $this->sendWebhook('/kapture/watering', 'w2', 'corr-L');
        $this->sendWebhook('/kapture/fertilizing', 'f1', 'corr-L');

        $byMethod = $this->apiGet('/api/v1/captures', ['method' => 'POST', 'limit' => '10']);
        self::assertSame(200, $byMethod['code']);
        self::assertCount(3, $byMethod['body']['captures']);

        $byUri = $this->apiGet('/api/v1/captures', ['uri' => '/fertilizing']);
        self::assertSame(200, $byUri['code']);
        $captures = $byUri['body']['captures'];
        self::assertCount(1, $captures);
        self::assertSame('f1', $captures[0]['body']);

        $limited = $this->apiGet('/api/v1/captures', ['correlationId' => 'corr-L', 'limit' => '2']);
        self::assertSame(200, $limited['code']);
        self::assertCount(2, $limited['body']['captures']);
    }

    public function test_api_requires_token_when_enabled(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        ob_start();
        $this->apiController->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.9', [], ''));
        $output = ob_get_clean();

        $data = \json_decode((string) $output, true);
        self::assertSame(401, http_response_code());
        self::assertSame('unauthorized', $data['error']);
    }

    public function test_get_capture_by_id_works_without_token(): void
    {
        $captureId = $this->sendWebhook('/kapture/watering', 'payload', 'corr-NT');

        $result = $this->apiGetUnauthenticated('/api/v1/captures/' . $captureId);

        self::assertSame(200, $result['code']);
        self::assertSame($captureId, $result['body']['captureId']);
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

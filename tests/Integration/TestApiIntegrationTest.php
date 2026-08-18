<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\CaptureWebhook;
use App\Application\CountCapturedRequests;
use App\Application\GetCapturedRequest;
use App\Application\QueryCapturedRequests;
use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\HttpMethod;
use App\Infrastructure\Persistence\FilesystemCapturedRequestRepository;
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
#[UsesClass(FilesystemCapturedRequestRepository::class)]
#[UsesClass(CapturedRequest::class)]
#[UsesClass(CapturedAt::class)]
#[UsesClass(HttpMethod::class)]
#[UsesClass(ServerRequest::class)]
final class TestApiIntegrationTest extends TestCase
{
    private string $tmpDir;
    private FilesystemCapturedRequestRepository $repo;
    private CaptureWebhook $captureWebhook;
    private WebhookController $webhookController;
    private ApiController $apiController;
    private array $savedServer;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        unset($_SERVER['HTTP_X_KAPTURE_CORRELATION_ID']);

        $this->tmpDir = sys_get_temp_dir() . '/kapture_testapi_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);

        $this->repo = new FilesystemCapturedRequestRepository($this->tmpDir, 7);
        $this->captureWebhook = new CaptureWebhook($this->repo);
        $this->webhookController = new WebhookController($this->captureWebhook, $this->repo);
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
        $this->rmdir($this->tmpDir);
        http_response_code(200);
        header_remove();
    }

    /** @return array<string, string> */
    private function sendWebhook(string $method, string $uri, array $query = [], ?string $body = null, ?string $correlationId = null): array
    {
        if ($correlationId !== null) {
            $_SERVER['HTTP_X_KAPTURE_CORRELATION_ID'] = $correlationId;
        } else {
            unset($_SERVER['HTTP_X_KAPTURE_CORRELATION_ID']);
        }

        ob_start();
        $this->webhookController->handle(new ServerRequest($method, $uri, '10.0.0.9', $query, $body ?? ''));
        $output = ob_get_clean();

        return json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);
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
            'body' => json_decode((string) $output, true),
        ];
    }

    /** @param array<string, string> $query */
    private function apiGetUnauthenticated(string $path, array $query = []): array
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        ob_start();
        $this->apiController->handle(new ServerRequest('GET', $path, '10.0.0.9', $query, ''));
        $output = ob_get_clean();

        return [
            'code' => http_response_code(),
            'body' => json_decode((string) $output, true),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function captures(array $result): array
    {
        return $result['body']['captures'];
    }

    public function test_full_flow_capture_with_correlation_then_query_by_correlation(): void
    {
        $responses = [];
        foreach (['one', 'two', 'three'] as $step) {
            $responses[] = $this->sendWebhook('POST', '/kapture/watering', [], '{"step":"' . $step . '"}', 'corr-flow');
        }

        foreach ($responses as $response) {
            self::assertSame(true, $response['ok']);
            self::assertArrayHasKey('captureId', $response);
        }

        $result = $this->apiGet('/api/v1/captures', ['correlationId' => 'corr-flow']);

        self::assertSame(200, $result['code']);
        $captures = $this->captures($result);
        self::assertCount(3, $captures);
        self::assertSame(3, $result['body']['total']);
        self::assertSame('/watering', $captures[0]['uri']);
        self::assertSame(['step' => 'one'], json_decode($captures[0]['body'], true));
        self::assertSame('corr-flow', $captures[0]['correlationId']);
        self::assertSame(['step' => 'three'], json_decode($captures[2]['body'], true));
    }

    public function test_get_capture_by_id_returns_full_capture(): void
    {
        $captured = $this->sendWebhook(
            'POST',
            '/kapture/api/data?debug=1',
            ['debug' => '1'],
            '{"status":"ok"}',
            'corr-x',
        );

        $result = $this->apiGet('/api/v1/captures/' . $captured['captureId']);

        self::assertSame(200, $result['code']);
        $data = $result['body'];
        self::assertSame($captured['captureId'], $data['captureId']);
        self::assertSame('POST', $data['method']);
        self::assertSame('/api/data?debug=1', $data['uri']);
        self::assertSame(['debug' => '1'], $data['query']);
        self::assertSame('{"status":"ok"}', $data['body']);
        self::assertSame('10.0.0.9', $data['ip']);
        self::assertSame('corr-x', $data['correlationId']);
        self::assertArrayHasKey('capturedAt', $data);
        self::assertArrayHasKey('headers', $data);
    }

    public function test_get_capture_by_id_returns_404_for_unknown(): void
    {
        $result = $this->apiGet('/api/v1/captures/unknown-id');

        self::assertSame(404, $result['code']);
        self::assertSame('capture not found', $result['body']['error']);
    }

    public function test_multiple_requests_with_same_correlation_are_all_available_in_order(): void
    {
        $this->sendWebhook('POST', '/kapture/watering', [], 'r1', 'corr-A');
        $this->sendWebhook('POST', '/kapture/watering', [], 'r2', 'corr-A');
        $this->sendWebhook('POST', '/kapture/watering', [], 'r3', 'corr-A');

        $result = $this->apiGet('/api/v1/captures', ['correlationId' => 'corr-A']);

        self::assertSame(200, $result['code']);
        $captures = $this->captures($result);
        self::assertCount(3, $captures);
        self::assertSame(['r1', 'r2', 'r3'], array_map(fn (array $c): string => $c['body'], $captures));
        // No capture overrides another — every captureId is distinct
        $ids = array_map(fn (array $c): string => $c['captureId'], $captures);
        self::assertCount(3, array_unique($ids));
    }

    public function test_parallel_correlations_are_isolated(): void
    {
        $this->sendWebhook('POST', '/kapture/watering', [], 'from-A', 'corr-A');
        $this->sendWebhook('POST', '/kapture/watering', [], 'from-B', 'corr-B');
        $this->sendWebhook('POST', '/kapture/watering', [], 'from-A-2', 'corr-A');

        $result = $this->apiGet('/api/v1/captures', ['correlationId' => 'corr-A']);

        self::assertSame(200, $result['code']);
        $captures = $this->captures($result);
        self::assertCount(2, $captures);
        self::assertSame(['from-A', 'from-A-2'], array_map(fn (array $c): string => $c['body'], $captures));
    }

    public function test_filter_by_method(): void
    {
        $this->sendWebhook('POST', '/kapture/watering', [], 'p1', 'corr-M');
        $this->sendWebhook('GET', '/kapture/watering', [], null, 'corr-M');

        $result = $this->apiGet('/api/v1/captures', ['method' => 'POST']);

        self::assertSame(200, $result['code']);
        $captures = $this->captures($result);
        self::assertCount(1, $captures);
        self::assertSame('POST', $captures[0]['method']);
    }

    public function test_filter_by_uri_substring(): void
    {
        $this->sendWebhook('POST', '/kapture/watering?zone=1', [], 'w', 'corr-U');
        $this->sendWebhook('POST', '/kapture/fertilizing', [], 'f', 'corr-U');

        $result = $this->apiGet('/api/v1/captures', ['uri' => '/watering']);

        self::assertSame(200, $result['code']);
        $captures = $this->captures($result);
        self::assertCount(1, $captures);
        self::assertSame('/watering?zone=1', $captures[0]['uri']);
    }

    public function test_filter_by_capture_id(): void
    {
        $a = $this->sendWebhook('POST', '/kapture/watering', [], 'a', 'corr-C');
        $this->sendWebhook('POST', '/kapture/watering', [], 'b', 'corr-C');

        $result = $this->apiGet('/api/v1/captures', ['captureId' => $a['captureId']]);

        self::assertSame(200, $result['code']);
        $captures = $this->captures($result);
        self::assertCount(1, $captures);
        self::assertSame($a['captureId'], $captures[0]['captureId']);
    }

    public function test_filter_by_time_range(): void
    {
        $this->repo->save(new CapturedRequest(
            CapturedAt::fromString('2026-05-24T10:00:00Z'),
            HttpMethod::POST,
            '/watering',
            [],
            [],
            'early',
            '10.0.0.9',
            'cap-early',
            correlationId: 'corr-T',
        ));
        $this->repo->save(new CapturedRequest(
            CapturedAt::fromString('2026-05-24T11:00:00Z'),
            HttpMethod::POST,
            '/watering',
            [],
            [],
            'late',
            '10.0.0.9',
            'cap-late',
            correlationId: 'corr-T',
        ));

        $after = $this->apiGet('/api/v1/captures', ['capturedAfter' => '2026-05-24T10:30:00Z']);
        self::assertSame(200, $after['code']);
        self::assertCount(1, $this->captures($after));
        self::assertSame('late', $this->captures($after)[0]['body']);

        $before = $this->apiGet('/api/v1/captures', ['capturedBefore' => '2026-05-24T10:30:00Z']);
        self::assertSame(200, $before['code']);
        self::assertCount(1, $this->captures($before));
        self::assertSame('early', $this->captures($before)[0]['body']);
    }

    public function test_limit_filter(): void
    {
        $this->sendWebhook('POST', '/kapture/watering', [], '1', 'corr-L');
        $this->sendWebhook('POST', '/kapture/watering', [], '2', 'corr-L');
        $this->sendWebhook('POST', '/kapture/watering', [], '3', 'corr-L');

        $result = $this->apiGet('/api/v1/captures', ['correlationId' => 'corr-L', 'limit' => '2']);

        self::assertSame(200, $result['code']);
        $captures = $this->captures($result);
        self::assertCount(2, $captures);
        self::assertSame(['1', '2'], array_map(fn (array $c): string => $c['body'], $captures));
    }

    public function test_order_desc_returns_newest_first(): void
    {
        $this->repo->save(new CapturedRequest(
            CapturedAt::fromString('2026-05-24T10:00:00Z'),
            HttpMethod::POST,
            '/watering',
            [],
            [],
            'first',
            '10.0.0.9',
            'cap-first',
            correlationId: 'corr-O',
        ));
        $this->repo->save(new CapturedRequest(
            CapturedAt::fromString('2026-05-24T11:00:00Z'),
            HttpMethod::POST,
            '/watering',
            [],
            [],
            'second',
            '10.0.0.9',
            'cap-second',
            correlationId: 'corr-O',
        ));

        $asc = $this->apiGet('/api/v1/captures', ['correlationId' => 'corr-O']);
        self::assertSame(['first', 'second'], array_map(fn (array $c): string => $c['body'], $this->captures($asc)));

        $desc = $this->apiGet('/api/v1/captures', ['correlationId' => 'corr-O', 'order' => 'desc']);
        self::assertSame(['second', 'first'], array_map(fn (array $c): string => $c['body'], $this->captures($desc)));
    }

    public function test_forward_result_fields_are_returned_by_api(): void
    {
        $captured = $this->sendWebhook('POST', '/kapture/watering', [], 'payload', 'corr-F');
        $entry = $this->repo->findByCriteria(new \App\Domain\CapturedRequestCriteria(captureId: $captured['captureId']))[0];

        $forwarded = $entry->withForwardResult('https://target.example/webhook', 503);
        $this->repo->delete($forwarded->captureId);
        $this->repo->save($forwarded);

        $result = $this->apiGet('/api/v1/captures', ['correlationId' => 'corr-F']);

        self::assertSame(200, $result['code']);
        $captures = $this->captures($result);
        self::assertSame('https://target.example/webhook', $captures[0]['forwardUrl']);
        self::assertSame(503, $captures[0]['forwardStatusCode']);
        self::assertSame('corr-F', $captures[0]['correlationId']);
    }

    public function test_plain_text_body_and_headers_preserved(): void
    {
        $captured = $this->sendWebhook('POST', '/kapture/plain', [], 'raw-text-body', 'corr-P');

        $result = $this->apiGet('/api/v1/captures/' . $captured['captureId']);

        self::assertSame(200, $result['code']);
        self::assertSame('raw-text-body', $result['body']['body']);
        self::assertArrayHasKey('headers', $result['body']);
    }

    public function test_headers_values_returned_through_api(): void
    {
        $this->repo->save(new CapturedRequest(
            CapturedAt::fromString('2026-05-24T10:00:00Z'),
            HttpMethod::POST,
            '/watering',
            [],
            ['Content-Type' => 'application/json', 'X-Trace-Id' => 't-42'],
            '{"ok":true}',
            '10.0.0.9',
            'cap-hdr',
            correlationId: 'corr-H',
        ));

        $result = $this->apiGet('/api/v1/captures', ['correlationId' => 'corr-H']);

        self::assertSame(200, $result['code']);
        $captures = $this->captures($result);
        self::assertSame('application/json', $captures[0]['headers']['Content-Type']);
        self::assertSame('t-42', $captures[0]['headers']['X-Trace-Id']);
    }

    public function test_api_disabled_returns_404(): void
    {
        $disabled = new ApiController(
            new GetCapturedRequest($this->repo),
            new QueryCapturedRequests($this->repo),
            new CountCapturedRequests($this->repo),
            'test-token',
            false,
        );

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token';
        ob_start();
        $disabled->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.9', [], ''));
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertSame(404, http_response_code());
        self::assertSame('not found', $data['error']);
    }

    public function test_api_without_token_returns_401(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        ob_start();
        $this->apiController->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.9', [], ''));
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertSame(401, http_response_code());
        self::assertSame('unauthorized', $data['error']);
    }

    public function test_get_capture_by_id_works_without_token(): void
    {
        $captured = $this->sendWebhook('POST', '/kapture/watering', [], '{"ok":true}', 'corr-NT');

        $result = $this->apiGetUnauthenticated('/api/v1/captures/' . $captured['captureId']);

        self::assertSame(200, $result['code']);
        self::assertSame($captured['captureId'], $result['body']['captureId']);
        self::assertSame('/watering', $result['body']['uri']);
    }

    public function test_list_requires_token_even_when_id_is_known(): void
    {
        $captured = $this->sendWebhook('POST', '/kapture/watering', [], 'payload', 'corr-NT2');

        $result = $this->apiGetUnauthenticated('/api/v1/captures', ['captureId' => $captured['captureId']]);

        self::assertSame(401, $result['code']);
        self::assertSame('unauthorized', $result['body']['error']);
    }

    public function test_webhook_receiver_behavior_is_unchanged(): void
    {
        $response = $this->sendWebhook('POST', '/kapture/anything', [], 'body', 'corr-W');

        self::assertSame(true, $response['ok']);
        self::assertArrayHasKey('captureId', $response);
        self::assertCount(1, $this->repo->findAll());
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

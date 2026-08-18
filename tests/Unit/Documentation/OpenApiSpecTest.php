<?php

declare(strict_types=1);

namespace Tests\Unit\Documentation;

use App\Application\CaptureWebhook;
use App\Application\GetCapturedRequest;
use App\Application\QueryCapturedRequests;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestRepository;
use App\Documentation\OpenApiSpec;
use App\Presentation\Http\ApiController;
use App\Presentation\Http\ServerRequest;
use App\Presentation\Http\WebhookController;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenApiSpec::class)]
#[UsesClass(ApiController::class)]
#[UsesClass(WebhookController::class)]
#[UsesClass(CaptureWebhook::class)]
#[UsesClass(CapturedRequest::class)]
#[UsesClass(ServerRequest::class)]
final class OpenApiSpecTest extends TestCase
{
    private array $savedServer;

    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        http_response_code(200);
        header_remove();

        $raw = file_get_contents(dirname(__DIR__, 3) . '/public/openapi.json');
        self::assertNotFalse($raw, 'Run `composer docs` to generate public/openapi.json');
        $this->spec = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        http_response_code(200);
        header_remove();
    }

    public function test_spec_contains_expected_paths_and_schemas(): void
    {
        self::assertSame('3.1.0', $this->spec['openapi']);
        self::assertSame('Kapture API', $this->spec['info']['title']);

        foreach (['/api/v1/captures', '/api/v1/captures/{captureId}', '/capture/{path}', '/kapture/{path}'] as $path) {
            self::assertArrayHasKey($path, $this->spec['paths']);
        }

        self::assertArrayHasKey('CapturedRequest', $this->spec['components']['schemas']);
        self::assertArrayHasKey('Error', $this->spec['components']['schemas']);
        self::assertArrayHasKey('bearerAuth', $this->spec['components']['securitySchemes']);
    }

    public function test_list_response_matches_spec(): void
    {
        $entry = CapturedRequest::fromArray($this->captureArray());

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->method('findWithTotal')->willReturn([[$entry], 1]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->apiController($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        $data = json_decode($output ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, http_response_code());
        $this->assertMatchesSpec($data, '/api/v1/captures', 'get', 200);
    }

    public function test_show_response_matches_spec(): void
    {
        $entry = CapturedRequest::fromArray($this->captureArray());

        $repo = $this->createMock(CapturedRequestRepository::class);
        $repo->method('findByCriteria')->willReturn([$entry]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';

        $controller = $this->apiController($repo);

        ob_start();
        $controller->handle(new ServerRequest('GET', '/api/v1/captures/abc123', '10.0.0.1', [], ''));
        $output = ob_get_clean();

        $data = json_decode($output ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, http_response_code());
        $this->assertMatchesSpec($data, '/api/v1/captures/{captureId}', 'get', 200);
    }

    public function test_webhook_response_matches_spec(): void
    {
        $repo = $this->createMock(CapturedRequestRepository::class);
        $controller = new WebhookController(new CaptureWebhook($repo), $repo, null, bin2hex(random_bytes(4)));

        ob_start();
        $controller->handle(new ServerRequest('POST', '/capture/test', '10.0.0.1', [], '{"key":"val"}'));
        $output = ob_get_clean();

        $data = json_decode($output ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, http_response_code());
        $this->assertMatchesSpec($data, '/capture/{path}', 'post', 200);
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('errorResponseProvider')]
    public function test_error_responses_match_spec(
        string $path,
        string $method,
        int $status,
        array $query,
        bool $withAuth,
        string $specPath,
        string $specMethod,
    ): void {
        if ($withAuth) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret';
        } else {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }

        $repo = $this->createMock(CapturedRequestRepository::class);
        $controller = $this->apiController($repo);

        ob_start();
        $controller->handle(new ServerRequest(strtoupper($method), $path, '10.0.0.1', $query, ''));
        $output = ob_get_clean();

        $data = json_decode($output ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($status, http_response_code());
        $this->assertMatchesSpec($data, $specPath, $specMethod, $status);
    }

    /** @return iterable<string, array{string, string, int, array<string, string>, bool, string, string}> */
    public static function errorResponseProvider(): iterable
    {
        yield 'invalid method' => ['/api/v1/captures', 'get', 400, ['method' => 'nope'], true, '/api/v1/captures', 'get'];
        yield 'unauthorized' => ['/api/v1/captures', 'get', 401, [], false, '/api/v1/captures', 'get'];
        yield 'unknown path' => ['/api/v1/sessions', 'get', 404, [], false, '/api/v1/captures', 'get'];
        yield 'method not allowed' => ['/api/v1/captures', 'post', 405, [], false, '/api/v1/captures', 'get'];
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

    private function apiController(CapturedRequestRepository $repo): ApiController
    {
        return new ApiController(
            new GetCapturedRequest($repo),
            new QueryCapturedRequests($repo),
            'secret',
            true,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertMatchesSpec(array $data, string $path, string $method, int $status): void
    {
        $schema = $this->responseSchema($path, $method, $status);
        $resolved = $this->resolveRefs($schema, $this->spec['components']['schemas']);

        $result = (new Validator())->validate(
            json_decode(json_encode($data), false),
            json_decode(json_encode($resolved), false),
        );

        self::assertTrue(
            $result->isValid(),
            sprintf(
                "Response for %s %s does not match the documented schema:\n%s",
                strtoupper($method),
                $path,
                json_encode(
                    (new ErrorFormatter())->formatOutput($result->error(), 'verbose'),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ),
            ),
        );
    }

    /** @return array<mixed, mixed> */
    private function responseSchema(string $path, string $method, int $status): array
    {
        $schema = $this->spec['paths'] ?? [];
        self::assertIsArray($schema);
        $schema = $schema[$path] ?? [];
        self::assertIsArray($schema);
        $schema = $schema[$method] ?? [];
        self::assertIsArray($schema);
        $schema = $schema['responses'] ?? [];
        self::assertIsArray($schema);
        $schema = $schema[(string) $status] ?? [];
        self::assertIsArray($schema);
        $schema = $schema['content'] ?? [];
        self::assertIsArray($schema);
        $schema = $schema['application/json'] ?? [];
        self::assertIsArray($schema);
        $schema = $schema['schema'] ?? null;
        self::assertIsArray($schema, sprintf('No %d response schema documented for %s %s', $status, strtoupper($method), $path));

        /** @var array<mixed, mixed> $schema */
        return $schema;
    }

    /**
     * Resolve $ref pointers into a standalone schema.
     *
     * @param array<mixed, mixed> $schema
     * @param array<mixed, mixed> $schemas
     * @return array<mixed, mixed>
     */
    private function resolveRefs(array $schema, array $schemas): array
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $ref = $schema['$ref'];
            if (str_starts_with($ref, '#/components/schemas/')) {
                $name = substr($ref, strlen('#/components/schemas/'));
                $target = $schemas[$name] ?? null;
                self::assertIsArray($target, sprintf('Unknown schema ref: %s', $ref));
                return $this->resolveRefs($target, $schemas);
            }
        }

        foreach (['properties', 'items', 'additionalProperties', 'not', 'if', 'then', 'else'] as $key) {
            if (!isset($schema[$key]) || !is_array($schema[$key])) {
                continue;
            }
            if ($key === 'properties') {
                foreach ($schema[$key] as $prop => $propSchema) {
                    if (is_array($propSchema)) {
                        $schema[$key][$prop] = $this->resolveRefs($propSchema, $schemas);
                    }
                }
                continue;
            }
            if ($key === 'items' && array_is_list($schema[$key])) {
                $schema[$key] = array_map(
                    static fn (mixed $item): array => $this->resolveRefs((array) $item, $schemas),
                    $schema[$key],
                );
                continue;
            }
            $schema[$key] = $this->resolveRefs($schema[$key], $schemas);
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                $schema[$key] = array_map(
                    static fn (mixed $item): array => $this->resolveRefs((array) $item, $schemas),
                    $schema[$key],
                );
            }
        }

        return $schema;
    }
}
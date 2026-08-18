<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\GetCapturedRequest;
use App\Application\QueryCapturedRequests;
use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestCriteria;
use App\Domain\HttpMethod;
use OpenApi\Attributes as OA;

final readonly class ApiController
{
    private const LIST_PATH = '/api/v1/captures';
    private const ID_PATTERN = '/^[A-Za-z0-9_-]+$/';
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 1000;

    public function __construct(
        private GetCapturedRequest $getCapturedRequest,
        private QueryCapturedRequests $queryCapturedRequests,
        private string $apiToken,
        private bool $apiAuthRequired,
    )
    {
    }

    public function handle(?ServerRequest $request = null): void
    {
        header('Cache-Control: no-store');

        if (!$this->apiAuthRequired) {
            HttpResponse::error(404, 'not found', 'not_found');
            return;
        }

        if ($request === null) {
            $request = ServerRequest::fromGlobals();
        }

        if ($request->method !== 'GET') {
            HttpResponse::error(405, 'method not allowed', 'method_not_allowed');
            return;
        }

        $path = parse_url($request->uri, PHP_URL_PATH) ?: '/';

        if ($path === self::LIST_PATH || $path === self::LIST_PATH . '/') {
            if (!BearerAuthGuard::check($this->apiToken)) {
                HttpResponse::error(401, 'unauthorized', 'unauthorized');
                return;
            }
            $this->listCaptures($request->query);
            return;
        }

        if (str_starts_with($path, self::LIST_PATH . '/')) {
            $captureId = substr($path, strlen(self::LIST_PATH . '/'));
            $this->showCapture($captureId);
            return;
        }

        HttpResponse::error(404, 'not found', 'not_found');
    }

    #[OA\Get(
        path: '/api/v1/captures/{captureId}',
        operationId: 'showCapture',
        tags: ['captures'],
        security: [],
        summary: 'Get a single captured request',
        description: 'Returns a single capture. No Bearer token is required — knowing the capture ID is sufficient (the ID is high-entropy and acts as the credential for this read).',
    )]
    #[OA\Parameter(name: 'captureId', in: 'path', required: true, description: 'Capture id', schema: new OA\Schema(type: 'string', pattern: '^[A-Za-z0-9_-]+$'))]
    #[OA\Response(response: 200, description: 'The captured request', content: new OA\JsonContent(ref: CapturedRequest::class))]
    #[OA\Response(response: 400, description: 'Invalid capture id', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
    #[OA\Response(response: 401, description: 'Missing or invalid bearer token', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
    #[OA\Response(response: 404, description: 'Capture not found', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
    private function showCapture(string $captureId): void
    {
        if ($captureId === '' || preg_match(self::ID_PATTERN, $captureId) !== 1) {
            HttpResponse::error(400, 'invalid capture id', 'invalid_capture_id');
            return;
        }

        $entry = $this->getCapturedRequest->handle($captureId);

        if ($entry === null) {
            HttpResponse::error(404, 'capture not found', 'capture_not_found');
            return;
        }

        HttpResponse::json(200, $entry->toArray());
    }

    /**
     * @param array<string, string> $query
     */
    #[OA\Get(
        path: '/api/v1/captures',
        operationId: 'listCaptures',
        tags: ['captures'],
        security: [['bearerAuth' => []]],
        summary: 'List captured requests',
        description: 'Returns captures matching the given filters, oldest first by default. `total` is the number of captures matching the filters ignoring `limit`, so polling clients can tell when all expected requests of a correlation have arrived.',
    )]
    #[OA\QueryParameter(name: 'captureId', description: 'Exact capture ID', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\QueryParameter(name: 'correlationId', description: 'All captures belonging to one test scenario', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\QueryParameter(name: 'method', description: 'HTTP method', required: false, schema: new OA\Schema(type: 'string', enum: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS']))]
    #[OA\QueryParameter(name: 'uri', description: 'Substring match on the captured URI (path + query)', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\QueryParameter(name: 'capturedAfter', description: 'ISO8601 timestamp; captures strictly after this time', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'))]
    #[OA\QueryParameter(name: 'capturedBefore', description: 'ISO8601 timestamp; captures strictly before this time', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'))]
    #[OA\QueryParameter(name: 'order', description: 'Sort order by receipt time', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc'))]
    #[OA\QueryParameter(name: 'limit', description: 'Maximum number of captures returned; 0 returns up to 1000 (server-side cap)', required: false, schema: new OA\Schema(type: 'integer', minimum: 0, default: 100))]
    #[OA\Response(
        response: 200,
        description: 'List of captures',
        content: new OA\JsonContent(
            required: ['captures', 'total'],
            properties: [
                new OA\Property(property: 'captures', type: 'array', items: new OA\Items(ref: CapturedRequest::class)),
                new OA\Property(property: 'total', type: 'integer'),
            ],
        ),
    )]
    #[OA\Response(response: 400, description: 'Invalid query parameter', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
    #[OA\Response(response: 401, description: 'Missing or invalid bearer token', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
    #[OA\Response(response: 404, description: 'API disabled', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
    #[OA\Response(response: 405, description: 'Method not allowed', content: new OA\JsonContent(ref: '#/components/schemas/Error'))]
    private function listCaptures(array $query): void
    {
        $criteria = $this->buildCriteria($query);
        if ($criteria === null) {
            return;
        }

        [$entries, $total] = $this->queryCapturedRequests->handleWithTotal($criteria);

        HttpResponse::json(200, [
            'captures' => array_map(
                fn (CapturedRequest $entry): array => $entry->toArray(),
                $entries,
            ),
            'total' => $total,
        ]);
    }

    /**
     * @param array<string, string> $query
     */
    private function buildCriteria(array $query): ?CapturedRequestCriteria
    {
        $method = null;
        if (($query['method'] ?? '') !== '') {
            $method = HttpMethod::tryFromMethod($query['method']);
            if ($method === null) {
                HttpResponse::error(400, 'invalid method', 'invalid_method');
                return null;
            }
        }

        try {
            $capturedAfter = $this->parseCapturedAt($query['capturedAfter'] ?? null, 'capturedAfter');
            $capturedBefore = $this->parseCapturedAt($query['capturedBefore'] ?? null, 'capturedBefore');
        } catch (\InvalidArgumentException $e) {
            $code = match ($e->getMessage()) {
                'invalid capturedAfter' => 'invalid_captured_after',
                'invalid capturedBefore' => 'invalid_captured_before',
                default => 'invalid_parameter',
            };
            HttpResponse::error(400, $e->getMessage(), $code);
            return null;
        }

        $order = null;
        if (($query['order'] ?? '') !== '') {
            $order = strtolower($query['order']);
            if (!in_array($order, ['asc', 'desc'], true)) {
                HttpResponse::error(400, 'invalid order', 'invalid_order');
                return null;
            }
        }

        $limit = self::DEFAULT_LIMIT;
        if (isset($query['limit']) && $query['limit'] !== '') {
            if (!ctype_digit($query['limit'])) {
                HttpResponse::error(400, 'invalid limit', 'invalid_limit');
                return null;
            }
            $requested = (int) $query['limit'];
            // 0 means "unlimited" in the API contract, but cap it server-side
            // to bound memory/CPU on the filesystem driver.
            $limit = $requested === 0 ? self::MAX_LIMIT : min($requested, self::MAX_LIMIT);
        }

        return new CapturedRequestCriteria(
            captureId: ($query['captureId'] ?? '') !== '' ? $query['captureId'] : null,
            correlationId: ($query['correlationId'] ?? '') !== '' ? $query['correlationId'] : null,
            method: $method,
            uri: ($query['uri'] ?? '') !== '' ? $query['uri'] : null,
            capturedAfter: $capturedAfter,
            capturedBefore: $capturedBefore,
            limit: $limit,
            order: $order,
        );
    }

    private function parseCapturedAt(?string $raw, string $param): ?CapturedAt
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return CapturedAt::fromString($raw);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('invalid ' . $param);
        }
    }
}

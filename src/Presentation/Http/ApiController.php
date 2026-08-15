<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\GetCapturedRequest;
use App\Application\QueryCapturedRequests;
use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestCriteria;
use App\Domain\HttpMethod;

final readonly class ApiController
{
    private const LIST_PATH = '/api/v1/captures';
    private const ID_PATTERN = '/^[A-Za-z0-9_-]+$/';

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
        if (!$this->apiAuthRequired) {
            HttpResponse::error(404, 'not found');
            return;
        }

        if (!BearerAuthGuard::check($this->apiToken)) {
            HttpResponse::error(401, 'unauthorized');
            return;
        }

        if ($request === null) {
            $request = ServerRequest::fromGlobals();
        }

        if ($request->method !== 'GET') {
            HttpResponse::error(405, 'method not allowed');
            return;
        }

        $path = parse_url($request->uri, PHP_URL_PATH) ?: '/';

        if ($path === self::LIST_PATH || $path === self::LIST_PATH . '/') {
            $this->listCaptures($request->query);
            return;
        }

        if (str_starts_with($path, self::LIST_PATH . '/')) {
            $captureId = substr($path, strlen(self::LIST_PATH . '/'));
            $this->showCapture($captureId);
            return;
        }

        HttpResponse::error(404, 'not found');
    }

    private function showCapture(string $captureId): void
    {
        if ($captureId === '' || preg_match(self::ID_PATTERN, $captureId) !== 1) {
            HttpResponse::error(400, 'invalid capture id');
            return;
        }

        $entry = $this->getCapturedRequest->handle($captureId);

        if ($entry === null) {
            HttpResponse::error(404, 'capture not found');
            return;
        }

        HttpResponse::json(200, $entry->toArray());
    }

    /**
     * @param array<string, string> $query
     */
    private function listCaptures(array $query): void
    {
        $criteria = $this->buildCriteria($query);
        if ($criteria === null) {
            return;
        }

        $entries = $this->queryCapturedRequests->handle($criteria);

        HttpResponse::json(200, array_map(
            fn (CapturedRequest $entry): array => $entry->toArray(),
            $entries,
        ));
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
                HttpResponse::error(400, 'invalid method');
                return null;
            }
        }

        try {
            $capturedAfter = $this->parseCapturedAt($query['capturedAfter'] ?? null, 'capturedAfter');
            $capturedBefore = $this->parseCapturedAt($query['capturedBefore'] ?? null, 'capturedBefore');
        } catch (\InvalidArgumentException $e) {
            HttpResponse::error(400, $e->getMessage());
            return null;
        }

        $limit = null;
        if (isset($query['limit']) && $query['limit'] !== '') {
            if (!ctype_digit($query['limit']) || (int) $query['limit'] < 1) {
                HttpResponse::error(400, 'invalid limit');
                return null;
            }
            $limit = (int) $query['limit'];
        }

        return new CapturedRequestCriteria(
            captureId: ($query['captureId'] ?? '') !== '' ? $query['captureId'] : null,
            correlationId: ($query['correlationId'] ?? '') !== '' ? $query['correlationId'] : null,
            method: $method,
            uri: ($query['uri'] ?? '') !== '' ? $query['uri'] : null,
            capturedAfter: $capturedAfter,
            capturedBefore: $capturedBefore,
            limit: $limit,
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

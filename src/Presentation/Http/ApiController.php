<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\CountCapturedRequests;
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
    private const DEFAULT_LIMIT = 100;

    public function __construct(
        private GetCapturedRequest $getCapturedRequest,
        private QueryCapturedRequests $queryCapturedRequests,
        private CountCapturedRequests $countCapturedRequests,
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
    private function listCaptures(array $query): void
    {
        $criteria = $this->buildCriteria($query);
        if ($criteria === null) {
            return;
        }

        $entries = $this->queryCapturedRequests->handle($criteria);
        $total = $this->countCapturedRequests->handle($criteria);

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
            $limit = (int) $query['limit'] === 0 ? null : (int) $query['limit'];
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

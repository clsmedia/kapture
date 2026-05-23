<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestRepository;

final readonly class CaptureWebhook
{
    public function __construct(
        private CapturedRequestRepository $repository,
    )
    {
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function handle(
        string $method,
        string $uri,
        array $query,
        array $headers,
        string $body,
        string $ip,
    ): CapturedRequest
    {
        $request = CapturedRequest::capture($method, $uri, $query, $headers, $body, $ip);
        $this->repository->save($request);
        return $request;
    }
}

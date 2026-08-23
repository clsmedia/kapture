<?php

declare(strict_types=1);

namespace App\Domain;

interface ForwardingClient
{
    /**
     * Dial the configured upstream for a captured request and return the verdict.
     *
     * @param string $method Raw HTTP method of the original request
     * @param string $uri Normalized captured URI (path + query)
     * @param array<string, string> $requestHeaders Original request headers; transport-sensitive ones are stripped by the adapter
     */
    public function send(string $method, string $uri, string $body, array $requestHeaders): ForwardResult;

    /** Base URL requests are forwarded to, as recorded on saved captures. */
    public function baseUrl(): string;
}

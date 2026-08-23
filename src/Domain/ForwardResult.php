<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * @param list<string> $relayHeaders Raw upstream response header lines eligible for relaying to the caller
 */
final readonly class ForwardResult
{
    /**
     * @param list<string> $relayHeaders
     */
    private function __construct(
        public bool $delivered,
        public int $statusCode,
        public string $body,
        public array $relayHeaders,
        public string $error,
    )
    {
    }

    /** @param list<string> $relayHeaders */
    public static function delivered(int $statusCode, string $body, array $relayHeaders): self
    {
        return new self(true, $statusCode, $body, $relayHeaders, '');
    }

    public static function failed(int $statusCode, string $error): self
    {
        return new self(false, $statusCode, '', [], $error);
    }
}

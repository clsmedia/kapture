<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class SecurityHeaders
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    /**
     * @return list<string>
     */
    public static function lines(bool $isHttps): array
    {
        $lines = [
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: DENY',
            'Referrer-Policy: no-referrer',
            'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()',
            'Content-Security-Policy: ' . self::CSP,
        ];

        // HSTS only makes sense once the response actually travels over
        // HTTPS — advertising it over plain HTTP would be meaningless.
        if ($isHttps) {
            $lines[] = 'Strict-Transport-Security: max-age=31536000; includeSubDomains';
        }

        return $lines;
    }

    public static function send(bool $isHttps): void
    {
        foreach (self::lines($isHttps) as $line) {
            header($line);
        }
    }
}

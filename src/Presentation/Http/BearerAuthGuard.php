<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class BearerAuthGuard
{
    public static function check(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $header = AuthorizationHeaderResolver::resolve();
        if (!str_starts_with($header, 'Bearer ')) {
            return false;
        }

        $provided = trim(substr($header, 7));
        if ($provided === '') {
            return false;
        }

        return hash_equals($token, $provided);
    }
}

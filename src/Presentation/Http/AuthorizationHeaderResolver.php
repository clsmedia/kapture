<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class AuthorizationHeaderResolver
{
    public static function resolve(): string
    {
        $raw = getallheaders();
        foreach ($raw as $key => $value) {
            if (strtolower((string) $key) === 'authorization') {
                return (string) $value;
            }
        }

        return $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }
}

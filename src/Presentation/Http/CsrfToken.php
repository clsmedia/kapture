<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class CsrfToken
{
    /**
     * Resolve the token for the current session: reuse the cookie value
     * when it is well-formed, otherwise generate and set a fresh one.
     */
    public static function resolve(): string
    {
        $token = (string) ($_COOKIE['XSRF-TOKEN'] ?? '');
        if ($token === '' || strlen($token) !== 32 || !ctype_xdigit($token)) {
            $token = bin2hex(random_bytes(16));
            self::setCookie($token, self::isHttps());
        }
        return $token;
    }

    public static function validate(string $token): bool
    {
        $cookie = $_COOKIE['XSRF-TOKEN'] ?? '';
        if ($cookie === '' || $token === '') {
            return false;
        }
        return hash_equals($cookie, $token);
    }

    private static function setCookie(string $token, bool $secure): void
    {
        setcookie('XSRF-TOKEN', $token, [
            'samesite' => 'Strict',
            'httponly' => true,
            'secure' => $secure,
            'path' => '/admin',
        ]);
    }

    private static function isHttps(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}

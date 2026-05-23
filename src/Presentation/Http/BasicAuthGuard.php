<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class BasicAuthGuard
{
    public static function sendChallenge(): void
    {
        header('WWW-Authenticate: Basic realm="Kapture"');
        http_response_code(401);
    }

    /**
     * Authenticate and terminate on failure (sends 401 + exits).
     */
    public static function protect(string $password): void
    {
        if (!self::checkCredentials($password)) {
            self::sendChallenge();
            echo "Unauthorized\n";
            exit;
        }
    }

    /**
     * Verify credentials without side effects (testable).
     */
    public static function checkCredentials(string $password): bool
    {
        $auth = self::parseBasicAuth();
        $pass = $auth[1] ?? '';

        if (!hash_equals($password, $pass)) {
            return false;
        }
        return true;
    }

    /** @return array{string, string}|null */
    private static function parseBasicAuth(): ?array
    {
        $raw = getallheaders();
        $authHeader = '';
        foreach ($raw as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $authHeader = $v;
                break;
            }
        }
        $hdr = $authHeader !== '' ? $authHeader
            : ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (str_starts_with($hdr, 'Basic ')) {
            $decoded = base64_decode(substr($hdr, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                $parts = explode(':', $decoded, 2);
                return [$parts[0], $parts[1]];
            }
        }
        if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']];
        }
        return null;
    }
}

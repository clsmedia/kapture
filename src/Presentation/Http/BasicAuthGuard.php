<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class BasicAuthGuard
{
    private const RATE_LIMIT_MAX = 30;
    private const RATE_LIMIT_WINDOW = 60;

    public static function sendChallenge(): void
    {
        header('WWW-Authenticate: Basic realm="Kapture"');
        http_response_code(401);
    }

    /**
     * Authenticate and terminate on failure (sends 401 + exits). Failed
     * attempts are rate-limited per client IP to slow brute force.
     */
    public static function protect(string $password): void
    {
        if (!self::checkCredentials($password)) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (!RateLimiter::record('admin_' . $ip, self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW)) {
                self::sendChallenge();
                echo "Too many attempts\n";
                exit;
            }
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
        $hdr = AuthorizationHeaderResolver::resolve();
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

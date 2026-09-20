<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Presentation\Http\BasicAuthGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BasicAuthGuard::class)]
final class BasicAuthGuardTest extends TestCase
{
    protected function setUp(): void
    {
        // Clean $_SERVER state to prevent leakage between tests
        unset(
            $_SERVER['PHP_AUTH_USER'],
            $_SERVER['PHP_AUTH_PW'],
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
        );
    }

    public function test_send_challenge_sets_401(): void
    {
        BasicAuthGuard::sendChallenge();
        self::assertSame(401, http_response_code());
    }

    public function test_check_credentials_correct_password_returns_true(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'secret';

        self::assertTrue(BasicAuthGuard::checkCredentials('secret'));
    }

    public function test_check_credentials_correct_password_via_header(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('admin:secret');

        self::assertTrue(BasicAuthGuard::checkCredentials('secret'));
    }

    public function test_check_credentials_wrong_password_returns_false(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'wrong';

        self::assertFalse(BasicAuthGuard::checkCredentials('secret'));
    }

    public function test_check_credentials_invalid_base64_returns_false(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic !!!invalid!!!';

        self::assertFalse(BasicAuthGuard::checkCredentials('secret'));
    }

    public function test_check_credentials_empty_password_returns_false(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = '';

        self::assertFalse(BasicAuthGuard::checkCredentials('secret'));
    }

    public function test_check_credentials_no_credentials_returns_false(): void
    {
        self::assertFalse(BasicAuthGuard::checkCredentials('secret'));
    }

    public function test_failed_attempt_is_logged_with_client_ip(): void
    {
        $logFile = sys_get_temp_dir() . '/kapture_authlog_' . bin2hex(random_bytes(4));
        $previous = ini_set('error_log', $logFile);
        try {
            BasicAuthGuard::logFailedAttempt('10.9.8.7');
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $content = (string) file_get_contents($logFile);
        unlink($logFile);

        self::assertStringContainsString('failed admin login attempt from 10.9.8.7', $content);
    }
}

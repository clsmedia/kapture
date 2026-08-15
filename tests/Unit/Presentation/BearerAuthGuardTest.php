<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Presentation\Http\BearerAuthGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BearerAuthGuard::class)]
final class BearerAuthGuardTest extends TestCase
{
    protected function setUp(): void
    {
        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
        );
    }

    public function test_correct_token_via_header_returns_true(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret-token';

        self::assertTrue(BearerAuthGuard::check('secret-token'));
    }

    public function test_wrong_token_returns_false(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-token';

        self::assertFalse(BearerAuthGuard::check('secret-token'));
    }

    public function test_missing_header_returns_false(): void
    {
        self::assertFalse(BearerAuthGuard::check('secret-token'));
    }

    public function test_empty_configured_token_returns_false(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secret-token';

        self::assertFalse(BearerAuthGuard::check(''));
    }

    public function test_basic_auth_header_returns_false(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('admin:secret');

        self::assertFalse(BearerAuthGuard::check('secret'));
    }

    public function test_empty_bearer_value_returns_false(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';

        self::assertFalse(BearerAuthGuard::check('secret-token'));
    }
}

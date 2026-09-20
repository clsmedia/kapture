<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Presentation\Http\SecurityHeaders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecurityHeaders::class)]
final class SecurityHeadersTest extends TestCase
{
    public function test_sends_core_protection_headers(): void
    {
        $lines = SecurityHeaders::lines(false);

        $names = array_map(
            static fn(string $line): string => strtolower((string) strstr($line, ':', true)),
            $lines,
        );
        self::assertContains('x-content-type-options', $names);
        self::assertContains('x-frame-options', $names);
        self::assertContains('referrer-policy', $names);
        self::assertContains('content-security-policy', $names);
        self::assertContains('permissions-policy', $names);
    }

    public function test_hsts_only_over_https(): void
    {
        $names = static fn(array $lines): array => array_map(
            static fn(string $line): string => strtolower((string) strstr($line, ':', true)),
            $lines,
        );

        self::assertNotContains('strict-transport-security', $names(SecurityHeaders::lines(false)));
        self::assertContains('strict-transport-security', $names(SecurityHeaders::lines(true)));
    }
}

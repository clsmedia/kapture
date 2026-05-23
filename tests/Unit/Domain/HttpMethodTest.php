<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\HttpMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpMethod::class)]
final class HttpMethodTest extends TestCase
{
    #[TestDox('Has exactly 7 HTTP method cases')]
    public function test_all_methods_present(): void
    {
        $methods = HttpMethod::cases();

        self::assertCount(7, $methods);
    }

    #[TestDox('Cases have correct string values')]
    public function test_case_values(): void
    {
        self::assertSame('GET', HttpMethod::GET->value);
        self::assertSame('POST', HttpMethod::POST->value);
        self::assertSame('PUT', HttpMethod::PUT->value);
        self::assertSame('PATCH', HttpMethod::PATCH->value);
        self::assertSame('DELETE', HttpMethod::DELETE->value);
        self::assertSame('HEAD', HttpMethod::HEAD->value);
        self::assertSame('OPTIONS', HttpMethod::OPTIONS->value);
    }

    #[TestDox('label() returns the HTTP method string')]
    public function test_label(): void
    {
        self::assertSame('GET', HttpMethod::GET->label());
        self::assertSame('POST', HttpMethod::POST->label());
        self::assertSame('DELETE', HttpMethod::DELETE->label());
        self::assertSame('OPTIONS', HttpMethod::OPTIONS->label());
    }

    #[TestDox('tryFromMethod matches exact uppercase')]
    public function test_try_from_exact_uppercase(): void
    {
        self::assertSame(HttpMethod::POST, HttpMethod::tryFromMethod('POST'));
        self::assertSame(HttpMethod::GET, HttpMethod::tryFromMethod('GET'));
    }

    #[TestDox('tryFromMethod matches lowercase input')]
    public function test_try_from_lowercase(): void
    {
        self::assertSame(HttpMethod::POST, HttpMethod::tryFromMethod('post'));
        self::assertSame(HttpMethod::GET, HttpMethod::tryFromMethod('get'));
        self::assertSame(HttpMethod::DELETE, HttpMethod::tryFromMethod('delete'));
    }

    #[TestDox('tryFromMethod matches mixed-case input')]
    public function test_try_from_mixed_case(): void
    {
        self::assertSame(HttpMethod::POST, HttpMethod::tryFromMethod('PosT'));
        self::assertSame(HttpMethod::HEAD, HttpMethod::tryFromMethod('Head'));
        self::assertSame(HttpMethod::PATCH, HttpMethod::tryFromMethod('PaTcH'));
    }

    #[TestDox('tryFromMethod returns null for invalid method')]
    public function test_try_from_invalid(): void
    {
        self::assertNull(HttpMethod::tryFromMethod('INVALID'));
        self::assertNull(HttpMethod::tryFromMethod('CONNECT'));
    }

    #[TestDox('tryFromMethod returns null for empty string')]
    public function test_try_from_empty(): void
    {
        self::assertNull(HttpMethod::tryFromMethod(''));
    }

    #[TestDox('Enum serializes to JSON as string value')]
    public function test_json_serialization(): void
    {
        $json = json_encode(HttpMethod::POST, JSON_THROW_ON_ERROR);
        self::assertSame('"POST"', $json);
    }
}

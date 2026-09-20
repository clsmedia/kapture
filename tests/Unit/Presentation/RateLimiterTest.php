<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Presentation\Http\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = 'rl_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink(sys_get_temp_dir() . '/kapture_rl_' . md5($this->key));
        @unlink(sys_get_temp_dir() . '/kapture_rl_' . md5($this->key . '-other'));
    }

    public function test_allows_up_to_max_then_blocks(): void
    {
        $results = [];
        for ($i = 0; $i < 31; $i++) {
            $results[] = RateLimiter::record($this->key, 30, 60);
        }

        self::assertSame(
            array_fill(0, 30, true),
            array_slice($results, 0, 30),
            'first 30 attempts must be allowed',
        );
        self::assertFalse($results[30], 'attempt 31 must be blocked');
    }

    public function test_counter_file_persists_between_calls(): void
    {
        RateLimiter::record($this->key, 30, 60);

        $file = sys_get_temp_dir() . '/kapture_rl_' . md5($this->key);
        self::assertFileExists($file);

        $window = unserialize((string) file_get_contents($file), ['allowed_classes' => false]);
        self::assertIsArray($window);
        self::assertSame(1, $window['count']);
    }

    public function test_keys_are_independent(): void
    {
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::record($this->key, 30, 60);
        }

        self::assertTrue(RateLimiter::record($this->key . '-other', 30, 60));
        self::assertFalse(RateLimiter::record($this->key, 30, 60));
    }

    public function test_counter_resets_after_window_expiry(): void
    {
        for ($i = 0; $i < 31; $i++) {
            RateLimiter::record($this->key, 30, 60);
        }
        self::assertFalse(RateLimiter::record($this->key, 30, 60));

        // Simulate the window having elapsed: rewind the stored reset time.
        $file = sys_get_temp_dir() . '/kapture_rl_' . md5($this->key);
        file_put_contents($file, serialize(['reset' => time() - 1, 'count' => 31]));

        self::assertTrue(RateLimiter::record($this->key, 30, 60), 'a fresh window must allow again');
    }
}

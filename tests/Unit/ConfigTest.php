<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ConfigTest extends TestCase
{
    private array $originalEnv;

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
    }

    /**
     * @param array<string, string> $extraEnv
     * @return array<string, mixed>
     */
    private function loadConfig(array $extraEnv): array
    {
        $_ENV = array_merge(
            ['ADMIN_PASSWORD' => 'test', 'LOG_DIR' => '/tmp', 'ROTATE_DAYS' => '7'],
            $extraEnv,
        );

        return require __DIR__ . '/../../config.php';
    }

    public function test_defaults_to_filesystem_when_not_set(): void
    {
        $config = $this->loadConfig([]);
        self::assertSame('filesystem', $config['storage_driver']);
    }

    public function test_defaults_to_filesystem_when_empty_string(): void
    {
        $config = $this->loadConfig(['STORAGE_DRIVER' => '']);
        self::assertSame('filesystem', $config['storage_driver']);
    }

    public function test_accepts_filesystem(): void
    {
        $config = $this->loadConfig(['STORAGE_DRIVER' => 'filesystem']);
        self::assertSame('filesystem', $config['storage_driver']);
    }

    public function test_accepts_sqlite(): void
    {
        $config = $this->loadConfig(['STORAGE_DRIVER' => 'sqlite']);
        self::assertSame('sqlite', $config['storage_driver']);
    }

    public function test_case_insensitive_sqlite(): void
    {
        $config = $this->loadConfig(['STORAGE_DRIVER' => 'SQLITE']);
        self::assertSame('sqlite', $config['storage_driver']);
    }

    public function test_case_insensitive_filesystem(): void
    {
        $config = $this->loadConfig(['STORAGE_DRIVER' => 'Filesystem']);
        self::assertSame('filesystem', $config['storage_driver']);
    }

    public function test_invalid_value_triggers_error(): void
    {
        $script = __DIR__ . '/../../.test_cfg_invalid.php';
        file_put_contents($script, "<?php\n\$_ENV = ['ADMIN_PASSWORD' => 'test', 'LOG_DIR' => '/tmp', 'ROTATE_DAYS' => '7', 'STORAGE_DRIVER' => 'mongo'];\nrequire __DIR__ . '/config.php';\n");

        $output = shell_exec('php -d variables_order=EGPCS ' . escapeshellarg($script) . ' 2>&1');
        unlink($script);

        self::assertStringContainsString('mongo', $output ?: '');
    }

    public function test_sqlite_extension_when_loaded(): void
    {
        if (!extension_loaded('sqlite3')) {
            self::markTestSkipped('ext-sqlite3 not available');
        }

        $config = $this->loadConfig(['STORAGE_DRIVER' => 'sqlite']);
        self::assertSame('sqlite', $config['storage_driver']);
    }

    public function test_returns_all_required_keys(): void
    {
        $config = $this->loadConfig([]);
        self::assertArrayHasKey('admin_password', $config);
        self::assertArrayHasKey('log_dir', $config);
        self::assertArrayHasKey('rotate_days', $config);
        self::assertArrayHasKey('storage_driver', $config);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class Bootstrap
{
    public static function loadEnvFile(string $path): void
    {
        if (!file_exists($path)) {
            echo "Kapture: .env not found at {$path}. Copy .env.example to .env and set your values.\n";
            exit(1);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            if ((str_starts_with($val, '"') && str_ends_with($val, '"'))
                || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            $_ENV[$key] = $val;
            putenv("$key=$val");
        }
    }

    public static function resolveLogDir(string $raw, string $projectRoot): string
    {
        if (!str_starts_with($raw, '/')) {
            return $projectRoot . $raw;
        }

        $resolvedRoot = realpath($projectRoot);
        if ($resolvedRoot !== false && !str_starts_with($raw, rtrim($resolvedRoot, '/') . '/')) {
            error_log('Kapture: WARNING — LOG_DIR is outside the project root. Captured request data may be written to an unexpected location.');
        }

        return $raw;
    }
}

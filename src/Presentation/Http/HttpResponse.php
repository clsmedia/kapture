<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class HttpResponse
{
    /** @param array<string, mixed> $data */
    public static function json(int $code, array $data): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_THROW_ON_ERROR) . "\n";
    }

    public static function error(int $code, string $msg): void
    {
        self::json($code, ['error' => $msg]);
    }
}

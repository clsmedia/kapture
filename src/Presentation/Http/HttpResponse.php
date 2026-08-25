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

    public static function text(int $code, string $body): void
    {
        http_response_code($code);
        header('Content-Type: text/plain');
        header('Cache-Control: no-store');
        echo $body;
    }

    public static function error(int $code, string $msg, ?string $errorCode = null): void
    {
        $data = ['error' => $msg];
        if ($errorCode !== null) {
            $data['code'] = $errorCode;
        }
        self::json($code, $data);
    }
}

<?php

declare(strict_types=1);

namespace App\Presentation\Html;

final readonly class LogoutView
{
    public static function render(): void
    {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="Kapture"');
        header('Content-Type: text/plain');
        echo "Logged out.\n";
    }
}

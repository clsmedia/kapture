<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        $headers = [];
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] ??= $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return $headers;
    }
}

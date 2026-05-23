<?php

declare(strict_types=1);

namespace App\Domain;

enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case HEAD = 'HEAD';
    case OPTIONS = 'OPTIONS';

    public function label(): string
    {
        return $this->value;
    }

    public static function tryFromMethod(string $method): ?self
    {
        $upper = strtoupper($method);

        /** @var ?self $result */
        $result = self::tryFrom($upper);

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Documentation;

use OpenApi\Attributes as OA;

/**
 * Carries the global OpenAPI attributes (info, security scheme, tags and
 * shared schemas) that swagger-php collects when generating
 * public/openapi.json via `composer docs`.
 */
#[OA\OpenApi(openapi: OA\OpenApi::VERSION_3_1_0)]
#[OA\Info(
    version: '1.0.0',
    title: 'Kapture API',
    description: 'Catch, log, and inspect every HTTP webhook in real time. The Test API is opt-in: it is only exposed when API_AUTH_REQUIRED=true in .env, and every request must then carry Authorization: Bearer <API_TOKEN>.',
    license: new OA\License(name: 'MIT'),
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    description: 'Bearer token for the read API (API_TOKEN in .env).',
)]
#[OA\Tag(name: 'captures', description: 'Captured requests')]
#[OA\Schema(
    schema: 'Error',
    type: 'object',
    required: ['error'],
    properties: [
        new OA\Property(property: 'error', type: 'string', description: 'Human-readable message'),
        new OA\Property(property: 'code', type: 'string', description: 'Stable machine-readable code for branching in tests'),
    ],
)]
final class OpenApiSpec
{
}
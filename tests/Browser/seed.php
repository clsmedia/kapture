<?php

declare(strict_types=1);

require __DIR__ . '/../../autoload.php';

use App\Domain\CapturedAt;
use App\Domain\CapturedRequest;
use App\Domain\HttpMethod;
use App\Infrastructure\Persistence\FilesystemCapturedRequestRepository;
use App\Infrastructure\Persistence\SqliteCapturedRequestRepository;

$command = $argv[1] ?? 'seed';

$env = parseEnv(__DIR__ . '/../../.env');
$logDir = str_starts_with($env['LOG_DIR'], '/')
    ? $env['LOG_DIR']
    : realpath(__DIR__ . '/../..') . '/' . $env['LOG_DIR'];

$repo = ($env['STORAGE_DRIVER'] ?? 'filesystem') === 'sqlite'
    ? new SqliteCapturedRequestRepository($logDir, 7)
    : new FilesystemCapturedRequestRepository($logDir, 7);

if ($command === 'cleanup') {
    $prefix = $argv[2] ?? '';
    if ($prefix === '') {
        fwrite(STDERR, "usage: seed.php cleanup <prefix>\n");
        exit(1);
    }
    $all = $repo->findAll();
    $ids = array_map(
        static fn (CapturedRequest $e): string => $e->captureId,
        array_filter($all, static fn (CapturedRequest $e): bool => str_starts_with($e->captureId, $prefix)),
    );
    $repo->deleteMany(array_values($ids));
    echo json_encode(['deleted' => count($ids)]) . "\n";
    exit(0);
}

$count = max(1, (int)($argv[1] ?? 3));
$prefix = 'e2e-' . bin2hex(random_bytes(4));
$group = $argv[2] ?? 'e2e';

$ids = [];
for ($i = 0; $i < $count; $i++) {
    $id = sprintf('%s-%04d', $prefix, $i);
    $repo->save(new CapturedRequest(
        capturedAt: CapturedAt::now(),
        method: HttpMethod::POST,
        uri: sprintf('/%s/hook-%d?src=%s', $group, $i, $prefix),
        query: ['src' => $prefix],
        headers: ['User-Agent' => 'kapture-e2e', 'Content-Type' => 'application/json'],
        body: json_encode(['hello' => 'world', 'n' => $i], JSON_THROW_ON_ERROR),
        ip: '127.0.0.1',
        captureId: $id,
        correlationId: $prefix,
    ));
    $ids[] = $id;
}

echo json_encode(['prefix' => $prefix, 'ids' => $ids], JSON_THROW_ON_ERROR) . "\n";

function parseEnv(string $path): array
{
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $vars[trim($key)] = trim(trim($val), "\"'");
    }
    return $vars;
}

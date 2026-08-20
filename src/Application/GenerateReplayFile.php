<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\CapturedRequest;

final readonly class GenerateReplayFile
{
    public function __construct(
        private GetCapturedRequest $getCapturedRequest,
    ) {
    }

    public function handle(string $captureId, string $format = 'http'): ?string
    {
        $entry = $this->getCapturedRequest->handle($captureId);

        if ($entry === null) {
            return null;
        }

        return match ($format) {
            'curl' => $this->generateCurl($entry),
            default => $this->generateHttp($entry),
        };
    }

    private function generateHttp(CapturedRequest $entry): string
    {
        $lines = ['### Replay captured request', sprintf('%s %s', $entry->method->value, $entry->uri)];

        foreach ($entry->headers as $key => $value) {
            $lines[] = sprintf('%s: %s', $key, $value);
        }

        if ($entry->body !== '') {
            $lines[] = '';
            $lines[] = $entry->body;
        }

        return implode("\n", $lines) . "\n";
    }

    private function generateCurl(CapturedRequest $entry): string
    {
        $parts = ['curl'];

        if ($entry->method->value !== 'GET') {
            $parts[] = sprintf('-X %s', $entry->method->value);
        }

        $parts[] = self::shellSingleQuote($entry->uri);

        foreach ($entry->headers as $key => $value) {
            $parts[] = '-H ' . self::shellSingleQuote(sprintf('%s: %s', $key, $value));
        }

        if ($entry->body !== '') {
            $parts[] = '-d ' . self::shellSingleQuote($entry->body);
        }

        return implode(" \\\n  ", $parts) . "\n";
    }

    /**
     * Proper POSIX shell single-quote escaping. addslashes() is NOT enough:
     * inside single quotes a backslash is literal, so a single quote must be
     * closed, escaped and reopened ('\'').
     */
    private static function shellSingleQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
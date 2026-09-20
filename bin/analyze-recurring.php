#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';
\App\Infrastructure\Bootstrap::loadEnvFile(__DIR__ . '/../.env');

use App\Application\RunRecurringAnalysis;
use App\Infrastructure\Persistence\FilesystemCapturedRequestRepository;
use App\Infrastructure\Persistence\SqliteCapturedRequestRepository;

$config = require __DIR__ . '/../config.php';
$logDir = \App\Infrastructure\Bootstrap::resolveLogDir($config['log_dir'], __DIR__ . '/../');

$repo = match ($config['storage_driver']) {
    'sqlite' => new SqliteCapturedRequestRepository($logDir, $config['rotate_days']),
    default => new FilesystemCapturedRequestRepository($logDir, $config['rotate_days']),
};

$analyzer = new RunRecurringAnalysis($repo, $logDir, $config['rotate_days']);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if (!$analyzer->isDue($now)) {
    echo "Analysis not due yet. Last run was less than 24 hours ago.\n";
    echo "State file: {$logDir}/recurring-state.json\n";
    exit(0);
}

$report = $analyzer->run($now);

if ($report === null) {
    echo "Analysis could not be completed (lock contention or error). Check logs.\n";
    exit(1);
}

echo "=== Recurring Analysis Report ===\n";
echo "Run at:        {$report->runAt}\n";
echo "Window:        {$report->windowDays} days\n";
echo "Scanned:       {$report->scannedEntries} entries\n";
echo "\n";

if ($report->periodicPatterns !== []) {
    echo "Periodic patterns (" . count($report->periodicPatterns) . "):\n";
    foreach ($report->periodicPatterns as $p) {
        echo "  [{$p->type->value}] {$p->fingerprint}\n";
        echo "    occurrences: {$p->occurrences}, period: {$p->periodSeconds}s, cron: {$p->suggestedCron}\n";
        echo "    first: {$p->firstSeen}, last: {$p->lastSeen}\n";
    }
    echo "\n";
} else {
    echo "No periodic patterns detected.\n\n";
}

if ($report->topOffenders !== []) {
    echo "Top offenders (" . count($report->topOffenders) . "):\n";
    foreach ($report->topOffenders as $o) {
        echo "  {$o->fingerprint} — {$o->occurrences} occurrences\n";
    }
    echo "\n";
} else {
    echo "No frequent repetitions detected.\n\n";
}

echo "Report appended to: {$logDir}/recurring-report.jsonl\n";
echo "State updated:      {$logDir}/recurring-state.json\n";

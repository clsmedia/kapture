<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\RunRecurringAnalysis;
use App\Domain\RecurringReport;
use App\Presentation\Html\AnalysisView;

final readonly class AnalysisController
{
    public function __construct(
        private RunRecurringAnalysis $runRecurringAnalysis,
        private AnalysisView $analysisView,
        private string $adminPassword,
    ) {
    }

    public function handle(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        BasicAuthGuard::protect($this->adminPassword);

        if ($path === '/admin/api/analysis') {
            $this->apiAnalysis();
            return;
        }

        if ($path === '/admin/api/analysis/run') {
            $this->apiRun();
            return;
        }

        $this->analysisView->render();
    }

    /**
     * Latest analysis snapshot. When the 24-hour interval has elapsed (or
     * no snapshot exists yet) a fresh analysis runs before responding.
     */
    private function apiAnalysis(): void
    {
        header('Cache-Control: no-store');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $report = $this->runRecurringAnalysis->latestReport();
        $due = $this->runRecurringAnalysis->isDue($now);

        if ($due || $report === null) {
            $fresh = $this->runRecurringAnalysis->run($now);
            if ($fresh !== null) {
                $report = $fresh;
                $due = false;
            }
        }

        $this->respond($report, $due);
    }

    /**
     * Force a fresh analysis on demand, bypassing the interval throttle.
     */
    private function apiRun(): void
    {
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            HttpResponse::error(405, 'method not allowed', 'method_not_allowed');
            return;
        }

        if (!CsrfToken::validate((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            HttpResponse::error(403, 'Invalid or missing CSRF token');
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $report = $this->runRecurringAnalysis->run($now, force: true);

        if ($report === null) {
            HttpResponse::error(409, 'Analysis is already running', 'analysis_busy');
            return;
        }

        $this->respond($report, false);
    }

    private function respond(?RecurringReport $report, bool $due): void
    {
        $lastRunAt = $this->runRecurringAnalysis->lastRunAt();
        $nextRunAt = $lastRunAt?->modify('+' . RunRecurringAnalysis::INTERVAL_SECONDS . ' seconds');

        HttpResponse::json(200, [
            'report' => $report?->toArray(),
            'due' => $due,
            'lastRunAt' => $lastRunAt?->format('Y-m-d\TH:i:s\Z'),
            'nextRunAt' => $nextRunAt?->format('Y-m-d\TH:i:s\Z'),
            'csrfToken' => CsrfToken::resolve(),
        ]);
    }
}

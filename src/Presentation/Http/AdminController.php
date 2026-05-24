<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\ListCapturedRequests;
use App\Application\ListCapturedRequestsResult;
use App\Domain\CapturedRequest;
use App\Presentation\Html\AdminView;
use App\Presentation\Html\LogoutView;

final readonly class AdminController
{
    public function __construct(
        private ListCapturedRequests $listCapturedRequests,
        private string $adminPassword,
        private string $logDir,
    )
    {
    }

    public function handle(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        if ($path === '/admin/logout') {
            LogoutView::render();
            return;
        }

        BasicAuthGuard::protect($this->adminPassword);

        $requestedFile = $_GET['file'] ?? null;

        if (isset($_GET['raw'])) {
            $this->serveRaw($requestedFile);
            return;
        }

        $result = $this->listCapturedRequests->handle($requestedFile);

        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            $this->serveJson($result);
            return;
        }

        AdminView::render($result);
    }

    private function serveRaw(?string $file): void
    {
        $path = $file
            ? $this->logDir . '/webhooks-' . basename($file) . '.jsonl'
            : $this->logDir . '/webhooks-' . date('Y-m-d') . '.jsonl';

        if (file_exists($path)) {
            header('Content-Type: text/plain');
            readfile($path);
            return;
        }

        self::jsonError(404, 'File not found');
    }

    private function serveJson(ListCapturedRequestsResult $result): void
    {
        header('Content-Type: application/json');

        echo json_encode([
                'entries' => array_map(
                    fn(CapturedRequest $e) => $e->toArray() + ['capturedAtHuman' => $e->capturedAt->toHumanReadable()],
                    $result->entries,
                ),
                'archive' => $result->selectedArchive,
            ], JSON_THROW_ON_ERROR) . "\n";
    }

    private static function jsonError(int $code, string $msg): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['error' => $msg], JSON_THROW_ON_ERROR) . "\n";
    }
}

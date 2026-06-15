<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\ListCapturedRequests;
use App\Application\ListCapturedRequestsResult;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestRepository;
use App\Presentation\Html\AdminView;
use App\Presentation\Html\LogoutView;

final readonly class AdminController
{
    public function __construct(
        private ListCapturedRequests $listCapturedRequests,
        private CapturedRequestRepository $repository,
        private AdminView $adminView,
        private string $adminPassword,
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

        if (isset($_GET['delete'])) {
            $this->delete((string) $_GET['delete'], $requestedFile);
            return;
        }

        if (isset($_GET['raw'])) {
            $this->serveRaw($requestedFile);
            return;
        }

        $result = $this->listCapturedRequests->handle($requestedFile);

        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            $this->serveJson($result);
            return;
        }

        $csrfToken = (string) ($_COOKIE['XSRF-TOKEN'] ?? '');
        if ($csrfToken === '' || strlen($csrfToken) !== 32 || !ctype_xdigit($csrfToken)) {
            $csrfToken = self::generateCsrfToken();
            self::setCsrfCookie($csrfToken);
        }
        $this->adminView->render($result, $csrfToken);
    }

    private function serveRaw(?string $file): void
    {
        try {
            $date = $file !== null
                ? \DateTimeImmutable::createFromFormat('Y-m-d|', basename($file))
                : new \DateTimeImmutable('today');
        } catch (\Exception) {
            HttpResponse::error(404, 'File not found');
            return;
        }

        if ($date === false) {
            HttpResponse::error(404, 'File not found');
            return;
        }

        $content = $this->repository->getRawContent($date);
        if ($content === null) {
            HttpResponse::error(404, 'File not found');
            return;
        }

        header('Content-Type: text/plain');
        echo $content;
    }

    private function delete(string $captureId, ?string $requestedFile): void
    {
        if (!self::validateCsrfToken($_GET['_csrf'] ?? '')) {
            HttpResponse::error(403, 'Invalid or missing CSRF token');
            return;
        }

        $this->repository->delete($captureId);

        $redirect = '/admin';
        if ($requestedFile !== null) {
            $redirect .= '?file=' . rawurlencode($requestedFile);
        }

        header('Location: ' . $redirect);
        http_response_code(302);
    }

    private static function generateCsrfToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function setCsrfCookie(string $token): void
    {
        setcookie('XSRF-TOKEN', $token, [
            'samesite' => 'Strict',
            'httponly' => true,
            'path' => '/admin',
        ]);
    }

    private static function validateCsrfToken(string $token): bool
    {
        $cookie = $_COOKIE['XSRF-TOKEN'] ?? '';
        if ($cookie === '' || $token === '') {
            return false;
        }
        return hash_equals($cookie, $token);
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
}

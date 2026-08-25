<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\GenerateReplayFile;
use App\Application\ListCapturedRequestsResult;
use App\Application\QueryCapturedRequests;
use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestRepository;
use App\Presentation\Html\AdminView;
use App\Presentation\Html\LogoutView;

final readonly class AdminController
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public function __construct(
        private QueryCapturedRequests $queryCapturedRequests,
        private CapturedRequestRepository $repository,
        private GenerateReplayFile $generateReplayFile,
        private AdminView $adminView,
        private string $adminPassword,
    ) {
    }

    public function handle(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        if ($path === '/admin/logout') {
            LogoutView::render();
            return;
        }

        BasicAuthGuard::protect($this->adminPassword);

        if ($path === '/admin/api/state') {
            $this->apiState();
            return;
        }

        if ($path === '/admin/api/delete') {
            $this->apiDelete();
            return;
        }

        $requestedFile = $_GET['file'] ?? null;

        $page = max(1, (int) ($_GET['page'] ?? 1));

        if (isset($_GET['delete'])) {
            $this->delete((array) $_GET['delete'], $requestedFile);
            return;
        }

        if (isset($_GET['raw'])) {
            $this->serveRaw($requestedFile);
            return;
        }

        if (isset($_GET['replay'])) {
            $this->serveReplay();
            return;
        }

        $result = $this->queryCapturedRequests->dashboard($requestedFile, page: $page);

        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            $this->serveJson($result);
            return;
        }

        if (isset($_GET['format']) && $_GET['format'] === 'rows') {
            $this->serveRows($result);
            return;
        }

        $csrfToken = $this->resolveCsrfToken();
        $this->adminView->render($result, $csrfToken);
    }

    /**
     * Full dashboard state as JSON for the admin UI: current page of
     * entries, pagination metadata, archive list with counts, and the
     * CSRF token. Additive companion to the HTML view — same query
     * semantics as /admin (?file, ?page).
     */
    private function apiState(): void
    {
        header('Cache-Control: no-store');

        $requestedFile = $_GET['file'] ?? null;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = $this->queryCapturedRequests->dashboard($requestedFile, page: $page);

        $archives = [];
        foreach ($result->dailyArchives as $date) {
            $archives[] = ['date' => $date, 'count' => $result->archiveCounts[$date] ?? 0];
        }

        HttpResponse::json(200, [
            'entries' => array_map(
                fn(CapturedRequest $e) => $e->toArray(),
                $result->page->entries,
            ),
            'total' => $result->page->totalEntries,
            'page' => $result->page->currentPage,
            'perPage' => $result->page->perPage,
            'archives' => $archives,
            'selectedArchive' => $result->selectedArchive,
            'csrfToken' => $this->resolveCsrfToken(),
        ]);
    }

    /**
     * Bulk delete over JSON POST: {"ids": [...]} with the CSRF token in
     * the X-CSRF-Token header. Returns the number of removed captures
     * instead of redirecting.
     */
    private function apiDelete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            HttpResponse::error(405, 'method not allowed', 'method_not_allowed');
            return;
        }

        if (!self::validateCsrfToken((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            HttpResponse::error(403, 'Invalid or missing CSRF token');
            return;
        }

        $body = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($body)) {
            HttpResponse::error(400, 'invalid JSON body', 'invalid_body');
            return;
        }

        $ids = array_values(array_filter(
            (array) ($body['ids'] ?? []),
            static fn (mixed $id): bool => is_string($id) && preg_match(self::ID_PATTERN, $id) === 1,
        ));
        if ($ids === []) {
            HttpResponse::error(400, 'no valid capture ids', 'invalid_ids');
            return;
        }

        $this->repository->deleteMany($ids);

        HttpResponse::json(200, ['deleted' => count($ids)]);
    }

    private function resolveCsrfToken(): string
    {
        $csrfToken = (string) ($_COOKIE['XSRF-TOKEN'] ?? '');
        if ($csrfToken === '' || strlen($csrfToken) !== 32 || !ctype_xdigit($csrfToken)) {
            $csrfToken = self::generateCsrfToken();
            self::setCsrfCookie($csrfToken, $this->isHttps());
        }
        return $csrfToken;
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

    private function serveReplay(): void
    {
        header('Cache-Control: no-store');

        $captureId = $_GET['replay'] ?? '';
        $format = $_GET['format'] ?? 'http';

        if (!is_string($captureId) || $captureId === '' || preg_match(self::ID_PATTERN, $captureId) !== 1) {
            HttpResponse::error(400, 'Invalid capture id');
            return;
        }

        if (!in_array($format, ['http', 'curl'], true)) {
            HttpResponse::error(400, 'Invalid format');
            return;
        }

        $content = $this->generateReplayFile->handle($captureId, $format);

        if ($content === null) {
            HttpResponse::error(404, 'Capture not found');
            return;
        }

        header('Content-Type: application/json');
        try {
            $json = json_encode([
                'content' => $content,
                'format' => $format,
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException) {
            HttpResponse::error(500, 'Could not serialize replay content');
            return;
        }
        echo $json . "\n";
    }

    /**
     * @param array<mixed> $captureIds
     */
    private function delete(array $captureIds, ?string $requestedFile): void
    {
        if (!self::validateCsrfToken($_GET['_csrf'] ?? '')) {
            HttpResponse::error(403, 'Invalid or missing CSRF token');
            return;
        }

        $captureIds = array_values(array_filter(
            $captureIds,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        ));

        $this->repository->deleteMany($captureIds);

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

    private static function setCsrfCookie(string $token, bool $secure): void
    {
        setcookie('XSRF-TOKEN', $token, [
            'samesite' => 'Strict',
            'httponly' => true,
            'secure' => $secure,
            'path' => '/admin',
        ]);
    }

    private function isHttps(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    private static function validateCsrfToken(string $token): bool
    {
        $cookie = $_COOKIE['XSRF-TOKEN'] ?? '';
        if ($cookie === '' || $token === '') {
            return false;
        }
        return hash_equals($cookie, $token);
    }

    private function serveRows(ListCapturedRequestsResult $result): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        $this->adminView->renderRows($result);
    }

    private function serveJson(ListCapturedRequestsResult $result): void
    {
        header('Content-Type: application/json');

        echo json_encode([
                'entries' => array_map(
                    fn(CapturedRequest $e) => $e->toArray(),
                    $result->page->entries,
                ),
                'archive' => $result->selectedArchive,
            ], JSON_THROW_ON_ERROR) . "\n";
    }
}

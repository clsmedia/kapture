# Kapture — AGENTS.md

## Run

```bash
cp .env.example .env     # required — app won't start without it
php -S localhost:8000 -t public
```

## Test / Lint

```bash
composer test            # phpunit
composer ecs             # coding standards (PSR-12 + Symfony rules)
composer ecs:fix         # auto-fix
composer phpstan         # static analysis (level 8)
composer check           # phpstan → ecs → test (in that order)
npx playwright test      # browser E2E (tests/Browser/, boots server on :8010)
```

## Structure

- `public/` — document root (`-t public`). `index.php` is the front controller (manually wires DI), assets served directly.
- `.env` required — parsed in `public/index.php` before `config.php`. Missing file or var = specific 500 error.
- `config.php` — reads from `$_ENV`, validates 3 required vars (`ADMIN_PASSWORD`, `LOG_DIR`, `ROTATE_DAYS`) plus optional `STORAGE_DRIVER` (defaults to `filesystem`, can be `sqlite`).
- `autoload.php` — custom PSR-4 autoloader (`App\` → `src/`). Tests boot via `vendor/autoload.php` instead.
- `src/Domain/` — CapturedRequest, CapturedAt, HttpMethod enum, CapturedRequestRepository interface.
- `src/Application/` — CaptureWebhook + QueryCapturedRequests use cases.
- `src/Infrastructure/Persistence/` — FilesystemCapturedRequestRepository (JSONL files, daily rotation, pruning) + SqliteCapturedRequestRepository (SQLite database, no pruning).
- `src/Presentation/Http/` — Router, WebhookController, AdminController, ApiController, BasicAuthGuard.
- `src/Presentation/Html/` — AdminView + LogoutView render the dashboard HTML.
- `public/assets/` — `admin.js` (Alpine `kaptureAdmin` component), `alpine-csp.min.js` (vendored Alpine CSP build 3.16.x), `style.css`.
- `tests/Browser/` — Playwright E2E suite + `seed.php` (seeds captures via the repository directly, never HTTP — avoids triggering FORWARD_URL) + visual baselines.

## Conventions

- PHP 8.4+ with `declare(strict_types=1)` everywhere.
- `final readonly class` for services; no frameworks, zero runtime deps (the vendored Alpine asset and the dev-only Playwright package are not composer runtime deps).
- No code comments unless the code cannot be made self-documenting.
- Tests use `#[CoversClass]` attribute and `createMock` for mocking.
- Admin UI runs under a strict CSP (`script-src 'self'`, no `unsafe-inline`/`unsafe-eval`): no inline event handlers, no inline scripts, no inline style attributes; all admin JS logic lives in `Alpine.data()` registrations inside `admin.js` (Alpine CSP build forbids DOM property assignment and globals in markup expressions).
- E2E tests purge `e2e-*` prefixed captures before each test and clean up their own seeds; never seed through the webhook HTTP endpoint.

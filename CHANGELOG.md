# Changelog

## [Unreleased]

### Added
- Recurring analysis: daily lazy-triggered analysis of captured requests within the retention window, detecting periodic patterns (regular intervals), daily-same-hour patterns, and top offenders — results persisted as a JSON snapshot in `logs/recurring-report.json`, state tracked in `logs/recurring-state.json`; runs best-effort in the background of webhook requests after the response is flushed, with flock-based concurrency guard and 24-hour throttle
- `/admin/analysis` — dedicated admin view for the analysis: stat cards (captures, endpoints, periodic, daily), a recurring-patterns table (period, occurrence count, suggested cron, `new` badge for patterns first detected within 24h), and top offenders with share-of-traffic bars; auto-refreshes when due and offers on-demand `Run now`
- `GET /admin/api/analysis` (snapshot + run metadata) and `POST /admin/api/analysis/run` (forced run, CSRF-protected) behind admin Basic Auth
- `bin/analyze-recurring.php` — optional CLI script; forces a run and points at the admin view
- `src/Application/DetectRecurring.php` — pure analysis use-case (fingerprint grouping by method+URI+IP, gap regularity detection ±20%, daily-bucket detection, top-10 offenders)
- `src/Application/RunRecurringAnalysis.php` — trigger orchestrator (24-hour throttle from the state's `lastRunAt`, flock, snapshot persistence, `firstDetectedAt` tracking per pattern)
- `src/Domain/RecurringPattern.php`, `src/Domain/RecurringReport.php`, `src/Domain/PatternType.php` — domain models
- `src/Infrastructure/Bootstrap.php` — shared `loadEnvFile()` / `resolveLogDir()` (extracted from `public/index.php`)
- `src/Presentation/Http/CsrfToken.php` — shared CSRF cookie/token handling (extracted from `AdminController`)
- Server-side dashboard search: the admin filter box now queries all stored captures (`?q=` matches URI, body, headers, query params, capture/correlation IDs, IP) instead of only the visible page — debounced live filtering, shareable deep links (`/admin?q=…`), search preserved across pagination and archive switches, clear button
- Alpine.js (CSP build 3.16.2) vendored as a static asset (`public/assets/alpine-csp.min.js`) — the admin UI is now a reactive single-page-style component with zero build step and zero npm runtime
- `GET /admin/api/state` — full dashboard state as JSON (entries, pagination metadata, archives with counts, CSRF token) behind admin Basic Auth
- `POST /admin/api/delete` — JSON bulk delete (`{"ids": [...]}` with `X-CSRF-Token` header) returning `{"deleted": n}` instead of a redirect
- Playwright browser E2E suite (`tests/Browser/`, 25 scenarios) covering auth, table rendering, filters, selection, delete, replay modal, live polling, archives, raw view, pagination, and the analysis view — plus visual baseline screenshots
- Strict Content-Security-Policy on all responses: `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'`
- `package.json` declaring `@playwright/test` as the only (dev-only, non-runtime) dependency for browser tests
- Request payload details (Body, Headers, Query) in the admin UI are now syntax-highlighted JSON — keys, strings, numbers, booleans, null, and punctuation render in token colors via CSP-safe `<span>` classes bound with `x-for`/`:class` (the Alpine CSP build prohibits `x-html`; payload text is bound with `x-text`, so captured content stays inert), and tokens are only built for the expanded row

### Fixed
- Rate limiting was silently ineffective: the counter file was discarded after every write, so the window restarted on each request and limits never triggered. The admin brute-force window (30/min per IP) and the webhook per-IP cap (60/min) now actually enforce — with unit tests covering the limit, window reset, and key independence
- Recurring analysis: intraday cadences (e.g. every 2 hours) are no longer misclassified as daily patterns — daily detection now requires the occurrence count to match roughly one per day, tolerating retries
- Recurring analysis: suggested cron is only emitted for cadences cron can express (whole minutes/hours/days); odd and sub-minute periods report the period without a misleading cron string
- Recurring analysis: an empty or corrupt `recurring-state.json` no longer blocks analysis for a full interval — the due-check reads the `lastRunAt` field and treats unreadable state as “due”
- Recurring analysis: state and report writes no longer take a redundant per-write `LOCK_EX` (already serialized by the dedicated analysis lock), preventing truncated zero-byte files when advisory locking is unreliable
- Recurring analysis: top-offender `firstSeen`/`lastSeen` now reflect the actual first and last occurrence instead of both pointing at the first capture
- `bin/analyze-recurring.php` no longer emits a PHP parse error on `|count` interpolation

### Changed
- Admin dashboard rewritten from vanilla ES5 + inline `onclick` handlers to Alpine.js CSP-build components (`Alpine.data('kaptureAdmin')` in `admin.js`) — all logic lives in JS, markup only references named methods, `x-text`/`x-show` escape automatically
- Live polling now refreshes through `/admin/api/state` with keyed row reconciliation instead of HTML-fragment diffing; the `?format=rows` endpoint is retained for backward compatibility but no longer used by the UI
- Single and bulk delete now go through `POST /admin/api/delete` + in-place state refresh instead of full-page GET navigation
- `AdminViewTest` row-rendering coverage migrated to `renderRows()` (the `?format=rows` fragment renderer); page-shell coverage (pills, pagination, bulk menu) stays on `render()`
- CSRF token for the admin UI is delivered via `/admin/api/state` (the `<meta name="csrf-token">` tag was removed)

### Fixed
- Admin dashboard row expansion now correctly shows request details
- Whitespace gap between URI group span and rest path span

### Security
- Failed admin login attempts are now logged (with the client IP) before the 401 challenge
- `Permissions-Policy` header sent on every response; `Strict-Transport-Security` added when the app is served over HTTPS
- Inline event handlers (`onclick`/`oninput`) removed from the admin page — the panel now runs under a strict CSP with no `unsafe-inline`/`unsafe-eval`, hardening against XSS from captured webhook content

## [0.3.0] — 2026-06-12

- Add AJAX auto-refresh and URI group filter

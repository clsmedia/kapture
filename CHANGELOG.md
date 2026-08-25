# Changelog

## [Unreleased]

### Added
- Alpine.js (CSP build 3.16.2) vendored as a static asset (`public/assets/alpine-csp.min.js`) — the admin UI is now a reactive single-page-style component with zero build step and zero npm runtime
- `GET /admin/api/state` — full dashboard state as JSON (entries, pagination metadata, archives with counts, CSRF token) behind admin Basic Auth
- `POST /admin/api/delete` — JSON bulk delete (`{"ids": [...]}` with `X-CSRF-Token` header) returning `{"deleted": n}` instead of a redirect
- Playwright browser E2E suite (`tests/Browser/`, 20 scenarios) covering auth, table rendering, filters, selection, delete, replay modal, live polling, archives, raw view, and pagination — plus visual baseline screenshots
- Strict Content-Security-Policy on all responses: `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'`
- `package.json` declaring `@playwright/test` as the only (dev-only, non-runtime) dependency for browser tests

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
- Inline event handlers (`onclick`/`oninput`) removed from the admin page — the panel now runs under a strict CSP with no `unsafe-inline`/`unsafe-eval`, hardening against XSS from captured webhook content

## [0.3.0] — 2026-06-12

- Add AJAX auto-refresh and URI group filter

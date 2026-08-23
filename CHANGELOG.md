# Changelog

## [Unreleased]

### Added
- `.env` file support
- `AdminView` and `LogoutView` presentation classes
- `.env.example` with documented configuration options
- `.env` to `.gitignore`
- Query parameters rendered as inline colored clickable segments in the URI column, with per-key pastel colors, margin spacing, and JS filtering

### Changed
- Forwarding extracted behind a `ForwardingClient` port with a `StreamForwardingClient` adapter — the webhook controller now consumes scripted forward verdicts instead of dialing upstream itself
- Repository `save()` upserts by capture ID; forward metadata is attached in a single atomic save instead of a delete → re-save dance
- Public entry point now served from `public/index.php` — run with `php -S localhost:8000 -t public`
- `config.php` reads from `$_ENV` with no fallback — missing vars produce specific error
- `ecs.php` and `phpstan.neon` paths updated to reference `public/index.php`
- Admin dashboard is now responsive — sidebar collapses to off-canvas drawer on tablet (≤900px), table hides Capture ID and IP columns and timestamp splits into two lines on mobile (≤600px)

### Fixed
- Admin dashboard row expansion now correctly shows request details
- Whitespace gap between URI group span and rest path span

## [0.3.0] — 2026-06-12

- Add AJAX auto-refresh and URI group filter

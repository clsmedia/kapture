# Kapture — PHP Webhook Receiver & Inspector

Catch, log, and inspect every HTTP request — self-hosted, zero dependencies, and yours forever.

[![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?logo=php)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/clsmedia/kapture?style=flat&logo=github)](https://github.com/clsmedia/kapture)

![Kapture Admin UI](/docs/kapture-dashboard-screenshot.png)

## Why?

Testing webhook integrations is painful. You guess what your service sent, hit F5 a hundred times, and pray the request format is right. **Kapture** gives you a dead-simple endpoint that logs everything — method, headers, body, query params, IP — and lets you inspect it in a clean UI.

But here's the thing about tools like webhook.site and RequestBin: every webhook you send to them **leaves your machine**. Your payloads live on someone else's server, they expire in hours, and if you need to check what Stripe sent you last week — it's gone.

Kapture is self-hosted, open-source, and **persists your logs as long as you need them**. One command and your data stays yours forever. No rate limits, no signup, no "try again tomorrow." Zero dependencies — drop it on any server (even shared hosting) and it just works.

## Quick Start

```bash
php -S 0.0.0.0:8080 -t public
```

Send a webhook:

```bash
curl -X POST -H "Content-Type: application/json" \
  -d '{"event":"user.created","email":"user@example.com"}' \
  http://localhost:8080/kapture/anything-you-like
```

Open the admin:

```bash
open http://localhost:8080/admin
```

Password: `changeme`

To switch to SQLite storage, set `STORAGE_DRIVER=sqlite` in `.env` (requires `ext-sqlite3`).

## Features

### You own your data
- **Self-hosted** — your webhooks never leave your machine. No third party sees your payloads.
- **Persistent logs** — stays across restarts. Check what Shopify sent you last week? Open the archive.
- **Configurable retention** — keep logs for 7 days or 7 months. Prune when you're ready.

### Drop-dead simple
- **Zero dependencies** — just PHP 8.3+. No Composer install, no database setup, no Docker required.
- **One command to run** — `php -S 0.0.0.0:8080 -t public` and you're capturing.
- **Works everywhere** — laptop, shared hosting, DigitalOcean box, Raspberry Pi.

### Inspect everything
- **Full capture** — headers, body, query params, IP, timestamp, any HTTP method
- **Dark theme admin UI** — expandable details, text filter, live auto-refresh
- **Archive browser** — pick any daily log from the sidebar. Browse tomorrow what came in today.
- **Raw dump** — `?raw` for JSONL access. Pipe into `jq`, grep, or your own tooling.

### Flexible storage
- **JSONL files** — one JSON object per line, standard format, readable by any tool
- **SQLite option** — set `STORAGE_DRIVER=sqlite` for a single database file
- **Daily rotation** — automatic, with configurable pruning

## What makes Kapture different

- **Self-hosted** — no third-party server sees your payloads
- **Persistent logs** — stays across restarts, browsable by day, configurable retention
- **Zero dependencies** — just PHP. No Composer, no Docker, no database setup
- **No rate limits, no signup** — run it, use it, done
- **Runs anywhere** — laptop, shared hosting, VPS, Raspberry Pi

## Usage

### Webhook endpoint

```
POST /kapture/your-custom-path
GET  /kapture/anything?foo=bar
PUT  /kapture/test
...
```

Returns `{"ok":true,"uid":"<unique-id>"}`. Keep the capture ID to find it in the logs.

### Admin panel

| URL | What |
|---|---|
| `/admin` | UI — browse, filter, expand requests |
| `/admin?raw` | Raw JSONL dump of today's file |
| `/admin?file=2026-05-23` | Browse a specific day's log |
| `/admin?file=2026-05-23&raw` | Raw dump of a specific day |

## Configuration

Copy `.env.example` → `.env` and edit — the app won't start without it:

```bash
ADMIN_PASSWORD=changeme    # Admin login password
LOG_DIR=./logs             # Where logs / database are stored
ROTATE_DAYS=7              # Days to keep logs (filesystem only)
STORAGE_DRIVER=filesystem  # 'filesystem' (default) or 'sqlite'
```

## Log Format

Each request is logged as a single JSON line (JSONL):

```json
{
  "capturedAt": "2026-05-23T14:30:00Z",
  "method": "POST",
  "uri": "/kapture/orders",
  "query": {"source": "shopify"},
  "headers": {
    "Content-Type": "application/json",
    "User-Agent": "Shopify-Captain-Hook/1.0"
  },
  "body": "{\"event\":\"order.created\"}",
  "ip": "203.0.113.42",
  "captureId": "a1b2c3d4e5f6g7h8"
}
```

## Requirements

- PHP 8.3+

## Development

```bash
php -S 0.0.0.0:8080 -t public
```

The built-in server handles routing. No Apache or Nginx needed.

### Testing

```bash
composer test
```

### Code quality

```bash
composer check    # phpstan + ecs + tests
composer ecs:fix  # auto-fix code style
```

- **PHPStan** — static analysis at level 8
- **ECS** — PSR-12 code style with Symfony rules
- **PHPUnit**

## FAQ

**Does it work with Stripe / Shopify / GitHub webhooks?**
Yes. Kapture accepts any HTTP method and captures the full request — headers, body, query params, and IP. Drop the endpoint URL into any webhook provider's dashboard.

**Can I run it in production?**
Kapture is designed for local development and testing. For production use, add HTTPS and a stronger password.

**Does it persist logs between restarts?**
Yes. Logs are written to `logs/` as JSONL files (or a single `kapture.db` SQLite file when using `STORAGE_DRIVER=sqlite`). The admin UI lets you browse any daily archive from the sidebar.

**How is this different from webhook.site?**
webhook.site is convenient for one-off testing, but your webhooks go through their servers, expire quickly, and you can't browse yesterday's data. Kapture is self-hosted, open-source, and keeps your logs as long as you configure it to. Your data never leaves your machine. It's not a temporary buffer — it's your webhook archive.

**What if I need to check a webhook from last week?**
Open `/admin?file=2026-05-20` and scroll. Kapture persists logs across restarts, organized by day, browsable from the sidebar. Set `ROTATE_DAYS=90` and you have a 3-month audit trail.

## Roadmap

- [ ] Docker image for one-command deploy
- [ ] Webhook forwarding / replay
- [ ] Configurable log retention per route
- [ ] CLI tail command for live log streaming

## Contributing

Contributions are welcome! Here's how to help:

- [Report a bug](https://github.com/clsmedia/kapture/issues)
- [Submit a pull request](https://github.com/clsmedia/kapture/pulls)
- Star the repo to show support ⭐

## License

MIT

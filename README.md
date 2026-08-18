# Kapture — PHP Webhook Receiver & Inspector

Catch, log, and inspect every HTTP request — self-hosted, zero dependencies, and yours forever.

[![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/clsmedia/kapture?style=flat&logo=github)](https://github.com/clsmedia/kapture)

![Kapture Admin UI](/docs/kapture-dashboard-screenshot.png)

## Why?

Testing webhook integrations is painful. You guess what your service sent, hit F5 a hundred times, and pray the request format is right. **Kapture** gives you a dead-simple endpoint that logs everything — method, headers, body, query params, IP — and lets you inspect it in a clean UI.

But here's the thing about tools like webhook.site and RequestBin: every webhook you send to them **leaves your machine**. Your payloads live on someone else's server, they expire in hours, and if you need to check what Stripe sent you last week — it's gone.

Kapture is self-hosted, open-source, and **persists your logs as long as you need them**. One command and your data stays yours forever. No rate limits, no signup, no "try again tomorrow." Zero dependencies — drop it on any server (even shared hosting) and it just works.

**And now it also forwards.** Set `FORWARD_URL` and Kapture becomes a transparent proxy: capture every request your backend receives, inspect it in the dashboard, and forward it unchanged — all in a single pass. Debug webhooks without taking your integration offline.

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
- **Zero dependencies** — just PHP 8.4+. No Composer install, no database setup, no Docker required.
- **One command to run** — `php -S 0.0.0.0:8080 -t public` and you're capturing.
- **Works everywhere** — laptop, shared hosting, DigitalOcean box, Raspberry Pi.

### Inspect everything
- **Full capture** — headers, body, query params, IP, timestamp, any HTTP method
- **Dark theme admin UI** — expandable details, text filter, live auto-refresh
- **Clickable URL parts** — click any path segment or `?param=value` to instantly filter the table by that value. Each query param key gets its own color so you can spot patterns at a glance.
- **Archive browser** — pick any daily log from the sidebar. Browse tomorrow what came in today.
- **Raw dump** — `?raw` for JSONL access. Pipe into `jq`, grep, or your own tooling.

### Forwarding Proxy Mode
Set `FORWARD_URL` and Kapture stops being just a sink — it becomes a **transparent proxy** sitting between your webhook provider and your real backend.

**How it works:** Stripe sends a webhook → Kapture captures it, then instantly forwards it to your actual endpoint. You see the full request in the dashboard, your backend receives it unchanged. No integration code. No duplicate endpoints. One config variable.

```
Stripe/GitHub/Shopify ──▶ Kapture ──▶ Your real server
                   capture + log          unchanged
```

**Why this matters:**
- **Zero downtime debugging** — your backend keeps running while you inspect every request that hits it
- **See what actually arrived** — not what you think arrived. Catch malformed payloads, missing headers, wrong content types before they break your code
- **Color-coded status badges** — forwarded requests show their response status on the dashboard. Green for 200, orange for 429, red for 500. Spot failures at a glance
- **No tunnel required** — runs on your existing server. No ngrok, no cloudflare, no third-party relay

To enable, add one line to your `.env`:

```
FORWARD_URL=https://your-real-server.com/webhook
```

Kapture captures the request, forwards it to your server, logs the response status, and stores the result — all in a single pass. Your server never knows Kapture was there.

### Flexible storage
- **JSONL files** — one JSON object per line, standard format, readable by any tool
- **SQLite option** — set `STORAGE_DRIVER=sqlite` for a single database file
- **Daily rotation** — automatic, with configurable pruning

## What makes Kapture different

- **Self-hosted** — no third-party server sees your payloads
- **Persistent logs** — stays across restarts, browsable by day, configurable retention
- **Forwarding proxy** — capture AND forward in one pass. Set `FORWARD_URL`, inspect every request your backend receives
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

Returns `{"ok":true,"captureId":"<unique-id>"}`. Keep the capture ID to find it in the logs.

### Forwarding proxy

Set `FORWARD_URL` in `.env` and every captured request is also forwarded to your real backend. The forwarded request's response status code is logged alongside the captured data, color-coded in the dashboard:

- **Green** — 2xx success
- **Orange** — 4xx client error
- **Red** — 5xx server error

Your backend receives the original request unchanged. Kapture sits silently in between, capturing and forwarding in one pass.

### Admin panel

| URL | What |
|---|---|
| `/admin` | UI — browse, filter, expand requests |
| `/admin?raw` | Raw JSONL dump of today's file |
| `/admin?file=2026-05-23` | Browse a specific day's log |
| `/admin?file=2026-05-23&raw` | Raw dump of a specific day |

**Pro tip:** in the table, click any URI segment (`/stripe`, `/webhook`) or query parameter (`?event=created`, `?source=shopify`) to filter all matching entries. Click again to clear. Each query param key has a distinct pastel color — spot patterns at a glance.

## Integration Testing

Kapture doubles as a **self-hosted HTTP test harness**. Run it on your dev machine or CI, point your application's HTTP calls at it, and let your integration tests assert exactly what was sent — without your payloads ever leaving your machine.

> The Test API is **opt-in**. Set `API_AUTH_REQUIRED=true` in `.env` to expose it. Listing and searching captures requires `Authorization: Bearer <API_TOKEN>`; fetching a single capture by ID works without a token — the capture ID itself is the credential (whoever knows the ID can read that capture).

### The flow

```
1. test generates correlationId          (e.g. "01K2...")
2. test passes correlationId to the app  (X-Kapture-Correlation-Id header)
3. app sends HTTP request to Kapture     (POST /kapture/watering)
4. Kapture stores the request            (with its correlationId)
5. test polls the Test API               (GET /api/v1/captures?correlationId=...)
6. test receives the captures
7. test asserts method / uri / headers / query / body
```

### Step by step

1. The test generates a correlation ID that identifies one test scenario:

```bash
CORRELATION_ID=$(php -r 'echo strtolower(bin2hex(random_bytes(8)));')
```

2. The test passes it to the application under test. Any app in any language can do this — just send the header:

```bash
curl -X POST -H "X-Kapture-Correlation-Id: $CORRELATION_ID" \
  -H "Content-Type: application/json" \
  -d '{"zone":1,"amount":42}' \
  http://localhost:8080/kapture/watering
```

3. The app makes its HTTP call (through a queue, a worker, whatever) and Kapture captures it.

4. The test polls until the expected number of requests appear (handle timeout + polling interval yourself):

```bash
curl -H "Authorization: Bearer $API_TOKEN" \
  "http://localhost:8080/api/v1/captures?correlationId=$CORRELATION_ID"
```

```json
{
  "captures": [
    {
      "capturedAt": "2026-08-14T20:35:45Z",
      "method": "POST",
      "uri": "/watering",
      "query": [],
      "headers": {"Content-Type": "application/json"},
      "body": "{\"zone\":1,\"amount\":42}",
      "ip": "127.0.0.1",
      "captureId": "6a937019c989ec6a",
      "correlationId": "01K2..."
    }
  ],
  "total": 1
}
```

`total` is the full match count ignoring `limit`, so a polling test can tell when *all* expected requests have arrived, even when the response is truncated by `limit`.

5. Assert against the response: method, URI, headers, query params, body, IP — everything.

> Machine-readable contract: [`public/openapi.json`](public/openapi.json) describes the Test API (paths, parameters, schemas, error codes) and is generated from the code via `composer docs`. Browse it interactively at `/api-docs.html` (Swagger UI) when the server is running. Ready-made `.http` requests live in [`http/`](http/README.md).

### Test API endpoints

| Endpoint | Description |
|---|---|
| `GET /api/v1/captures/{captureId}` | One capture by ID — no Bearer token needed (the ID is the credential). `200` with the capture, `404` when unknown |
| `GET /api/v1/captures` | List captures as `{"captures": [...], "total": N}`, filtered by query params — requires `Bearer <API_TOKEN>` |

`GET /api/v1/captures` filters:

| Param | Meaning |
|---|---|
| `captureId` | Exact capture ID |
| `correlationId` | All captures belonging to one test scenario |
| `method` | HTTP method, e.g. `POST` |
| `uri` | Substring match on the captured URI (path + query) |
| `capturedAfter` | ISO8601 — captures strictly after this time |
| `capturedBefore` | ISO8601 — captures strictly before this time |
| `limit` | Max results (default `100`; `0` means unlimited) |
| `order` | `asc` (default, oldest first) or `desc` |

Errors return `{"error": "...", "code": "..."}` — `code` is a stable machine-readable value (`capture_not_found`, `unauthorized`, `invalid_limit`, …) so tests can branch on failure type without string matching. API responses also send `Cache-Control: no-store` so shared proxies never cache captured payloads.

### How `captureId`, `correlationId` and `API_TOKEN` differ

| Concept | Purpose |
|---|---|
| `captureId` | Identifies **one** captured HTTP request. Generated by Kapture, returned by the webhook endpoint. High-entropy, so it also acts as the credential for reading that single capture. |
| `correlationId` | Groups **many** requests belonging to one test scenario. Chosen by the test, sent as `X-Kapture-Correlation-Id`. Multiple tests can run against one Kapture instance in parallel. |
| `API_TOKEN` | Authorizes **listing and searching** captures (`GET /api/v1/captures`). Not needed to fetch a capture you already have the ID for. Never used as a resource identifier. |

The webhook receiver itself is unchanged: `POST /kapture/anything` still returns `{"ok":true,"captureId":"..."}` and stores the full request, now with an optional `correlationId` field.

### Parallel tests

Tests with different correlation IDs are logically isolated:

```bash
# Test A → correlationId=A, Test B → correlationId=B
curl -H "Authorization: Bearer $API_TOKEN" \
  "http://localhost:8080/api/v1/captures?correlationId=A"   # only A's requests
```

### Forwarding proxy + Test API

With `FORWARD_URL` set, Kapture stays a transparent proxy: it forwards the original request unchanged **and** keeps the `correlationId` on the stored capture, so your tests can still find it. The forwarded status is stored as `forwardStatusCode` and returned by the Test API.

> Ready-made `.http` requests for every endpoint above live in [`http/`](http/README.md) — run them from PhpStorm, IntelliJ, or the VS Code REST Client.

## Configuration

Copy `.env.example` → `.env` and edit — the app won't start without it:

```bash
ADMIN_PASSWORD=changeme        # Admin login password
LOG_DIR=./logs                 # Where logs / database are stored
ROTATE_DAYS=7                  # Days to keep logs (filesystem only)
STORAGE_DRIVER=filesystem      # 'filesystem' (default) or 'sqlite'
FORWARD_URL=                   # Optional: forward captured requests to this URL
API_TOKEN=                     # Optional: Bearer token for the Test API
API_AUTH_REQUIRED=false        # Optional: set true to expose /api/v1/* (requires API_TOKEN)
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
  "captureId": "a1b2c3d4e5f6g7h8",
  "forwardUrl": "https://your-server.com/webhook",
  "forwardStatusCode": 200,
  "correlationId": "01K2ABC..."
}
```

`correlationId` is only present when the request carried an `X-Kapture-Correlation-Id` header.

## Requirements

- PHP 8.4+

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

**What is the forwarding proxy mode?**
Set `FORWARD_URL` in your `.env` and Kapture becomes a transparent proxy: it captures every incoming request, then immediately forwards it to your real backend. Your server receives the request unchanged — Kapture just silently logs the response status code and stores it alongside the captured data. Think of it as a webhook inspection layer that sits between Stripe and your app, letting you see everything without interrupting your integration.

## Roadmap

- [x] Webhook forwarding / proxy mode
- [ ] Docker image for one-command deploy
- [ ] Webhook replay — resend captured requests on demand
- [ ] Configurable log retention per route
- [ ] CLI tail command for live log streaming

## Contributing

Contributions are welcome! Here's how to help:

- [Report a bug](https://github.com/clsmedia/kapture/issues)
- [Submit a pull request](https://github.com/clsmedia/kapture/pulls)
- Star the repo to show support ⭐

## License

MIT

# Kapture — PHP Webhook Receiver & Inspector

A minimalist webhook receiver and inspector. Catch, log, and inspect every HTTP request sent your way.

[![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?logo=php)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

## Why?

Testing webhook integrations is painful. You guess what your service sent, hit F5 a hundred times, and pray the request format is right. **Kapture** gives you a dead-simple endpoint that logs everything — method, headers, body, query params, IP — and lets you inspect it in a clean UI.

Think webhook.site or RequestBin, but self-hosted, simpler, and always free.

I built Kapture because existing tools are either closed-source, require signup, or have rate limits that get in your way. Alternatives like webhook.site and RequestBin are convenient but you don't control them — and self-hosted options are often over-engineered and hard to deploy. Kapture runs locally, needs no account, and never tells you "try again tomorrow." It's a single PHP file with zero dependencies — drop it on any server (even shared hosting) and it just works.

## Quick Start

```bash
php -S 0.0.0.0:8080 index.php
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

## Screenshots

![Kapture Admin UI](/docs/kapture-dashboard-screenshot.png)

## Features

- **Any HTTP method** — GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS
- **Full capture** — headers, body, query params, IP, timestamp
- **JSONL logs** — one JSON object per line, standard format
- **Log rotation** — daily files, auto-pruned after 7 days
- **Admin UI** — dark theme sidebar, expandable details, text filter, live auto-refresh
- **Archive browsing** — pick any daily log file from the sidebar
- **Password protected** — Basic Auth on `/admin/`
- **Zero runtime dependencies** — just PHP 8.3+

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

Edit `config.php`:

```php
return [
    'admin_password' => 'changeme',    // Admin login password
    'log_dir' => __DIR__ . '/logs',     // Where logs are stored
    'rotate_days' => 7,                 // Days to keep logs
];
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
php -S 0.0.0.0:8080 index.php
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
Yes. Logs are written to `logs/` as JSONL files. The admin UI lets you browse any daily file from the sidebar.

**How is this different from webhook.site?**
Kapture is open-source, zero-dependency PHP. No signup, no rate limits, no data leaving your machine. You own every byte.

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

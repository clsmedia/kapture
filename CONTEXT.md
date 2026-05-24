# Kapture — Domain Glossary

## Kapture
A self-hosted webhook receiver and inspector. Catches HTTP requests, logs them as JSONL, and provides an admin UI for browsing.

## CapturedRequest
The canonical domain object representing a single captured HTTP request. Contains: method, uri, query parameters, headers, body, client IP, capture timestamp (CapturedAt), and a unique capture ID. Created via `CapturedRequest::capture()` and serialized to JSONL.

## CapturedAt
A value object wrapping a `DateTimeImmutable` in UTC. Always serializes to ISO8601 UTC format (`2026-05-23T14:30:00Z`). Used by CapturedRequest for the capture timestamp.

## HttpMethod
An enum of the HTTP methods Kapture handles: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS. Used as the canonical type for `CapturedRequest::$method` — incoming string values are validated and converted at the domain boundary via `HttpMethod::tryFromMethod()`.

## Capture
The act of receiving an HTTP request at the `/kapture/` webhook endpoint and persisting it as a CapturedRequest to the JSONL log.

## Webhook Endpoint
The canonical capture URL path is `/kapture/<anything-you-like>`. The path `/capture/` also works but is treated as a misspelling alias — both map to the same handler and both get stripped from the logged URI by the URI normalizer.

## Log Retention Pruning
Old log files are pruned when a new request is saved. A marker file (`.prune-timestamp` in the log directory) prevents pruning from running more than once per hour, so high-frequency webhook bursts don't trigger repeated glob scans.

## URI Normalizer
Strips the `/kapture/` or `/capture/` routing prefix from the incoming URI before logging, so the stored `uri` field shows only the caller's intended endpoint path. For example, `POST /kapture/orders` is logged as `uri: "/orders"`. Both the normalizer and the Router dispatch are case-insensitive — `/KAPTURE/orders`, `/Capture/test`, etc. all work.

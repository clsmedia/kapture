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

## CorrelationId
An optional grouping identifier on a CapturedRequest, taken from the `X-Kapture-Correlation-Id` header. Groups requests belonging to one test scenario so a Test API client can fetch them all via `GET /api/v1/captures?correlationId=...`. Distinct from `captureId` (identifies a single request) and from the Test API Bearer token (authorizes API access). Never replaces captureId; stored as its own nullable field.

## Test API
Opt-in, versioned read API under `/api/v1/*`. Enabled only when `API_AUTH_REQUIRED=true` in `.env`. Listing and searching (`GET /api/v1/captures`) requires `Authorization: Bearer <API_TOKEN>`; fetching a single capture by ID (`GET /api/v1/captures/{captureId}`) works without a token — the high-entropy capture ID acts as the credential for that read. `GET /api/v1/captures/{captureId}` returns one capture; `GET /api/v1/captures` lists captures (ascending by default, deterministic by receipt time) filtered by captureId, correlationId, method, uri substring, capturedAfter, capturedBefore, and limit, returning `{"captures": [...], "total": N}` where total ignores the limit. Reads go through the same CapturedRequestRepository abstraction as the admin UI.

## CapturedRequestCriteria
Value object describing a capture query: captureId, correlationId, HttpMethod, uri substring, CapturedAt after/before bounds, and an optional limit. Passed to `CapturedRequestRepository::findByCriteria()`, which both the Filesystem and SQLite repositories implement.

# Kapture HTTP Catalog

Ready-made `.http` requests for the **Test API** and the **webhook receiver**.
Runs in JetBrains IDEs (PhpStorm / IntelliJ IDEA) and the VS Code "REST Client"
extension.

## Prerequisites

1. Copy `.env.example` → `.env` and set:

   ```bash
   API_TOKEN=test-token          # must match {{apiToken}} below
   API_AUTH_REQUIRED=true        # exposes /api/v1/* (the Test API is opt-in)
   ```

2. Start Kapture:

   ```bash
   php -S 0.0.0.0:8080 -t public
   ```

3. Select the environment:

   - **JetBrains**: open `http/http-client.env.json`, pick the `dev` environment
     (top-right dropdown). Override `apiToken` to match your `.env`.
   - **VS Code REST Client**: define the variables (`baseUrl`, `apiToken`,
     `correlationId`) in your workspace settings or a `.env` file, or replace
     `{{baseUrl}}` etc. with concrete values.

## Variables

| Variable        | Meaning                                            |
|-----------------|----------------------------------------------------|
| `baseUrl`       | Kapture origin (default `http://localhost:8080`)   |
| `apiToken`      | `API_TOKEN` from `.env`                            |
| `correlationId` | Groups requests of one test scenario               |
| `captureId`     | Identifies a single captured request (fill in)     |

## Files

| File                 | Requests                                              |
|----------------------|-------------------------------------------------------|
| `webhook.http`       | Capture requests into the webhook receiver            |
| `api-captures.http`  | `GET /api/v1/captures` + all filters                  |
| `api-capture.http`   | `GET /api/v1/captures/{captureId}`                    |
| `api-errors.http`    | 401 / 400 / 404 / 405 responses                       |
| `forwarding.http`    | Capture + forward flow (needs `FORWARD_URL` set)      |

## Typical flow

1. Send a couple of requests from `webhook.http` — they all carry
   `X-Kapture-Correlation-Id: {{correlationId}}`.
2. In `api-captures.http`, run *List captures for one correlation* — you get
   every request of that scenario, oldest first.
3. Grab a `captureId` from the response, paste it into `api-capture.http`
   and fetch the single capture.

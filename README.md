# Payment webhook ingestion

Receives payment webhooks on `POST /api/v1/webhooks/payment`, stores each event once and notifies the sales team's API asynchronously, with retries and a dead-letter queue.

## Run

```bash
docker compose up -d --build --wait
```

Services: `nginx` (port `8080`, `HTTP_PORT` to change), `app` (php-fpm), `worker` (`messenger:consume async`), `db` (PostgreSQL 16), `migrate` (runs migrations, then exits), `notifier` (mock notifier API).

| Variable | Default | Meaning |
|---|---|---|
| `WEBHOOK_SECRET` | `change_me` | HMAC secret shared with the payment provider |
| `MOCK_FAILURE_RATE` | `0.3` | Share of notifier requests that fail (`0`–`1`) |
| `MOCK_FAILURE_STATUS` | `503` | HTTP status the mock notifier fails with |
| `APP_SECRET` | `change_me_in_production` | Symfony secret |

## Send a webhook

```bash
F=tests/Fixtures/charge-successful.json
curl -i -X POST http://localhost:8080/api/v1/webhooks/payment \
  -H 'Content-Type: application/json' \
  -H "X-Webhook-Signature: $(bin/sign-webhook $F change_me)" \
  --data-binary @$F
```

`202 {"event_id":"..."}` for new and duplicate events. Errors are `application/problem+json`: `401` bad or missing signature, `400` malformed JSON, `415` not JSON, `422` invalid fields (`violations[].propertyPath`).

The charge above waits until its intent arrives: send `tests/Fixtures/payment-intent-created.json` the same way, then watch `docker compose logs -f notifier worker`.

## Architecture

- **Signature:** `X-Webhook-Signature` = hex HMAC-SHA256 of the raw body, compared with `hash_equals`, checked before the body is parsed. The header name and scheme are assumed until the provider's spec is known.
- **Duplicates and races:** `event_id` is the primary key. Ingestion runs `INSERT ... ON CONFLICT DO NOTHING` and queues a message only when a row was inserted, in the same transaction as the Doctrine queue (same connection). A concurrent duplicate loses the insert and gets the same `202`.
- **Out-of-order:** a `charge.*` event whose transaction has no stored `payment_intent.created` is `parked`. When the intent is notified, its parked charges are released and queued again. Other event types never wait. Assumes a single worker; several workers would need a per-transaction lock.
- **Backoff and DLQ:** a notifier `5xx`, `408`, `429` or timeout (5 s) is retried 5 times after 1, 2, 4, 8, 16 s (±10% jitter). Other `4xx` answers are not retried. After that the message goes to the `failed` transport:
  ```bash
  docker compose exec worker php bin/console messenger:failed:show
  docker compose exec worker php bin/console messenger:failed:retry
  ```
  An event already `notified` is skipped, so a retry never sends a second notification.
- **Poison pills:** invalid payloads are rejected at the HTTP layer (`400`/`415`/`422`), logged as warnings without the raw body, and never stored or queued.
- **Personal data:** customer e-mail and name are stored with the event but not sent to the notifier.

## Check every example

```bash
bin/check-stack   # needs the stack running, jq and openssl; about a minute
```

Runs each rule from the spec (R1–R7) with curl and psql, including retries, the DLQ and `messenger:failed:retry`. It recreates the `notifier` container to force failures.

## Development

DDEV: `make qa` runs php-cs-fixer, PHPStan and PHPUnit.

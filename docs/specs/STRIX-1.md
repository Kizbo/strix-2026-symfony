# STRIX-1 — Payment webhook ingestion & notification pipeline

## Goal
Odbierać webhooki płatności na `POST /api/v1/webhooks/payment`, odporne na duplikaty i zdarzenia w złej kolejności, i asynchronicznie wysyłać powiadomienie do zespołu sprzedaży przez zewnętrzne API (mock), z exponential backoff i DLQ. Całość startuje przez `docker compose up`.

## Contract samples (verbatim, from the client or the other system)
- `docs/specs/STRIX-1-charge-successful.json` → copied unchanged to `tests/Fixtures/charge-successful.json`; used by R1
- INVENTED (no real sample yet):
  - `docs/specs/STRIX-1-payment-intent-created.INVENTED.json` — same envelope as the charge, `event: payment_intent.created`, `status: PENDING`. Risk: real intent event may have another shape or link to the charge by another field than `data.transaction.id`.
  - Signature header format (R3). Risk: provider uses another header/algorithm; only the verifier changes.
  - Notification payload sent to the notifier (R7). Risk: real channel needs another format; only the notifier client changes.

## Rules with examples

### R1 — The real sample is accepted
- Example: body = `STRIX-1-charge-successful.json` unchanged, valid signature → `202`, `{"event_id":"evt_9f8d7c6b5a4321"}`, one row in `payment_event`, one message queued.

### R2 — Duplicates are idempotent (unique `event_id` in DB)
- Example: R1 request sent twice, one after another → both `202` with the same body; one row; one notification.
- Edge: R1 request sent twice concurrently (same millisecond) → both `202`; the losing INSERT hits the unique index and is treated as a duplicate; one row; one notification.

### R3 — Signature required (HMAC-SHA256 over raw body, INVENTED header name)
- Header `X-Webhook-Signature: <hex(hmac_sha256(WEBHOOK_SECRET, raw body))>`, compared with `hash_equals`. Checked before payload validation.
- Example: valid header → request proceeds (R1).
- Edge: header missing → `401` problem+json; signature of another body or wrong secret → `401`. Body not processed, nothing stored.
- Replay of a captured request is harmless: same `event_id` → R2.

### R4 — Malformed input rejected as `application/problem+json`, nothing stored
- Example: `Content-Type: text/plain` → `415`.
- Example: body `{"event":` → `400`, `title: "Malformed JSON"`.
- Example: sample without `event_id` → `422`, `violations: [{"propertyPath":"event_id", ...}]`.
- Edge: `amount.value: "299.90"` → `422` on `data.transaction.amount.value` (integer minor units only); `currency: "zł"` → `422` (ISO 4217, 3 uppercase letters).
- Required: `event`, `event_id`, `timestamp`, `livemode`, `data.transaction.id`, `data.transaction.amount.value` (int ≥ 0), `data.transaction.amount.currency`. Other fields optional, stored as received.
- `detail` never contains exception text. Each rejection logged (warning) with reason, route, `event_id` if parseable; raw body not logged.

### R5 — Out-of-order: `charge.*` waits for its intent
- Intent = `payment_intent.created` with the same `data.transaction.id`.
- Example: `charge.successful` for `txn_8a7b6c5d4e3f` arrives, no intent stored → `202`; worker marks it `parked`; no notification.
  Then `payment_intent.created` for `txn_8a7b6c5d4e3f` arrives → worker notifies for the intent, then releases the parked charge → charge notified.
- Edge: intent first, then charge → charge notified right away.
- Edge: event type without `charge.` prefix (e.g. `refund.created`) → never waits.

### R6 — Every valid event notifies (answer 5b)
- Example: R1 sample with `livemode: false` → notified (payload carries `livemode: false`).
- Example: `event: "charge.refunded"` with its intent present → notified.

### R7 — Notifier failures: retry with exponential backoff, then DLQ
- Notifier: `POST ${NOTIFIER_URL}/notifications`, JSON `{event_id, event, livemode, transaction_id, order_id, amount:{value,currency}, customer_company, received_at}` (INVENTED). Email/name not sent.
- Example: notifier answers `503`, `503`, `200` → delivered on the 3rd attempt; event `notified`.
- Edge: notifier always `503`/timeout (5 s) → 1 + 5 retries (delays 1, 2, 4, 8, 16 s ± jitter), then message in `failed` transport (DLQ), listed by `messenger:failed:show`, retriable by `messenger:failed:retry`.
- Edge: notifier answers `400` → no retry, straight to `failed`.
- Edge: retry after a partial success never sends a second notification (event already `notified` → skip).

## Endpoints
| Method + path | Request (example) | Success | Errors |
|---|---|---|---|
| `POST /api/v1/webhooks/payment` | `STRIX-1-charge-successful.json` + `X-Webhook-Signature` | `202 {"event_id":"..."}` (also for duplicates) | `401`, `400`, `415`, `422` — all `application/problem+json` |

## Answers from the client
- Podpis / Duplikat / Out-of-order / Poison pill / Które zdarzenia → "1. a 2. a 3. a 4. a - zwrot w formacie json+problem 5. b"
  - 1a: HMAC-SHA256 over timestamp + raw body, header with time tolerance (INVENTED until provider spec).
  - Correction after spec review: "hmac z surowego body" → HMAC over the raw body only, no timestamp.
  - 2a: duplicate gets the same 2xx as the first delivery.
  - 3a: intent from a separate webhook; charge accepted with 202, parked, processed when intent arrives.
  - 4a: 400/422 as problem+json + structured log, raw body not stored.
  - 5b: every valid event notifies, regardless of type and `livemode`.

## Assumptions (shown to the client, accepted or corrected)
- Async via Symfony Messenger, Doctrine transport in the same DB, no broker — if wrong: RabbitMQ transport swap (config only).
- One worker process — if wrong: R5 park/release needs `pg_advisory_xact_lock` per transaction id (multiple workers could race).
- Database: PostgreSQL 16 in DDEV and docker-compose (`ON CONFLICT DO NOTHING` for R2, `LISTEN/NOTIFY` for the Messenger transport) — if wrong: swap image + DSN.
- Mock notifier: separate container, fails with `503` at rate `MOCK_FAILURE_RATE` (default 0.3), logs received notifications to stdout.
- Low volume, events kept forever, email/name masked in logs and not sent to the notifier.
- No lookup of `order_id` / `customer.id` in other systems.

## Out of scope
- Message broker, 5 notification channels, AI reports (explained in `EMAIL_TO_CLIENT.md`).
- Admin UI for DLQ (Messenger CLI only), data retention job, multiple workers.

## Steps (each ends with green `make qa` and a commit)
1. Ingestion core: `PaymentEvent` entity + migration (unique `event_id`), `WebhookIngestor` service storing an event idempotently and dispatching `ProcessPaymentEvent`; signature verifier — tests: `tests/Unit/WebhookSignatureVerifierTest.php`, `tests/Application/WebhookIngestorTest.php` (R2 incl. duplicate INSERT race)
2. HTTP contract: endpoint, input DTO with validation, signature listener, problem+json errors — tests: `tests/Application/PaymentWebhookTest.php` (R1, R2, R3, R4)
3. Worker: `ProcessPaymentEventHandler` (park/release, notify, skip if notified), notifier client, retry + `failed` transport config — tests: `tests/Application/ProcessPaymentEventHandlerTest.php` with `MockHttpClient` (R5, R6, R7)
4. Delivery: first fix the real queue — Doctrine PostgreSQL transport without NOTIFY trigger polls only every `check_delayed_interval` (60 s), breaking R7 backoff: set `use_notify=false` + short polling (or add the NOTIFY trigger); release of parked charges (status change + dispatch) in one transaction; test env `in-memory://?serialize=true` — tests: `tests/Application/DoctrineQueueTest.php` on the real Doctrine transport (rolled-back ingest leaves no message; message survives serialize → get). Then `Dockerfile`, `docker-compose.yml` (nginx, php-fpm, worker, PostgreSQL 16, mock notifier, migrations on start), `bin/sign-webhook` helper, `README.md` (run, curl, architecture: race, out-of-order, backoff/DLQ, poison pills) — tests: `docker compose up` from clean clone reaches healthy
5. Check: run every example above against the running app (curl), incl. mock failures and `messenger:failed:show`

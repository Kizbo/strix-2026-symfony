# STRIX-1 — answers from clarify

- Signature → 1a: HMAC-SHA256 over timestamp + raw body, header with time tolerance. INVENTED — replace with provider spec before integration.
- Duplicate `event_id` → 2a: same 2xx as the first delivery.
- Out-of-order → 3a: intent comes from a separate webhook (e.g. `payment_intent.created`); `charge` accepted with 202, parked, processed when the intent arrives. Intent payload: INVENTED (no sample).
- Malformed payload → 4a: 400/422 as `application/problem+json` + structured log, raw body not stored.
- Notifications → 5b: every valid event, regardless of type and `livemode`.
- All listed assumptions accepted.

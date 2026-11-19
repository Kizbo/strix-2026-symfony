# Gap checklist (lite)

Classify each line for the task: stated in the brief (quote) / ask / assume. Ask at most 5 — the ones whose wrong guess changes behaviour visible from outside. Everything else becomes an explicit assumption the client sees in one list.

- Input format and an example — if another system defines it: a real sample or spec, never a confirmed guess
- Duplicates and retries
- Ordering / concurrent updates
- Invalid input: reject all or accept the valid part
- Errors: status codes and body
- Security: who may call it, signatures, permissions
- Response shape: fields, sorting, empty / not found
- Unknown referenced things (ids, SKUs, customers)
- Volume and speed
- Storage and how long data is kept

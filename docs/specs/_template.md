# <ID> — <title>

## Goal
<one or two sentences in the client's words>

## Contract samples (verbatim, from the client or the other system)
- `docs/specs/<ID>-<name>.json` → copied unchanged to `tests/Fixtures/<name>.json`; used by R1
- INVENTED (no real sample yet): <none | file> — risk until replaced

## Rules with examples
### R1 — <rule>
- Example: <concrete input> → <concrete result>
- Edge: <concrete input> → <concrete result>

## Endpoints
| Method + path | Request (example) | Success | Errors |
|---|---|---|---|

## Answers from the client
- <question> → <answer, verbatim>

## Assumptions (shown to the client, accepted or corrected)
- <assumption> — <what changes if wrong>

## Out of scope
-

## Steps (each ends with green `make qa` and a commit)
1. <step> — tests: `<test file>`
n. Check: run every example above against the running app (curl)

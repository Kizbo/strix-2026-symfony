# Project — Symfony on DDEV

## Commands (everything through DDEV)
- `make qa` — php-cs-fixer, PHPStan (level 8), PHPUnit. Must pass before a step counts as done.
- Single test: `ddev exec bin/phpunit --filter <Name>`
- New Composer packages only after asking, and only ones that exist on Packagist.

## Structure
- Thin controllers: input DTO → service → output DTO. Input DTOs mapped with `#[MapRequestPayload]` / `#[MapQueryString]` and validator attributes; they hold only fields a client may set.
- Never serialize entities directly. Business logic in `src/Service/`, without HTTP.
- Tests: `tests/Unit/` (logic), `tests/Application/` (HTTP contract, `WebTestCase`).

## API conventions
- 400 malformed, 415 wrong format, 422 validation, 404, 409, 401/403, 201 + `Location` on create.
- Errors as RFC 9457 `application/problem+json`; never internal exception text in `detail`.
- Money as integer minor units; queries with bound parameters; sort columns from an allow-list.

## Working rules
- Simple over clever: no layers, interfaces or abstractions the task doesn't need.
- Tests before code; locked tests are never edited to make code pass.
- Small commits: `<ID> - <imperative summary>`; no AI attribution; never push.

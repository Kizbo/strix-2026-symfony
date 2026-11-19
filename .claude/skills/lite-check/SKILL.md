---
name: lite:check
description: Finish a task — run every spec example against the running app, one blocker-only review, fix only real findings, write the README section and show time and cost. Budget 15 minutes.
argument-hint: <ID>
disable-model-invocation: true
---

Budget: 15 minutes.

1. **Contract first:** `cmp docs/specs/<ID>-<name>.<ext> tests/Fixtures/<name>.<ext>` for every sample (must be identical), and send each sample unchanged with curl to the running app — it must succeed. A mismatch or failure is fixed before anything else.
2. **Examples:** run every example from `docs/specs/<ID>.md` with curl against the running app; table `example → expected → actual → OK/FAIL`. A FAIL is fixed test-first (new failing test, lock, fix, `make qa`, commit).
3. **One review:** the `reviewer` agent once. Fix only `[requirement]`, `[correctness]` and `[security]` findings, each with a test first. Notes are listed for the user and not acted on. No second review round.
4. **README** (append a section): how to run it (DDEV, env vars), endpoints with one curl each, key decisions and assumptions, what's not done, how it would grow (one line each: scale, failures, monitoring), a rough estimate for the full version.
5. **Summary:** `git log --oneline` for this task, `bin/lite-time`, and remind the user to open `/usage` for tokens and cost.
6. `bin/lite-lock clear`.

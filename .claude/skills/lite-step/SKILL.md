---
name: lite:step
description: One spec step test-first in this session — failing tests, lock them, minimal code, make qa, commit. Use for each step in order.
argument-hint: <ID> <step number>
disable-model-invocation: true
---

1. Write the tests for this step from the spec's examples (logic → `tests/Unit/`, HTTP → `tests/Application/`). No mocks of our own code; mock only outside systems. No `assertTrue(true)`, no skipped tests.
   Step 1 starts with the contract sample: copy it unchanged (`cp docs/specs/<ID>-<name>.json tests/Fixtures/<name>.json`, never retype or reformat it) and write an application test that sends that file as-is and expects success.
2. Run them and show they fail for the expected reason. Then `bin/lite-lock add <test files> <fixture files>` — from now on those files can't be edited.
3. Minimal code to make them pass. If a test turns out wrong, stop and say why — don't edit it.
4. `make qa` until green; show the summary line.
5. Commit tests and code: `<ID> - <imperative summary>`.
6. One line: what was done, `bin/lite-time` result, `Next: /lite:step <ID> <n+1>` or `/lite:check <ID>` after the last step.

If a step takes more than 15 minutes, stop and propose cutting or splitting it.

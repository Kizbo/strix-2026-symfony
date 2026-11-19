---
name: lite:spec
description: Write the short spec from the brief and the answers — rules with concrete examples, endpoints, answers verbatim, assumptions, out of scope, 3–6 steps. Budget 5 minutes.
argument-hint: <ID>
disable-model-invocation: true
---

Budget: 5 minutes.

1. Write `docs/specs/<ID>.md` from `docs/specs/_template.md`: every rule has at least one concrete example (and an edge example where the answers name one); client answers verbatim; assumptions as accepted; out of scope.
2. **Contract samples** saved in clarify are listed in the spec, and **R1** is "the real sample is accepted": its example is the sample file itself, unchanged.
3. **3–6 steps**, each small enough for one test-first cycle and a commit; logic first, HTTP contract next; the last step is the curl check of every example. No milestones, no sub-plans.
4. Show the spec in under 25 lines of summary and ask for OK. Then `Next: /lite:step <ID> 1`.

Simplest structure that meets the rules: no queues, interfaces, events or extra layers unless an answer requires them — mention them as "how this would grow" in one line instead.

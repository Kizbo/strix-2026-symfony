---
name: lite:clarify
description: Start of a task from a short brief — paraphrase, classify the gap checklist, ask at most 5 questions in one round, list every other point as an explicit assumption. Budget 10 minutes.
argument-hint: <ID> <brief text or file>
disable-model-invocation: true
---

Budget: 10 minutes. Starts the clock (`bin/lite-time` shows it).

1. Paraphrase the goal in 2 sentences and ask for one concrete example if the brief has none.
2. Go through `docs/gaps.md`: for each line decide stated (quote the brief) / ask / assume. Don't rely on noticing gaps — go line by line.
3. **Contracts with other systems** (a payload from an ERP, a partner API, an exchange file): ask for the real thing — "paste a real sample or the specification". Never show a format of your own and ask "is it like this?": a yes to a guess is not evidence. Save what the user pastes verbatim to `docs/specs/<ID>-<name>.<ext>`. If no sample exists, your proposal goes into the assumptions as "INVENTED — replace with a real sample before integration".
4. Ask **at most 5** questions in **one** message, those whose wrong guess changes behaviour visible from outside (API contract, duplicates, errors, security, data). Each with 2–3 options and what each changes. No recommendation inside the question.
5. In the same message, list every remaining point as an assumption with one line on what changes if it's wrong, and ask the user to accept them all or correct any.
6. When answered, run `bin/lite-time` and hand off: `Next: /lite:spec <ID>`.

Don't design or write code here.

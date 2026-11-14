---
kind: code
---

## Why

Gate-7 (`no-admin-idor`) reported four `#[NoAdminRequired]` methods with no authorization
guard in the call chain, after the checker stopped treating a delegated AUTHENTICATION
helper as a guard:

- `ParticipationBudgetController::submitProposal()`
- `ParticipationBudgetController::castAdvisoryVote()`
- `ParticipationController::submitReaction()`
- `EngagementController::index()`

Investigating them separates cleanly into two outcomes, and saying which is which is the
point of this change — a citizen-participation endpoint that is open to every authenticated
account is CORRECT, and narrowing it to silence a gate would be a functionality regression
(ADR-044), not a fix.

**The three participation endpoints are correctly open to all authenticated users.** The
citizen-participation spec says so in as many words ("Authenticated citizens SHALL submit
`BudgetProposal` objects…", "Authenticated citizens SHALL cast one advisory vote…", "The
system SHALL accept `ConsultationReaction` submissions … from authenticated Nextcloud
accounts by default"), and the register's own authorization baseline agrees
(`create: ["authenticated"]`). Their real authorization surface is (a) the actor identity
recorded on the object, and (b) the open-state of the round/consultation — and both were
already enforced. What was NOT visible at the routed method was (a): the acting UID was
handed to the service inside `ParticipationResponder::citizenAction()`, as a parameter of the
operation closure, so nothing reading `submitProposal()` — human or mechanical — could see
that `submitter` comes from the session rather than from the request body. That provenance is
the whole reason these endpoints are not IDOR, and it belonged in the endpoint.

**Two genuine defects were found while reading them.**

1. `EngagementController::index()` is a REAL read IDOR. `GET /api/engagement?meeting=…` took a
   caller-supplied meeting UUID and returned every participant's `EngagementRecord` for that
   meeting — speech log, questions raised, topics suggested and the derived
   `engagementScore`: per-person accountability data about other people. The only check was
   `getUser() === null`. The sibling write endpoint `capture()` already had the correct rule
   (admin, or the meeting's chair/secretary, may act for others; everyone else only for
   themselves) and the read path simply never got it. `EngagementRecord` declares no
   `authorization` block of its own, so the register baseline (`read`/`list`: `authenticated`
   + `public`) offered no narrowing either.

2. `BudgetVotingService::castAdvisoryVote()`'s voting-window guard FAILED OPEN. The
   `votingDeadline` / round-status check sat inside `if ($budgetId !== null) { if
   ($roundEntity !== null) { … } }`, so a proposal whose round could not be resolved (no
   `relations` entry and no flat `participatoryBudget`, or a round row since deleted) accepted
   votes indefinitely — the code that could have said no never ran. "The round could not be
   established" is not "the round is open".

## What Changes

- **`EngagementController::index()`** decides its read scope from the session identity BEFORE
  fetching: an NC admin or the meeting's chair/secretary reads the full meeting (unchanged —
  that is the REQ-PE-003 minutes-review surface); any other authenticated caller is narrowed
  to their own participant's records via the new `ownRecordsOnly()`, which fails closed when
  the caller has no linked `Participant`. No frontend caller of `GET /api/engagement` exists
  (`SpeakerQueuePanel.vue` only POSTs; the Engagement index page reads through OpenRegister's
  object API per ADR-022), so no UI surface loses data.
- **`EngagementController::mayRecordForOthers()`** is renamed **`hasMeetingOversight()`** — one
  predicate now answers both "may act for others" and "may read the whole meeting", instead of
  a second copy drifting away from the first.
- **`ParticipationResponder`** exposes `currentUid()`, and `citizenAction()` takes the resolved
  UID from its caller instead of resolving it internally and passing it into the operation
  closure. Behaviour is identical — an absent session is still `401` — but the session
  provenance of the recorded submitter/voter identity is now written in the routed method.
- **`ParticipationBudgetController::submitProposal()` / `castAdvisoryVote()`** and
  **`ParticipationController::submitReaction()`** bind `$uid = $this->responder->currentUid()`
  in their own bodies and hand it to the service alongside the caller-supplied object id. Their
  docblocks now state the rule explicitly: open to every authenticated account by design; the
  identity is the session's; the round/consultation open-state is the per-object gate.
- **`BudgetVotingService::castAdvisoryVote()`** fails closed on an unresolvable or missing
  round, with the same static "Voting is closed for this budget round" message the deadline
  path already used (no new oracle for which proposals have a broken round reference).

No register schema change is required. `EngagementRecord` keeps the register baseline: the
narrowing is a per-caller PROJECTION of a meeting-scoped list, which an OpenRegister group
rule cannot express, and closing `read`/`list` at the schema would break the chair/secretary
surface this change is protecting.

This is **not** marked BREAKING: no legitimate caller was relying on reading other
participants' engagement records, or on voting into a round that could not be resolved.

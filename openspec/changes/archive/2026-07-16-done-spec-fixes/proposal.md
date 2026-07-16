---
kind: code
---

# Proposal: done-spec-fixes

## Summary
decidesk's 88 `done` specs had never been semantically audited. decidesk is the **origin corpus** of
the gate-6 orphan-auth defect class, so this audit re-verified the 2026-04 findings first and then
hunted all six shapes. The headline is good news: **the original trio is genuinely fixed, and no
fabricated pass exists anywhere in the app.** Two real inert-declaration defects were found and are
fixed here; the remaining residuals are filed on an umbrella issue.

## Motivation
"Spec-says-done ≠ feature runs." A `done` spec is a claim, and claims decay silently — a dialect gets
retired upstream, a service gets DI-registered but never called, a route never lands. None of that
turns a pipeline red.

## The gate-6 original trio — re-verified (decidesk is where this class was first seen)
| Method | 2026-04 finding | Status now |
|---|---|---|
| `isTransitionAllowed()` | defined, never called | **Genuinely wired** — MeetingService.php:176, DecisionLifecycleService.php:229, DecisionTransitionGuard.php:216 |
| `requiresChairAuthorization()` | defined, never called | **Genuinely wired** — MeetingService.php:191, DecisionLifecycleService.php:133 & :245; MeetingService:191 is a real fail-closed guard (unresolved body or empty chair scope ⇒ deny) |
| `validateQuorum()` | defined, never called | **Legitimately superseded** — `QuorumService` deleted in quorum-chain-3 (#164); replacements wired: `isQuorumRequired()` (MeetingService.php:207, DecisionLifecycleService.php:266), `checkQuorum()` (VotingService.php:413) |

**Verdict: fixed, not regressed.** gate-6 PASSes and the pass is real.

A method note worth keeping: the first grep here used `grep -v "function X"` piped to `head`, which
truncated the real call sites and made the trio *look* orphaned. Only an invocation-shaped grep
(`->X(`) gave the truth. Verify-first prevented two manufactured findings — in the one app where a
manufactured regression would have been most believable.

## What this change fixes
Two **inert declarations** — the same "declared ≠ consumed" class as `fix-inert-seeds`:

1. **`ConsultationReaction.reactionPendingModeration` could never fire.** It declared
   `trigger.type: "create"`. OpenRegister's `NotificationAnnotationValidator::VALID_TRIGGERS` accepts
   only `created|updated|transition|scheduled|threshold|calculatedChange`. Every other decidesk
   notification already used `created`. One character of drift, one silently dead moderation
   notification — the same family as the `initialState`-vs-`initial` lifecycle drift.
2. **BoardEvaluation / EvaluationResponse relations were dead twice over.** They were declared only in
   the `x-openregister-relations` block, retired 2026-07-08 (ADR-062 rule 7) — and the properties were
   never materialised at all, so the reference had nowhere to live even in principle. Migrated to the
   canonical property-level `$ref`.

Both were invisible because an unreadable declaration is not an error to any engine — it is simply
never read.

## Tests were documenting the drift, not blocking it
`RegisterJsonTest::testRelationsAreConfigured` and `::testEngagementRecordSchemaExists` asserted the
**retired** dialect, so they went red when the core schemas migrated and then just stayed red — two
pre-existing failures carried as background noise. Repointed at the canonical dialect and reinforced
with guards for both drifts, each verified to fail on the bad path.

## Affected Projects
- [x] Project: `decidesk` — register configuration + register tests. No PHP behaviour change.

## Honest status downgrades
Three capabilities are implemented and specced but have **zero production callers**. Rather than leave
a false `done`, they are recorded as residuals on the umbrella issue:
- `QuorumVerificationService::computeQuorum()` — DI-registered (Application.php:772), 5 green unit
  tests, **only tests call it**. Textbook "green tests, dead feature".
- `DecisionNotificationService::notifyOnPublish()` — zero callers anywhere, not even tests. Plausibly
  superseded by the declarative `x-openregister-notifications` dialect (ADR-031), but that
  supersession is unconfirmed, so it is reported as *unsure* rather than quietly deleted.
- `NotificationPreferenceService::createPreference()` — zero callers anywhere.

Also filed: `settings#load` — decidesk's "re-import the configuration" endpoint — **has no route**
(gate-14), so the documented recovery path is unreachable. This was felt directly: proving the seed
fix required `occ app:enable` because the endpoint that exists to do exactly that cannot be called.

## Out of Scope
- Wiring or deleting the three orphaned capabilities: each needs a product decision (is the feature
  wanted?), and guessing would replace a false `done` with a false `fixed`.
- The two upstream OpenRegister defects found in `fix-inert-seeds`.
- Pre-existing accessibility gate failures (gates 32/38/40/43/45) — unrelated to spec semantics.

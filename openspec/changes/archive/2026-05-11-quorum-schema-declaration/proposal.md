---
kind: config
depends_on: []
chain:
  - quorum-schema-declaration   # this spec (head)
  - quorum-guard-rewrite         # next
  - quorum-service-deletion      # last
---

# Quorum — Schema declaration (chain spec 1 of 3)

## Problem

Per ADR-031, Meeting's quorum logic should live in the schema register
(`x-openregister-aggregations` + `x-openregister-calculations`) instead
of the `lib/Service/QuorumService.php` PHP service.

The original `quorum-declarative-migration` spec (PR #146, issue #148)
tried to do schema declaration + guard rewrite + service deletion + tests
all in one envelope — `mixed` per ADR-032. It blew the 200-turn Sonnet
builder budget on 2026-05-07 without producing a PR. ADR-032 mandates
splitting into a chain.

This is **chain spec 1 of 3** — config only, no PHP edits. Lands the
new declarative fields on the Meeting schema. Existing consumers
(QuorumService, MeetingTransitionGuard) keep working unchanged. The
new fields become read-only-available on every Meeting object.

## Proposed Solution

Add four declarative blocks to Meeting in
`lib/Settings/decidesk_register.json`:

1. **`x-openregister-aggregations.totalParticipantCount`** — count
   Participant objects where `governanceBody == @self.governanceBody`.
2. **`x-openregister-aggregations.presentParticipantCount`** — same
   filter + `attendanceStatus == "present"`.
3. **`x-openregister-calculations.quorumPercentage`** — number,
   `(presentCount / totalCount) * 100` with zero-divide guard.
4. **`x-openregister-calculations.quorumMet`** — boolean,
   `quorumRequired === null OR presentCount >= quorumRequired`.

Plus an integration test that imports the register, creates a Meeting +
matching Participants, and asserts the materialised values match
expectation.

**No code edits in this spec.** No `lib/Service/*.php` changes, no
`lib/Lifecycle/*.php` changes, no controller changes. The only PHP that
appears in this spec's diff is the integration test.

## Engine-dependency spike (in this spec)

The four declarations require OR's aggregation engine to support
**cross-schema filters via `@self.{relation}`** — Meeting aggregating
related Participants. Existing decidesk aggregations are all
within-schema (ActionItem aggregating ActionItem). Task 1 of `tasks.md`
spikes this in isolation; if the engine doesn't support it, this spec
applies ADR-031 exception 1 (file an OR feature request, pause the
chain at spec 1).

If the spike passes, the rest of the chain (specs 2 + 3) proceeds.
If it fails, specs 2 + 3 are blocked until OR lands the feature.

## Capabilities

### Modified Capabilities

- `meeting-management` — Meeting schema gains four declarative fields
  (read-only available on every Meeting object).

### New Capabilities

(none)

## Stakeholders

- **Decidesk maintainers** — own the schema declaration.
- **OpenRegister team** — own the cross-schema aggregation engine
  capability the spike validates.
- **Hydra reviewers** — first ADR-032 chain head spec. Validates that
  config-only specs are a clean Hydra fit.

## References

- ADR-031 (hydra) — Schema-declarative business logic over service classes
- ADR-032 (hydra) — Spec sizing taxonomy and chained-spec routing
- Predecessor (closed-as-superseded): `quorum-declarative-migration`
  (PR #146, issue #148)
- Chain successors: `quorum-guard-rewrite` (`depends_on: this`),
  `quorum-service-deletion` (`depends_on: quorum-guard-rewrite`)
- ActionItem in same register — working `x-openregister-aggregations`
  + `x-openregister-calculations` reference

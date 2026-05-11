---
kind: code
depends_on:
  - quorum-schema-declaration
chain:
  - quorum-schema-declaration   # head (predecessor)
  - quorum-guard-rewrite         # this spec
  - quorum-service-deletion      # last
---

# Quorum — Guard rewrite (chain spec 2 of 3)

## Problem

Chain spec 1 (`quorum-schema-declaration`) lands `quorumMet` as a
declarative boolean on every Meeting object. `MeetingTransitionGuard`
still calls `QuorumService::validateQuorum()` to make the same
decision — duplicated logic.

This spec switches the guard to read `meeting.quorumMet` from the
object directly. It does NOT delete `QuorumService` (that's chain
spec 3). It only rewires the single caller.

## Proposed Solution

Edit `lib/Lifecycle/MeetingTransitionGuard.php`:

1. Drop the constructor parameter `private readonly QuorumService $quorumService`.
2. Replace the `open` transition's quorum check:
   - Before: `return $this->quorumService->validateQuorum($meetingId);`
   - After: `return ($meeting['quorumMet'] ?? false) === true;`
3. Update the guard's covering test to fixture-load Meeting objects
   with `quorumMet` populated.

Update `lib/AppInfo/Application.php` to remove the QuorumService
constructor argument from MeetingTransitionGuard's DI registration
(keep the QuorumService registration itself — chain spec 3 deletes it).

After this spec lands: QuorumService still exists in the codebase but
is unused. The guard now relies on the declarative field landed in
chain spec 1.

## Why this is `kind: code` (small)

- ~30 LOC change across 2 files (the guard + Application.php).
- Test fixture updates (~50 LOC).
- No new classes, no signature changes on public APIs, no schema work.
- Strictly mechanical: copy the existing guard pattern, swap the
  service call for an array read.

Per ADR-032 small-code-spec rules: default Hydra budget (200 turns
Sonnet) is sufficient. Two files + their tests should complete in
~60-100 turns.

## Capabilities

### Modified Capabilities

- `meeting-management` — `MeetingTransitionGuard` switches data source
  for quorum check from service call to object field read. No external
  contract change (the guard's pass/fail behaviour for any given
  Meeting state is identical).

## Stakeholders

- **Decidesk maintainers** — own the guard rewrite.
- **Hydra reviewers** — validates that small `kind: code` chain specs
  land cleanly in default budget after a `kind: config` predecessor
  has done the schema work.

## References

- ADR-031 (hydra) — Schema-declarative business logic
- ADR-032 (hydra) — Spec sizing taxonomy and chained-spec routing
- Predecessor in chain: `quorum-schema-declaration` (must close before
  this builds; Hydra's `depends_on` enforces)
- Successor in chain: `quorum-service-deletion`

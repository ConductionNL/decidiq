# Design: Quorum — Guard rewrite (chain spec 2 of 3)

## Status
pr-created

## Spec kind & chain position (ADR-032)

- `kind: code` (small) — ~30 LOC change + test fixtures.
- Chain position: 2 of 3. `depends_on: [quorum-schema-declaration]`.
  Hydra blocks this from building until issue for spec 1 is closed.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Read quorum status during transition | **Code (existing PHP guard)** | ADR-031 explicitly preserves lifecycle guards as a legitimate PHP seam. The guard stays in PHP; it just reads from a declarative field instead of calling a service. |
| Quorum computation itself | **Already declarative** (chain spec 1) | Lives in `x-openregister-aggregations` + `x-openregister-calculations` on Meeting after spec 1. |

## Impact on existing code

- `lib/Lifecycle/MeetingTransitionGuard.php`:
  - Drop `private readonly QuorumService $quorumService` constructor param
  - Replace `$this->quorumService->validateQuorum($meetingId)` body in
    the `open`-transition check with `($meeting['quorumMet'] ?? false) === true`
  - Update `@spec` PHPDoc tag to point at this change's tasks.md
- `lib/AppInfo/Application.php`:
  - Remove the QuorumService argument from MeetingTransitionGuard's DI
    construction (Container should auto-wire from public type-hints,
    so removing the explicit arg is enough)
- `tests/Unit/Lifecycle/MeetingTransitionGuardTest.php`:
  - Drop QuorumService mock setup
  - Add three fixture cases: meeting with `quorumMet = true` (allow
    transition), `quorumMet = false` (block), `quorumMet` not set
    AND `quorumRequired` null (allow — null-required path)
- `lib/Service/QuorumService.php`:
  - **Unchanged.** Still exists, still registered, still callable by
    anyone who happens to call it. It just becomes unused. Chain spec
    3 deletes it.

## Risks

1. **`quorumMet` not yet materialised on Meeting.** Won't happen —
   spec 1 must close before this spec builds (Hydra `depends_on`).
2. **Other callers of QuorumService surface during the audit.**
   Currently only MeetingTransitionGuard calls it (verified via
   `grep -rn QuorumService lib/ src/`). If a new caller appears
   during chain spec 1's wait, this spec catches it in tasks.md task
   3 (regression scan).

## Out of scope

- Deleting QuorumService (chain spec 3).
- Updating other lifecycle guards (none currently use QuorumService).
- Frontend changes (the guard is server-side only).

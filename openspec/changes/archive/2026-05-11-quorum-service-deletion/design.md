# Design: Quorum — Service deletion (chain spec 3 of 3)

## Status
proposed

## Spec kind & chain position (ADR-032)

- `kind: code` (small) — file deletion + DI line removal.
- Chain position: 3 of 3 (tail). `depends_on: [quorum-guard-rewrite]`.
  Hydra blocks this until chain spec 2's issue is closed.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Quorum computation | **Already declarative** | Done in chain spec 1 (`x-openregister-aggregations` + `x-openregister-calculations` on Meeting). |
| Guard data source | **Code (declarative read)** | Done in chain spec 2 (`MeetingTransitionGuard` reads `meeting.quorumMet`). |
| QuorumService class lifecycle | **Delete** | This spec. The service has no remaining callers after chain spec 2. |

## Impact on existing code

- **Deleted**: `lib/Service/QuorumService.php`.
- **Deleted**: `tests/Unit/Service/QuorumServiceTest.php` (if exists).
- **Edited**: `lib/AppInfo/Application.php` — remove QuorumService
  Container registration line(s).
- **Verified clean**: `grep -rn QuorumService lib/ src/ tests/` returns
  zero hits after this spec lands.

## Risks

1. **A caller appeared between chain spec 2 closing and this spec
   building.** Mitigated by task 1's regression scan; if a caller
   exists, this spec stops and the new caller is added to the
   migration scope (back to chain spec 2's territory).
2. **A `@spec` PHPDoc tag elsewhere references the deleted file.**
   Caught by `composer check:strict`; tasks.md task 3 fixes any.

## Out of scope

- Adding new behaviour. This spec deletes only.
- Frontend changes.
- Documentation updates beyond "remove QuorumService from the data-model
  doc" (one-line edit).

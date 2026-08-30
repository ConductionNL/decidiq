---
kind: code
---

# Proposal: action-item-vtodo-migration

## Summary
Migrate existing **legacy app-local** action-item data — `task`, `delegation`, and pre-projection
`ActionItem` objects — onto authoritative CalDAV **VTODOs**, so it surfaces through the read-only
action-item projection introduced by `action-items-vtodo-deck-reconcile`. Because CalDAV writes
require the owning user's context (a repair step has none), the migration runs as a **per-user,
idempotent** projection (background job iterating users + impersonation), archiving the legacy objects
without hard-deleting them.

## Motivation
`action-items-vtodo-deck-reconcile` made the `action-item` schema a read-only VTODO projection and
**retired** the old `MigrateActionItemsToDeckLeaf` repair step to a no-op — because a repair step has
no user session and CalDAV writes need per-user context, and the old step wrongly wrote app-local
objects (which the read-only schema now rejects). Consequently, on any instance that already has
legacy `task`/`delegation`/`ActionItem` objects, those items are **invisible** in the new
projection (the projection only serves VTODOs). This change supplies the missing migration: project
each legacy item onto a VTODO owned by the right user, idempotently, preserving an audit trail.
(The dev environment has 0 legacy items; this matters for production instances.)

## Affected Projects
- [x] Project: `decidiq` — add a per-user migration (background job + service) projecting legacy
  task/delegation/ActionItem objects onto VTODOs; archive legacy objects. No schema additions.

## Scope

### In Scope
- A per-user, idempotent migration that, for each legacy `task` / `delegation` / app-local
  `ActionItem` object, creates the canonical VTODO (via `ActionItemWriter`/OR TaskService) in that
  user's calendar, mapping title/assignee/dueDate/status + source relations (round-tripped via the
  field blob).
- Idempotency: a migration marker (e.g. `_migratedToVtodo` / source-uuid link) so re-runs create no
  duplicates; resume-safe.
- Archive (not hard-delete) the legacy objects after projection (audit trail retained).
- A driver that supplies per-user CalDAV context: a background job iterating affected users +
  impersonation (or an on-first-login one-shot), since a repair step cannot.
- An admin/occ trigger + progress logging; absence of legacy data is a clean no-op.

### Out of Scope
- The read-only projection / write path / object-source binding (shipped in the reconcile).
- The Deck-board surface (separate change `action-item-deck-board`).
- Bi-directional sync or ongoing replication — this is a one-time forward migration per item.

## Approach
Replace the retired `MigrateActionItemsToDeckLeaf` no-op with a real **per-user** migration: a
`BackgroundJob` (or occ command) enumerates users who own legacy `task`/`delegation`/`ActionItem`
objects, sets up each user's session/CalDAV context (impersonation per the OR seed-CLI pattern), and
calls the existing `ActionItemWriter` to create the VTODO + archive the legacy object behind a marker.
The work is chunked + resumable. Reuses the shipped VTODO write path; no bespoke CalDAV→OR copier.

## New Dependencies
None. Reuses `ActionItemWriter` (OR TaskService) and the OR ObjectService for reading/archiving legacy
objects. Per-user impersonation uses Nextcloud's `IUserSession`/`IUserManager`.

## Impact
- **decidiq backend**: a migration service + background-job/occ driver; the retired
  `MigrateActionItemsToDeckLeaf` becomes (or delegates to) the real per-user migration entry point.
- **Data**: legacy `task`/`delegation`/`ActionItem` objects archived (soft, marker-stamped); new
  VTODOs created per user. No schema changes.

## Cross-Project Dependencies
Depends on the merged OpenRegister object-source capability + the action-item VTODO write path
(decidiq `ActionItemWriter`, shipped in `action-items-vtodo-deck-reconcile`). Requires the user
context / CalDAV available at run time.

## Risks

### Risk 1: CalDAV writes need per-user context (no user session in a repair step)
**Severity:** High — **Mitigation:** Do NOT run in a repair step; run as a background job (or occ)
that impersonates each affected user before calling the VTODO write path (documented OR seed-CLI
impersonation pattern). Skip + log users whose context can't be established.

### Risk 2: Duplicate VTODOs on re-run
**Severity:** Medium — **Mitigation:** Idempotency marker keyed by the legacy object's UUID; skip any
legacy object already migrated; the job is resume-safe and re-runnable.

### Risk 3: Data loss / premature deletion of legacy objects
**Severity:** Medium — **Mitigation:** Archive (soft-delete with marker), never hard-delete; the
legacy object remains auditable and recoverable; archive only after the VTODO is confirmed created.

### Risk 4: Large instances (many users / items)
**Severity:** Low — **Mitigation:** Chunked, paginated, resumable; per-user batching with progress
logging; no silent truncation.

## Rollback Strategy
The migration is forward-only but non-destructive (legacy archived, not deleted). Rollback = stop the
job; archived legacy objects can be un-archived; created VTODOs can be removed by uid if a full revert
is needed. Disabling the job restores the pre-migration state (legacy objects intact, just hidden by
the read-only projection — same as today).

## Open Questions
- Driver mechanism: a one-shot background job enumerating users + impersonation, vs an on-first-login
  per-user one-shot, vs an occ command for admins to run deliberately? (Resolve in design — leaning
  occ command + optional background job.)
- Do we migrate ALL three legacy types (`task`, `delegation`, `ActionItem`) or is `delegation`
  folded into the VTODO assignee only? (Resolve in design.)

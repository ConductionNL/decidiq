# Design: action-item-vtodo-migration

## Context
`action-items-vtodo-deck-reconcile` made `action-item` a read-only VTODO projection and retired the
old `MigrateActionItemsToDeckLeaf` repair step to a no-op (a repair step has no user session, and the
old step wrote app-local objects the read-only schema now rejects). Legacy `task`/`delegation`/
pre-projection `ActionItem` objects therefore no longer surface. This change projects them onto
per-user VTODOs via the shipped `ActionItemWriter` (OR `TaskService`).

CalDAV is inherently per-user: `TaskService` writes to the acting user's calendar. So the migration
cannot be a repair step or a system-context job — it must establish each owning user's context.

## Goals / Non-Goals
**Goals**
- Idempotent, resumable projection of legacy `task`/`delegation`/`ActionItem` → VTODO, per owning user.
- Archive (soft, marker-stamped) legacy objects after the VTODO is confirmed — never hard-delete.
- A driver that supplies per-user CalDAV context.

**Non-Goals**
- No change to the projection / write path / object-source binding.
- No ongoing sync — one-time forward migration per legacy object.
- No Deck-board surface (separate change).

## Decisions

### D1 — occ command as the primary driver (+ optional one-shot background job)
Primary entry point is an `occ decidiq:migrate-action-items` command an admin runs deliberately;
it enumerates owning users and migrates per user. A guarded one-shot background job MAY schedule it
post-upgrade. **Why over a repair step:** repair steps have no user session for CalDAV; an occ command
runs interactively with controllable impersonation + progress, and admins expect data migrations to be
explicit. Alternative (on-first-login per-user one-shot) rejected as primary — unpredictable timing,
harder to observe; could be a later add-on.

### D2 — Per-user impersonation before the VTODO write
For each owning user, set the session/CalDAV context to that user (Nextcloud `IUserSession` +
the OR seed-CLI impersonation pattern) before calling `ActionItemWriter::create`. **Why:** VTODOs land
in the owner's calendar; the projection scopes per user. Users whose context can't be established are
skipped + logged (no partial corruption). Alternative (write all to admin's calendar) rejected —
wrong ownership, breaks per-user scoping.

### D3 — Idempotency via a per-legacy-object marker
Stamp each legacy object with `_migratedToVtodo` (+ the created VTODO uid) before/at archive; skip any
already-marked object on re-run. The created VTODO also records the source legacy uuid (field blob) so
a second pass detects existing projections. **Why:** safe re-runs + resume after interruption.
Alternative (delete-on-migrate as the dedup signal) rejected — destructive, not resumable-safe.

### D4 — Archive-not-delete, only after VTODO confirmed
Create the VTODO first; only on success mark + soft-archive the legacy object (OR `deleteObject` is
retention-aware / soft). **Why:** no data loss; audit trail retained (REQ-AI-DECK-003 spirit); ordering
guarantees we never archive an item we failed to project.

### D5 — Mapping (task / delegation / ActionItem → VTODO)
`task`/`ActionItem` → VTODO via `ActionItemWriter` (title→summary, status→VTODO STATUS, dueDate→DUE,
assignee + remaining fields → X-OPENREGISTER-DATA blob, source relations preserved). `delegation`
folds onto the target VTODO's **assignee** (+ an audit note), not a separate VTODO. **Why:** a
delegation is a reassignment of an action item, not an item itself (REQ-AI-DECK-002).

## Risks / Trade-offs
- Impersonation in a CLI/job context is the sensitive part (D2) — guard + skip-and-log on failure.
- Large instances → chunked, paginated, resumable (no silent truncation; progress logged).
- Forward-only but non-destructive (legacy archived); full revert is manual (un-archive + delete VTODO by uid).

## Seed Data
No new schemas — no `_registers.json` entries. To exercise the migration in a dev/test env, first seed
legacy objects in the OLD shape (app-local `task`/`delegation`/`ActionItem` via the OR object API for a
test user), then run the migration and assert they appear as that user's VTODOs in the projection and
the legacy objects are archived+marked. Realistic municipality examples: a `task` "Plan van aanpak
zonnepanelen opstellen" (owner: a councillor user), a `delegation` reassigning it to "Wethouder
Duurzaamheid", an `ActionItem` "Notulen vaststellen".

## Security Considerations
The migration impersonates users to write into their calendars — admin-only trigger (occ /
AuthorizedAdminSetting-guarded). Validate that each legacy object's owner is resolved before
impersonating; never cross-write between users. No new public endpoint. Reuses the existing
per-user-scoped VTODO write path. Soft-archive preserves auditability.

## NL Design System
No UI surface (backend migration). occ output + logs only; user-facing strings (if any admin UI is
added later) would follow ADR-005 (nl + en).

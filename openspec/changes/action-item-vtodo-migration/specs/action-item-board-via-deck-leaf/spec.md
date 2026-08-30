# action-item-board-via-deck-leaf — delta: legacy → VTODO migration

## ADDED Requirements

### Requirement: REQ-AI-DECK-010 Per-user legacy-to-VTODO migration
The system SHALL provide a per-user, idempotent migration that projects legacy app-local action-item
data (`task`, `delegation`, and pre-projection `ActionItem` objects) onto authoritative CalDAV VTODOs
in the owning user's calendar (via the action-item VTODO write path), so the data surfaces through the
read-only `action-item` projection. The migration SHALL run with each owning user's context (NOT a
repair step) and SHALL be resumable; absence of legacy data SHALL be a clean no-op.

#### Scenario: Legacy task becomes the user's VTODO
- GIVEN a legacy `task` owned by user U with title/assignee/dueDate/status
- WHEN the migration runs for U
- THEN a VTODO action item is created in U's calendar with those fields mapped
- AND it appears in the read-only `action-item` projection scoped to U.

#### Scenario: No legacy data is a no-op
- GIVEN an instance with no legacy `task`/`delegation`/`ActionItem` objects
- WHEN the migration runs
- THEN it completes successfully creating nothing and logs a no-op result.

### Requirement: REQ-AI-DECK-011 Idempotent, archive-not-delete
The migration SHALL mark each migrated legacy object (e.g. `_migratedToVtodo` + the created VTODO uid)
and SHALL skip already-marked objects on re-run, creating no duplicate VTODOs. Legacy objects SHALL be
soft-archived (never hard-deleted) and only AFTER their VTODO is confirmed created.

#### Scenario: Re-run creates no duplicates
- GIVEN a legacy object already migrated (marked)
- WHEN the migration runs again
- THEN no second VTODO is created for it and it is skipped.

#### Scenario: Archive only after VTODO confirmed
- GIVEN a legacy object being migrated
- WHEN VTODO creation fails
- THEN the legacy object is NOT archived and is left for retry
- AND when creation succeeds THEN the legacy object is soft-archived (recoverable), not hard-deleted.

### Requirement: REQ-AI-DECK-012 Delegation folds onto the VTODO assignee
A legacy `delegation` SHALL be applied as the assignee (and an audit note) on the target action item's
VTODO rather than creating a separate VTODO, preserving who the item is currently delegated to.

#### Scenario: Delegation reassigns the VTODO
- GIVEN a legacy `delegation` retargeting action item A to person P
- WHEN the migration runs
- THEN A's VTODO assignee is set to P (with an audit note), and no standalone VTODO is created for the delegation.

# Tasks: action-item-vtodo-migration

## Implementation Tasks

### Task 1: Per-user migration service (project legacy → VTODO, idempotent)
- **spec_ref**: `openspec/changes/archive/2026-11-25-action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration`
- **files**: `lib/Service/ActionItemMigrationService.php` (new), `lib/Service/ActionItemWriter.php`
- **acceptance_criteria**:
  - GIVEN a legacy `task`/`ActionItem` for user U WHEN migrated THEN a VTODO is created in U's calendar with mapped fields
  - GIVEN no legacy data WHEN run THEN it is a clean no-op
  - GIVEN an already-migrated (marked) object WHEN run again THEN it is skipped (no duplicate)
- [x] Implement the service: read legacy `task`/`ActionItem` per user, map + create VTODO via ActionItemWriter, mark + soft-archive the legacy object after success; resumable; no-op when empty.
- [x] Test: mapping + idempotency marker + archive-after-success (mocked ObjectService/ActionItemWriter).

### Task 2: Delegation → VTODO assignee
- **spec_ref**: `openspec/changes/archive/2026-11-25-action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-012-delegation-folds-onto-the-vtodo-assignee`
- **files**: `lib/Service/ActionItemMigrationService.php`
- **acceptance_criteria**:
  - GIVEN a legacy `delegation` retargeting item A to P WHEN migrated THEN A's VTODO assignee = P + audit note
  - GIVEN a delegation WHEN migrated THEN no standalone VTODO is created for it
- [x] Implement delegation folding onto the target VTODO assignee (+ audit note) via the update path.
- [x] Test: delegation updates assignee, creates no standalone VTODO.

### Task 3: Per-user driver — occ command (+ optional one-shot job) with impersonation
- **spec_ref**: `openspec/changes/archive/2026-11-25-action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration`
- **files**: `lib/Command/MigrateActionItems.php` (new), `lib/Migration/MigrateActionItemsToDeckLeaf.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN legacy data across users WHEN `occ decidiq:migrate-action-items` runs THEN each owning user is impersonated and their items migrated
  - GIVEN a user whose context can't be established WHEN run THEN they are skipped + logged (no partial corruption)
  - GIVEN re-run THEN it resumes/skips already-migrated objects
- [x] Implement the occ command (admin-only) enumerating owning users + per-user impersonation calling the service; chunked/paginated/resumable with progress logging; repoint/retire the old repair-step no-op to reference this.
- [x] Test: command enumerates users, impersonates, skips uncontextable users; idempotent across runs.

### Task 4: Archive-not-delete safety + markers
- **spec_ref**: `openspec/changes/archive/2026-11-25-action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-011-idempotent-archive-not-delete`
- **files**: `lib/Service/ActionItemMigrationService.php`
- **acceptance_criteria**:
  - GIVEN VTODO creation fails WHEN migrating THEN the legacy object is NOT archived (left for retry)
  - GIVEN success THEN the legacy object is soft-archived (recoverable), never hard-deleted, and marked
- [x] Implement the create-then-mark-then-soft-archive ordering with the idempotency marker.
- [x] Test: failure leaves legacy intact; success soft-archives + marks.

## Verification
- [x] `openspec validate action-item-vtodo-migration --strict` passes.
- [x] Live on :8080: seed legacy `task`/`delegation`/`ActionItem` for a test user → run `occ decidiq:migrate-action-items` → they appear as that user's VTODOs in the projection; legacy archived+marked; re-run is a no-op; empty instance is a clean no-op.
  - 25 Nov (sandbox Nextcloud 34.0.6, PostgreSQL 16, OpenRegister main): empty instance `No legacy action items to migrate.`, exit 0. Seeded one legacy `task`, one `ActionItem` (status `done`) and one active `delegation` of that task (substitute `admin`) owned by a test user; the run printed `2 migrated, 1 delegations applied, 0 skipped, 0 failed`, exit 0. The user's action-item projection then listed both (`open` with assignee `admin` from the delegation, `completed`); the three legacy objects carry `_migratedToVtodo` (the VTODO uid) and are soft-deleted with a 730-day purge date, not purged. A second run printed `No legacy action items to migrate.`, exit 0, and created no duplicate VTODOs.
  - Found on the way: Nextcloud 34's default `personal` calendar holds VEVENT only, so a user without a VTODO calendar (no Tasks app, never opened Tasks) gets `failed` for every item and the log line `No VTODO-supporting calendar found`; the legacy objects stay unmarked and are taken on the next run once the user has a tasks calendar (verified: after a VTODO calendar was added the run above succeeded). This is the designed resumable failure, no data is lost.
- [x] `composer check:strict` green; Hydra gates (no app-local write store post-migration; admin-only trigger; no hard delete).
  - 21 Nov: `composer check:strict` run in full. The one finding in this change's files was a phpmd `ElseExpression` in `ActionItemMigrationService::legacyObject()`, now early returns (`testRowsGivenAsDataAreReadAndUnusableRowsPassedOver` covers the data-row and unusable-row paths); PHPUnit green. The suite's remaining phpcs, phpmd and psalm findings, and the hydra `integration-parity` gate, are in files outside this change and fail on development alike. The migration still runs only from the admin-only occ command and archives with `permanent: false` (`testALegacyTaskBecomesAVtodoThenIsMarkedAndArchived`).

## Acceptance Criteria
- Legacy task/delegation/ActionItem data is projected onto per-user VTODOs and surfaces in the projection.
- Idempotent + resumable; legacy soft-archived (not deleted); delegation folds onto assignee.
- Runs with per-user CalDAV context (occ command), never a repair step.

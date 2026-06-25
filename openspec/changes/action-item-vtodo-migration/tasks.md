# Tasks: action-item-vtodo-migration

## Implementation Tasks

### Task 1: Per-user migration service (project legacy → VTODO, idempotent)
- **spec_ref**: `openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration`
- **files**: `lib/Service/ActionItemMigrationService.php` (new), `lib/Service/ActionItemWriter.php`
- **acceptance_criteria**:
  - GIVEN a legacy `task`/`ActionItem` for user U WHEN migrated THEN a VTODO is created in U's calendar with mapped fields
  - GIVEN no legacy data WHEN run THEN it is a clean no-op
  - GIVEN an already-migrated (marked) object WHEN run again THEN it is skipped (no duplicate)
- [ ] Implement the service: read legacy `task`/`ActionItem` per user, map + create VTODO via ActionItemWriter, mark + soft-archive the legacy object after success; resumable; no-op when empty.
- [ ] Test: mapping + idempotency marker + archive-after-success (mocked ObjectService/ActionItemWriter).

### Task 2: Delegation → VTODO assignee
- **spec_ref**: `openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-012-delegation-folds-onto-the-vtodo-assignee`
- **files**: `lib/Service/ActionItemMigrationService.php`
- **acceptance_criteria**:
  - GIVEN a legacy `delegation` retargeting item A to P WHEN migrated THEN A's VTODO assignee = P + audit note
  - GIVEN a delegation WHEN migrated THEN no standalone VTODO is created for it
- [ ] Implement delegation folding onto the target VTODO assignee (+ audit note) via the update path.
- [ ] Test: delegation updates assignee, creates no standalone VTODO.

### Task 3: Per-user driver — occ command (+ optional one-shot job) with impersonation
- **spec_ref**: `openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration`
- **files**: `lib/Command/MigrateActionItems.php` (new), `lib/Migration/MigrateActionItemsToDeckLeaf.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN legacy data across users WHEN `occ decidesk:migrate-action-items` runs THEN each owning user is impersonated and their items migrated
  - GIVEN a user whose context can't be established WHEN run THEN they are skipped + logged (no partial corruption)
  - GIVEN re-run THEN it resumes/skips already-migrated objects
- [ ] Implement the occ command (admin-only) enumerating owning users + per-user impersonation calling the service; chunked/paginated/resumable with progress logging; repoint/retire the old repair-step no-op to reference this.
- [ ] Test: command enumerates users, impersonates, skips uncontextable users; idempotent across runs.

### Task 4: Archive-not-delete safety + markers
- **spec_ref**: `openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-011-idempotent-archive-not-delete`
- **files**: `lib/Service/ActionItemMigrationService.php`
- **acceptance_criteria**:
  - GIVEN VTODO creation fails WHEN migrating THEN the legacy object is NOT archived (left for retry)
  - GIVEN success THEN the legacy object is soft-archived (recoverable), never hard-deleted, and marked
- [ ] Implement the create-then-mark-then-soft-archive ordering with the idempotency marker.
- [ ] Test: failure leaves legacy intact; success soft-archives + marks.

## Verification
- [ ] `openspec validate action-item-vtodo-migration --strict` passes.
- [ ] Live on :8080: seed legacy `task`/`delegation`/`ActionItem` for a test user → run `occ decidesk:migrate-action-items` → they appear as that user's VTODOs in the projection; legacy archived+marked; re-run is a no-op; empty instance is a clean no-op.
- [ ] `composer check:strict` green; Hydra gates (no app-local write store post-migration; admin-only trigger; no hard delete).

## Acceptance Criteria
- Legacy task/delegation/ActionItem data is projected onto per-user VTODOs and surfaces in the projection.
- Idempotent + resumable; legacy soft-archived (not deleted); delegation folds onto assignee.
- Runs with per-user CalDAV context (occ command), never a repair step.

# Tasks: dedupe-governance-controller-admin-guard

## Implementation Tasks

### Task 1: Lift the shared admin guard into GovernanceControllerTrait
- **spec_ref**: `openspec/changes/dedupe-governance-controller-admin-guard/specs/governance-controller-shared-helpers/spec.md#requirement-req-gcs-001-the-admin-guard-is-defined-exactly-once`
- **files**: `lib/Controller/GovernanceControllerTrait.php`
- **acceptance_criteria**:
  - GIVEN the trait THEN it exposes `requireAdmin(IUserSession $session, IGroupManager
    $groupManager): ?JSONResponse` returning `401` when no user, `403` when not admin, `null`
    when authorized — matching the exact messages currently duplicated
- [x] Add the method to `GovernanceControllerTrait`, importing `IGroupManager`. (done: done another way: the shared guard is the RequiresOrAdmin trait (lib/Controller/RequiresOrAdmin.php:47), used by AuditLogController, RegulatorExportController, GovernanceReportController and MultilingualReconciliationController; no private requireAdmin() is left in them; closed on code by decision 399, delta amended to name the trait)
- [x] Test (new/extended `GovernanceControllerTraitTest`): null-user → 401; non-admin → 403; (done: done another way: the shared guard is the RequiresOrAdmin trait (lib/Controller/RequiresOrAdmin.php:47), used by AuditLogController, RegulatorExportController, GovernanceReportController and MultilingualReconciliationController; no private requireAdmin() is left in them; closed on code by decision 399, delta amended to name the trait)
      admin → null.

### Task 2: Remove the four duplicated private methods and rewire call sites
- **spec_ref**: `openspec/changes/dedupe-governance-controller-admin-guard/specs/governance-controller-shared-helpers/spec.md#requirement-req-gcs-001-the-admin-guard-is-defined-exactly-once`
- **files**:
  - `lib/Controller/AuditLogController.php` (private method at line 175; call sites at 77, 123, 141)
  - `lib/Controller/RegulatorExportController.php` (private method at line 200; call sites at 79, 131, 172)
  - `lib/Controller/GovernanceReportController.php` (private method at line 209; call sites at 73, 104, 138, 175)
  - `lib/Controller/MultilingualReconciliationController.php` (private method at line 173; call sites at 76, 119, 144)
- **acceptance_criteria**:
  - GIVEN any of the four controllers THEN no local `private function requireAdmin()` remains
  - GIVEN each existing call site `$this->requireAdmin()` THEN it becomes
    `$this->requireAdmin($this->userSession, $this->groupManager)` with identical return handling
  - GIVEN the full existing PHPUnit suite for these four controllers THEN it passes unchanged
    (response bodies/status codes are byte-identical to pre-refactor)
- [x] Delete the four private methods. (done: done another way: the shared guard is the RequiresOrAdmin trait (lib/Controller/RequiresOrAdmin.php:47), used by AuditLogController, RegulatorExportController, GovernanceReportController and MultilingualReconciliationController; no private requireAdmin() is left in them; closed on code by decision 399, delta amended to name the trait)
- [x] Update all 13 call sites (3+3+4+3) to the two-argument trait call. (done: done another way: the shared guard is the RequiresOrAdmin trait (lib/Controller/RequiresOrAdmin.php:47), used by AuditLogController, RegulatorExportController, GovernanceReportController and MultilingualReconciliationController; no private requireAdmin() is left in them; closed on code by decision 399, delta amended to name the trait)
- [x] Run `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) + PHPUnit for the four controllers. (done: done another way: the shared guard is the RequiresOrAdmin trait (lib/Controller/RequiresOrAdmin.php:47), used by AuditLogController, RegulatorExportController, GovernanceReportController and MultilingualReconciliationController; no private requireAdmin() is left in them; closed on code by decision 399, delta amended to name the trait)

### Task 3: Confirm the two out-of-scope controllers stay on their own pattern
- **spec_ref**: `openspec/changes/dedupe-governance-controller-admin-guard/specs/governance-controller-shared-helpers/spec.md#requirement-req-gcs-002-per-object-checked-controllers-are-not-forced-onto-the-admin-guard`
- **files**: `lib/Controller/ConflictOfInterestController.php`, `lib/Controller/EIDASSignatureController.php`
- **acceptance_criteria**:
  - GIVEN `ConflictOfInterestController` and `EIDASSignatureController` THEN neither is modified by
    this change — they already use `#[NoAdminRequired]` + per-object checks, a different and
    correct pattern, not the admin-only gate
- [x] No code change; verified by review only (documents the boundary so a future contributor does (done: done another way: the shared guard is the RequiresOrAdmin trait (lib/Controller/RequiresOrAdmin.php:47), used by AuditLogController, RegulatorExportController, GovernanceReportController and MultilingualReconciliationController; no private requireAdmin() is left in them; closed on code by decision 399, delta amended to name the trait)
      not "complete the set" by force-fitting these two onto `requireAdmin()`).

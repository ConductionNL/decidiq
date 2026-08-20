# Tasks: dedupe-governance-controller-admin-guard

## Implementation Tasks

### Task 1: Lift the shared admin guard into GovernanceControllerTrait
- **spec_ref**: `openspec/changes/dedupe-governance-controller-admin-guard/specs/governance-controller-shared-helpers/spec.md#requirement-req-gcs-001-the-admin-guard-is-defined-exactly-once`
- **files**: `lib/Controller/GovernanceControllerTrait.php`
- **acceptance_criteria**:
  - GIVEN the trait THEN it exposes `requireAdmin(IUserSession $session, IGroupManager
    $groupManager): ?JSONResponse` returning `401` when no user, `403` when not admin, `null`
    when authorized — matching the exact messages currently duplicated
- [ ] Add the method to `GovernanceControllerTrait`, importing `IGroupManager`.
- [ ] Test (new/extended `GovernanceControllerTraitTest`): null-user → 401; non-admin → 403;
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
- [ ] Delete the four private methods.
- [ ] Update all 13 call sites (3+3+4+3) to the two-argument trait call.
- [ ] Run `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) + PHPUnit for the four controllers.

### Task 3: Confirm the two out-of-scope controllers stay on their own pattern
- **spec_ref**: `openspec/changes/dedupe-governance-controller-admin-guard/specs/governance-controller-shared-helpers/spec.md#requirement-req-gcs-002-per-object-checked-controllers-are-not-forced-onto-the-admin-guard`
- **files**: `lib/Controller/ConflictOfInterestController.php`, `lib/Controller/EIDASSignatureController.php`
- **acceptance_criteria**:
  - GIVEN `ConflictOfInterestController` and `EIDASSignatureController` THEN neither is modified by
    this change — they already use `#[NoAdminRequired]` + per-object checks, a different and
    correct pattern, not the admin-only gate
- [ ] No code change; verified by review only (documents the boundary so a future contributor does
      not "complete the set" by force-fitting these two onto `requireAdmin()`).

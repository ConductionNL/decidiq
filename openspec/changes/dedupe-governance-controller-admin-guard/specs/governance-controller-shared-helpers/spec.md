## ADDED Requirements

### Requirement: REQ-GCS-001 The admin guard is defined exactly once
`GovernanceControllerTrait` MUST be the single source of the admin-only authorization check
(`requireAdmin()`) used by the retained governance controllers whose endpoints are gated purely by
Nextcloud admin membership. No controller under `lib/Controller/` MAY define its own private
`requireAdmin()` method that duplicates the trait's behavior.

#### Scenario: A controller using the admin guard delegates to the trait
- **GIVEN** `AuditLogController`, `RegulatorExportController`, `GovernanceReportController`, or
  `MultilingualReconciliationController`
- **WHEN** an endpoint needs an admin-only gate
- **THEN** it calls `$this->requireAdmin($this->userSession, $this->groupManager)` from
  `GovernanceControllerTrait` and returns its result directly; no local re-implementation exists

#### Scenario: Non-admin caller is rejected with 403
- **GIVEN** an authenticated non-admin user
- **WHEN** they call an admin-gated endpoint on any of the four controllers above
- **THEN** the response is `403 Forbidden` with message `"Administrator role required."`

#### Scenario: Unauthenticated caller is rejected with 401
- **GIVEN** a request with no authenticated session
- **WHEN** it reaches an admin-gated endpoint on any of the four controllers above
- **THEN** the response is `401 Unauthorized` with message `"Authentication required."`

---

### Requirement: REQ-GCS-002 Per-object-checked controllers are not forced onto the admin guard
Controllers whose authorization model is `#[NoAdminRequired]` plus a per-object/per-actor check (e.g. `ConflictOfInterestController`, `EIDASSignatureController`) MUST NOT be migrated onto `GovernanceControllerTrait::requireAdmin()`. The trait's admin guard is for genuinely admin-only surfaces only; a per-object check is a different authorization shape and collapsing the two would either wrongly lock out authorized non-admin actors or wrongly widen admin-only data to any authenticated user.

#### Scenario: A per-object-gated controller keeps its own check
- **GIVEN** `ConflictOfInterestController`
- **WHEN** reviewed against this change
- **THEN** it is unmodified and continues to use `#[NoAdminRequired]` with its existing per-object
  authorization logic

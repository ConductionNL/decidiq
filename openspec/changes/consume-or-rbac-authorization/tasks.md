# Tasks: consume-or-rbac-authorization

## Implementation Tasks

### Task 1: Project governance-body roles into OR RBAC scopes
- **spec_ref**: `openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes`
- **files**: `lib/Service/GovernanceRoleScopeProjector.php` (new), `lib/AppInfo/Application.php` (hook wiring), `lib/Listener/*` (Participant/Membership write listener)
- **acceptance_criteria**:
  - GIVEN a member gains role `chair` WHEN the projection runs THEN the member is in both `decidesk:body:{bodyId}:chair` and `:signatory`
  - GIVEN a member gains role `secretary` WHEN the projection runs THEN the member is in `:signatory` only
  - GIVEN a role is removed WHEN the projection re-runs THEN both scopes reconcile and a second run is a no-op (idempotent, fails closed)
- [ ] Implement an idempotent per-body reconcile that maps the current chair/secretary/vice-chair roster to the two OR scopes, driven by a Participant/Membership write listener.
- [ ] Test: chair→both scopes; secretary→signatory-only; removal reconciles; re-run no-op; empty scope denies.

### Task 2: Backfill existing bodies (one-shot reconciler)
- **spec_ref**: `openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes`
- **files**: `lib/Migration/ProjectGovernanceRoleScopes.php` (new repair step)
- **acceptance_criteria**:
  - GIVEN existing bodies with chair/secretary/vice-chair members WHEN the repair step runs THEN every body's chair + signatory scopes match its roster
  - GIVEN the repair step re-runs THEN it makes no further change (idempotent)
- [ ] Implement an idempotent repair step invoking the projector for every GovernanceBody.
- [ ] Test: backfill populates scopes; re-run is a no-op.

### Task 3: Write-gate signing on the signatory scope; delete MinutesAuthorizationService
- **spec_ref**: `openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-002-signatory-authorization-is-an-openregister-rbac-rule-not-an-app-local-service`
- **files**: `lib/Settings/decidesk_register.json` (minutes + resolution decision RBAC scope), `lib/Controller/EIDASSignatureController.php`, delete `lib/Service/MinutesAuthorizationService.php` + its DI registration in `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a signatory WHEN they initiate signing THEN OR authorizes the signing-request write and signing starts
  - GIVEN a non-signatory WHEN they initiate signing THEN OR returns 403 and no app-local service is consulted
  - GIVEN the worktree after the change WHEN `lint-or-abstraction-anti-patterns.sh` runs THEN no `consume-or-rbac-fleet-wide` finding for decidesk
- [ ] Declare the write-gating RBAC rule on the signing-request property (minutes + resolution decision) keyed on `decidesk:body:{bodyId}:signatory`.
- [ ] Route the controller's signing-request write through `ObjectService`; remove the `canInitiateSigning()` call; delete the service + registration.
- [ ] Test: signatory allowed, non-signatory 403; gate reports clean.

### Task 4: Write-gate chair-only lifecycle transitions; remove the actor branch
- **spec_ref**: `openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-003-chair-only-lifecycle-transitions-are-enforced-by-openregister-property-rbac`
- **files**: `lib/Settings/decidesk_register.json` (meeting.lifecycle RBAC scope), `lib/Service/MeetingService.php`, `lib/Service/WorkflowService.php`
- **acceptance_criteria**:
  - GIVEN a `chairOnlyTransitions` transition WHEN a non-chair attempts it THEN OR denies the `meeting.lifecycle` write (403); WHEN the chair attempts it THEN it advances
  - GIVEN a domain with `allowPause: false` WHEN the chair attempts `opened → paused` THEN the workflow policy refuses it regardless of actor
  - GIVEN `MeetingService` WHEN reviewed THEN the `requiresChairAuthorization()` branch + NC-UID comparison are removed; policy predicates + quorum data-predicate remain
- [ ] Declare the write-gating RBAC rule on `meeting.lifecycle` keyed on `decidesk:body:{bodyId}:chair` for the `chairOnlyTransitions` set.
- [ ] Remove the chair-auth actor branch from `MeetingService`; keep `allowPause`/`allowAdjourn`/`isQuorumRequired` and the quorum guard.
- [ ] Test: chair vs non-chair; domain policy still forbids independent of actor.

### Task 5: Consolidate the four requireAdmin guards onto OR's admin decision
- **spec_ref**: `openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-004-the-duplicated-admin-guards-consume-openregisters-admin-determination`
- **files**: `lib/Controller/OrAdminGuard.php` (new shared guard/trait), `lib/Controller/GovernanceReportController.php`, `lib/Controller/MultilingualReconciliationController.php`, `lib/Controller/AuditLogController.php`, `lib/Controller/RegulatorExportController.php`
- **acceptance_criteria**:
  - GIVEN a non-admin WHEN they call any previously admin-gated method THEN the shared guard returns 403
  - GIVEN an admin WHEN they call a previously admin-gated method THEN it is permitted
  - GIVEN the four controllers WHEN reviewed THEN there is exactly one admin-guard implementation shared across them
- [ ] Implement one shared admin guard consuming `PropertyRbacHandler::isAdmin()` / the OR admin decision; replace the four private copies.
- [ ] Test: non-admin denied and admin allowed on all four surfaces; single implementation.

### Task 6: Fail-closed verification across all migrated paths
- **spec_ref**: `openspec/changes/consume-or-rbac-authorization/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-005-fail-closed-authorization-is-preserved-end-to-end`
- **files**: `tests/` (unit + Playwright e2e)
- **acceptance_criteria**:
  - GIVEN a signing / chair-only transition whose body scope cannot be resolved WHEN OR evaluates RBAC THEN the write is denied (403)
  - GIVEN the change WHEN reviewed THEN no `catch (\Throwable) { return null; }` resolver whose null is treated as "skipped" is introduced
- [ ] Add unit + e2e assertions for the deny-on-ambiguity paths; confirm no unsafe-auth-resolver pattern.
- [ ] Test (UI): non-signatory blocked from signing; non-chair blocked from a chair-only transition; non-admin blocked on the four admin surfaces.

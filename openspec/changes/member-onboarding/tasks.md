# Tasks: member-onboarding

## Implementation Tasks

### Task 1: Register fragment 59 — OnboardingTraject + OffboardingTraject schemas with declarative dialects
- **spec_ref**: `openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-001-onboardingtraject-schema-on-openregister` (+ REQ-MOB-002/REQ-MOB-003/REQ-MOB-004/REQ-MOB-010)
- **files**: `lib/Settings/register.d/59-member-onboarding.json`
- **acceptance_criteria**:
  - GIVEN the fragment is loaded WHEN the register imports THEN `onboarding-traject` and `offboarding-traject` schemas exist with all required fields, property titles, and `x-schema-org: schema:Action`, and no schema outside fragment 59 is created or modified
  - GIVEN both schemas WHEN inspected THEN `x-openregister-lifecycle` uses the canonical `initial` keyword with `gestart → in-uitvoering → afgerond | vervallen` (terminals final) and `x-openregister-notifications` declares created + due-soon + overdue triggers (nl+en subjects) with no imperative dispatch anywhere
  - GIVEN a step with `verplicht: true` WHEN its status is set to `overgeslagen` THEN validation rejects it; GIVEN a traject with an open mandatory step WHEN `afgerond` is attempted THEN the transition is refused naming the open steps
  - GIVEN a traject with two open steps WHEN one step is completed THEN the other step's status and note are unchanged (full-array PUT-semantic carry-forward proven by test)
- [ ] Implement
- [ ] Test

### Task 2: Seed data — realistic trajecten for both schemas
- **spec_ref**: `openspec/changes/member-onboarding/design.md#seed-data`
- **files**: `lib/Settings/register.d/59-member-onboarding.json` (seed section)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeding completes THEN 3 onboarding trajecten (in-uitvoering with one overdue step, gestart with due-soon steps, afgerond association-domain with a skipped optional step) and 2 offboarding trajecten (in-uitvoering blocked on groepen-intrekken, afgerond with exit confirmation) exist per the design tables, linked to seeded persons/bodies/meetings with nil-UUID placeholders only for unresolvable refs
  - GIVEN the seeds WHEN the dashboard renders THEN the batch `raadswisseling-2026`, the overdue KPI, and the per-status widgets are demoable on install
- [ ] Implement
- [ ] Test

### Task 3: Manifest fragment — index/detail pages + menu for both trajecten
- **spec_ref**: `openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-012-list-and-detail-pages`
- **files**: `src/manifest.d/member-onboarding.json`
- **acceptance_criteria**:
  - GIVEN the built app WHEN navigating the menu THEN Onboarding and Offboarding index pages render with the specced columns (person, body, role/reden, trigger, lifecycle, step progress) and quick filters (lifecycle, trigger, body, batch), schema refs by slug `onboarding-traject` / `offboarding-traject`, never PascalCase
  - GIVEN a traject detail page WHEN opened THEN the checklist renders per-step status/dueDate/actions, linked person/body/meeting are navigable relations, and the audit-trail sidebar is present
- [ ] Implement
- [ ] Test

### Task 4: Dashboard widgets — trajecten per status + overdue-steps KPI
- **spec_ref**: `openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-011-griffie-progress-dashboard`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN seeded data WHEN the dashboard renders THEN per-status traject counts and the overdue-steps KPI come from declarative source aggregations (no imperative counting endpoint) and each widget routes to the pre-filtered index
  - GIVEN the widget filter DSL lacks a relative-now or array-predicate token for the overdue cut WHEN implementing THEN the documented fallback applies (count non-terminal trajecten and cut overdue on the pre-filtered index — never a silently wrong count) and the resolution is recorded in the PR
- [ ] Implement
- [ ] Test

### Task 5: Provisioning service — account linkage, group assignment, fail-closed revocation
- **spec_ref**: `openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-006-griffie-confirmed-account-linkage-and-role-based-provisioning` (+ REQ-MOB-008)
- **files**: `lib/Service/OnboardingProvisioningService.php`, `lib/Controller/OnboardingController.php`, `appinfo/routes.php`, `tests/Unit/Service/`
- **acceptance_criteria**:
  - GIVEN a traject with linked ncAccount WHEN the griffie confirms groepen-toewijzen THEN the body-role-mapped NC groups are written via IGroupManager, the step records the verified group list, and RBAC scopes follow via the existing role→scope projection (no direct scope writes)
  - GIVEN an unresolved mapping or failed group write WHEN provisioning runs THEN the step stays not-completed with a named error — no partial success reported (fail-closed, unit-proven)
  - GIVEN an offboarding groepen-intrekken confirmation WHEN executed THEN mapped groups are removed, the Membership carries endDate = eindeDatum, unrelated groups are untouched, and the traject cannot reach `afgerond` while this step is open
  - GIVEN a non-griffie member WHEN calling the provisioning/revocation endpoints THEN the request is rejected (per-body guard, correct auth attributes — no-admin-idor/semantic-auth gates pass)
- [ ] Implement
- [ ] Test

### Task 6: Induction pack delivery into the member's Files
- **spec_ref**: `openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files`
- **files**: `lib/Service/InductionPackService.php`, `lib/Controller/OnboardingController.php`, `tests/Unit/Service/`
- **acceptance_criteria**:
  - GIVEN a traject with linked ncAccount and a configured induction set WHEN the introductiepakket step is triggered THEN a folder with the deliverable documents appears in the member's Files (FileService folder-package pattern) and the step completes with the folder as reference
  - GIVEN an undeliverable item WHEN delivery runs THEN remaining items are delivered and the result lists the skipped item (skip-report, never silent); GIVEN no linked ncAccount THEN delivery refuses with a message pointing at the account-koppeling step
- [ ] Implement
- [ ] Test

### Task 7: Raadswisseling batch orchestration — diff, suggestion list, griffie confirm
- **spec_ref**: `openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import`
- **files**: `lib/Service/RaadswisselingService.php`, `lib/Controller/OnboardingController.php`, `src/dialogs/`, `src/components/`, `tests/Unit/Service/`
- **acceptance_criteria**:
  - GIVEN a completed Member Import and current active memberships WHEN the griffie starts a raadswisseling run THEN a suggestion list shows onboarding suggestions for new persons and offboarding suggestions for departed members, and nothing is created yet
  - GIVEN the griffie deselects suggestions and confirms THEN only confirmed suggestions become trajecten sharing one batch label; the run itself never creates or end-dates a Membership; a suggestion invalidated between diff and confirm is rejected, not double-applied (TOCTOU re-validation)
  - GIVEN a 45-member body WHEN the diff runs THEN it completes interactively without N+1 object reads (bulk queries)
- [ ] Implement
- [ ] Test

### Task 8: E2E coverage — Playwright scenarios for onboarding, offboarding, and the batch run
- **spec_ref**: `openspec/changes/member-onboarding/specs/member-onboarding/spec.md`
- **files**: `tests/e2e/`
- **acceptance_criteria**:
  - GIVEN gate-19 WHEN it scans the changed spec THEN every scenario is referenced by an e2e test or carries a reason-bearing `@e2e exclude` (backend-only invariants excluded to PHPUnit as appropriate)
  - GIVEN the seeded environment WHEN the e2e suite runs THEN create → beediging → provision → pack → afgerond (onboarding), end-date → revoke → exit-confirm (offboarding, incl. the completion block while revocation is open), and diff → deselect → confirm (batch) pass end-to-end through the UI
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`); revocation and fail-closed provisioning mutation-guarded (tests flip on behaviour change, no fake green)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Feature documentation updated in `docs/features/member-onboarding.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added; i18n keys in English (ADR-005/ADR-007)
- Hydra gates pass on register+manifest changes (incl. 18 notification-dialect, 28/30/51/52 manifest/slug/lifecycle/spec-anchors)
- `openspec validate` passes

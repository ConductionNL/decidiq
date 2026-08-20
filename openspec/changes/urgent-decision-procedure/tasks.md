# Tasks: urgent-decision-procedure

## Implementation Tasks

### Task 1: Register — Decision urgency fields, awaitingRatification calculation, Meeting deviation fields, notification rules
- **spec_ref**: `openspec/changes/urgent-decision-procedure/specs/decision-management/spec.md#requirement-decision-urgency-fields`, `openspec/changes/urgent-decision-procedure/specs/decidesk-notifications/spec.md#requirement-urgency-notification-rules-in-the-verified-dialect`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register after import WHEN the `decision` schema is inspected THEN `isUrgent`, `urgencyReason`, `urgencyDeclaredBy`, `urgencyDeclaredAt`, and the `awaitingRatification` calculation exist and the `x-openregister-lifecycle` block is unchanged
  - GIVEN the `meeting` schema WHEN inspected THEN `shortenedNotice`, `actualNoticeHours`, `noticeDeviationReason` exist as optional properties
  - GIVEN the `decision` notifications WHEN inspected THEN `urgentDecisionDeclared` (updated/isUrgent) and `urgentRatificationDue` (scheduled/awaitingRatification) exist in the verified dialect with `{nl,en}` subjects and no `kind:field` recipients
- [ ] Implement
- [ ] Test

### Task 2: ProcessTemplate urgencyPolicy fragment + fail-closed validation
- **spec_ref**: `openspec/changes/urgent-decision-procedure/specs/process-configuration/spec.md#requirement-per-template-urgency-policy`
- **files**: `lib/Settings/register.d/46-urgency-policy.json`, `lib/Service/ProcessTemplateService.php`
- **acceptance_criteria**:
  - GIVEN a template save with `responseDeadlineHours={min:96,max:12}` WHEN validated THEN HTTP 400 naming the inverted bounds
  - GIVEN `ratificationRequired=true` without `ratifyingBody` WHEN validated THEN the save is rejected
  - GIVEN a body whose template has no `urgencyPolicy` WHEN the urgent procedure is attempted THEN it is unavailable (fail closed)
- [ ] Implement
- [ ] Test

### Task 3: Urgency trigger — guard, endpoint, field guard, audit
- **spec_ref**: `openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration`
- **files**: `lib/Lifecycle/UrgencyTriggerGuard.php`, `lib/Controller/DecisionController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authorised chair WHEN POST `/api/decisions/{id}/urgency` with a reason THEN urgency fields are stored, the audit trail records the declaration, and lifecycle is unchanged
  - GIVEN a non-chair (role not in `allowedTriggerRoles`) or an unresolvable role WHEN triggering THEN 403 and no fields change
  - GIVEN a direct OR object update setting `isUrgent` WHEN processed THEN the urgency-field write is rejected
  - GIVEN an unrelated title edit on an urgent decision WHEN saved THEN urgency fields survive (PUT-semantic carry-forward)
- [ ] Implement
- [ ] Test

### Task 4: Ratification orchestration — stage auto-append + agenda placement + reversal linking
- **spec_ref**: `openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-004-mandatory-ratification-stage-is-auto-appended`, `…#req-005-ratification-outcome-confirms-or-reverses`
- **files**: `lib/Service/UrgentRatificationService.php`
- **acceptance_criteria**:
  - GIVEN a completed urgency declaration WHEN `ratificationRequired=true` THEN a `stageType=ratifying` stage assigned to the configured (or overridden) ratifying body is appended with the next sequence and counted by route progress
  - GIVEN a scheduled `regular` meeting of the ratifying body WHEN the stage is appended THEN a linked AgendaItem is created on it; GIVEN none WHEN appended THEN the placement is recorded pending and retried when a qualifying meeting is created
  - GIVEN a ratifying stage decided `rejected` WHEN the reversal decision with `repeals` is recorded THEN the urgent decision's `effectiveStatus` derives to `repealed` with lifecycle and audit unchanged
- [ ] Implement
- [ ] Test

### Task 5: Expedited written round with bounded hours deadline
- **spec_ref**: `openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-003-expedited-variant-b--written-round-with-an-hours-based-response-deadline`
- **files**: `lib/Controller/DecisionController.php`, `lib/Service/UrgentRatificationService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN bounds `{min:4,max:72}` WHEN POST `/api/decisions/{id}/expedited-round` with 24 THEN a VotingRound with `votingDeadline` = open+24h is created linked to the decisive stage, outcome derivation unchanged
  - GIVEN a 1-hour deadline WHEN requested THEN 422 naming the bounds and no round is created
- [ ] Implement
- [ ] Test

### Task 6: Emergency convocation — shortened-notice deviation recording + floor enforcement
- **spec_ref**: `openspec/changes/urgent-decision-procedure/specs/meeting-management/spec.md#requirement-shortened-notice-deviation-recording-for-emergency-convocations`
- **files**: `lib/Service/BoardMeetingService.php`, `src/services/noticeRules.js`
- **acceptance_criteria**:
  - GIVEN an `extraordinary` meeting 72h out with floor 48h WHEN the convocation is sent confirming shortened notice THEN per-recipient deliveries are recorded plus `shortenedNotice=true`, `actualNoticeHours=72`, and the deviation reason
  - GIVEN a meeting 24h out with floor 48h WHEN sending THEN the send is rejected naming the floor
  - GIVEN a `regular` meeting WHEN sent late THEN the existing overdue warning applies and no deviation can be recorded
- [ ] Implement
- [ ] Test

### Task 7: UI — declare-urgency dialog, banner, list badge/filter, dashboard KPI
- **spec_ref**: `openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-006-awaiting-ratification-indicators-and-dashboard-kpi`
- **files**: `src/dialogs/DeclareUrgencyDialog.vue`, `src/dialogs/ExpeditedRoundDialog.vue`, `src/components/decision/UrgencyBanner.vue`, decision list + dashboard views
- **acceptance_criteria**:
  - GIVEN an urgent decision awaiting ratification WHEN its detail opens THEN the "urgent — awaiting ratification" banner (icon + text, CSS variables, lifecycle badge still visible) names the ratifying body
  - GIVEN the decision list WHEN rendered THEN urgent rows carry a badge and an `awaitingRatification` filter is offered
  - GIVEN the dashboard WHEN loaded THEN the KPI counts `awaitingRatification=true` decisions and navigates to the pre-filtered list
- [ ] Implement
- [ ] Test

### Task 8: Seed data — municipal + corporate urgent procedures end-to-end
- **spec_ref**: `openspec/changes/urgent-decision-procedure/design.md#seed-data`
- **files**: `lib/Settings/decidesk_register.json` (x-openregister-seeds), `lib/Settings/register.d/46-urgency-policy.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register is listed THEN the municipal spoedbesluit (awaiting ratification, emergency `extraordinary` meeting with recorded deviation, ratification AgendaItem on the `regular` raadsvergadering) and the corporate urgent resolution (expedited 24h written round, ratified by RvC) exist per the design tables
  - GIVEN the seeded process templates WHEN inspected THEN both carry a valid `urgencyPolicy`
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — guard matrix (authorised/unauthorised/unresolvable), bounds/floor validation, calculation derivation, PUT carry-forward
- New/changed API endpoints covered by Newman/Postman tests (403/422 contracts, direct-write rejection)
- UI changes covered by Playwright browser tests (banner, badge/filter, KPI navigation, dialogs)
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean
- Hydra gates: route-auth + route-reachability for the new endpoints, orphan-auth (guard must be invoked), notification-dialect on the register JSON, manifest/schema refs by slug
- Feature documentation updated in `docs/` (ADR-010) with a screenshot of the awaiting-ratification banner
- Dutch (`nl_NL`) and English (`en_US`) strings for all new user-facing text (ADR-005/ADR-007); i18n keys in English
- `openspec validate` passes

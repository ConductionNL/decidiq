# Tasks: urgent-decisions-register

Build after `urgent-decision-procedure` (it adds the declaration endpoint, `UrgencyTriggerGuard` and the ratification stage this change hooks into).

## Implementation tasks

### Task 1: Fields
- **spec_ref**: `openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-020-the-declaration-records-the-declaring-body-or-office`
- **files**: `lib/Settings/register.d/128-urgent-decisions-register.json` (next free number at build time, `slug: decision`)
- **acceptance_criteria**:
  - GIVEN the import WHEN `decision` is read THEN `urgencyDeclaredByBody` (`$ref` governance-body), `urgencyDeclaredInOffice` (string) and `reportedToRatifyingBodyAt` (date) exist, optional, and the import log has no `PARTIAL IMPORT`
  - the three fields join the server-guarded urgency fields: a direct object update that sets them is rejected, every decision write carries them forward
- [ ] Implement
- [ ] Test

### Task 2: Declaring body on the declaration
- **spec_ref**: `#requirement-req-020-the-declaration-records-the-declaring-body-or-office`
- **files**: `lib/Lifecycle/UrgencyTriggerGuard.php`, `lib/Controller/DecisionController.php` (from urgent-decision-procedure), `src/dialogs/DeclareUrgencyDialog.vue`
- **acceptance_criteria**:
  - GIVEN a declaration without a body WHEN posted THEN 422 naming the field
  - GIVEN the mayor's declaration WHEN stored THEN body and office are saved
- [ ] Implement
- [ ] Test

### Task 3: Report action and automatic first report date
- **spec_ref**: `#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once`
- **files**: `lib/Controller/DecisionController.php` (`POST /api/decisions/{id}/urgency/reported`), `appinfo/routes.php`, `lib/Service/UrgentRatificationService.php`, raadsinformatiebrief link listener
- **acceptance_criteria**:
  - GIVEN no report date WHEN the action, the agenda placement or a linked raadsinformatiebrief happens THEN the date is set; GIVEN a date WHEN any of them happens again THEN it stays
  - GIVEN a caller outside the allowed roles WHEN the action is called THEN 403 (with an OCS controller: refuse with a non-403 code plus `error`, see OCS middleware)
- [ ] Implement
- [ ] Test (through the real controller and middleware)

### Task 4: List per board DcSpoedbesluiten
- **spec_ref**: `#requirement-req-022-the-register-reads-as-the-board-draws-it`
- **files**: `src/manifest.d/urgent-decision-procedure.json`, a subtitle cell renderer if the shared index lacks a two-line cell, `l10n/`
- **acceptance_criteria**:
  - GIVEN the example set WHEN `/urgent-decisions` opens THEN columns, pills, quick filters with counts, the count line, Downloaden and Bekijken match the board
  - Pills carry text, colours through CSS variables
- [ ] Implement
- [ ] Test (one Playwright test on the five example decisions)

### Task 5: Example data
- **files**: `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**: the five decisions of the board exist with their bodies, dates, ratification state and lifecycle
- [ ] Implement
- [ ] Test

# Tasks: urgent-decisions-register

Build after `urgent-decision-procedure` (it adds the declaration endpoint, `UrgencyTriggerGuard` and the ratification stage this change hooks into).

## Implementation tasks

### Task 1: Fields
- **spec_ref**: `openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-020-the-declaration-records-the-declaring-body-or-office`
- **files**: `lib/Settings/register.d/128-urgent-decisions-register.json` (next free number at build time, `slug: decision`)
- **acceptance_criteria**:
  - GIVEN the import WHEN `decision` is read THEN `urgencyDeclaredByBody` (`$ref` governance-body), `urgencyDeclaredInOffice` (string) and `reportedToRatifyingBodyAt` (date) exist, optional, and the import log has no `PARTIAL IMPORT`
  - the three fields join the server-guarded urgency fields: a direct object update that sets them is rejected, every decision write carries them forward
- [x] Implement
- [x] Test
- Built (20 Nov): fragment `lib/Settings/register.d/135-urgent-decisions-register.json` (128 was taken by then; properties only, so no schema version moves, as in 134-decision-authority-basis). The three fields joined `UrgencyDeclarationService::URGENCY_FIELDS`, which `UrgencyFieldGuardListener` guards and carries forward. Tests: `tests/Unit/Settings/UrgentDecisionsRegisterTest.php` (fields, optional, guarded) and `UrgencyFieldGuardListenerTest::testTheRegisterFieldsAreGuardedAndCarriedForward` (a direct change or clear of any of the three is refused, an edit that leaves them out carries them). The import log check needs a live instance.

### Task 2: Declaring body on the declaration
- **spec_ref**: `#requirement-req-020-the-declaration-records-the-declaring-body-or-office`
- **files**: `lib/Lifecycle/UrgencyTriggerGuard.php`, `lib/Controller/DecisionController.php` (from urgent-decision-procedure), `src/dialogs/DeclareUrgencyDialog.vue`
- **acceptance_criteria**:
  - GIVEN a declaration without a body WHEN posted THEN 422 naming the field
  - GIVEN the mayor's declaration WHEN stored THEN body and office are saved
- [x] Implement
- [x] Test
- Built (20 Nov): `UrgencyDeclarationService::declare()` takes `declarer` (`body`, `office`) and refuses a declaration without a body with 422 naming `urgencyDeclaredByBody`, after the role check; an office over 255 characters is refused. The controller reads `urgencyDeclaredByBody` and `urgencyDeclaredInOffice` from the request. `UrgencyTriggerGuard` needed no change. `DeclareUrgencyDialog` asks for the body (defaulting to the decision's meeting body) and the office; the payload is built by `declarationBody()` in `src/services/urgency.js`. Tests: `UrgencyDeclarationServiceTest` (no body, role before body, office stored or left out), `UrgentProcedureControllerTest`, `tests/vitest/urgentDecisionsRegister.spec.js`.

### Task 3: Report action and automatic first report date
- **spec_ref**: `#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once`
- **files**: `lib/Controller/DecisionController.php` (`POST /api/decisions/{id}/urgency/reported`), `appinfo/routes.php`, `lib/Service/UrgentRatificationService.php`, raadsinformatiebrief link listener
- **acceptance_criteria**:
  - GIVEN no report date WHEN the action, the agenda placement or a linked raadsinformatiebrief happens THEN the date is set; GIVEN a date WHEN any of them happens again THEN it stays
  - GIVEN a caller outside the allowed roles WHEN the action is called THEN 403 (with an OCS controller: refuse with a non-403 code plus `error`, see OCS middleware)
- [ ] Implement
- [ ] Test (through the real controller and middleware)
- Built (20 Nov), box left open: `POST /api/decisions/{decisionId}/urgency/reported` (`UrgentProcedureController::reported`, `UrgentReportService`), guarded by the same roles as the declaration through `UrgencyTriggerGuard::mayTrigger` (403 before anything about the decision is revealed; 409 when not urgent; a second call keeps the first date). `RatificationPlacedReportListener` sets the date when an agenda item with `ratifiesDecision` is saved on a meeting, whichever path placed it, and never overwrites. The banner offers "Mark as reported to the ratifying body" and shows the date. Tests: `UrgentReportServiceTest`, `RatificationPlacedReportListenerTest`, `UrgentProcedureControllerTest`. Open: (a) the information-letter trigger. `raadsinformatiebrief` is retired by documents-as-agenda-items, and a letter (an agenda item of a letter kind) has no relation to a decision to listen to; see the question in the PR. (b) the test through the real controller and middleware needs a live instance.

### Task 4: List per board DcSpoedbesluiten
- **spec_ref**: `#requirement-req-022-the-register-reads-as-the-board-draws-it`
- **files**: `src/manifest.d/urgent-decision-procedure.json`, a subtitle cell renderer if the shared index lacks a two-line cell, `l10n/`
- **acceptance_criteria**:
  - GIVEN the example set WHEN `/urgent-decisions` opens THEN columns, pills, quick filters with counts, the count line, Downloaden and Bekijken match the board
  - Pills carry text, colours through CSS variables
- [ ] Implement
- [ ] Test (one Playwright test on the five example decisions)
- Written, not run (20 Nov): `tests/e2e/urgent-decisions-register.spec.ts` (quick-filter counts, filter on waiting, the line under the title, the report action, download). Left: the list as the board draws it (board design work) and running it on an instance.

### Task 5: Example data
- **files**: `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**: the five decisions of the board exist with their bodies, dates, ratification state and lifecycle
- [x] Implement
- [x] Test
- Built (20 Nov): the municipality example set now holds five urgent decisions, each with its declaring body (`college-van-b-en-w-amsterdam`), a decisive and a ratifying stage: Noodopvang statushouders sporthal De Vliet (reported 4 June, awaiting), Noodverordening Havenfestival 2026 (office Burgemeester, reported 6 October, awaiting), Tijdelijke opvang in sporthal De Linde (not yet reported, awaiting), Aanwijzing noodlocatie bij hoogwater Amstel (office Burgemeester, ratified) and Spoedherstel brug Lindengracht (ratified). `objectCount` is 302. Test: `UrgentDecisionsRegisterTest::testTheExampleSetHoldsFiveUrgentDecisions` (five, three awaiting, bodies resolve, each fits the schema).

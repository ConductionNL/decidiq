# Tasks: platform-case-system-document-exchange

## Implementation tasks

### Task 1: Connection, case link and exchange record
- **spec_ref**: `openspec/changes/platform-case-system-document-exchange/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case`
- **files**: `lib/Settings/connections.json` (`case-system`), `lib/Settings/register.d/92-case-system-exchange.json` (`AgendaItem.caseReference`, `CaseExchangeRecord`, register `schemas` list), `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN hydra gate `connections-declaration` WHEN it runs THEN it passes
  - GIVEN the descriptor test WHEN it runs THEN `case-exchange-record` is attached
- [x] Implement
- [x] Test

### Task 2: Resolve and store the case link
- **spec_ref**: `openspec/changes/platform-case-system-document-exchange/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection`
- **files**: `lib/Service/CaseSystemClient.php` (the four integriq operations), `lib/Service/CaseDocumentService.php`, `lib/Controller/CaseSystemController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN no linked source WHEN any case route is called THEN 409 (PHPUnit, red then green)
  - GIVEN an unknown case number WHEN linked THEN refused with the number named (PHPUnit, stubbed integriq)
  - GIVEN hydra gates `route-auth`, `no-admin-idor`, `route-reachability` WHEN they run THEN they pass
- [x] Implement
- [x] Test

### Task 3: Fetch case documents onto an item
- **spec_ref**: `openspec/changes/platform-case-system-document-exchange/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each`
- **files**: `lib/Service/CaseDocumentService.php`, `src/modals/CaseDocumentsModal.vue`, `src/manifest.json` (`AgendaItemDetail` action), `tests/e2e/case-system-exchange.spec.ts`
- **acceptance_criteria**:
  - GIVEN a document already fetched WHEN fetched again THEN skipped (PHPUnit)
  - GIVEN the integriq source in mock mode on the dev instance WHEN the griffier fetches two documents THEN both show in the item's Documents widget (Playwright)
- [x] Implement
- [x] Test

### Task 4: The decision list document
- **spec_ref**: `openspec/changes/platform-case-system-document-exchange/specs/case-system-exchange/spec.md#requirement-req-csdx-004-approving-the-minutes-produces-a-decision-list-document`
- **files**: `lib/Service/DecisionListService.php`, the minutes transition path in `lib/Controller/MinutesController.php`
- **acceptance_criteria**:
  - GIVEN three decisions and two signers WHEN minutes reach `signed` THEN the rendered HTML holds all three and both signers (PHPUnit, red then green)
  - GIVEN filinq absent WHEN rendered THEN the HTML is saved with a note, as `MinutesDocumentService` does
- [x] Implement
- [x] Test

### Task 5: Assemble and send the meeting file
- **spec_ref**: `openspec/changes/platform-case-system-document-exchange/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval`
- **files**: `lib/Service/MeetingFileService.php`, `lib/Service/CaseSystemExchangeService.php`, `lib/BackgroundJob/SendMeetingFileJob.php`, `appinfo/info.xml` if the job needs registering, `lib/Controller/CaseSystemController.php`, `src/components/tabs/MinutesDocumentTab.vue`, admin setting `caseSystemSendOnApproval`
- **acceptance_criteria**:
  - GIVEN minutes in `review` WHEN send is called THEN 422
  - GIVEN approved minutes WHEN the job runs with a stubbed integriq THEN item decisions go to the item's case and the full file to one meeting case, each line `sent` (PHPUnit)
  - GIVEN a confidential document WHEN sent THEN its request carries the flag and ground (PHPUnit)
- [x] Implement
- [x] Test

### Task 6: The Case system widget and "Send again"
- **spec_ref**: `openspec/changes/platform-case-system-document-exchange/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request`
- **files**: `src/components/tabs/MeetingCaseSystemTab.vue`, `src/registry.js`, `src/manifest.json` (`MeetingDetail` widget, layout, slot), `tests/e2e/case-system-exchange.spec.ts`
- **acceptance_criteria**:
  - GIVEN the seeded record with one failed line WHEN "Send again" is pressed THEN only that line is resent (PHPUnit on the service, Playwright on the widget)
- [x] Implement
- [x] Test

### Task 7: Strings and docs
- Dutch and English strings for the actions, statuses and refusals (`test:l10n`, `check:schema-l10n` green).
- `docs/features/case-system-exchange.md` (new): linking the case system in integriq, linking an item, fetching, what the meeting file holds and when it is sent.
- [x] Implement
- [x] Test

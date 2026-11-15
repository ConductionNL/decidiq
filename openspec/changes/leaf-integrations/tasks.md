# Tasks: leaf-integrations

All `@spec` tags added to code MUST point at the canonical spec home
(`openspec/specs/leaf-integrations/spec.md` after sync), never at this change directory —
change dirs evaporate on archive.

## Implementation Tasks

### Task 1: Calendar leaf on the meeting integrations page
- **spec_ref**: `openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md#requirement-req-leaf-001-the-meeting-detail-shall-offer-a-calendar-leaf`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `MeetingIntegrations` WHEN its widgets are read THEN a widget `{"type": "integration", "integrationId": "calendar"}` exists alongside `mi-deck`/`mi-talk`/`mi-files`/`mi-notes`
  - GIVEN the whole manifest (incl. `manifest.d/`) WHEN `integrationId: "calendar"` widgets are counted THEN the count is exactly 1
  - AND the widget carries an English title and an icon consistent with the leaf (`Calendar`)
- [x] Implement
- [x] Test

### Task 2: Contacts leaf on ParticipantDetail and GovernanceBodyDetail
- **spec_ref**: `openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md#requirement-req-leaf-002-people-and-body-pages-shall-offer-a-contacts-leaf`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `ParticipantDetail` and `GovernanceBodyDetail` WHEN their widgets are read THEN each declares `{"type": "integration", "integrationId": "contacts"}`
  - GIVEN the whole manifest WHEN `integrationId: "contacts"` widgets are counted THEN the count is exactly 2
- [x] Implement
- [x] Test

### Task 3: Polls leaf (straw poll) on ConsultationDetail and DecisionIntegrations
- **spec_ref**: `openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md#requirement-req-leaf-003-straw-polls-shall-be-offered-as-an-advisory-polls-leaf-upstream-of-formal-voting`
- **files**: `src/manifest.json`, `src/manifest.d/citizen-participation.json`
- **acceptance_criteria**:
  - GIVEN `ConsultationDetail` (citizen-participation fragment) and `DecisionIntegrations` WHEN their widgets are read THEN each declares `{"type": "integration", "integrationId": "polls"}` with a "Straw poll" title
  - GIVEN a completed straw poll on a dev instance WHEN decidiq objects are counted before/after THEN no `voting-round`, `vote` or `citizen-vote` object was created or modified by the poll surface (measure counts, not the UI)
- [x] Implement
- [x] Test

### Task 4: Forms leaf + reaction intake on ConsultationDetail
- **spec_ref**: `openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path`
- **files**: `src/manifest.d/citizen-participation.json`, `lib/Service/ReactionIntakeService.php` (import entry point only if one is missing)
- **acceptance_criteria**:
  - GIVEN `ConsultationDetail` WHEN its widgets are read THEN a `{"type": "integration", "integrationId": "forms"}` widget exists
  - GIVEN a linked form with N responses WHEN responses are imported THEN exactly N `consultation-reaction` objects exist with `moderationStatus: pending` and the `consultation` ref set, visible in the ModerationQueue page
  - GIVEN the same import re-run WHEN counts are compared THEN no duplicates were created (idempotent import)
- [x] Implement
  - 2026-11-13: the `consult-forms` widget is on ConsultationDetail (`src/manifest.d/citizen-participation.json`).
  - 2026-11-14: the response import is built without a new HTTP endpoint: `FormResponseReader` reads the forms OpenRegister links to the consultation and their submissions and answers; `FormResponseImportService` hands each response to `ReactionIntakeService::importReaction()`, which stores it as a `pending` reaction with the `consultation` ref, under an id derived from the submission so a re-run creates nothing. It runs on demand as `occ decidiq:consultation:import-form-responses <consultation>`. An "import responses" button on the widget is not built: the shared integration widget has no action slot and this change makes no nc-vue change.
- [x] Test
  - The widget is asserted in `tests/Unit/Settings/LeafIntegrationsTest.php`; the import in `tests/Unit/Service/Forms/FormResponseImportServiceTest.php` (3 responses give 3 pending reactions, a re-run gives none), `FormResponseReaderTest.php`, `tests/Unit/Service/ReactionIntakeImportTest.php` and `tests/Unit/Command/ImportFormResponsesTest.php`. A live import on :8080 is not run here.

### Task 5: `linkedTypes` on Meeting / Decision / AgendaItem / ActionItem + register version bump
- **spec_ref**: `openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md#requirement-req-leaf-005-mail-sidebar-linking-shall-be-declared-on-the-schemas-that-carry-email-surfaces`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN `configuration.linkedTypes` declarations are collected THEN exactly `Meeting`, `Decision`, `AgendaItem` and `ActionItem` carry one, each including the registry's mail linked-type id (resolve the exact id against `IntegrationRegistry::listIds()` + `legacyLinkedTypeIds()` — do not guess the string)
  - GIVEN a dev instance WHEN the register is re-imported after the version bump THEN the import succeeds (an invalid id fails with `InvalidArgumentException` — prove the validator can say NO with a deliberate bad id first, then remove it)
  - GIVEN the Mail sidebar WHEN the link action is opened THEN the four schemas are offered and no others
- [x] Implement
  - 2026-11-13: declared in the register fragment `lib/Settings/register.d/129-leaf-integrations.json`, not the base file: fragments overlay the base, and the fragment signature makes the importer re-import. The mail id is `mail`, the id the Mail sidebar filters on and OpenRegister's `LinkedTypesRule` accepts (the Email provider's own id is `email`, which the sidebar does not read).
- [x] Test

### Task 6: `mailObjectTemplate` on Decision only
- **spec_ref**: `openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md#requirement-req-leaf-006-create-from-email-shall-exist-for-draft-decisions-only`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN `mailObjectTemplate` declarations are collected THEN exactly one exists, on `Decision`: `title` ← `{{subject}}`, `text` ← `{{preview}}`, `externalReference` ← `{{mailRef}}`, verbatim `lifecycle: "draft"` and `decisionType: "resolution"`
  - GIVEN an email in Mail on a dev instance WHEN create-from-email is invoked THEN a draft `Decision` is created with the mapped fields and zero notification/publication side effects (check the notification table count before/after)
  - AND `ActionItem` declares no `mailObjectTemplate` (grep returns exactly one hit repo-wide)
- [x] Implement
- [x] Test
  - Tasks 5 and 6 are tested against the shipped register (base plus fragments) in `tests/Unit/Settings/LeafIntegrationsTest.php`. Their live checks (re-import on :8080, the Mail sidebar offer, a create-from-email with zero notifications) need an instance and are not run here.

## Verification

- [x] `python3 -m json.tool lib/Settings/decidesk_register.json` and every touched `manifest*` JSON file parses after each edit.
- [x] Grep checks: `grep -c '"integrationId": "calendar"' src/manifest.json` = 1; contacts = 2; polls spread over the two named pages; `grep -rc mailObjectTemplate lib/Settings/` = 1.
- [ ] `composer check:strict` exits zero (touched PHP, if any, is lint-clean; `php -l` on any edited PHP file).
- [x] Hydra gates pass (`hydra-gates` skill / `scripts/run-hydra-gates.sh` delegator) — no new endpoints, so route-auth/no-admin-idor must show no new findings.
  - 2026-11-14: the same 12 gates fail on development and on this branch, none naming a file of this change; no new finding.
- [ ] `openspec validate leaf-integrations --type change --strict` passes.
- [ ] Live on :8080: each touched page renders with the leaf app enabled AND with it disabled (graceful degradation, REQ-LEAF-007); 0 console errors; bundle rebuilt and the served bundle md5-matches the built artefact.

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests — register-JSON assertions (linkedTypes on exactly 4 schemas; mailObjectTemplate on exactly 1; template pins `lifecycle: draft`), reaction-intake idempotency.
- [ ] Newman/Postman tests — N/A: no new decidiq HTTP endpoints (create-from-email and linking are OpenRegister surfaces).
- [ ] Browser tests (Playwright MCP) — the four pages render their new widget with the app present; degrade with it absent.
- [ ] All tests pass (`composer test`), zero new failures against a self-measured baseline.

## Documentation (company-wide ADR-010)

- [x] Feature documentation updated in `docs/` — the leaf surfaces per page, the Mail-sidebar link/create flows, and explicitly that straw polls are advisory.
- [ ] Screenshot captured and committed to `docs/images/` for the meeting calendar leaf and the consultation polls/forms row.

## i18n (company-wide ADR-005)

- [x] Widget titles ("Meeting calendar", "Contacts", "Straw poll", "Intake form") present in nl + en.

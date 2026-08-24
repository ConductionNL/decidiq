# Tasks: document-accessibility-check

<!-- HYDRA CAP: 18 unindented `- [ ]` checkboxes (9 tasks x Implement/Test), under the 20 cap.
     Acceptance criteria are plain bullets, not checkboxes. -->

## Implementation Tasks

### Task 1: AccessibilityScanReport schema + declarative dialects in the register
- **spec_ref**: `openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-002-per-document-accessibility-status-stored-and-badged`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register import runs WHEN it completes THEN an `accessibility-scan-report` schema exists with fileId, fileName, sourceObject, governanceBody, status enum (pass/warnings/fail/not-scanned), findings[], scannerVersion, scannedAt
  - GIVEN the schema WHEN inspected THEN it carries `x-openregister-aggregations` (per-body/period status counts) and `x-openregister-notifications` (created + status==fail notifies the uploader) — no imperative dispatch (gate 18)
  - GIVEN `publication-record` WHEN inspected THEN it has the additive `accessibilityOverride` object (reason, actor, overriddenAt, reports[])
  - Schema references use slugs, not PascalCase (gates 28/30/51/52 pass)
- [ ] Implement
- [ ] Test

### Task 2: Seed data for the new schema and the override example
- **spec_ref**: `openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN a clean install WHEN seeds import THEN 4 `accessibility-scan-report` objects exist per the design Seed Data table (pass/fail/warnings/not-scanned) linked to seeded agenda items / bodies
  - GIVEN the seeds WHEN the aggregate report is opened THEN non-zero counts render without any manual data entry
  - One existing PublicationRecord seed carries an example `accessibilityOverride` with a Dutch reason
- [ ] Implement
- [ ] Test

### Task 3: DocumentAccessibilityScanService (imperative scanner, ADR-031 exception)
- **spec_ref**: `openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents`
- **files**: `composer.json` (smalot/pdfparser), `lib/Service/DocumentAccessibilityScanService.php`
- **acceptance_criteria**:
  - GIVEN a tagged PDF with /Lang, title, and text WHEN scanned THEN status `pass` with scanner version recorded
  - GIVEN an untagged scanned-image PDF WHEN scanned THEN status `fail` with `no-text-layer` and `not-tagged` findings carrying evidence
  - GIVEN a >20-page PDF without outlines WHEN scanned THEN a `no-bookmarks` warning finding
  - GIVEN a malformed or >25 MB file WHEN scanned THEN status `not-scanned` with `parse-failure`/`too-large` finding and no uncaught exception (fail-closed, never `pass`)
  - GIVEN a non-PDF attachment WHEN scanned THEN `not-scanned` with a size/type sanity note only
  - Report upsert supersedes the previous report for the same fileId via ObjectService (all fields carried forward — PUT semantics)
- [ ] Implement
- [ ] Test

### Task 4: Admin settings + scan-on-upload background job
- **spec_ref**: `openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning`
- **files**: `lib/Service/SettingsService.php`, `lib/BackgroundJob/DocumentAccessibilityScanJob.php`, admin settings Vue view
- **acceptance_criteria**:
  - GIVEN the admin settings page WHEN loaded THEN enforcement mode (off/warn/block, default warn) and scan-on-upload toggle (default on) are configurable and persist via IAppConfig
  - GIVEN scan-on-upload on WHEN a PDF is attached to an agenda item THEN a QueuedJob is queued and the report appears after the job runs
  - GIVEN scan-on-upload off WHEN a PDF is attached THEN no job is queued and the badge shows `not-scanned`
  - Admin settings component is NOT registered in the vue-router (gate: admin-router)
- [ ] Implement
- [ ] Test

### Task 5: On-demand scan endpoint
- **spec_ref**: `openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents`
- **files**: `lib/Controller/AccessibilityController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authorised user WHEN POST `/api/accessibility/scan` with sourceObject + fileId THEN 200 with the created/updated report
  - GIVEN a user without access to the source object WHEN they call the endpoint THEN 403 via OR per-object RBAC (no-admin-idor)
  - Route declares `#[NoAdminRequired]` auth posture (route-auth) and the method carries `@spec` tags (gate 16)
- [ ] Implement
- [ ] Test

### Task 6: Publication gate in the two publish chokepoints with recorded override
- **spec_ref**: `openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override`
- **files**: `lib/Service/AgendaService.php`, `lib/Service/PublicationService.php`, `lib/Service/DocumentAccessibilityScanService.php` (gate method), publish controllers (pass-through fields)
- **acceptance_criteria**:
  - GIVEN mode `block` and a `fail` attachment WHEN publish is requested without override (direct API, no UI) THEN 409 `accessibility-gate` payload listing the documents and nothing is published
  - GIVEN mode `block` WHEN an authorised user publishes with a non-empty override reason THEN publication proceeds and actor/reason/timestamp/report refs land on the PublicationRecord (agenda-only path: source audit trail)
  - GIVEN mode `warn` WHEN publish carries `accessibilityAcknowledged: true` THEN it proceeds; without it THEN 409 listing fail + not-scanned documents
  - GIVEN mode `off` WHEN publishing THEN behaviour is identical to pre-change `agenda-publication`/`public-publication` flows
  - GIVEN a `not-scanned` attachment at publish time WHEN the gate runs THEN it is scanned synchronously first (size-capped)
  - Gate method is invoked from both chokepoints — no defined-but-never-called auth/validation code (gate: orphan-auth)
- [ ] Implement
- [ ] Test

### Task 7: Accessibility badge + scan detail with remediation guidance
- **spec_ref**: `openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-004-remediation-guidance-per-finding`
- **files**: `src/components/AccessibilityBadge.vue`, attachment list integrations (agenda builder rows, Files tabs), scan detail panel
- **acceptance_criteria**:
  - GIVEN attachments with reports WHEN any attachment list renders THEN each shows its status badge (text + colour, CSS variables, heuristic-scan label)
  - GIVEN a `fail` badge WHEN clicked THEN the scan detail shows each finding with evidence and source-level fix guidance in the UI language (nl/en)
  - GIVEN an unscanned attachment WHEN viewed THEN a `not-scanned` badge with a working "scan now" action calling Task 5's endpoint
- [ ] Implement
- [ ] Test

### Task 8: Publication-gate dialog
- **spec_ref**: `openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override`
- **files**: `src/dialogs/AccessibilityGateDialog.vue`, publish flows in agenda builder + publication views
- **acceptance_criteria**:
  - GIVEN a 409 `accessibility-gate` response WHEN publishing from the UI THEN the dialog renders the server-provided document list, findings, and remediation guidance
  - GIVEN mode `warn` THEN the dialog offers acknowledge-and-publish; GIVEN mode `block` THEN it requires a non-empty override reason before the publish button enables
  - Dialog lives in its own file under `src/dialogs/` (modal-isolation) and is keyboard operable per accessibility-baseline
- [ ] Implement
- [ ] Test

### Task 9: Aggregate accessibility report per body/period + CSV export
- **spec_ref**: `openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period`
- **files**: `src/views/AccessibilityReport.vue`, navigation entry
- **acceptance_criteria**:
  - GIVEN seeded reports WHEN staff open the report for a body and period THEN pass/warnings/fail/not-scanned counts + percentages and the override count render from the declarative aggregation (no imperative counting endpoint)
  - GIVEN the report WHEN CSV export is clicked THEN a CSV downloads with the same figures and the heuristic-scan disclaimer
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — scanner checks, gate matrix (3 modes x fail/warn/not-scanned), override recording
- New/changed API endpoints covered by Newman tests — scan endpoint 200/403, publish 409 contract, mode `off` regression
- UI changes covered by Playwright browser tests — badge, gate dialog, report view; fixtures include one tiny tagged PDF and one scanned-image PDF
- All tests pass (`composer test`, `composer check:strict`, `newman run`)
- Feature documentation updated in `docs/` (ADR-010) including the honest heuristic-scan disclaimer
- Dutch (`nl_NL`) and English (`en_US`) strings for statuses, findings, remediation guidance, dialogs (ADR-005; i18n keys in English)
- `openspec validate` passes

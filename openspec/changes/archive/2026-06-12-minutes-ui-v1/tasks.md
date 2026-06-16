# Tasks — minutes-ui-v1

## 1. Schema (additive)

- [x] 1.1 Add `itemNotes`, `corrections`, `reviewComments`, `generatedDocuments`
      properties to the Minutes schema in `lib/Settings/decidesk_register.json`.

## 2. Backend services

- [x] 2.1 `MinutesGenerationService::reject()` — review → draft with mandatory
      comment, appended to `reviewComments` with server-side author attribution.
- [x] 2.2 `MeetingFolderService::writeMeetingFile()` — ensure tree + write file
      content into a named subfolder, fail-soft null on Files unavailability.
- [x] 2.3 NEW `MinutesDocumentService` — content resolution (content → draft
      fallback + itemNotes), markdown persistence, optional Docudesk PDF with
      graceful fallback, `generatedDocuments` record on the Minutes object.
- [x] 2.4 NEW `ProofPackageService` — convocation + quorum + votes + decisions
      package, SHA-256 integrity hash, JSON + markdown files in the meeting folder.

## 3. REST surface

- [x] 3.1 `MinutesController::addCorrection` (participant-gated, draft/review only,
      server-attributed author) + `resolveCorrection` (chair/secretary, accept/reject).
- [x] 3.2 `MinutesController::reject` (chair/secretary, mandatory comment).
- [x] 3.3 `MinutesController::generateDocument` (chair/secretary; format
      markdown|pdf; honest docudesk availability in the response).
- [x] 3.4 `MeetingController::proofPackage` (chair/secretary on the meeting,
      NC-admin fallback, fail closed).
- [x] 3.5 Register the six routes in `appinfo/routes.php` (before the catch-all).

## 4. Frontend

- [x] 4.1 `src/components/minutesEditor/minutesEditor.js` logic module — debounced
      autosave scheduler, itemNotes merge by agendaItem, dirty tracking,
      corrections state helpers.
- [x] 4.2 `src/components/minutesEditor/MinutesPanel.vue` — find/create draft
      minutes, per-agenda-item notes/decisions fields, action-item capture
      shortcut, autosave status line.
- [x] 4.3 Mount the panel in `src/views/LiveMeeting.vue` for chair/secretary
      (new `isSecretary` computed).
- [x] 4.4 `src/components/tabs/MinutesApprovalTab.vue` — lifecycle timeline,
      submit / approve / reject actions, corrections list + add/accept/reject.
- [x] 4.5 `src/components/tabs/MinutesDocumentTab.vue` — generate document
      (format select), generated-documents list, proof-package trigger.
- [x] 4.6 `src/modals/MinutesRejectModal.vue` + `src/modals/MinutesCorrectionModal.vue`.
- [x] 4.7 Manifest `MinutesDetail` sidebarTabs + `src/registry.js` entries.

## 5. Tests

- [x] 5.1 PHPUnit: `MinutesDocumentServiceTest`, `ProofPackageServiceTest`,
      `MinutesGenerationService::reject` cases, new `MinutesController` endpoint
      cases (200/400/403/404/409), `MeetingController::proofPackage` cases,
      `MeetingFolderService::writeMeetingFile` cases.
- [x] 5.2 vitest: `tests/vitest/minutesEditor.spec.js` (debounce, merge,
      dirty/flush, corrections state).
- [x] 5.3 Playwright: `tests/e2e/spec-coverage/resolution-minutes.spec.ts` with
      `@e2e` annotations + defensive skips.
- [x] 5.4 Newman: `tests/integration/decidesk-minutes.postman_collection.json`
      (lifecycle auth model: noauth collection + explicit basic per request +
      noAuthBase 401 probes), wired into `tests/newman/run-all.sh`.

## 6. i18n & docs

- [x] 6.1 English-source keys in `l10n/en.json`, `en_US.json`, `nl.json`
      (lossless merge).
- [x] 6.2 Spec delta `specs/resolution-minutes/spec.md` with honest `@e2e`
      annotations/excludes; main spec frontmatter updated on archive.

## 7. Verification

- [x] 7.1 `php -l` all changed PHP; PHPUnit unit suite green; vitest green;
      `npm run build` green; all 24 hydra gates green.

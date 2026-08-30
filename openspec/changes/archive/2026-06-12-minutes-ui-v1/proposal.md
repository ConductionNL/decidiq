---
kind: code
---

# Proposal: Minutes UIs v1 — Real-Time Editor, Approval Workflow, Document Generation, Notarial Proof

## Problem

The seeded spec `openspec/specs/resolution-minutes/spec.md` (status: partial) has a
complete backend (ResolutionService, WrittenResolutionService, MinutesGenerationService,
MinutesAuthorizationService, MinutesService — and PR #54's decision-state-machine
already generates resolution records on enact) but **no user surfaces** for:

- **Real-time minute-taking** — the live meeting view (`src/views/LiveMeeting.vue`)
  drives agenda, BOB phases, and hamerstukken but has no minutes panel; a secretary
  cannot record per-agenda-item notes/decisions during the meeting.
- **Digital approval workflow** — the lifecycle endpoints exist
  (`/transition`, `/submit-for-approval` plus `notifyApproversOnSubmit`), but the
  MinutesDetail page only shows the data widget + a Signers tab. There is no UI for
  submit-for-review / approve / reject, and no way for participants to suggest
  corrections at all (no schema field, no endpoint, no UI).
- **Minutes document generation** — `generateDraft` renders text into a preview but
  nothing persists a formatted document into the meeting's Files folder
  (MeetingFolderService from PR #56 creates the folder tree incl. a 'Minutes'
  subfolder that nothing writes into). No Docudesk pathway exists.
- **Notarial proof package** — the scenario "Provide proof of proper adoption for
  notarial deed" has no implementation at any layer.

## What Changes

### Backend (all additive)

- **MODIFIED** `lib/Settings/decidesk_register.json` — additive Minutes properties:
  `itemNotes` (per-agenda-item notes/decisions captured live), `corrections`
  (participant correction suggestions with status), `reviewComments`
  (reject-with-comment history), `generatedDocuments` (persisted document records).
- **MODIFIED** `lib/Service/MinutesGenerationService.php` — new `reject()` method
  (review → draft with mandatory comment, recorded in `reviewComments`).
- **NEW** `lib/Service/MinutesDocumentService.php` — renders the minutes content
  (falling back to the generated draft) and persists it into the linked meeting's
  Files folder ('Minutes' subfolder) as markdown; optional Docudesk PDF pathway with
  graceful fallback when Docudesk is not resolvable.
- **NEW** `lib/Service/ProofPackageService.php` — assembles the notarial evidence
  package (convocation record, quorum snapshot, votes tally, adopted decision /
  resolution texts) as structured JSON + human-readable markdown, with a SHA-256
  integrity hash, stored in the meeting folder.
- **MODIFIED** `lib/Service/MeetingFolderService.php` — additive
  `writeMeetingFile()` helper (ensure tree + write a file into a subfolder).
- **MODIFIED** `lib/Controller/MinutesController.php` — new endpoints:
  `addCorrection` (participant-gated), `resolveCorrection` (chair/secretary-gated),
  `reject` (chair/secretary-gated), `generateDocument` (chair/secretary-gated).
- **MODIFIED** `lib/Controller/MeetingController.php` — new `proofPackage` endpoint
  (chair/secretary-gated via ParticipantResolver, admin fallback).
- **MODIFIED** `appinfo/routes.php` — six new guarded routes.

### Frontend

- **NEW** `src/components/minutesEditor/MinutesPanel.vue` + logic module
  `minutesEditor.js` — minutes panel in the live meeting view: finds/creates the
  draft Minutes for the meeting, per-agenda-item note + decision fields, action-item
  capture shortcut, debounced autosave to the draft Minutes object via the shared
  object store.
- **MODIFIED** `src/views/LiveMeeting.vue` — mounts the minutes panel for
  chair/secretary participants.
- **NEW** `src/components/tabs/MinutesApprovalTab.vue` — lifecycle timeline +
  submit-for-review / approve / reject-with-comment actions, corrections list with
  add (participants) and accept/reject (chair/secretary).
- **NEW** `src/components/tabs/MinutesDocumentTab.vue` — generate document
  (markdown / PDF-via-Docudesk with honest fallback note), generated-documents list,
  and the notarial proof package trigger.
- **NEW** `src/modals/MinutesRejectModal.vue` + `src/modals/MinutesCorrectionModal.vue`
  (ADR-004 modal isolation).
- **MODIFIED** `src/manifest.json` — MinutesDetail gains the Approval (order 15) and
  Documents (order 30) sidebar tabs; **MODIFIED** `src/registry.js` accordingly.

### Tests & i18n

- PHPUnit for MinutesDocumentService, ProofPackageService, the new controller
  endpoints, and `MinutesGenerationService::reject`.
- vitest for the editor logic module (debounced autosave, itemNotes merge,
  corrections state).
- Playwright UI specs in `tests/e2e/spec-coverage/resolution-minutes.spec.ts` with
  `@e2e` traceability and defensive skips.
- Newman: NEW `tests/integration/decidesk-minutes.postman_collection.json`
  (decidesk-lifecycle auth model), wired into `tests/newman/run-all.sh`.
- i18n: English source keys; `l10n/en.json`, `en_US.json`, `nl.json`.

## Goals

1. All four spec requirements user-reachable and traceable (gate-16 `@spec`,
   gate-19 `@e2e`).
2. Fail-closed authorization on every new endpoint: participants read/suggest,
   chair/secretary approve/generate, mirroring MinutesAuthorizationService and the
   existing `requireChairOrAdminForMinutes` posture.
3. No hard dependency on Docudesk — the PDF pathway activates only when
   `OCA\DocuDesk\Service\PdfService` is resolvable; otherwise the plain document is
   produced and the response says so.

## Non-Goals

- ODT output (spec mentions PDF and ODT; ODT requires a renderer neither decidesk
  nor docudesk ships — recorded as a documented limitation in the spec delta).
- Server-side write-locking of approved minutes inside OpenRegister's generic object
  API (the OR object API has no lifecycle interceptor; the app's own endpoints
  enforce the sequence and the UI disables editing from `approved` onwards —
  documented limitation, same posture as the MeetingFolderService ACL note).
- Resolution generation from adopted decisions — already shipped by
  decision-state-machine-v1 (PR #54); this change does not duplicate it.
- Real tamper-evident sealing (qualified timestamps) — the proof package carries a
  SHA-256 integrity hash; QES sealing stays with the existing eIDAS flow.

## Impact

- Six new routes, all `#[NoAdminRequired]` with per-object guards.
- Additive schema changes only; existing Minutes objects remain valid.
- MeetingController constructor gains three collaborators (DI-injected; its unit
  test is updated in the same change).

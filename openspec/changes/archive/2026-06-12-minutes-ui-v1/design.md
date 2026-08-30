# Design — minutes-ui-v1

## Context

The resolution-minutes backend shipped across p2-minutes-and-decisions* and
board-meeting-resolutions; decision-state-machine-v1 (PR #54) generates resolution
records when a decision is enacted; nc-platform-integration-v1 (PR #56) added
MeetingFolderService which creates "Decidesk/<body>/<date> <title>/" with
"Agenda Documents" and "Minutes" subfolders. This change adds the user surfaces and
the two missing server capabilities (document persistence, proof package).

## Decisions

### D1 — Autosave goes through the OR object API, not a new endpoint

The live editor saves `itemNotes` on the draft Minutes object via
`useObjectStore().saveObject('minutes', …)` — the canonical decidesk write path.
Adding a pass-through controller method would trip the redundant-controller gate
(ADR-022). Lifecycle, corrections, rejection, document generation, and the proof
package DO get controller endpoints because each enforces server-side rules a plain
object write cannot (sequence validation, server-side author attribution,
role-gating, file-system side effects).

### D2 — itemNotes shape

`itemNotes` is an array of `{ agendaItem, notes, decisions }` objects (array of
objects rather than a map so the JSON-schema validates items without
additionalProperties tricks). The editor merges by `agendaItem` id. The generated
draft is unaffected (the template keeps its placeholder section); `generateDraft`
remains the meeting-data renderer while live notes are first-class data the
document service includes when rendering.

### D3 — Corrections are server-attributed

`POST /api/minutes/{id}/corrections` ignores any client-sent author: the entry is
built server-side from the authenticated session
(`{ id, author: uid, authorName, text, status: 'proposed', createdAt }`).
Guard: the caller must be a participant of the linked meeting
(`ParticipantResolver::isParticipant`) or chair/secretary/admin — fail closed when
the meeting cannot be resolved. Corrections are accepted while the lifecycle is
`draft` or `review` only; `resolveCorrection` (accept/reject) is chair/secretary/
admin-gated and records `resolvedBy`/`resolvedAt` server-side.

### D4 — Reject-with-comment is a guarded backward transition

`MinutesGenerationService::LIFECYCLE_TRANSITIONS` stays forward-only; rejection is a
separate `reject()` method: allowed only from `review`, returns to `draft`, requires
a non-empty comment, and appends `{ action: 'rejected', comment, author, createdAt }`
to `reviewComments`. This keeps the forward map intact (signing/eIDAS flows depend
on it) while satisfying the spec's review loop.

### D5 — Document generation: markdown is canonical, Docudesk PDF is opportunistic

`MinutesDocumentService::generate(minutesId, format, displayName)`:

1. Content = `minutes.content` when non-empty, else
   `MinutesGenerationService::generateDraft()` (which auto-inserts voting results),
   appended with the live `itemNotes` per agenda item.
2. `markdown` → write `<sanitised title> v<version> <date>.md` into the linked
   meeting's folder ('Minutes' subfolder) via
   `MeetingFolderService::writeMeetingFile()`.
3. `pdf` → try `container->get('OCA\DocuDesk\Service\PdfService')`. When resolvable,
   convert the markdown to minimal HTML (headings, bold, paragraphs — a private
   ~30-line converter, no dependency) and call `generatePdfFromHtml()`; write the
   returned bytes as `.pdf`. When Docudesk is absent or throws, **fall back to the
   markdown file** and return `docudeskAvailable: false` + a `note` so the UI can
   tell the user honestly.
4. Append a `{ path, format, generatedAt, generatedBy, docudesk }` record to
   `minutes.generatedDocuments` and save the object.

**Documented limitation**: ODT output is not implemented (no renderer available in
the stack); PDF/A and template styling are Docudesk's concern when present. The
spec scenario's "PDF and ODT" is therefore satisfied for PDF (conditionally) and
recorded as a gap for ODT in the spec delta — no fake stub.

### D6 — Proof package is meeting-scoped and hash-sealed

`ProofPackageService::assemble(meetingId, generatedBy)` collects:

- **convocation**: meeting title/type/scheduledDate/location, `@self.created`
  (when the meeting record was registered), the published agenda item list. When no
  explicit notice timestamp exists on the meeting record the package says
  `"noticeRecorded": false` — honest, not fabricated.
- **quorum**: `quorumRequired` from the meeting + participant roll with
  `attendanceStatus` per participant (present/remote/proxy counted as present),
  `met` boolean. When no attendance was recorded the snapshot carries
  `"attendanceRecorded": false`.
- **votes**: every voting round linked to the meeting with
  votesFor/votesAgainst/votesAbstain/result/quorumMet.
- **decisions**: adopted decisions (title, text, legalBasis, decisionDate,
  outcome, enactedAt) — the resolution texts in the council model.
- **integrity**: `sha256` over the canonical (sorted-key) JSON of the payload,
  plus generatedAt/generatedBy. Verification = recompute the hash over the
  `package` member. Qualified sealing stays with the eIDAS flow (Non-Goal).

Output: `Proof package <date>.json` + `.md` written to the meeting folder's
'Minutes' subfolder. Endpoint `POST /api/meetings/{id}/proof-package` is
chair/secretary-gated (ParticipantResolver::hasRole, NC-admin fallback), fail
closed.

### D7 — UI placement

- Live editor: a collapsible panel inside LiveMeeting.vue, rendered for
  chair/secretary participants (`isSecretary` mirrors the existing `isChair`
  computed). Component lives in `src/components/minutesEditor/MinutesPanel.vue`;
  pure logic (debounce, merge, dirty tracking) in `minutesEditor.js` for vitest.
- Approval + Documents: sidebar tabs on the existing manifest `MinutesDetail` page
  (component-tab pattern, same as MinutesSignersTab), registered in
  `src/registry.js`. Manifest fragments can only append pages/menu, so
  `src/manifest.json`'s MinutesDetail `sidebarTabs` is edited directly
  (union-merge note for concurrent PRs).
- Modals live in `src/modals/` (ADR-004).

### D8 — Authorization matrix

| Action | Guard |
| --- | --- |
| Read minutes / autosave itemNotes | OR object ACL (existing posture) |
| Add correction | meeting participant ∨ chair/secretary ∨ NC admin |
| Accept/reject correction | chair/secretary ∨ NC admin |
| Submit for review / transition / reject | chair/secretary ∨ NC admin (existing `requireChairOrAdminForMinutes`) |
| Generate document | chair/secretary ∨ NC admin |
| Proof package | chair/secretary on the meeting ∨ NC admin |

All guards fail closed (unresolvable meeting → 403, never skip).

## Risks

- **Deploy drift**: the dev container may serve an older decidesk; Playwright specs
  use defensive skips so absent surfaces report as skipped, not false-green.
- **Concurrent PR merges**: manifest.json / registry.js / decidesk_register.json /
  l10n are rebase hotspots → union-resolve, preserve both sides.
- **Docudesk API drift**: the optional pathway is wrapped in try/catch on a
  container lookup + method call; any failure degrades to markdown.

# Design: platform-case-system-document-exchange

Kind: code. One connection, two schema patches, one new schema, three services,
one queued job, two widgets. Read against decidiq `development` at 4d7430ff.

## What exists today

- **No case system code.** `grep -rniE 'stuf|zgw|zaken ?api'` in `lib` and
  `src` finds only a comment in `lib/Event/DecisionConcludedEvent.php:9`
  ("procest ZGW advance"), the consumer's side effect.
- **Provenance for openregister case apps.** `DecisionIntegrationService`
  (`lib/Service/DecisionIntegrationService.php:60`) copies `sourceApp`,
  `subjectRegister`, `subjectSchema`, `subjectId`, `subjectLabel`,
  `outcomeCallbackUrl` and `externalReference` onto a decision raised by
  another app.
- **The meeting's documents.** `ProofPackageService::assemble()`
  (`lib/Service/ProofPackageService.php:91`) writes convocation, quorum, votes
  and adopted decision texts with a SHA-256 seal into the meeting folder,
  called from `POST /api/meetings/{id}/proof-package` (`appinfo/routes.php:124`).
  `MinutesDocumentService::generate()` (`lib/Service/MinutesDocumentService.php:113`)
  writes the minutes PDF through filinq, `POST /api/minutes/{minutesId}/generate-document`
  (`appinfo/routes.php:120`). `MeetingPackageService::assemble()`
  (`lib/Service/MeetingPackageService.php:95`) builds the agenda paper package.
  The published agenda's versions are snapshots on `Meeting.agendaVersions`
  (`lib/Settings/register.d/91-agenda-publication-has-its-own-fields.json`).
- **Minutes.** `minutes` has `lifecycle` (`draft`, `review`, `approved`,
  `signed`, `published`), `approvedAt`, `signedBy` and `generatedDocuments`.
- **Confidentiality.** `ConfidentialityRestriction`
  (`lib/Settings/register.d/83-confidentiality-in-plain-words.json`) targets a
  document, an agenda item or a decision, with a `ground`.
- **PDF.** `FilinqPdf::fromHtml()` (`lib/Support/FilinqPdf.php:97`).
- **Connections.** `lib/Settings/connections.json`.

## D1. integriq speaks the protocol, decidiq names the intent

A `case-system` connection with a `sourceTemplate`. decidiq asks integriq's
call service, against the linked source, for four operations:

1. read a case (by address or case number);
2. list a case's documents;
3. add a document to a case, with a document kind (`agenda`, `item-document`,
   `decision`, `decision-list`, `minutes`, `proof-package`) and a
   confidentiality flag with its ground;
4. create a case for a meeting, with its title and date.

integriq maps a document kind to the organisation's informatieobjecttype and a
meeting to its zaaktype, for ZGW and for StUF-ZKN alike. That mapping is
integriq's half, to be specified in integriq, on the precedent of
`hand-woo-diwoo-to-integriq`: mapping an object onto a national standard is
integriq's responsibility.

## D2. The link on the agenda item

`AgendaItem.caseReference` in a new fragment
`lib/Settings/register.d/92-case-system-exchange.json`: `{url, identification,
title}`. The clerk sets it by pasting a case address or number; decidiq reads
the case once to fill `title` and prove the link resolves.

## D3. Fetching documents onto an item

`CaseDocumentService::list(agendaItemId)` returns the case's documents with a
flag for those already fetched. `fetch(agendaItemId, remoteUrls[])` copies them
into the item's folder and writes a `CaseExchangeRecord` line per document with
its remote address, so a second fetch skips it. Routes
`GET /api/agenda-items/{id}/case-documents` and
`POST /api/agenda-items/{id}/case-documents`, guarded by the agenda item
guard (`lib/Service/AgendaAuthorizationGuard.php`).

## D4. The decision list

`DecisionListService::render(meetingId)`: every decision of the meeting's
agenda items with its title, outcome and vote totals, headed with the meeting
and the minutes' `approvedAt` and `signedBy`, rendered through
`FilinqPdf::fromHtml()` into the meeting folder as `Besluitenlijst.pdf`. It is
made when minutes reach `approved`, and again on `signed` so the signers show.

## D5. The meeting file and the send

`MeetingFileService::assemble(meetingId)` returns the file list: the last
published agenda version rendered to PDF, each item's documents, the decision
list, the minutes PDF (from `generatedDocuments`, generated if absent), and the
proof package (assembled if absent). Refused before the minutes are
`approved`.

`CaseSystemExchangeService::sendMeetingFile(meetingId)` writes one
`CaseExchangeRecord` per target with a line per document in status `pending`
and enqueues `SendMeetingFileJob` (a `QueuedJob`, ADR-069). The job:

- for each item with `caseReference`: adds the item's decisions (and the
  decision list excerpt for the item) to that case;
- creates a case for the meeting once, and adds the whole file to it;
- marks each line `sent` with the remote address, or `failed` with the reason.

A confidential target (a restriction on the item, the document or the
decision) is sent with its flag and ground; nothing is dropped silently.

Trigger: "Send the meeting file to the case system" on `MinutesDocumentTab`
(`POST /api/meetings/{id}/case-system/send`, chair or secretary through
`TranscriptionStaffGuard::forMeeting()`), and an admin setting
`caseSystemSendOnApproval` that sends when minutes reach `approved`.

## D6. The record and its widget

`CaseExchangeRecord` (slug `case-exchange-record`): `meeting`, `agendaItem`,
`direction` (`fetch`, `send`), `target` (case address), `lines` (`{name,
fileId, kind, remoteUrl, status, error}`), `requestedBy`, `requestedAt`.
Staff read only.

A "Case system" widget on `MeetingDetail` (`src/manifest.json:561`) lists the
meeting's records with a count per status and "Send again" for failed lines.

## Declarative or imperative

- `caseReference`, `CaseExchangeRecord` and their RBAC: declared.
- The widget list: declared `object-list` over `case-exchange-record` filtered
  by meeting, with a thin custom action for "Send again".
- Fetching, sending and the decision list: imperative, under the ADR-031
  exceptions for external systems and for rendered documents.

## Seed data

In `lib/Settings/profiles/municipality.json`:

1. Agenda item "Vaststelling omgevingsvisie" with `caseReference`
   `{identification: "Z-2026-00412", url: "https://zaken.example.org/api/v1/zaken/00000000-0000-0000-0000-000000000000", title: "Omgevingsvisie 2040"}`.
2. A `CaseExchangeRecord` of direction `fetch` for that item with one line
   `sent`: "Raadsvoorstel omgevingsvisie.pdf".
3. A `CaseExchangeRecord` of direction `send` for the council meeting of 12
   March with five lines `sent` and one `failed`: "The case system refused the
   document: informatieobjecttype not configured".

## Risks

- A case system that is slow or down: the job retries a failed line only when
  the clerk presses "Send again", so a broken mapping does not hammer the case
  system every few minutes.

## Corrections at build (30 Sep 2026)

The design was read against 4d7430ff; the build on ef52ec1a changed these:

- **Fragment number.** `92-*` is taken (`92-citizen-advice-on-motions.json`);
  the fragment is `112-case-system-exchange.json`.
- **Five operations, not four.** Fetching a document needs its content, so
  decidiq also asks integriq to read one document. The contract decidiq sends,
  one POST per operation on the linked source: `/case-system/read-case`
  `{reference}` → `{url, identification, title}` (no `url` = not found);
  `/case-system/list-documents` `{case}` → `{documents: [{url, name}]}`;
  `/case-system/read-document` `{document}` → `{name, content (base64)}`;
  `/case-system/add-document` `{case, name, kind, content (base64),
  confidential, ground}` → `{url}`; `/case-system/create-case` `{kind:
  "meeting", title, date}` → `{url, identification}`. A status of 400 or more
  is a refusal whose `message` is shown. integriq's half (source template
  `zgw-zaken`, the ZGW and StUF-ZKN mapping) is drafted for integriq in
  `for-ruben/integriq-case-system-operations.md`.
- **Guard.** Every item and meeting action uses
  `TranscriptionStaffGuard::forMeeting()` on the item's meeting (chair,
  secretary or admin), after reading the item with the caller's own rights.
  `AgendaAuthorizationGuard::requireChairOrAdminForAgendaItem()` reads
  `@self.relations.meeting`, which an object entity does not carry.
- **Approval hook.** The decision list and the send on approval run from an
  `ObjectUpdatedEvent` listener on `minutes` (`MinutesApprovedListener`), so a
  lifecycle change through any path triggers them, not only
  `MinutesController::transition()`.
- **Setting key.** `case_system_send_on_approval` (the app-config naming used
  by the other keys), switched in the admin settings.
- **Widget.** The Case system widget is a custom component
  (`MeetingCaseSystemTab`) rather than a declared `object-list`: each record
  shows its lines and their status, which the object list cannot.
- **The case link** is set from a Case widget on the agenda item
  (`AgendaItemCaseTab`), which also opens `CaseDocumentsModal`.
- **Records are service owned.** `CaseExchangeRecord` declares `read` for the
  secretariat and administrators and no write action, so the object API
  cannot forge a sent line (RegisterAuthorizationTest).
- **Seed data.** The municipality profile has no 12 March meeting; the
  seeded item hangs off "Raadsvergadering 15 januari 2025".

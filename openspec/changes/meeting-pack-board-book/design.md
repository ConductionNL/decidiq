# Design: meeting-pack-board-book

## Architecture Overview

Decidiq stays a thin client: `MeetingPack` is an OpenRegister object (no Decidiq tables), the pack file lives in the meeting's Files folder tree (created by the existing `MeetingFolderService`), and only the *compilation action* gets a Decidiq controller — reading packs, versions, and change notes goes straight from the Vue frontend to the OpenRegister objects API via `useObjectStore` (ADR-022; the redundant-controller gate forbids pass-through CRUD wrappers).

```
Meeting detail page ── POST /api/meetings/{id}/board-book ──► BoardBookController
                                                                    │ (guard: secretary/chair)
                                                                    ▼
                                                          BoardBookCompileJob (queued)
                                                                    │
                                                                    ▼
                                                            BoardBookService
                       ┌────────────────────────────────────────────┼──────────────────────────────┐
                       ▼                                            ▼                              ▼
        collect agenda items + files                 Docudesk PdfService                 MeetingFolderService
        (ObjectService + FileService,                renderPdf(cover+TOC+agenda)          write versioned PDF
         orderNumber sort, confidentiality           + NEW mergePdfs(attachments,         into meeting folder
         filter, fingerprint)                        bookmarks) — lazy container
                                                     lookup, honest fallback
                       └────────────────────────────► MeetingPack object created ◄───────┘
                                                       │ (version, changeNote, fingerprint,
                                                       │  filePath, pageCount, skipped)
                                                       ├─► x-openregister-notifications (created) → attendees notified
                                                       └─► per-attendee read-only share (OCP\Share\IManager) → offline sync
```

The outdated indicator is computed client-side friendly: `BoardBookService::fingerprint(meeting)` is also exposed through the status endpoint so the detail page compares `latestPack.fingerprint` with the current value.

Reused as-is: `MeetingFolderService` (folder tree), `MeetingPackageService` (non-Docudesk fallback + skip-report pattern), `MinutesDocumentService`'s lazy-Docudesk delegation pattern (`tryDocudeskPdf`), existing auth helpers/`GovernanceScopeGuard` for the secretary/chair check.

## API Design

### `POST /api/meetings/{meetingId}/board-book`
Starts compilation (queues `BoardBookCompileJob`). Guard: caller must be secretary or chair of the meeting's governance body. Body `changeNote` is required when a prior version exists.
**Request:**
```json
{ "changeNote": "Bijlage begroting toegevoegd aan punt 3" }
```
**Response (202):**
```json
{ "status": "queued", "meetingId": "00000000-0000-0000-0000-000000000000", "nextVersion": 2 }
```

### `GET /api/meetings/{meetingId}/board-book/status`
Returns compile state and freshness so the detail page can poll and render the outdated badge.
**Response:**
```json
{
  "state": "idle|queued|running|done|failed",
  "latestVersion": 2,
  "latestFingerprint": "sha256:…",
  "currentFingerprint": "sha256:…",
  "outdated": true,
  "skipped": ["03 - Kadernota/begroting-detail.xlsx (non-PDF attachment)"],
  "deliveryFailures": ["p.devries (no Nextcloud account)"]
}
```

No download or list endpoints: MeetingPack objects are read via the OpenRegister objects API; files download via Files/OR file endpoints.

## Database Changes

None. Decidiq owns no tables; `MeetingPack` is a new schema in `lib/Settings/decidesk_register.json` (OpenRegister storage). The `AgendaItem` schema gains an optional `confidentiality` enum (`public` | `internal` | `confidential`, default `internal`) — additive, no migration needed for existing objects (absent = `internal`).

## Nextcloud Integration

- Controllers: `BoardBookController` (`#[NoAdminRequired]` + explicit per-object secretary/chair guard in the body — semantic-auth gate).
- Services: `BoardBookService` (compose HTML, delegate to Docudesk, fingerprint, versioning, delivery), reusing `MeetingFolderService`, `MeetingPackageService` (fallback), container-lazy `OCA\OpenRegister\Service\ObjectService` / `FileService`, and Docudesk `OCA\DocuDesk\Service\PdfService`.
- Background job: `BoardBookCompileJob` (`OCP\BackgroundJob\QueuedJob` via `IJobList`) — compilation and delivery run out-of-request.
- Shares: `OCP\Share\IManager` creates per-attendee read-only user shares of the pack file (delivery, REQ-006).
- Mappers/Entities: none (thin client).
- Events/Hooks: none — pack notification is declarative (see below); outdated detection is fingerprint comparison, not event listeners.

## Security Considerations

- **Compile authorization**: `#[NoAdminRequired]` endpoints carry an explicit guard resolving the caller's role on the meeting's governance body (secretary or chair) before any work — and the guard resolver MUST NOT fail open (`catch → null → skip` is forbidden; a resolution failure denies).
- **Read access**: MeetingPack objects inherit meeting RBAC in OpenRegister; the pack file sits in the meeting folder and is only additionally shared to active attendees (explicit allow-list, read-only, resharing disabled).
- **Confidentiality**: `confidentiality: confidential` items are excluded from every compiled pack body (single safe rule, no per-user variants — no risk of the wrong variant reaching the wrong person). Their attachments never leave per-item RBAC.
- **Input validation**: `changeNote` length-limited and HTML-escaped before entering the rendered PDF; attachment names sanitized for placeholder pages (reuse `MeetingPackageService::itemFolderName` sanitization).
- **Resource abuse**: size guard per attachment and per pack; compilation runs in a queued job so the HTTP layer cannot be held open; one in-flight compilation per meeting.
- CSRF: default Nextcloud CSRF protection stays on (no `#[NoCSRFRequired]`).

## Declarative-vs-imperative decision (ADR-031)

- **Declarative (chosen wherever the dialect reaches):**
  - *Pack availability notification* — `x-openregister-notifications` on the `MeetingPack` schema: trigger `created`, channel `nc-notification`, recipients `object-acl` (read), bilingual subject. Zero imperative dispatch code; matches the existing Meeting schema entries (`meetingScheduled`, `meetingReminder`).
  - *Pack access* — OpenRegister RBAC on the objects, not app-side checks.
- **Imperative (justified exceptions):**
  - *Document generation and PDF merging* — inherently side-effectful, long-running, cross-app (Docudesk), and file-producing; no OpenRegister dialect can express "render HTML, import N PDF byte streams, paginate, bookmark, write a file". This is the same justified exception already established by `resolution-minutes` "Minutes Document Generation" (`MinutesDocumentService` → Docudesk `PdfService`), and we deliberately reuse that delegation-plus-honest-fallback pattern rather than inventing a new one.
  - *Fingerprint computation* — covers attachment etags across a relation, which `x-openregister-calculations` cannot reach (calculations see object properties/aggregations, not Files metadata); computed in `BoardBookService` and *stored* on the object.
  - *Per-attendee file delivery* — `OCP\Share\IManager` calls; a Files-level side effect outside the object dialects.

## NL Design System

- `CnDetailCard` section "Vergaderbundel" on the meeting detail page; compile action in the `#header-actions` slot (secretary/chair only).
- `CnStatusBadge` for the outdated indicator ("Vergaderbundel verouderd") — status color via CSS variables, text + color (never color alone), WCAG 2.1 AA.
- Version list as a plain table; download links as standard NC link buttons; skip report in a `NcNoteCard`-style warning area.
- All strings bilingual (nl/en), i18n keys in English.

## File Structure

```
decidiq/
  lib/
    Controller/BoardBookController.php        (new — compile + status)
    Service/BoardBookService.php              (new — compose, delegate, version, fingerprint, deliver)
    BackgroundJob/BoardBookCompileJob.php     (new — queued compilation)
    Settings/decidesk_register.json           (MeetingPack schema + seeds + notifications; AgendaItem.confidentiality)
  appinfo/routes.php                          (2 new routes)
  src/  (meeting detail view)                 (Vergaderbundel CnDetailCard section + store wiring)
  tests/Unit/Service/BoardBookServiceTest.php (new)
  tests/e2e/…board-book…                      (new — compile, versions, outdated badge, confidential exclusion)

docudesk/
  lib/Service/PdfService.php                  (additive mergePdfs(array $pdfBytes, array $bookmarks): string)
  tests/Unit/Service/PdfServiceMergeTest.php  (new)
```

## Seed Data

New schema `meeting-pack` gets seeds referencing the existing Meeting seeds (`raadsvergadering-2025-01-15`, `rvc-vergadering-q1-2025`, `directieoverleg-2025-04-14`) so the pack section is populated on a clean install. One AgendaItem seed is extended with `confidentiality: confidential` to make the exclusion visible.

### Schema: `meeting-pack`
| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | vergaderbundel-raad-2025-01-15-v1 | vergaderbundel-raad-2025-01-15-v2 | vergaderbundel-rvc-q1-2025-v1 | vergaderbundel-directie-2025-04-14-v1 |
| meeting | raadsvergadering-2025-01-15 | raadsvergadering-2025-01-15 | rvc-vergadering-q1-2025 | directieoverleg-2025-04-14 |
| version | 1 | 2 | 1 | 1 |
| changeNote | "" | "Bijlage begroting toegevoegd aan agendapunt 4" | "" | "" |
| generatedAt | 2025-01-08T10:00:00Z | 2025-01-13T09:15:00Z | 2025-03-20T14:30:00Z | 2025-04-10T08:45:00Z |
| generatedBy | griffier | griffier | bestuurssecretaris | managementassistent |
| fingerprint | sha256:seed-placeholder-1 | sha256:seed-placeholder-2 | sha256:seed-placeholder-3 | sha256:seed-placeholder-4 |
| filePath | Vergaderingen/Raadsvergadering 15 januari 2025/Vergaderbundel v1.pdf | …/Vergaderbundel v2.pdf | Vergaderingen/RvC Q1 2025/Vergaderbundel v1.pdf | Vergaderingen/Directieoverleg 14 april 2025/Vergaderbundel v1.pdf |
| pageCount | 42 | 58 | 23 | 12 |
| skipped | [] | ["04 - Kadernota begroting 2026/begroting-detail.xlsx (non-PDF attachment)"] | [] | [] |

`@self` envelope for each: `{ "register": "decidesk", "schema": "MeetingPack", "slug": "<slug>" }` (inline `x-openregister-seeds`, same mechanism as the existing Meeting/AgendaItem seeds).

**Modified seed** — AgendaItem `jaarrekening-2024` gains `confidentiality: confidential` (its pack section then shows the closed-doors placeholder); the other AgendaItem seeds stay `internal` by default.

**Related items per object:**
- Files: the referenced `filePath` PDFs are seeded as small placeholder PDFs in the meeting folders where the seed mechanism allows file seeding; otherwise the detail page renders the version rows with a "file pending regeneration" note.
- Notes: none.
- Tasks: none.
- Contacts: none — `generatedBy` references seed user display roles, not real accounts.

## Migration Plan

1. Merge docudesk `mergePdfs()` first (additive, independently releasable).
2. Merge decidesk register changes (MeetingPack schema + seeds + `AgendaItem.confidentiality`) — register re-import is additive.
3. Merge decidiq service/controller/UI. Feature is dormant until a secretary compiles.
4. Rollback: revert decidiq PRs; MeetingPack objects/files stay as inert data; docudesk method may remain (unused, additive).

## Trade-offs

- **One pack for everyone (confidential items always excluded)** over per-clearance pack variants: variants multiply storage, complicate delivery (wrong-variant risk = a leak), and every competitor's baseline is satisfied by the single-pack rule. Members with clearance still reach confidential documents per item.
- **Merge in Docudesk, not Decidiq**: Decidiq stays free of PDF libraries; Docudesk already bundles mPDF 8.2 (FPDI import). Cost: a cross-project PR and a runtime soft-dependency — accepted, because minutes rendering already set this contract.
- **Queued job + polling** over synchronous compile: 50 MB merges will not fit in a web request; cost is a status endpoint and UI polling.
- **Fingerprint stored on the pack** over event-listener invalidation: no listener wiring across agenda/attachment mutations (which historically arrive via many paths); cost is a cheap fingerprint recomputation on detail-page load.
- **Placeholder pages for non-PDF/unmergeable attachments** over document conversion: conversion (LibreOffice) is a heavy new moving part; deferred until demand shows.

## Open Questions

- Delivery mechanics: per-user share of the single pack file (provisional) vs. copying into a per-user `Vergaderbundels/` folder — share keeps one canonical file and revocation is trivial; copy survives share-permission edge cases. Decide during apply against real NC share behaviour for files inside the app-managed meeting folder.
- Whether `GET …/board-book/status` should also trigger fingerprint persistence (cache) to keep detail-page loads O(1) for meetings with hundreds of attachments.

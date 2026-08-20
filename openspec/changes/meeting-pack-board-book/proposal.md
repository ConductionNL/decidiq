---
kind: code
---

# Proposal: meeting-pack-board-book

## Summary

Add a compiled meeting pack (vergaderbundel / board book) to Decidesk: one-click compilation of a complete, single-PDF meeting bundle per meeting — cover page, table of contents, the agenda in order, and all agenda-item attachments merged with continuous pagination and per-item bookmarks — rendered via Docudesk (same delegation pattern as minutes rendering). Packs are versioned (regeneration keeps prior versions with a change note), an "outdated" indicator appears on the meeting detail page when the agenda or attachments change after compilation, access follows meeting confidentiality (confidential agenda items are excluded from the compiled pack), attendees are notified declaratively when a pack becomes available, and the pack is delivered into each attendee's own Nextcloud Files so native NC clients sync it for offline reading.

## Motivation

The compiled board book is table stakes for every board portal (iBabs "vergaderset", OnBoard, Diligent) and is absent from Decidesk. The 2026-07-16 intelligence-DB deep-dive lists it as the largest unresolved must-feature cluster for Decidesk: digital-board-books-with-annotations-and-version-control (demand 482), online-board-books-privacy-robust (436), agenda-native-board-books-one-click-publishing (330), easy-access-to-board-books (220), board-book-topic-highlighting (209), secure-offline-access-offline-sync-traveling-board-members (207), secure-board-books-offline-mobile-approvals (201).

Decidesk today has only per-agenda-item attachments (`agenda-publication` REQ-PUB-003) and a **folder-based** package: `MeetingPackageService::assemble()` copies item documents into a `Meeting package/` folder tree with a markdown table of contents (`agenda-management` "Agenda Document Package"). There is no single compiled document, no pagination/bookmarks, no version tracking, no outdated indicator, no confidentiality filtering, and no per-attendee offline delivery. A councillor or board member preparing on a train cannot read "the bundle" the way every competing portal offers it.

## Affected Projects

- [ ] Project: `decidesk` — new `MeetingPack` schema (with seeds + declarative notification), new `BoardBookService` building on `MeetingPackageService`/`MeetingFolderService`, `BoardBookController` + routes, meeting detail page pack section with version list and outdated indicator, per-attendee delivery of the pack file.
- [ ] Project: `docudesk` — small additive API on `PdfService`: merge pre-rendered PDF byte streams into one document with continuous pagination and named bookmarks (mPDF 8.2 already ships the FPDI import capability; no new library).

## Scope

### In Scope

1. **One-click compilation** of a complete meeting pack per meeting: cover page (governance body, meeting title, date, location), table of contents, agenda items in `orderNumber` order, all item attachments merged into a single PDF with continuous pagination and one bookmark per agenda item — rendered via Docudesk (`PdfService`), same delegation-and-honest-fallback pattern as `MinutesDocumentService`.
2. **Version tracking**: each compilation creates a new `MeetingPack` version object with a change note; prior versions and their files are kept; the meeting detail page shows a "Vergaderbundel verouderd" (pack outdated) indicator when the agenda or attachments changed after the latest pack was compiled (content-fingerprint comparison).
3. **Access control**: pack visibility follows the meeting (OpenRegister RBAC, attendees + authorized roles). Agenda items marked confidential (new optional `confidentiality` property on `AgendaItem`) are excluded from the compiled pack body and replaced by a placeholder page; their documents stay behind per-item RBAC. One pack per meeting version — no per-user pack variants.
4. **Offline reading**: the compiled pack PDF is persisted in the meeting's Files folder and additionally delivered (shared read-only) to each active attendee so it appears in their own Nextcloud Files and syncs offline via the native desktop/mobile clients.
5. **Availability notification**: declarative `x-openregister-notifications` on the `MeetingPack` schema (trigger `created`, recipients `object-acl` read) — no imperative dispatch.

### Out of Scope

- In-app annotations on the pack (separate sibling change `document-annotations`).
- Native mobile app work and DRM / watermarking.
- Per-user pack variants (a clearance-filtered "full pack" per individual); confidential items are excluded from the single compiled pack for everyone.
- Non-PDF attachment conversion (Word/Excel) into the merged body — such attachments get a placeholder page and remain available in the existing folder package.
- Replacing the existing folder-based `MeetingPackageService` package — it remains as-is and serves as the non-Docudesk fallback.

## Approach

Reuse before build: `MeetingFolderService` locates/creates the meeting Files tree, `MeetingPackageService.collectAgendaItems`-style ordering and defensive skip-reporting are extended, and the Docudesk delegation follows `MinutesDocumentService::tryDocudeskPdf` (resolve `PdfService` lazily via the container; when Docudesk is absent, honestly fall back to the existing folder package + markdown TOC and say so). A new `BoardBookService` composes cover + TOC + agenda body as HTML, renders it via Docudesk, merges attachment PDFs with a new `PdfService::mergePdfs()` in Docudesk, writes the result to the meeting folder, creates a `MeetingPack` object (version, changeNote, fingerprint, file reference), and shares the file to active attendees. Details in design.md.

## New Dependencies

None. Docudesk already bundles mPDF ^8.2 (which requires setasign/fpdi for PDF import); Decidesk gains no new composer packages.

## Impact

- `lib/Settings/decidesk_register.json` — new `MeetingPack` schema (+ seeds, notifications, relations), new optional `confidentiality` property on `AgendaItem`.
- `lib/Service/BoardBookService.php` (new), `lib/Controller/BoardBookController.php` (new), `appinfo/routes.php` (new routes).
- `src/` meeting detail view — pack section (compile button, version list, download, outdated badge).
- Docudesk `lib/Service/PdfService.php` — additive `mergePdfs()`; no behavioural change to existing callers.
- Existing `MeetingPackageService` / `MeetingFolderService` / `MinutesDocumentService` are reused, not modified (except any shared helper extraction).

## Cross-Project Dependencies

- **docudesk** (soft at runtime, hard for the single-PDF feature): compiled-PDF rendering and merging delegate to Docudesk's `PdfService`; when Docudesk is not installed the folder package + honest message is the fallback (same contract as minutes rendering).
- **openregister**: existing `ObjectService` + `FileService` (per-item attachments already flow through `FileService` per REQ-PUB-003); declarative notifications dialect.

## Risks

### Risk 1: Confidential items leak into a widely shared pack
**Severity:** High — **Mitigation:** confidential items are excluded from the compiled pack for *everyone* (placeholder page only); the pack file inherits meeting access; delivery shares go only to active attendees; exclusion is unit-tested and e2e-tested before delivery is enabled.

### Risk 2: PDF import fails for some attachments (FPDI free parser cannot read PDFs with compressed cross-reference streams)
**Severity:** Medium — **Mitigation:** defensive per-attachment merge with a skip report (same pattern as `MeetingPackageService::$skipped`); a skipped attachment gets a placeholder page naming the file, which remains downloadable per item.

### Risk 3: Large packs (hundreds of MB of attachments) exhaust memory/time during merge
**Severity:** Medium — **Mitigation:** compile in a background job with a size guard; report per-attachment sizes; skip oversized attachments with a placeholder rather than failing the pack.

### Risk 4: Outdated-indicator false negatives (agenda changed but fingerprint unchanged)
**Severity:** Low — **Mitigation:** fingerprint covers agenda item ids, order, titles, and attachment etags; secretary can always recompile manually.

## Rollback Strategy

The change is additive. Rollback = revert the code PRs; the `MeetingPack` schema and its objects remain inert data in OpenRegister (no other schema depends on them), the `AgendaItem.confidentiality` property is optional and ignored by existing code, and Docudesk's `mergePdfs()` is an unused additive method. Already-delivered pack files in attendees' Files are ordinary files and can stay.

## Open Questions

- Whether delivery into each attendee's Files uses per-user shares from the meeting folder or a copy into a per-user `Vergaderbundels/` folder (provisional: per-user read-only share; see design.md).
- Whether `document-annotations` (sibling change) will need per-page anchors from the pack; the bookmark structure is designed to be stable per agenda item so annotations can reference it later.

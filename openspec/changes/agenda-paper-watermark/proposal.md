---
kind: code
depends_on: [agenda-office-files-to-pdf]
---

# Proposal: agenda-paper-watermark

## Summary

A confidential paper that leaks should say who leaked it. This change stamps every page of a paper opened or downloaded through decidiq with the reader's name and the date, and optionally a line such as "Vertrouwelijk". Clerks switch it on per meeting, and it is on by default for meetings that are not public and for agenda items under a confidentiality restriction.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### age-19, watermark meeting papers on screen and in exports with the viewer's name and the date

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Matrix note: "decidiq puts no watermark on papers or exports. Nextcloud Office has a secure view with watermarks of its own, which decidiq neither sets up nor was checked here."

No demand row (origin `featurePage`, https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/watermarking-documents-bwa.htm).

Competitor cells rated yes:

- diligent-boards: "https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/watermarking-documents-bwa.htm states watermarks show the viewer's name and view date on the page view and on exported documents, with optional custom text"

## Why

The rule for this pass says a row covered by an archived change and still `none` is built again. Archived `2026-06-12-board-meeting-resolutions` specified exactly this ("materials are watermarked with the member's name on every page", `specs/multilingual-minutes-and-access.md:35-49`) on a `BoardMaterial` schema with `watermarked` and `watermark-text`. Archived `2026-06-14-retire-board-portal` deleted `BoardMaterial` with the rest of the parallel board schemas and said there was no feature loss. For watermarking there was: `grep -rli watermark lib src` finds nothing today. This change puts the capability back on the universal entities, as ADR-006 asks, instead of on a board-only schema.

## What changes

1. A meeting gets `watermarkPapers` (on, off, or automatic) and an optional `watermarkText`. Automatic means on when the meeting is not public or the agenda item carries an active confidentiality restriction.
2. Opening a paper from the agenda item page or the meeting page, when watermarking applies, goes through `GET /api/papers/{fileId}/view`, which returns a PDF with "<reader's name>, <date and time>, <text>" across every page.
3. The meeting package that decidiq assembles for a member, and any paper export decidiq makes, is stamped the same way for the person who asked for it.
4. The stamping itself is filinq's: decidiq asks filinq's PDF service to stamp text on every page. Where filinq is missing, decidiq refuses to hand out an unstamped copy of a paper that must be stamped, and says why.

## Out of scope

- Office files. After `agenda-office-files-to-pdf` the paper members read is a PDF; an Office original stays a clerk's download.
- Watermarks in Nextcloud Office's own secure view, which an administrator configures in richdocuments.
- Stopping a member who can reach the raw file in Files. That is confidentiality enforcement (matrix row age-09, state building); this change marks what decidiq serves.

## Risks

- Stamping costs time on large papers. The stamped copy is cached per paper version and reader for the day, so a reader opening the same paper twice waits once.
- filinq does not yet expose a stamping method (checked at filinq `7733074`: `PdfService`, `Pdfa3ConversionService` and `MergedPdfDocument` import pages with FPDI and mPDF, and none takes a watermark text). The filinq half is a small method on the page-import loop it already has; this proposal names it as filinq's to build, and the decidiq tasks wait on it.

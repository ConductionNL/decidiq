---
kind: code
depends_on: []
---

# Proposal: agenda-office-files-to-pdf

## Summary

Clerks add Word, Excel and PowerPoint files to meetings and agenda items. Members then need the right Office app, and every reader sees a different layout. This change converts each Office paper to PDF as soon as it is added, keeps the original next to it, and makes the PDF the copy members read and the meeting package bundles.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### age-17, convert Word, Excel and PowerPoint files to PDF inside the system when they are added

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Matrix note: "Word, Excel and PowerPoint files attached to a meeting or agenda item are not converted. decidiq only turns its own generated documents into PDF, through filinq when it is installed."

Demand: origin `tender`, https://www.tenderned.nl/aankondigingen/overzicht/384605

Competitor cells rated yes:

- ibabs: "https://support.ibabs.com/docs/attachments-to-an-agenda-item.md states uploaded Word, Excel, PowerPoint and other files are automatically saved as PDF"
- diligent-boards: "https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/adding-a-document-word-excel-powerpoint-bwa.htm states a document uploaded from Word, Excel or PowerPoint is converted and then appears in the book"

## Why

The tender behind this row (TenderNed 384605) asks for it outright, and iBabs and Diligent do it on upload. The conversion engine already exists in the fleet: filinq's `PdfConversionService::convertToPdfReporting()` walks Collabora or OnlyOffice through Nextcloud's conversion broker, then LibreOffice, then its own fallbacks, and writes `<name>.pdf` beside the source. decidiq already delegates PDF rendering to filinq through `lib/Support/FilinqPdf.php`. What is missing is decidiq asking for the conversion when a paper arrives.

## What changes

1. When an Office file lands in the files folder of an agenda item or a meeting, decidiq queues a conversion job.
2. The job asks filinq to convert it. The PDF appears beside the original, and the agenda item records the pair and which backend made it.
3. The agenda item page and the meeting package show the PDF as the paper and keep the original as a download for the clerk.
4. An administrator can switch automatic conversion off. Without filinq nothing is converted and the admin page says why.

## Out of scope

- Converting files that were attached before this change. A one-off repair job can do that later.
- Accessibility repair of the resulting PDF. `document-accessibility-check` covers checking papers.
- Converting in the browser, or any converter outside the fleet.

## Risks

- A conversion can fail or look wrong. The original stays, the failure is recorded on the item with the backend attempts filinq reports, and the clerk sees it.
- Conversion is slow for large decks. It runs in a queued background job, never in the upload request.
- Two Office apps can race to convert. The job skips a file whose PDF sibling already exists and is newer.

---
kind: code
depends_on: []
---

# Proposal: motions-export-with-attachments

## Summary

A griffier is asked for "all motions of this year, with their attachments" or "every proposal on housing since January". Today decidiq exports the list as a spreadsheet of fields, and only one meeting's papers can be bundled. This change lets a clerk export the motions or decisions she selected, or every one that matches the list's current filter, as one PDF with each text followed by its attachments, or as a ZIP of the documents as they are.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### mot-16, export motions with their attachments to PDF

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Matrix note: "Motions cannot be exported to PDF with their attachments."

No demand row (origin `competitor`).

Competitor cells rated yes:

- notubiz: "https://amsterdam.raadsinformatie.nl/modules/6/moties_en_amendementen/view offers Exporteer overzicht or selectie as PDF or Excel with bijlages, attachments only in PDF format"
- openslides: "source read at 4.3.4, not driven: openslides-client/client/src/app/site/pages/meetings/pages/motions/services/export/motion-pdf-export.service/motion-pdf-export.service.ts:49 the motion export (motions/motion-export) with includePdfAttachments merges each motion PDF with its PDF attachments into one file (line 127), and motion-pdf.service.ts:167 lists attachments."

### pub-17, export a set of documents chosen by filter in one go, such as all motions of one party or all proposals in a period

Own rating `partial`, built.state `built`, owner `ConductionNL/decidiq`. Matrix note: "A filtered selection of motions, decisions or other records can be exported in one go as a spreadsheet of their fields. The documents themselves are not exported: only one meeting's papers can be bundled, through the meeting package."

Demand: origin `tender`, https://www.tenderned.nl/aankondigingen/overzicht/384605

Competitor cells rated yes:

- notubiz: "https://amsterdam.raadsinformatie.nl/modules/6/moties_en_amendementen/view offers Exporteer overzicht and Exporteer selectie as PDF or Excel with attachments after filtering by party, period and type"

## Why

NotuBiz exports a filtered selection of motions as PDF with attachments; OpenSlides merges each motion with its PDF attachments. The tender behind pub-17 (TenderNed 384605) asks for documents chosen by filter in one go. decidiq already has the pieces around it: the Motions and Decisions lists filter and keep their filter in the address (`CnIndexPage` persists it in `$route.query`), `CnIndexPage` offers declared `bulkActions` that receive the selection, OpenRegister lists an object's files (`FileService::getFiles()`), and filinq merges an ordered list of files into one bookmarked PDF (`DocumentMergeService::merge()` and `queue()`, which check read rights first and write no partial file).

## What changes

1. The Motions and Decisions lists get a bulk action Export with attachments.
2. It asks what to export (the selected rows, or all rows matching the current filter) and how (one PDF, or a ZIP).
3. For a PDF, decidiq renders each decision's text as a page through filinq, puts its attachments after it, and asks filinq to merge them in list order with one bookmark per decision. Large sets run in the background and the clerk is notified when the file is ready.
4. For a ZIP, decidiq collects each decision's attachments in a folder named after the decision.
5. The result lands in the clerk's Files under "Decidiq exports", named after the list and the date.

## Out of scope

- A party filter. Decisions carry the proposer as a name, not a party reference; filtering by party needs a party on the decision, which is a separate change.
- Exports for residents. Public exports go through the publication path.

## Risks

- A selection can include a decision whose attachment the clerk may not read. filinq's merge refuses before any work starts and names the file; the dialog shows that and exports nothing.
- A thousand motions make a very large PDF. Above a threshold (filinq's `shouldQueue()`), the export is queued; above a hard limit of 500 decisions the dialog asks to narrow the filter.

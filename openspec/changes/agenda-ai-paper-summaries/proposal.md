---
kind: code
depends_on: []
---

# Proposal: agenda-ai-paper-summaries

## Summary

Council and board members get long meeting papers. This change lets a clerk ask Nextcloud's AI for a short summary of a paper, or for the main differences between two papers or two versions of one. The clerk reviews the text, edits it or hides it, and only then do members see it, marked as AI-generated.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### age-16, get an AI summary of long meeting papers

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Matrix note: "decidiq uses Nextcloud's AI task processing to draft minutes from a transcript, but not to summarise meeting papers."

No demand row (origin `competitor`).

Competitor cells rated yes:

- go-raadsinformatie: "https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._digitale_assistent/ states the AI assistant summarises long proposals and compares documents inside GO. vergaderen"
- diligent-boards: "https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/managing-ai-content-for-a-book-bwa.htm describes Smart Summary, a condensed version of the book"

### age-20, compare two documents or two versions of a paper with AI and see the main differences

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Matrix note: "There is no AI comparison of documents or versions. An amendment is shown as tracked changes against its motion, which is a plain diff and not AI."

No demand row (origin `featurePage`, https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._digitale_assistent/).

Competitor cells rated yes:

- go-raadsinformatie: "https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._digitale_assistent/ states the assistant gives insight into the main differences between documents and answers what changed compared with last year"
- diligent-boards: "https://www.diligent.com/platform/diligent-ai-for-directors states directors can instantly pinpoint key changes to board materials with comparisons to earlier meetings"

### plt-27, review, edit or hide AI generated summaries of meeting papers before members see them

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`. Matrix note: "decidiq makes no AI summaries of meeting papers, so there is nothing to review, edit or hide."

Demand: origin `changelog`, https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/release_notes/release-notes-bwa.htm

Competitor cells rated yes:

- diligent-boards: "https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/release_notes/release-notes-bwa.htm (Dec 12, 2025) adds deleting generated AI content, and https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/managing-ai-content-for-a-book-bwa.htm describes a Show to directors toggle and editing the Smart Summary"

plt-27 is clustered here on purpose. On its own it has one competitor rated yes. It is the review step of the same feature on the same screen, and a public body should not show members AI text nobody checked.

## Why

GO. and Diligent both sell this, and it is the question members ask first about a 200-page budget paper: what is in it, and what changed since last year. decidiq already runs Nextcloud's TaskProcessing for minutes drafts (`lib/Service/MinutesDraftService.php:288-290`, `TextToText`), and OpenRegister already extracts the text of files attached to objects (`TextExtractionService::extractFile()`, chunks readable through `ChunkMapper::findBySource('file', $fileId)`). The pieces are there. What is missing is the paper summary itself, a record of it, and a person between the AI and the member.

## What changes

1. A new `PaperSummary` schema records one AI output: the paper (file id) and agenda item, the kind (`summary` or `comparison`), the second paper for a comparison, the text, the provider that wrote it, and a review status.
2. On the agenda item page a clerk chooses Summarise on a paper, or Compare with and a second paper. decidiq runs the task through TaskProcessing and stores a draft `PaperSummary`.
3. The clerk reads the draft, edits the text, and chooses Show to members or Hide. Members see only shown summaries, labelled "AI-generated summary, checked by <clerk>".
4. The action is hidden when no TaskProcessing provider is installed, exactly like the minutes draft.

## Out of scope

- Summaries of meeting recordings (matrix row liv-15) and suggested questions or legal risk flags (rows ins-11, ins-12, deferred).
- Sending papers to any AI outside Nextcloud's TaskProcessing. The provider is whatever the organisation installed, local or remote, and that choice stays theirs.
- Summarising confidential papers for members outside the confidentiality circle. A summary inherits its paper's readers.

## Risks

- An AI summary can be wrong. The clerk review is mandatory: nothing a member reads skips it, and the label says a person checked it.
- A summary could leak a confidential paper. The summary's read rule follows the agenda item's, and the service refuses a paper under an active confidentiality restriction unless the caller is in its circle.
- Long papers exceed a model's context. The service summarises chunk by chunk and then summarises the summaries, and records that it did.

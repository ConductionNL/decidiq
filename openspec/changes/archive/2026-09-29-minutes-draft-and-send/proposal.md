---
kind: code
depends_on: ["meeting-attendance-per-meeting"]
---

# Proposal: minutes-draft-and-send

## Summary

The server can draft minutes from the agenda, votes and decisions, and from a transcript with AI, and can tell members minutes are available; but the first has no button and no attendance, the AI draft is never written into the minutes, and distribution is API only. This change puts all three on the minutes page.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### min-01, draft the minutes automatically from the agenda, attendance and decisions

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Service/MinutesGenerationService.php:90 generateDraft() renders agenda items, motions, voting rounds and decisions of the linked meeting via MinutesDraftRenderer; route appinfo/routes.php:98 POST /api/minutes/{minutesId}/generate-draft (MinutesController.php:135). No src/ caller of 'generate-draft' for minutes (the only hit is the transcript route in MeetingTranscriptionTab.vue:555). No attendance in the renderer (grep attend/present in MinutesDraftRenderer.php and MinutesGenerationService.php: none).

Matrix note, verbatim:

> Server-side draft from agenda, votes and decisions exists but no screen calls it, and attendance is not included. MinutesDocumentService uses it as a content fallback when generating a document.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/notulen.md and https://support.ibabs.com/docs/besluitenlijst.md state minutes and decision lists are generated from the agenda with selectable elements; https://support.ibabs.com/docs/additional-functions-for-agenda-items.md records attendance and decisions per item
- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-add-ons/minutes/setting-attendees-and-book-structure.htm prepopulates minutes sections from the book agenda and attendees from book access; https://www.diligent.com/features/boards/boards-minutes states the first draft is auto generated from agendas and materials

### min-02, draft the minutes from the meeting transcript with AI

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> src/components/tabs/MeetingTranscriptionTab.vue:549 generateDraft() calls POST /api/transcripts/{id}/generate-draft (routes.php:132) -> lib/Controller/TranscriptionController.php:242 -> lib/Service/MinutesDraftService.php:117 generate() runs Nextcloud TaskProcessing TextToText per agenda item with provenance and cross-check against recorded votes/decisions. Draft is shown with discard/edit markers (MeetingTranscriptionTab.vue discardSection/markEdited) but only provenance is saved, on the Transcript (MinutesDraftService.php:163 'never on a Minutes object').

Matrix note, verbatim:

> Works when a Nextcloud AI (TaskProcessing) provider is installed; the generated draft is displayed but not written into the Minutes record, so the secretary has to carry it over by hand.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/documenten-genereren.md states iBabs Debrief generates transcript, verbatim minutes and a summary from the transcription; https://support.ibabs.com/docs/probleemoplossing-1.md states minutes are made by AI from agenda and transcription
- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-add-ons/minutes/convert_transcripts_to_minutes.htm converts an uploaded meeting transcript into minutes per section with AI

### min-07, send the approved minutes to the members

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Service/ALVMinutesService.php:173 distribute() sends a Nextcloud notification ('De notulen zijn nu beschikbaar', deep link) to active body members once minutes are approved/signed; route appinfo/routes.php:104 POST /api/minutes/{id}/distribute. No src/ caller of 'distribute'. MinutesPublicationTab publishes publicly via /api/publications, not to members.

Matrix note, verbatim:

> Only a notification with a link is sent, not the minutes document, and no screen triggers it.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/notuleren-1.md states minutes become visible to all participants on republication; https://support.ibabs.com/docs/werken-met-agendas.md sends a notification on publication
- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-add-ons/minutes/monitoring-managing-approving-the-document.htm states approved minutes can be added to the next board book, which is published with notification (https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/publishing-and-hiding-books-bwa.htm)

## Why

Each row is rated yes by two competitors. The secretary today copies drafts by hand.

## What is built today

- MinutesGenerationService::generateDraft() (API only, no attendance).
- MinutesDraftService AI draft shown on the Transcription widget, saved only as provenance on the transcript.
- ALVMinutesService::distribute() notifies body members (API only).

## What changes

1. MinutesDetail gets Draft from the meeting, which calls generate-draft; the draft includes attendance (present, excused, absent) from the meeting's attendance records.
2. The Transcription widget's AI draft gets Use as minutes, writing the kept sections into the minutes content and itemNotes.
3. Approved minutes get Send to members, which calls distribute and attaches the minutes PDF when one exists.

## Out of scope

- Signing (signing-external-service-with-order).

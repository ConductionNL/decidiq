---
kind: code
depends_on: []
---

# Proposal: agenda-meeting-page-item-tools

## Summary

Three agenda tools work today, but only on the live meeting screen, and nothing links to that screen. A chair can drag agenda items into order there. A secretary can write minutes per agenda item there. The page that opens an agenda item with its documents is not linked from the meeting either. This change brings all three to the meeting page, where clerks already work, and links the live meeting screen from it.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### age-02, reorder agenda items by dragging them

Own rating `partial`, built. Matrix note: "Drag-to-reorder works and persists, but only for the chair on the live meeting screen, which no button or menu entry opens. The meeting page's agenda widget has no drag."

No demand row. Competitor cells rated yes:

- diligent-boards: "https://www.diligent.com/features/boards/boards-agenda states drag-and-drop board agenda-building tools; https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/agenda-builder/agenda-builder-bwa.htm is the builder"
- openslides: "source read at 4.3.4, not driven: openslides-client/client/src/app/site/pages/meetings/pages/agenda/pages/agenda-sort/components/agenda-sort/agenda-sort.component.html:53 os-sorting-tree drag and drop on the agenda/sort route, saved via agenda_item.sort (gateways/repositories/agenda/agenda-item-repository.service.ts:130)."

### age-03, attach documents to an agenda item

Own rating `partial`, built. Matrix note: "Files can be attached per agenda item through OpenRegister's files integration, but the agenda item page is not linked from the meeting's agenda, so most users will attach papers at meeting level instead."

The row's `built.owner` was `ConductionNL/openregister`. The openregister lane of this pass recorded (openregister `openspec/parity/gap-decisions.json`, row age-03) that per-object files are OpenRegister's `file-actions` and that the unlinked agenda item page is decidiq's, so the owner moves to `ConductionNL/decidiq` for the missing half. No demand row. All five competitors rated yes:

- notubiz: "https://www.notubiz.nl/onze-diensten states documents are added directly in the agenda; https://www.kennisbank.notubiz.nl/handleidingen/werken-met-de-kalender-en-agenda shows the number of documents per agenda item"
- ibabs: "https://support.ibabs.com/docs/attachments-to-an-agenda-item.md states attachments are uploaded per agenda item and converted to PDF"
- go-raadsinformatie: "https://gemeenteraad.groningen.nl/api/v2/ documents endpoints getMeetingItemDocuments and https://gemeenteraad.groningen.nl/api/v2/meetings/5693/documents return documents attached to meetings and agenda items"
- diligent-boards: "https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/adding-documents-to-a-book-bwa.htm adds documents to book tabs that correspond to agenda topics"
- openslides: "source read at 4.3.4, not driven: openslides-client/client/src/app/site/pages/meetings/pages/agenda/modules/topics/pages/topic-detail/components/topic-detail/topic-detail.component.html:113 attachment control on the agenda topic, stored as openslides-backend/meta/collections/topic.yml:22 attachment_meeting_mediafile_ids and listed at line 82."

### min-03, edit the minutes per agenda item in a structured editor

Own rating `partial`, built. Matrix note: "The structured per-item editor works, but the only page that mounts it has no navigation entry, so a user reaches it only by typing the URL. The MinutesDetail page shows the minutes as a generic data widget."

No demand row. Competitor cells rated yes:

- ibabs: "https://support.ibabs.com/docs/notuleren-1.md states Notuleren shows a text box under every agenda item to write the minutes, saved as concept until republished"
- diligent-boards: "https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-add-ons/minutes/taking-minutes.htm describes a sectioned editor where sections follow the book tabs"

## Why

The tools exist and are tested. They are invisible because the only screen that mounts them has no way in. Issue decidiq#1374 ("The live meeting page can only be reached by typing its URL, and nobody is recognised as its chair") records the same finding as a defect. This change fixes it as a capability: the meeting page gets the tools, and the live screen gets a way in.

The live screen also decides "is this user the chair" differently from the server. The server lets a chair, a secretary or an admin reorder (`AgendaAuthorizationGuard::requireChairOrAdmin`). The live screen asks for a participant with role `chair` only. The meeting page asks the server instead, so both sides agree.

## What changes

1. The agenda widget on the meeting page lets a chair, secretary or admin drag items into a new order, with a keyboard alternative, and saves through the existing reorder endpoint.
2. Each agenda row gets an Open action that goes to the agenda item page, where its documents live.
3. The agenda widget header gets an Open live meeting button for a chair, secretary or admin.
4. The minutes page gets the per-item minutes editor for the minutes' meeting.
5. A small endpoint tells the page which meeting roles the current user holds, read from the same resolver the server's guards use.

## Out of scope

- Who counts as chair on the live screen itself (decidiq#1374 second half). The live screen may adopt the same endpoint later.
- Publishing the agenda from a screen (matrix row age-05, decidiq#1384).
- Uploading files in bulk to several agenda items at once.

## Risks

- Two places can reorder the same agenda. Both write through `PUT /api/agendas/{meetingId}/reorder`, which renumbers all items in one call, so the last save wins and nothing is half-written.
- A drag-only control fails WCAG 2.5.7. The widget offers move up and move down actions on every row, which the live screen's builder already does with arrow keys.

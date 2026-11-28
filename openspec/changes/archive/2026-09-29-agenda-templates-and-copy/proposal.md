---
kind: code
depends_on: []
---

# Proposal: agenda-templates-and-copy

## Summary

The only template-like path is copying standing recurring items on the live screen. This change lets the secretary start a meeting's agenda from a template or from an earlier meeting, and copy single items from an earlier agenda.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### age-04, start an agenda from a template

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `competitor`, no originUrl.

Matrix evidence, verbatim:

> src/dialogs/RecurringItemsDialog.vue ('pick recurring agenda items to copy onto the current agenda') used by src/components/AgendaBuilder.vue:346, :795-801, only on LiveMeeting (/meetings/:id/live, chair, no link); no agenda-template schema or 'from template' action (ProcessTemplate is a decision process, not an agenda)

Matrix note, verbatim:

> The only template-like path is copying standing 'recurring' items into an agenda on the unlinked live meeting screen. There is no agenda template to start a new meeting's agenda from.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/overview-of-the-settings-for-an-agenda-type.md states fixed agenda items (vaste agendapunten) defined on the agendatype are added to a new agenda
- go-raadsinformatie: https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._vergaderen/ states meetings are created with standaard sjablonen (standard templates)
- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/agenda-builder/customizing-an-agenda-template.htm describes the Agenda template manager; https://www.diligent.com/features/boards/boards-agenda states agendas can copy previous meeting structures

### pla-14, copy agenda items or a whole meeting from an earlier one

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `competitor`, no originUrl.

Matrix evidence, verbatim:

> src/dialogs/RecurringItemsDialog.vue copies items flagged isRecurring onto the current agenda (src/components/AgendaBuilder.vue:346, :795-801); AgendaBuilder is mounted only in src/views/LiveMeeting.vue:93 for the chair, and nothing links to /meetings/:id/live; lib/Service/MeetingSeriesService.php copies the meeting but has no agenda handling; no copy-meeting or copy-agenda action anywhere in src/

Matrix note, verbatim:

> Standing items marked recurring can be copied onto an agenda, but only in the live meeting screen, which nothing links to. There is no way to copy a whole meeting or pick items from an earlier agenda.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/basic-functions-for-agenda-items.md states an agenda item can be copied to another agenda with or without documents and decisions
- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/copying-an-existing-book-bwa.htm copies a book with structure and optionally all documents; https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/basic-navigation/editing-the-calendar-bwa.htm copies a calendar meeting
- openslides: source read at 4.3.4, not driven: openslides-client/client/src/app/gateways/repositories/meeting-repository.service.ts:232 duplicate and duplicateFrom (line 243) send meeting.clone, which openslides-backend/openslides_backend/action/actions/meeting/clone.py:40 implements as a full meeting export and import including agenda; called from the committee meeting form (meeting-edit.component.ts:375). Topics can also be imported from CSV at agenda/topics/import.

## Why

age-04 and pla-14 are each rated yes by three competitors; pla-14 is in the core area.

## What is built today

- RecurringItemsDialog copies items flagged isRecurring onto the current agenda, from the live screen (reachable since #1441 through Open live meeting).
- MeetingSeriesService copies a meeting without its agenda.

## What changes

1. An AgendaTemplate schema (name, meeting type, ordered item titles and types); a meeting type can name its default template.
2. The meeting Agenda widget gets Start from template and Copy from a meeting, both adding items in order to an empty or existing agenda.
3. A copied item keeps title, type and description; papers are copied only when asked.
4. MeetingSeriesService copies the agenda too.

## Out of scope

- Copying decisions or votes.

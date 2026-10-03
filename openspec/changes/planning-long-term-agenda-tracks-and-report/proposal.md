---
kind: code
depends_on: []
---

# Proposal: planning-long-term-agenda-tracks-and-report

## Summary

The long-term agenda is a flat list today. A big topic such as the energy
transition reaches the council along several paths at once, and each path has
its own period, body and portfolio holder. This change lets a clerk group
those paths as tracks under one topic, filter the list by the named portfolio
holder and by the official who drafts the proposal, and download the filtered
list as a formatted report instead of only a spreadsheet.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`. Two rows, one
cluster: both change the same page (`PlannedAgenda`, `/planned-agenda`) and the
same schema.

### pla-18, show several parallel tracks within one topic of the long-term agenda

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`.

Demand row: origin `changelog`, originUrl
https://www.notubiz.nl/nieuws/van-feedback-naar-vooruitgang-dit-is-de-nieuwe-lta

Competitor cells rated yes, verbatim:

- notubiz = yes | evidence: https://www.notubiz.nl/nieuws/van-feedback-naar-vooruitgang-dit-is-de-nieuwe-lta (29/4/2026) states the LTA now shows parallel trajectories within one topic

### pla-19, filter the long-term agenda by status, portfolio holder or author and export it as a report

Own rating `partial`, built.state `built`, owner `ConductionNL/decidiq`.

Demand row: origin `changelog`, originUrl
https://www.notubiz.nl/nieuws/van-feedback-naar-vooruitgang-dit-is-de-nieuwe-lta

Competitor cells rated yes, verbatim:

- notubiz = yes | evidence: https://www.notubiz.nl/nieuws/van-feedback-naar-vooruitgang-dit-is-de-nieuwe-lta states search and filters on status, portefeuillehouder, steller and fasering plus an export for reporting or archiving
- ibabs = yes | evidence: https://support.ibabs.com/docs/bestuurlijke-planning.md states dossiers are selected by owner, organisation unit, agendatype or planned date and exported as a list

The matrix note, verbatim: "The long-term agenda can be filtered by status and
by kind of owner, and the rows exported as a spreadsheet. It cannot be filtered
by the named portfolio holder or by author, and the export is a data file
rather than a formatted report."

## Why

The long-term agenda (termijnagenda) is where a council sees what the college
will bring and when. It is in decidiq's core area, planning. A flat list forces
a clerk to either lump three tracks into one vague item or split one topic
into three unrelated rows. Councillors ask for "everything of wethouder De
Boer" and for the list as a document they can take into a committee, and a
CSV answers neither.

## What changes

1. `PlannedAgendaItem.parentItem`: a track points at its topic, one level deep.
   The topic's detail page lists its tracks, and the topic carries a count of
   its open tracks.
2. `PlannedAgendaItem.owner` becomes facetable and a column, resolved to the
   portfolio holder's name.
3. `PlannedAgendaItem.author`: the official who drafts the proposal (the
   steller), a `Person`, facetable and a column.
4. "Download report" on the long-term agenda page: a PDF, rendered through
   filinq, of the rows the current filters select, grouped by topic and
   ordered by period, headed with the filters used and the date.

## How it relates to the termijnagenda change

It extends the capability `termijnagenda-register` of the open change
`termijnagenda`, whose REQ-LTA-007 filters on governance body, lifecycle,
expected type and period and exports CSV. It supersedes nothing there; it adds
requirements.

The `termijnagenda` proposal puts "College-internal project management" out of
scope: "no subtasks, no capacity planning". A track is not a subtask. It is a
separate thing the council will receive, with its own period and body, grouped
under a heading. Nothing here plans the college's work behind it.

## Out of scope

- Tracks inside tracks. One level is what the evidence shows and what a list
  can show.
- A Word or Excel report. The spreadsheet export stays as it is; the report is
  a PDF.
- Publishing tracks. Each track has its own `publicationDate` like any item.

## Risks

- **An item that is both a topic and a track.** Refused on save: a track's
  topic cannot itself be a track, and an item with tracks cannot become one.
- **filinq absent.** The report then falls back to the same HTML the PDF is
  made from, saved to the user's Files with a note that filinq is not
  installed, the way `MinutesDocumentService` already degrades.

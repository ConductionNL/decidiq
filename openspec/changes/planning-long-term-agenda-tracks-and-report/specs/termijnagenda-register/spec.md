# termijnagenda-register Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [termijnagenda](../../../termijnagenda/) (the register, REQ-LTA-001 to REQ-LTA-010)
- [planning-long-term-agenda-tracks-and-report](../../) (this delta)

## Purpose

Groups parallel tracks under one topic of the long-term agenda, filters the
list by the named portfolio holder and by the author, and downloads the
filtered list as a formatted report. Closes matrix rows pla-18 and pla-19.

**Standards**: Schema.org `PlanAction`, `isPartOf`.

## ADDED Requirements

### Requirement: REQ-LTAT-001 A long-term agenda item can be a track under a topic

`PlannedAgendaItem` SHALL carry an optional `parentItem` referencing another
planned agenda item, its topic. A topic's detail page SHALL list its tracks
with subject, body, period, portfolio holder and status, and SHALL let the
clerk add a track in place. The topic SHALL carry `trackCount` and
`openTrackCount` as declared aggregations.

#### Scenario: The clerk splits a topic into tracks

- GIVEN the topic "Energietransitie" on the long-term agenda
- WHEN the griffier opens it and adds the tracks "Warmtevisie 2.0" for 2026-Q4 and "Regionale energiestrategie 2.0" for 2027-Q1 in the Tracks widget
- THEN the topic page lists both tracks with their own period and status, and the topic shows two open tracks

#### Scenario: The list can show topics only

- GIVEN the topic and its two tracks
- WHEN the griffier chooses the quick filter "Topics only" on the long-term agenda
- THEN only "Energietransitie" is listed

### Requirement: REQ-LTAT-002 Tracks are one level deep

Saving a planned agenda item SHALL be refused when its `parentItem` points at
an item that has a `parentItem` itself, when the item already has tracks, or
when it points at the item itself. The refusal SHALL name the topic.

#### Scenario: A track cannot hold tracks

- GIVEN the track "Warmtevisie 2.0" under "Energietransitie"
- WHEN someone saves a new item with `parentItem` set to "Warmtevisie 2.0"
- THEN the save is refused with "Warmtevisie 2.0 is a track itself and cannot hold tracks"
- @e2e exclude save guard; covered by PHPUnit on PlannedAgendaTrackGuardListener with the real ObjectCreatingEvent

### Requirement: REQ-LTAT-003 The list filters by portfolio holder and by author

`PlannedAgendaItem.owner` SHALL be facetable and shown as the column "Portfolio
holder" with the person's name. `PlannedAgendaItem` SHALL carry an optional
`author`, the official who drafts the proposal, as a facetable `Person`
reference shown as the column "Author".

#### Scenario: A councillor asks for everything of one portfolio holder

- GIVEN items owned by wethouder De Boer and by wethouder Yilmaz
- WHEN the griffier picks "De Boer" under the "Portfolio holder" facet on the long-term agenda
- THEN only De Boer's items are listed, each showing her name in the Portfolio holder column

#### Scenario: The clerk finds what one official is drafting

- GIVEN two items with author S. Visser and one with another author
- WHEN the griffier picks "S. Visser" under the "Author" facet
- THEN the two items are listed

### Requirement: REQ-LTAT-004 The filtered list downloads as a formatted report

The long-term agenda page SHALL offer "Download report". The report SHALL hold
exactly the rows the active filters select that the user may read, tracks
grouped under their topic and ordered by period, headed with the filters in
plain words and the date. It SHALL be a PDF rendered through filinq. When
filinq is not installed the HTML source SHALL be saved instead, with a note
saying so. A filter that selects nothing SHALL be refused with "No items match
these filters".

#### Scenario: The griffier takes the list into a committee

- GIVEN the filters "Portfolio holder: De Boer" and "Status: planned"
- WHEN the griffier presses "Download report" on the long-term agenda
- THEN a PDF opens that lists De Boer's planned items grouped by topic, headed "Portfolio holder: De Boer, status: planned" and today's date

#### Scenario: The report never shows a row the list would hide

- GIVEN an item the user may not read
- WHEN the user downloads a report with no filters
- THEN that item is not in the report
- @e2e exclude access contract; covered by PHPUnit on PlannedAgendaReportService with a denied object

#### Scenario: Without filinq the source is saved with a note

- GIVEN filinq is not installed
- WHEN the griffier presses "Download report"
- THEN an HTML report is saved in their Files and the page says "filinq is not installed, so the report was saved as HTML"
- @e2e exclude degraded path; covered by PHPUnit with FilinqPdf returning null

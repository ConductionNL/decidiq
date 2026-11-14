---
kind: code
depends_on: []
---

# Proposal: insight-decisions-report-by-period-and-body

## Summary

The Decisions report counts every decision the organisation ever took, for every body at once. A griffie wants to answer "what did the council decide in 2026" and "what did the college decide this quarter". This change gives the report a period picker and a body picker, lets every tile and chart follow both, and opens the matching list for export.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26).

### ins-02, report on the decisions taken per period and per body

Own rating `partial`, built.state `built`, owner `ConductionNL/openregister`. Matrix note: "A decisions report exists but only for all time and all bodies; it cannot be cut per period or per body."

No demand row (origin `code`).

Competitor cells rated yes:

- notubiz: "https://www.kennisbank.notubiz.nl/kennisclips/besluitenlijsten-bekijken states decision lists are filtered by bestuurslaag (raad, college) and period with PDF output"
- ibabs: "https://support.ibabs.com/docs/besluiten.md states all decisions are searchable, filterable and exported to Word or Excel"

The row's `built.owner` was `ConductionNL/openregister`, on the page's own claim that OpenRegister's aggregation endpoint evaluates scalar equality only. The openregister lane of this pass checked and found that false: `lib/Service/Aggregation/AggregationQuery.php:12-13` supports "scalar equality + in / notIn / gt / gte / lt / lte / ne". The per-period cut is therefore decidiq's report configuration, the row's provider is already decidiq, and the owner moves to `ConductionNL/decidiq` in this pass.

## Why

NotuBiz filters decision lists by bestuurslaag and period and prints them; iBabs makes every decision searchable, filterable and exportable. decidiq's report has the charts but not the cut, and its page note (`_note` of `DecisionsReport` in `src/manifest.json:1354`) tells the next person not to try. The components already support it: nextcloud-vue's `CnDashboardPage` takes a `dateRange` and publishes it as `@workspace.dateFrom` and `@workspace.dateTo`, and its widgets resolve those tokens in operator filters.

A decision also has no reliable body today. `Decision.governingBody` is the free-text BRC `bestuursorgaan` ("de raad", "het college"), and `Decision.meeting` points at a meeting that points at a governance body. This change adds a reference field for the deciding body and fills it from the meeting.

## What changes

1. `Decision.decidingBody`, a reference to a `GovernanceBody`, facetable, filled from the meeting's body when a decision is saved with a meeting, and editable otherwise.
2. The Decisions report gets a period picker (this year, last year, this quarter, a custom range) and a body picker, and every tile and chart counts decisions of that period and body.
3. A By body chart, and a Show these decisions link that opens the Decisions list with the same period and body applied, where the existing export takes over.
4. The page note that forbids operator filters is replaced with one that says what the aggregation endpoint does support.

## Out of scope

- A formatted, printable decision list (besluitenlijst per vergadering). Minutes and decision list documents belong to the minutes flow.
- Reports on motions' implementation status (`followup-implementation-progress`).

## Risks

- Existing decisions have no `decidingBody`. A repair step fills it once from `meeting` for every decision that has one; decisions without a meeting stay empty and count under "No body" in the chart.
- A date filter on a field some decisions lack (`decisionDate`) excludes them silently. The report counts on `decisionDate` and the period picker says so in its label.

# dashboard Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [insight-decisions-report-by-period-and-body](../../) (this delta)

## Purpose

Lets the Decisions report answer what a body decided in a period, and hands the same set to the Decisions list for export. Closes decidiq matrix row ins-02.

**Standards**: OpenRaadsinformatie `Besluit`, Schema.org `Organization` for the deciding body.

## ADDED Requirements

### Requirement: REQ-DRP-001 A decision names the body that took it

A decision SHALL carry `decidingBody`, a reference to a governance body. When a decision is saved with a meeting and no deciding body, the app SHALL set it to the meeting's governance body. A body set by hand SHALL not be overwritten. Existing decisions with a meeting SHALL be filled once by a repair step.

#### Scenario: A decision taken in a council meeting
- GIVEN the griffier records a decision from the Decisions widget of the council meeting of 14 October
- WHEN the decision is saved
- THEN its deciding body is the municipal council

### Requirement: REQ-DRP-002 Every figure on the Decisions report follows the chosen period

The Decisions report SHALL offer a period picker with this year, last year, this quarter and a custom range, and every tile and chart on it SHALL count only decisions whose decision date falls in that period.

#### Scenario: The griffier looks at last year
- GIVEN 40 decisions dated in 2025 and 25 in 2026, of which 30 and 20 adopted
- WHEN the griffier picks last year on the Decisions report
- THEN the Adopted tile shows 30

### Requirement: REQ-DRP-003 The Decisions report can be cut per body

The Decisions report SHALL offer a picker listing the installation's governance bodies, and every figure SHALL count only the chosen body's decisions when one is picked. A By body chart SHALL show the count per deciding body.

#### Scenario: Only the college
- GIVEN decisions of the council and of the college
- WHEN the griffier picks the college
- THEN every tile and chart counts the college's decisions only

### Requirement: REQ-DRP-004 The report opens its decisions as an exportable list

The report SHALL offer a Show these decisions link that opens the Decisions list with the chosen period and body applied, so the list's export exports exactly the decisions the report counts.

#### Scenario: Exporting the college's decisions of a quarter
- GIVEN the college and this quarter are picked on the report
- WHEN the griffier chooses Show these decisions and exports the list
- THEN the export holds the college's decisions of this quarter and no others

# conflict-of-interest Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-agenda-management. Update Purpose after archive.

## Requirements

### Requirement: REQ-COI-001 Participant declares conflict of interest for an agenda item
Any Participant SHALL be able to declare a conflict of interest (COI) against a specific AgendaItem by adding a structured note to the item. The note title SHALL be prefixed with `COI:` and the note body SHALL include the Participant's name and a free-text reason. The declaration SHALL be saved via the OpenRegister built-in notes mechanism on AgendaItem.

#### Scenario: Participant declares COI
- **GIVEN** a published AgendaItem and the current user is an active Participant
- **WHEN** the user clicks "Belangenverstrengeling melden" on the AgendaItem detail page
- **THEN** a dialog opens with fields: "Reden voor ontheffing" (text area, required)
- **AND** on submit, a note is added to the AgendaItem with title `COI: [Participant displayName]` and the reason in the body

#### Scenario: COI requires a stated reason
- **WHEN** the Participant submits the COI declaration with an empty reason field
- **THEN** a validation error is shown: "Geef een reden op voor de belangenverstrengeling"
- **AND** the note is not saved

#### Scenario: Participant can view their own COI declaration
- **WHEN** a Participant who has declared COI views the AgendaItem
- **THEN** the Notes section in `CnObjectSidebar` shows their COI note with a "COI" label badge

---

### Requirement: REQ-COI-002 Chair sees all COI declarations for a meeting
The Meeting detail page and live agenda view SHALL display a COI summary panel showing all AgendaItems that have one or more COI declarations, grouped by item, with the declaring Participant's name.

#### Scenario: COI summary panel shows all declarations
- **GIVEN** a Meeting where Participant A declared COI on item 3 and Participant B on items 3 and 5
- **WHEN** the chair opens the Meeting detail page
- **THEN** a "Verklaringen belangenverstrengeling" section lists: item 3 (Participant A, Participant B), item 5 (Participant B)

#### Scenario: No COI declarations shows empty state
- **GIVEN** a Meeting where no Participants have declared COI
- **WHEN** the chair views the COI summary panel
- **THEN** the section shows: "Geen verklaringen van belangenverstrengeling ingediend"

#### Scenario: COI badge on agenda item row
- **WHEN** an AgendaItem in the agenda list has one or more COI notes
- **THEN** a "COI" badge is displayed next to the item title in the agenda list
- **AND** the count of declarations is shown (e.g., "COI (2)")

---

### Requirement: REQ-COI-003 Motion is linked to a decision agenda item
The app SHALL allow a Motion to be linked to a `decision`-type AgendaItem via the OpenRegister relations mechanism. The linked Motion(s) SHALL appear in the AgendaItem detail view. A single AgendaItem may have multiple linked Motions.

#### Scenario: Secretary links a motion to an agenda item
- **GIVEN** a `decision`-type AgendaItem and an existing Motion in the same Meeting
- **WHEN** the secretary uses the "Motie koppelen" action on the AgendaItem
- **THEN** an OpenRegister relation is created from AgendaItem → Motion
- **AND** the Motion title appears in the "Gekoppelde moties" section of the AgendaItem detail page

#### Scenario: Multiple motions linked to one agenda item
- **GIVEN** a `decision`-type AgendaItem
- **WHEN** two Motions are linked to the item
- **THEN** both Motion titles are listed in the "Gekoppelde moties" section

#### Scenario: Discussion items cannot link motions
- **GIVEN** an AgendaItem with `itemType: "discussion"`
- **WHEN** the user views the AgendaItem detail page
- **THEN** the "Motie koppelen" action is not available
- **AND** no "Gekoppelde moties" section is shown

---

### Requirement: REQ-COI-004 Declared COI is recorded in the audit trail
All COI note additions and removals SHALL be captured in the OpenRegister built-in audit trail for the AgendaItem. The audit trail SHALL be viewable by the secretary and chair via the `CnObjectSidebar` Audit Trail tab.

#### Scenario: COI note addition appears in audit trail
- **GIVEN** a COI declaration note added by Participant A on AgendaItem "Bestemmingsplan Centrum"
- **WHEN** the secretary opens the `CnObjectSidebar` Audit Trail tab for that AgendaItem
- **THEN** an audit entry is visible showing the note was added by Participant A with timestamp

#### Scenario: COI note removal is logged
- **WHEN** the chair removes a COI note (e.g., participant withdraws the declaration)
- **THEN** an audit entry is created recording the deletion, the user who performed it, and the timestamp

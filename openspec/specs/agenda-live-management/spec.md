# agenda-live-management Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-agenda-management. Update Purpose after archive.

## Requirements

### Requirement: REQ-LIV-001 Chair can amend the agenda during an open meeting
When a Meeting has lifecycle `opened`, the chair SHALL be able to add new AgendaItems, remove items (with confirmation), and reorder items. All amendments SHALL be saved immediately via `ObjectService.saveObject()`. Non-chair participants SHALL see the updated agenda in real-time on page refresh or store poll.

#### Scenario: Chair adds an item during the meeting
- **GIVEN** a Meeting with lifecycle `opened`
- **WHEN** the chair clicks "Agendapunt toevoegen" in the live agenda view
- **THEN** a `CnFormDialog` opens for a new AgendaItem linked to the meeting
- **AND** on save, the item appears in the agenda with the next available `orderNumber`

#### Scenario: Non-chair cannot amend the agenda during the meeting
- **GIVEN** a Meeting with lifecycle `opened` and the current user has role `member` or `observer`
- **WHEN** the user views the live agenda page
- **THEN** add, remove, and reorder controls are hidden
- **AND** the agenda is shown in read-only mode

#### Scenario: Chair removes an item with confirmation
- **WHEN** the chair clicks the delete icon on an agenda item during an open meeting
- **THEN** a `CnDeleteDialog` confirmation dialog is shown with the warning "Agendapunt wordt verwijderd uit deze vergadering"
- **AND** on confirmation, the item is deleted and remaining items are renumbered

---

### Requirement: REQ-LIV-002 BOB phase is tracked per agenda item during the meeting
For `discussion` and `decision` type AgendaItems, the chair SHALL be able to advance the BOB phase (Beeldvorming → Oordeelsvorming → Besluitvorming → Afgerond) using the `status` field. The current phase SHALL be visible as a `CnTimelineStages` component on the agenda item detail and in the live agenda panel.

#### Scenario: Chair advances BOB phase
- **GIVEN** an AgendaItem with `itemType: "discussion"` and `status: "beeldvorming"`
- **WHEN** the chair clicks "Volgende fase" in the live meeting view
- **THEN** `AgendaService::advanceBobPhase()` is called, the `status` is updated to `oordeelsvorming`
- **AND** the `CnTimelineStages` component highlights the new phase

#### Scenario: BOB phase timeline shows current stage visually
- **WHEN** a participant views an AgendaItem with `status: "oordeelsvorming"` during a live meeting
- **THEN** the `CnTimelineStages` component shows three stages (Beeldvorming, Oordeelsvorming, Besluitvorming) with the second stage highlighted as active

#### Scenario: Informational items do not show BOB phase
- **GIVEN** an AgendaItem with `itemType: "informational"`
- **WHEN** the user views the item during a meeting
- **THEN** no BOB phase timeline is displayed

#### Scenario: Completed phase cannot be reversed by member
- **GIVEN** an AgendaItem at phase `besluitvorming`
- **WHEN** a Participant with role `member` views the item
- **THEN** the "Vorige fase" button is hidden; only the chair can move phases backward

---

### Requirement: REQ-LIV-003 Consent agenda items (hamerstukken) are batch-adopted
Decision-type AgendaItems tagged with `hamerstuk` SHALL be grouped in a "Hamerstukken" section at the top of the live agenda. The chair SHALL be able to adopt all consent items in a single action without individual debate. The batch action SHALL update `status` to `afgerond` on all tagged items via `AgendaService::processHamerstukken()`.

#### Scenario: Hamerstukken appear in dedicated section
- **GIVEN** a Meeting with 3 AgendaItems tagged `hamerstuk` and 4 regular items
- **WHEN** the chair or secretary opens the live meeting agenda view
- **THEN** a "Hamerstukken" section appears above the regular agenda items listing the 3 consent items

#### Scenario: Chair batch-adopts all hamerstukken
- **WHEN** the chair clicks "Hamerstukken vaststellen" in the hamerstukken section
- **THEN** a confirmation dialog shows: "3 agendapunten worden als hamerstuk vastgesteld"
- **AND** on confirmation, all 3 AgendaItems have `status` set to `afgerond` via `processHamerstukken()`

#### Scenario: Single item removed from consent agenda before adoption
- **WHEN** the chair clicks "Uit hamerstukken halen" on one consent item before batch adoption
- **THEN** the `hamerstuk` tag is removed from that AgendaItem
- **AND** it moves to the regular agenda section for individual debate

#### Scenario: Adopted hamerstukken are visually distinguished
- **WHEN** a consent item's `status` is `afgerond`
- **THEN** the item row shows a green "Vastgesteld" badge in the agenda list

---

### Requirement: REQ-LIV-004 Live agenda shows which item is currently being discussed
The live meeting view SHALL indicate which AgendaItem is currently active (being discussed or voted on) by marking it with an "Actief" badge. The chair SHALL be able to activate an item by clicking it. Only one item can be active at a time.

#### Scenario: Chair activates an agenda item
- **GIVEN** a Meeting with lifecycle `opened`
- **WHEN** the chair clicks an AgendaItem and selects "Activeer dit agendapunt"
- **THEN** the item receives an "Actief" visual indicator (e.g., highlighted row or `CnStatusBadge`)
- **AND** any previously active item loses its active state

#### Scenario: Participants see which item is active
- **WHEN** a Participant refreshes the live meeting page
- **THEN** the currently active AgendaItem is visually distinguished with the "Actief" indicator

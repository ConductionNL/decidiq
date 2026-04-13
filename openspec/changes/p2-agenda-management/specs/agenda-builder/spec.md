## ADDED Requirements

### Requirement: REQ-BLD-001 Agenda items are ordered by orderNumber ascending
The app SHALL display AgendaItems within a meeting ordered by `orderNumber` ascending in both the meeting detail view and the standalone agenda builder view.

#### Scenario: User views meeting agenda
- **GIVEN** a Meeting with AgendaItems having orderNumbers 1, 3, 2
- **WHEN** the user opens the MeetingDetail agenda section
- **THEN** AgendaItems are displayed in orderNumber sequence: 1, 2, 3

#### Scenario: New item appended at end
- **WHEN** the user adds a new AgendaItem without specifying `orderNumber`
- **THEN** the item is assigned `orderNumber` equal to the current maximum + 1 for that meeting

---

### Requirement: REQ-BLD-002 Chair can reorder agenda items via drag-and-drop
The app SHALL allow users with chair or secretary role to reorder AgendaItems within a meeting using drag-and-drop. The new order SHALL be persisted by updating `orderNumber` values via `ObjectService.saveObject()`.

#### Scenario: User drags an item to a new position
- **GIVEN** an agenda with items at positions 1, 2, 3, 4
- **WHEN** the user drags item at position 4 to position 2
- **THEN** items are renumbered: former 1 stays at 1, dragged item becomes 2, former 2 becomes 3, former 3 becomes 4
- **AND** all affected AgendaItems are persisted via `ObjectService.saveObject()`

#### Scenario: Reorder is accessible via keyboard
- **WHEN** a user navigates to an agenda item row using keyboard
- **THEN** up/down arrow actions are available to move the item one position and save the new order

---

### Requirement: REQ-BLD-003 Agenda item types determine discussion workflow
Each AgendaItem SHALL have an `itemType` of `informational`, `discussion`, or `decision`. The type SHALL be displayed as a `CnStatusBadge` in the agenda list and shall determine the BOB phase progression available in `agenda-live-management`.

#### Scenario: User creates a decision item
- **WHEN** the user opens the `CnFormDialog` for a new AgendaItem and selects `itemType = decision`
- **THEN** the item is saved with `itemType: "decision"` and displays a "Besluit" badge in the agenda list

#### Scenario: Informational items cannot have motions linked
- **GIVEN** an AgendaItem with `itemType = "informational"`
- **WHEN** the user views the AgendaItem detail page
- **THEN** the "Link motion" action is hidden and a tooltip explains that only decision-type items support motions

---

### Requirement: REQ-BLD-004 Estimated duration is shown and summed per agenda
Each AgendaItem SHALL display its `estimatedDuration` in minutes. The MeetingDetail agenda section SHALL show a total estimated duration summed from all items.

#### Scenario: Duration displayed on item
- **WHEN** an AgendaItem has `estimatedDuration: 45`
- **THEN** the agenda list row shows "45 min" in the duration column

#### Scenario: Total meeting duration shown
- **WHEN** a meeting has AgendaItems with estimatedDuration values 5, 10, 45, 60, 10
- **THEN** the agenda builder header shows "Totale duur: 130 min"

#### Scenario: Item without duration
- **WHEN** an AgendaItem has no `estimatedDuration` set
- **THEN** the row shows "—" in the duration column and the item is excluded from the total sum

---

### Requirement: REQ-BLD-005 Recurring items auto-populate on new meeting agendas
AgendaItems with `isRecurring: true` SHALL be offered as template items when a new meeting is created. The user SHALL be able to copy recurring items to the new meeting's agenda in bulk.

#### Scenario: User creates a new meeting with recurring items
- **GIVEN** AgendaItems with `isRecurring: true` exist (e.g., "Opening vergadering", "Rondvraag")
- **WHEN** the user creates a new meeting and opens the agenda builder
- **THEN** a "Terugkerende agendapunten toevoegen" button is shown listing the available recurring items

#### Scenario: Recurring items are copied, not referenced
- **WHEN** the user clicks "Toevoegen" on a recurring item template
- **THEN** a new AgendaItem is created for the new meeting with the same `title`, `itemType`, `estimatedDuration`, and `isRecurring: true`
- **AND** the original template item is not modified

---

### Requirement: REQ-BLD-006 Participants can propose agenda items for chair review
Any Participant SHALL be able to submit an AgendaItem proposal (status `voorstel`) for a scheduled meeting. The chair or secretary SHALL be able to approve or reject the proposal. Approved items enter the agenda with the next available `orderNumber`.

#### Scenario: Participant submits a proposal
- **GIVEN** a Meeting with lifecycle `scheduled`
- **WHEN** a Participant clicks "Agendapunt voorstellen" and fills in the form
- **THEN** an AgendaItem is created with `status: "voorstel"` and is visible in the chair's proposal inbox

#### Scenario: Chair approves a proposal
- **WHEN** the chair clicks "Goedkeuren" on a proposed AgendaItem
- **THEN** the `status` field is cleared (or set to `beeldvorming`) and the item is assigned the next `orderNumber` in the meeting agenda
- **AND** the proposing Participant receives a Nextcloud notification

#### Scenario: Chair rejects a proposal
- **WHEN** the chair clicks "Afwijzen" and optionally enters a reason
- **THEN** the AgendaItem `status` is set to `afgewezen` and the proposing Participant receives a notification with the reason

---

### Requirement: REQ-BLD-007 Spokesperson is assigned per agenda item
The app SHALL allow the secretary or chair to assign a Participant as spokesperson (presenter) for an AgendaItem via an OpenRegister relation. The spokesperson name SHALL be visible in the agenda list and detail view.

#### Scenario: Assign spokesperson
- **GIVEN** an AgendaItem in the agenda builder
- **WHEN** the user clicks "Spreker toewijzen" and selects a Participant
- **THEN** an OpenRegister relation `spokesperson` is saved from AgendaItem → Participant
- **AND** the Participant's display name appears in the "Spreker" column of the agenda list

#### Scenario: Remove spokesperson
- **WHEN** the user clicks the remove icon next to the assigned spokesperson
- **THEN** the relation is deleted and the "Spreker" column shows "—"

# Delta Spec: meeting-agenda

**Change:** p2-meeting-management-core-t1
**Capability:** meeting-agenda — Agenda item creation, ordering, typing, and linking
**Schema.org type:** `meeting:AgendaItem` (ORI extension)

## ADDED Requirements

### Requirement: REQ-MTA-001 — Create an agenda item for a meeting

The system SHALL allow authorized users to create an agenda item linked to a meeting. The agenda item SHALL be stored as an OpenRegister object with a relation to the Meeting wrapper object. Required fields: title, itemType, orderNumber.

**Entity properties (ADR-000):**
| Property | Type | Required | Description |
|----------|------|----------|-------------|
| title | string | Yes | Agenda item title |
| itemType | string | Yes | Type: procedural, discussion, decision, motion, report, information |
| orderNumber | integer | Yes | Position on the agenda (1-based) |
| estimatedDuration | integer | No | Estimated minutes |
| actualDuration | integer | No | Actual minutes spent |
| description | string | No | Detailed description |
| isRecurring | boolean | No | Appears on every meeting of the series |

#### Scenario: REQ-MTA-001-S1 — Successful agenda item creation
- **GIVEN** a meeting "Vergadering Gemeenteraad Delft" exists
- **WHEN** the user creates an agenda item with title "Opening en mededelingen", itemType "procedural", orderNumber 1
- **THEN** the system creates an OpenRegister object of schema "agenda-item" with a relation to the meeting wrapper
- **AND** the API returns HTTP 201 with the agenda item including its generated id

#### Scenario: REQ-MTA-001-S2 — Auto-increment order number
- **GIVEN** a meeting has agenda items with orderNumber 1, 2, 3
- **WHEN** the user creates an agenda item without specifying orderNumber
- **THEN** the system assigns orderNumber 4

### Requirement: REQ-MTA-002 — List agenda items for a meeting

The system SHALL return all agenda items for a given meeting, sorted by orderNumber ascending.

#### Scenario: REQ-MTA-002-S1 — Retrieve ordered agenda
- **GIVEN** a meeting has 5 agenda items with orderNumber 1 through 5
- **WHEN** the user sends GET `/api/meetings/abc-123` with agenda items expanded
- **THEN** the response includes agendaItems array sorted by orderNumber ascending

### Requirement: REQ-MTA-003 — Reorder agenda items

The system SHALL allow authorized users to reorder agenda items by updating their orderNumber values. When an item is moved, all affected items SHALL have their orderNumber updated to maintain a contiguous sequence.

#### Scenario: REQ-MTA-003-S1 — Move item from position 3 to position 1
- **GIVEN** a meeting has items A(1), B(2), C(3), D(4)
- **WHEN** the user moves item C to position 1
- **THEN** the items are reordered to C(1), A(2), B(3), D(4)

#### Scenario: REQ-MTA-003-S2 — Reorder via PUT
- **GIVEN** an agenda item exists with orderNumber 3
- **WHEN** the user sends PUT `/api/agendaitems/item-id` with orderNumber 1
- **THEN** the system updates orderNumber for the moved item and all affected items

### Requirement: REQ-MTA-004 — Update an agenda item

The system SHALL allow authorized users to update agenda item properties (title, itemType, estimatedDuration, description, isRecurring). The orderNumber SHALL only be updated via the reorder mechanism (REQ-MTA-003).

#### Scenario: REQ-MTA-004-S1 — Update item description
- **GIVEN** an agenda item "Bestemmingsplan Westzone" exists
- **WHEN** the user sends PUT `/api/agendaitems/item-id` with description "Inclusief nota van beantwoording zienswijzen"
- **THEN** the system updates the description and records the change in the audit trail

### Requirement: REQ-MTA-005 — Delete an agenda item

The system SHALL allow authorized users to delete an agenda item. Remaining items SHALL have their orderNumber values adjusted to maintain a contiguous sequence.

#### Scenario: REQ-MTA-005-S1 — Delete and resequence
- **GIVEN** a meeting has items A(1), B(2), C(3)
- **WHEN** the user deletes item B
- **THEN** item B is removed and item C is updated to orderNumber 2

### Requirement: REQ-MTA-006 — Agenda item types

The system SHALL support the following agenda item types: `procedural`, `discussion`, `decision`, `motion`, `report`, `information`. The itemType SHALL be validated against this enumeration on create and update.

#### Scenario: REQ-MTA-006-S1 — Valid item type accepted
- **GIVEN** the user creates an agenda item with itemType "motion"
- **WHEN** the request is processed
- **THEN** the item is created successfully

#### Scenario: REQ-MTA-006-S2 — Invalid item type rejected
- **GIVEN** the user creates an agenda item with itemType "voting"
- **WHEN** the request is processed
- **THEN** the system returns HTTP 400 with a validation error

---
status: done
---

# action-item-board-via-deck-leaf Specification

## Purpose
Surfaces meeting and decision action items as cards on a Nextcloud Deck board bound to the underlying OpenRegister object, with the CalDAV VTODO record remaining authoritative. Delegation and reclaim map onto VTODO assignee changes and OpenRegister audit events, and a migration projects legacy in-app Task and Delegation objects onto the board before archiving them.
## Requirements
### Requirement: REQ-AI-DECK-001 VTODO is authoritative; Deck leaf is the board projection
The system SHALL treat the CalDAV VTODO ActionItem (ADR-002) as the authoritative record of an action item's content and status, and SHALL surface action items as cards on a Nextcloud Deck board bound to the meeting or decision OpenRegister object via the ADR-019 integration registry. The system SHALL NOT persist action items in an app-local `Task` schema.

#### Scenario: Action items appear as deck cards bound to the meeting
- **GIVEN** a meeting with action-item VTODOs (created by `ActionItemExtractionService`)
- **AND** the Nextcloud Deck app is installed and the deck leaf is registered
- **WHEN** a participant opens the action-item tab on the meeting detail page
- **THEN** the registry-driven deck board bound to the meeting object renders one card per VTODO ActionItem
- **THEN** the card reflects the VTODO's title, assignee, due date, and status

#### Scenario: VTODO remains the source of truth
- **WHEN** an action item's status changes
- **THEN** the change is reflected on the authoritative VTODO record
- **THEN** the deck card reflects the VTODO state (the board is a projection, not a second store)

#### Scenario: Deck app not installed degrades gracefully
- **GIVEN** the Nextcloud Deck app is not installed
- **WHEN** a participant opens the meeting or decision detail page
- **THEN** the board tab is hidden and action items remain reachable via the Tasks app over the same VTODOs
- **THEN** no error is raised

### Requirement: REQ-AI-DECK-002 Delegation and reclaim map onto VTODO and audit
The system SHALL represent action-item delegation as a change of assignee on the VTODO (reflected as a card reassignment on the board) and SHALL record a reclaim by the original delegator as an OpenRegister audit event on the meeting/decision object. The system SHALL NOT persist a standalone in-app `Delegation` object for these semantics.

#### Scenario: Reassigning an action item
- **GIVEN** an action-item VTODO assigned to participant A
- **WHEN** the delegator reassigns it to substitute B
- **THEN** the VTODO ATTENDEE is updated to B and the deck card shows B as assignee

#### Scenario: Reclaim is recorded in the audit trail
- **GIVEN** an action item currently assigned to a substitute
- **WHEN** the original delegator reclaims it
- **THEN** the assignee reverts to the delegator on the VTODO
- **THEN** an OpenRegister audit event records the reclaim against the meeting/decision object

### Requirement: REQ-AI-DECK-003 Migrate legacy Task/Delegation objects, archived not deleted
The system SHALL provide an idempotent migration that, for each existing in-app `Task` object, ensures a VTODO ActionItem and a corresponding deck card exist, and for each `Delegation` object applies the assignee/audit mapping, then archives the legacy `Task` and `Delegation` objects via OpenRegister's archival workflow without hard-deleting them.

#### Scenario: Legacy Task projected and archived
- **GIVEN** an in-app `Task` object with no corresponding VTODO
- **WHEN** the migration runs
- **THEN** a VTODO ActionItem is created from the Task and a deck card appears on the bound board
- **THEN** the legacy `Task` object is set to an archived state and remains queryable for audit

#### Scenario: Migration is idempotent
- **GIVEN** the migration has already run
- **WHEN** it runs again
- **THEN** no duplicate VTODOs or deck cards are created and already-archived objects are skipped

### Requirement: REQ-AI-DECK-004 Creation paths write VTODOs, not an app-local store
Every action-item creation path (meeting/decision extraction, minutes follow-ups, MCP tools) SHALL
create or update a CalDAV VTODO ActionItem as the authoritative record. The system SHALL NOT create
action items via `ObjectService.saveObject(schema: 'ActionItem')` into an app-local store. The
`ActionItem` schema SHALL be retained as a read-only projection of the VTODOs (not a write target);
app-side writes to it SHALL be rejected.

#### Scenario: Extraction creates a VTODO
- GIVEN minutes from which action items are extracted
- WHEN `ActionItemExtractionService` saves a confirmed candidate
- THEN a CalDAV VTODO ActionItem is created (title / assignee / dueDate / status)
- AND no app-local `ActionItem` object is written as the authoritative record.

#### Scenario: Projection rejects writes
- GIVEN the read-only `ActionItem` projection
- WHEN any code path attempts to write/update an `ActionItem` object directly
- THEN the write is rejected (the VTODO remains the only authoritative write path).

#### Scenario: Deck leaf is registered and renders the board
- GIVEN the Deck app is installed and a meeting has action-item VTODOs
- WHEN the meeting/decision detail action-items tab opens
- THEN the registry-driven Deck board (leaf `deck`, bound to the object) renders one card per VTODO.

### Requirement: REQ-AI-DECK-005 Dashboard/KPIs count the authoritative source
The "Open action items" KPI and any action-item list/filter SHALL count VTODO-backed action items
(or their read-only projection), not the retired app-local write store.

#### Scenario: KPI reflects VTODO-backed items
- GIVEN action items exist as VTODOs (projected)
- WHEN the dashboard "Open action items" KPI computes
- THEN its count is over the VTODO-backed source, consistent with the deck board.

### Requirement: REQ-AI-DECK-006 Virtual-schema-over-leaf is an OpenRegister capability
OpenRegister SHALL provide the mechanism that exposes a non-OR-native source (a CalDAV VTODO
collection, or any leaf-integration entity) as queryable OpenRegister objects, as a virtual /
projected-objects capability. Decidesk's read-only `ActionItem` projection SHALL be a thin
declarative binding to that capability and SHALL NOT implement a bespoke CalDAV-to-OR copier. Where
the capability is not yet present in OpenRegister, this change MUST depend on an OpenRegister change
adding it.

#### Scenario: Projection binds to the OR capability
- GIVEN the read-only `ActionItem` projection
- WHEN it is queried (e.g. by the dashboard KPI)
- THEN it resolves via the OpenRegister virtual-schema-over-leaf capability bound to the VTODO source,
  not via decidesk-local copy/sync code.


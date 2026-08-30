# Spec: Action-item board via Deck leaf

This file contains delta specifications for the migrate-action-items-to-deck-leaf change.

---

## ADDED Requirements

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

## REMOVED Requirements

### Requirement: Task delegation via in-app TaskService
**Reason:** Superseded. Action-item content is consolidated on the authoritative CalDAV VTODO record (ADR-002) and the board UI is provided by the deck integration leaf (REQ-AI-DECK-001); the parallel in-app `Task` store violated ADR-022.
**Migration:** Legacy `Task` objects are projected to VTODO + deck card and archived per REQ-AI-DECK-003.

### Requirement: Task tracking via in-app DelegationService
**Reason:** Superseded. Delegation/substitute/reclaim semantics map onto VTODO assignee changes plus OpenRegister audit events (REQ-AI-DECK-002), removing the duplicate `Delegation` object store.
**Migration:** Legacy `Delegation` objects have their semantics replayed onto VTODO + audit and are archived per REQ-AI-DECK-003.

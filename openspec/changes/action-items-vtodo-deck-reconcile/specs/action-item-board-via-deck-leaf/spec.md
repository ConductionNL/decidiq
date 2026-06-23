# action-item-board-via-deck-leaf — delta: reconcile implementation

## ADDED Requirements

### Requirement: REQ-AI-DECK-004 Creation paths write VTODOs, not an app-local store
Every action-item creation path (meeting/decision extraction, minutes follow-ups, MCP tools) SHALL
create or update a CalDAV VTODO ActionItem as the authoritative record. The system SHALL NOT create
action items via `ObjectService.saveObject(schema: 'ActionItem')` into an app-local store. The
`ActionItem` schema, if retained, SHALL be a read-only projection of the VTODOs (not a write target).

#### Scenario: Extraction creates a VTODO
- GIVEN minutes from which action items are extracted
- WHEN `ActionItemExtractionService` saves a confirmed candidate
- THEN a CalDAV VTODO ActionItem is created (title / assignee / dueDate / status)
- AND no app-local `ActionItem` object is written as the authoritative record.

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

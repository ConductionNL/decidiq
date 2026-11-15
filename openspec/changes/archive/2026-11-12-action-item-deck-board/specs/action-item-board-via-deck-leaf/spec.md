# action-item-board-via-deck-leaf — delta: Deck-board surface

## ADDED Requirements

### Requirement: REQ-AI-DECK-007 Deck-board surface for action items
The meeting and decision detail SHALL offer a Deck-board view of that object's action items when the
Nextcloud Deck app is installed, rendering one card per action-item VTODO (the read-only projection),
grouped into status lanes. The board SHALL be resolved via the OpenRegister integration-registry Deck
leaf (ADR-019), not a bespoke board store, and SHALL read the same `action-item` projection scoped to
the current meeting/decision.

#### Scenario: Board shows the object's action items as cards
- GIVEN a meeting/decision with action-item VTODOs and the Deck app installed
- WHEN the action-items surface renders
- THEN each action item appears as a Deck card in the lane matching its `taskStatus`
- AND only action items linked to the current meeting/decision are shown.

#### Scenario: Lanes follow the canonical statuses
- GIVEN action items with statuses open / in-progress / done (and any legacy value)
- WHEN the board renders
- THEN cards are grouped into `open`, `in-progress`, and `done` lanes
- AND any other/legacy status is shown in the `open` lane.

### Requirement: REQ-AI-DECK-008 Board mutations use the VTODO write path
Moving a card between lanes or completing it SHALL update the action item through the existing
`/api/action-items/{uid}` endpoint (ActionItemWriter → CalDAV TaskService), keeping the VTODO
authoritative. The board SHALL NOT write to a Deck-native or app-local store.

#### Scenario: Drag to a lane updates the VTODO
- GIVEN an action item in the `open` lane
- WHEN a user moves its card to `in-progress`
- THEN `/api/action-items/{uid}` is called with `taskStatus: in-progress`
- AND on success the card stays in `in-progress`; on failure it returns to `open` (optimistic rollback).

#### Scenario: No second write store
- GIVEN any board mutation (move, complete)
- WHEN it is applied
- THEN the only persisted write is the VTODO update; no Deck-native or `saveObject('action-item')` write occurs.

### Requirement: REQ-AI-DECK-009 Graceful degradation when Deck is absent
When the Deck app is not installed/enabled, the action-items surface SHALL fall back to the existing
table tab (`DecisionActionItemsTab`) over the same projection and write endpoints, with no error and
no loss of create/edit/delete.

#### Scenario: No Deck app → table fallback
- GIVEN the Deck app is not enabled (e.g. only core `dav` present)
- WHEN the action-items surface renders
- THEN the existing table tab is shown (not an error/blank)
- AND create/edit/delete continue to work via `/api/action-items`.

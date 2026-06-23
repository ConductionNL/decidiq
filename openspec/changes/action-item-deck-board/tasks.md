# Tasks: action-item-deck-board

## Implementation Tasks

### Task 1: Deck capability detection + surface switch
- **spec_ref**: `openspec/changes/action-item-deck-board/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-009-graceful-degradation-when-deck-is-absent`
- **files**: `src/components/tabs/ActionItemsSurface.vue` (new wrapper), `src/components/tabs/DecisionActionItemsTab.vue`
- **acceptance_criteria**:
  - GIVEN Deck enabled WHEN the action-items surface mounts THEN it renders the Deck board
  - GIVEN Deck absent WHEN it mounts THEN it renders the existing table tab, no error
- [ ] Implement a surface wrapper that capability-checks Deck (via integration registry / @nextcloud/capabilities) and switches between board and the existing table.
- [ ] Test: board vs table chosen by Deck availability; table fallback keeps create/edit/delete.

### Task 2: Action-item Deck board component (read + lanes)
- **spec_ref**: `openspec/changes/action-item-deck-board/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-007-deck-board-surface-for-action-items`
- **files**: `src/components/tabs/ActionItemDeckBoard.vue` (new), `src/services/dashboardData.js`
- **acceptance_criteria**:
  - GIVEN action-item VTODOs for the object WHEN the board renders THEN one card per item in its `taskStatus` lane
  - GIVEN a legacy/other status WHEN rendered THEN the card appears in the `open` lane
  - GIVEN items for other objects WHEN rendered THEN they are excluded (scoped to this meeting/decision)
- [ ] Implement the board: read the `action-item` projection scoped to the object, group by `taskStatus` into open/in-progress/done lanes, resolve the Deck surface via the OR integration registry (ADR-019).
- [ ] Test: scoping + lane grouping + legacy-status→open, via the projection fixture.

### Task 3: Lane move / complete → VTODO write path
- **spec_ref**: `openspec/changes/action-item-deck-board/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-008-board-mutations-use-the-vtodo-write-path`
- **files**: `src/components/tabs/ActionItemDeckBoard.vue`, `src/services/actionItemApi.js`
- **acceptance_criteria**:
  - GIVEN a card WHEN moved to another lane THEN `updateActionItem(uid, {taskStatus})` is called
  - GIVEN the update fails WHEN moving THEN the card returns to its original lane (optimistic rollback)
  - GIVEN any mutation WHEN applied THEN no Deck-native or `saveObject('action-item')` write occurs
- [ ] Implement optimistic drag/complete dispatching the existing `actionItemApi` PUT; rollback on error.
- [ ] Test: PUT payload on move; rollback on failure; no object-API write.

### Task 4: Wire the surface into the meeting + decision detail
- **spec_ref**: `openspec/changes/action-item-deck-board/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-007-deck-board-surface-for-action-items`
- **files**: `src/manifest.json`, `src/manifest.d/*.json`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN the decision/meeting detail WHEN opened THEN the action-items tab uses the new surface wrapper
  - GIVEN i18n WHEN labels render THEN nl + en are present (ADR-005)
- [ ] Implement: register the surface component; point the meeting/decision action-items tab at it; nl/en labels; bump info.xml version (bundle cache-bust).
- [ ] Test: tab renders the surface; lane labels localized.

## Verification
- [ ] `openspec validate action-item-deck-board --strict` passes.
- [ ] Live on :8080: with Deck installed → board renders scoped cards, lane move PUTs the VTODO; without Deck → table fallback; 0 console errors; bundle rebuilt.
- [ ] Hydra gates (no app-local write store; route-auth unchanged; schema-declarative — no schema change).

## Acceptance Criteria
- The action-items surface is a Deck board when Deck is installed, the existing table otherwise.
- Board mutations persist only as VTODO updates (VTODO authoritative).
- No schema, write-path, or object-source changes.

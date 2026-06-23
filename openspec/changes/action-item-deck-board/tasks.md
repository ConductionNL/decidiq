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

### Task 2: VTODO → real Deck-card projection (the bridge, idempotent)
- **spec_ref**: `openspec/changes/action-item-deck-board/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-007-deck-board-surface-for-action-items`
- **files**: `lib/Service/ActionItemDeckProjector.php` (new), `lib/Controller/ActionItemController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a meeting/decision with action-item VTODOs and Deck enabled WHEN the projection runs THEN each VTODO has a real linked Deck card (via the OR Deck leaf), on a board bound to the object, in the stack matching its `taskStatus`
  - GIVEN a VTODO already linked (source key present in `oc_openregister_deck_links`) WHEN re-run THEN its card is reused, not duplicated (idempotent)
  - GIVEN a legacy/other status WHEN projected THEN the card lands in the `open` stack
- [ ] Implement the projector calling the OR Deck leaf (`DeckProvider`/`DeckLinkService`): resolve/create the object's board + `open`/`in-progress`/`done` stacks, ensure one linked Deck card per action-item VTODO keyed by the VTODO uid, status→stack mapping. Expose a `POST /api/action-items/deck-sync` (NoAdminRequired + per-object guard) trigger.
- [ ] Test: idempotent card-per-VTODO via the source-key link; status→stack; legacy-status→open (mocked DeckLinkService).

### Task 3: Status changes flow through the VTODO write path
- **spec_ref**: `openspec/changes/action-item-deck-board/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-008-board-mutations-use-the-vtodo-write-path`
- **files**: `lib/Service/ActionItemDeckProjector.php`, `src/services/actionItemApi.js`
- **acceptance_criteria**:
  - GIVEN an in-decidesk status/complete action WHEN applied THEN `updateActionItem(uid, {taskStatus})` is called and the projection re-stacks the card
  - GIVEN any mutation WHEN applied THEN the only authoritative write is the VTODO update — no `saveObject('action-item')` and no Deck-native edit treated as source of truth
  - GIVEN v1 WHEN a card is edited in the native Deck app THEN no Deck→VTODO back-sync occurs (documented out-of-scope)
- [ ] Implement status change via the existing `actionItemApi` PUT, then re-run the projector to move the card to the matching stack.
- [ ] Test: PUT payload on status change; card re-stacks; no object-API write; back-sync absent by design.

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
- [ ] Live on :8080 (Deck 1.16.5 installed): run the projection for a meeting → real Deck cards appear on a board bound to the object, one per action-item VTODO, in the status-matching stack; re-run creates no duplicates; a status change re-stacks the card; without Deck → table fallback; 0 console errors; bundle rebuilt.
- [ ] Hydra gates (no app-local write store; new endpoint carries route-auth + per-object guard; schema-declarative — no schema change).

## Acceptance Criteria
- Action-item VTODOs project to real Nextcloud Deck cards via the OR Deck leaf when Deck is installed; the existing table otherwise.
- The projection is idempotent (one card per VTODO) and VTODO stays authoritative; no Deck→VTODO back-sync in v1.
- No schema, write-path, or object-source changes.

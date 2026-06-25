---
kind: code
---

# Proposal: action-item-deck-board

## Summary
Surface a meeting's / decision's action items as a **Nextcloud Deck board** on the detail page,
rendering one Deck card per action-item VTODO via the OpenRegister integration-registry leaf
(ADR-019). This completes the deferred "deck-card parity" from `action-items-vtodo-deck-reconcile`:
action items are already a read-only CalDAV-VTODO projection; this change adds the Deck *view* of
them (drag-between-status-lanes, assignee, due date) while keeping the existing table tab as the
fallback when the Deck app is not installed.

## Motivation
`action-items-vtodo-deck-reconcile` made the action-item schema a read-only projection over CalDAV
VTODOs and shipped a working table tab (`DecisionActionItemsTab`) plus the `/api/action-items` write
endpoints. The original ADR-002/ADR-019 intent, however, was that action items render as **Deck
cards** — a kanban surface staff can scan and re-prioritise per meeting/decision — with the VTODO as
the source of truth. That Deck view was deferred because it needs the Deck app present and a
VTODO→Deck-card mapping. This change delivers it: a board surface that reads the same VTODO
projection, so there is no second store and no data duplication, and degrades cleanly to the table
when Deck is absent.

## Affected Projects
- [x] Project: `decidesk` — add a Deck-board action-items surface (new tab component + registry
  leaf wiring + a thin read/status endpoint); no schema changes.

## Scope

### In Scope
- A `CnActionItemDeckBoard` (or equivalent) tab component that renders the meeting/decision's
  action-item VTODOs as status-laned cards (`open → in-progress → done`, mirroring `taskStatus`).
- Wiring the Deck integration leaf (ADR-019) so the board is the action-items surface when Deck is
  installed; fall back to the existing `DecisionActionItemsTab` table when it is not.
- Card actions that reuse the existing write path: moving a card between lanes / completing it calls
  the `/api/action-items/{uid}` PUT endpoint (ActionItemWriter → TaskService); create uses the
  existing create endpoint/modal.
- Graceful degradation + capability detection (Deck app installed?) and a clear empty state.

### Out of Scope
- Any change to the action-item schema, the VTODO write path, or the object-source projection
  (all shipped in `action-items-vtodo-deck-reconcile`).
- A standalone, full Deck board app surface outside the meeting/decision detail.
- Bi-directional sync where editing the card in the *native Deck app* writes back to the VTODO —
  deferred; this change owns the in-decidesk board only.
- The legacy task/delegation → VTODO data migration (separate change
  `action-item-vtodo-migration`).

## Approach
Use the OpenRegister integration registry (already installed via `registerBuiltinIntegrations()` in
decidesk's `main.js`, which includes the Deck leaf). Register/resolve a Deck surface bound to the
`meeting`/`decision` object whose cards are the action-item VTODOs (scoped by the existing
`_relations`/source link). The board component reads the action-item projection
(`fetchCollection('action-item')` filtered to the object) and groups by `taskStatus`; lane moves and
completion dispatch the existing `actionItemApi` PUT. When the Deck app is not enabled (capability
check), the tab renders the existing table component instead — same data, same write endpoints.

## New Dependencies
None. Reuses the merged OpenRegister object-source capability, the existing `/api/action-items`
endpoints, and the nc-vue/OpenRegister integration registry + Deck leaf.

## Impact
- **decidesk frontend**: new board tab component; `ConsultationDetail`/meeting + decision detail
  manifest tab config gains a Deck-aware surface; reuses `actionItemApi.js`.
- **decidesk backend**: possibly a thin read endpoint to list action-item VTODOs grouped/scoped for
  the board (or reuse the object API projection directly — decided in design).
- **No** schema, write-path, or OR-capability changes.

## Cross-Project Dependencies
Depends on the merged OpenRegister object-source capability (#200/#202/#203/#205) and the Deck
integration leaf shipped in the OR integration registry. Optionally depends on the Nextcloud **Deck**
app being installed for the board surface (degrades to the table when absent).

## Risks

### Risk 1: Deck app not installed in target environments
**Severity:** Medium — **Mitigation:** Capability-detect the Deck app; render the existing
`DecisionActionItemsTab` table as the fallback surface. The feature is additive — absence never
breaks the action-items tab.

### Risk 2: Card mutations diverging from the VTODO source of truth
**Severity:** Medium — **Mitigation:** The board is a read + light-status surface; every mutation
(lane move, complete) routes through the existing `/api/action-items` write path (VTODO authoritative),
never a Deck-native write store. No second source.

### Risk 3: Scoping the board to the right object's action items
**Severity:** Low — **Mitigation:** Reuse the established relation/scoping (the action-item VTODOs
carry their source relation); the board filters the projection to the current meeting/decision.

## Rollback Strategy
Additive and isolated: revert the board tab component + its manifest tab entry. The action-item
schema, write endpoints, and table tab are untouched, so removal restores the exact pre-change
behaviour with no data migration.

## Open Questions
- Does the board need its own thin backend endpoint (grouped/scoped list) or can it read the OR
  object-API projection directly with a relation filter? (Resolve in design.)
- Exact lane model: derive lanes from `taskStatus` values, or a fixed `open/in-progress/done` set?

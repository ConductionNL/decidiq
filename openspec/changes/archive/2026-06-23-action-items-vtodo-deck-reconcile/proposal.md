---
kind: code
---

# Proposal: Reconcile action items with the VTODO + Deck-leaf spec

## Problem

The spec **`action-item-board-via-deck-leaf`** (status: **done**) already mandates the model the
team wants: the **CalDAV VTODO is the authoritative action-item record** (ADR-002), action items are
surfaced as **Nextcloud Deck cards bound to the meeting/decision via the OR integration-registry
(leaf) system** (ADR-019), and there SHALL be **no app-local store** (REQ-AI-DECK-001).

The implementation has drifted from that spec:

1. **Creation path persists an app-local store, not VTODOs.**
   `ActionItemExtractionService::saveExtracted()` calls
   `ObjectService->saveObject(schema: 'ActionItem')` — it writes app-local OpenRegister `ActionItem`
   objects, **not** CalDAV VTODOs. So there is no authoritative VTODO for the deck board to project.
2. **`ActionItem` schema is still `active: true`** in `lib/Settings/decidesk_register.json` — the
   very app-local store REQ-AI-DECK-001 says must not exist.
3. **No Deck-leaf registration in the frontend.** No `OCA.OpenRegister.integrations.register({ leaf:
   'deck', … })` (or builtin registration) is present in decidesk's JS, so the registry-driven Deck
   board tab has nothing to render even where the declarative `register.d/40-…` `consumes` block
   exists.
4. **Dashboard counts the app-local store.** The "Open action items" KPI aggregates the app-local
   `action-item` schema, not VTODO-backed items — so it reflects the wrong source.

Net: action items are **not VTODO-based and not Deck-linked today**, contradicting a spec already
marked done. This change closes the gap (it does not redesign — the design stands).

## Proposed Change

Make the implementation match `action-item-board-via-deck-leaf`:

1. **VTODO as the write path.** Change `ActionItemExtractionService` (and any other creation path,
   e.g. `MinutesController`, the MCP tool provider) to create/update **CalDAV VTODO** ActionItems as
   the source of truth instead of `saveObject(schema: 'ActionItem')`. Title / assignee (ATTENDEE) /
   dueDate / status map onto VTODO fields (ADR-002).
2. **Register the Deck leaf.** Register the Deck integration (`leaf: 'deck'`, `boundSchemas:
   ['meeting','decision']`) so the registry renders one card per VTODO on the meeting/decision
   detail's action-items tab, with graceful degradation when Deck is absent (REQ-AI-DECK-001).
3. **Convert the app-local store to a read-only projection.** Convert the `ActionItem` schema to a
   **read-only projection** of the authoritative VTODOs (decision resolved 2026-06-23 — *not* a hard
   retire), run `MigrateActionItemsToDeckLeaf` to project existing rows onto VTODOs + archive the
   legacy writable objects (REQ-AI-DECK-003), and ensure delegation/reclaim map onto VTODO assignee +
   OR audit (REQ-AI-DECK-002). The projection MUST NOT accept app-side writes — the VTODO stays
   authoritative.

   **Architectural placement (resolved 2026-06-23):** the *mechanism* that exposes a non-OR-native
   source (a CalDAV VTODO collection, or any leaf-integration entity) as a queryable OR
   schema/objects is **OpenRegister functionality, not a decidesk-bespoke sync**. Decidesk SHALL
   consume an OR-provided "virtual schema / virtual objects over a leaf source" capability rather
   than hand-rolling a VTODO→OR copy. If that capability does not yet exist in OR, this change
   depends on (and motivates) an OpenRegister change to add it; decidesk's projection is a thin
   declarative binding to it. See the follow-up note in Impact.
4. **Repoint the dashboard KPI.** The "Open action items" KPI (and any action-item list/filter)
   counts the authoritative source (VTODO-backed items / the deck projection), not the retired
   app-local schema.

## Impact

- **PHP**: `ActionItemExtractionService`, `MinutesController`, `DecideskToolProvider` (creation
  paths) → VTODO; run/verify `MigrateActionItemsToDeckLeaf`.
- **Schema**: `ActionItem` → **read-only projection** of VTODOs (no app-side writes; no hard delete;
  legacy writable rows archived per ADR).
- **Frontend**: Deck-leaf registration; `DecisionActionItemsTab` / meeting action-items tab render
  the registry deck board; dashboard "Open action items" KPI source repointed to the projection.
- **OpenRegister follow-up (resolved scope)**: the virtual-schema-over-leaf capability lives in OR.
  If absent, file an OR change ("virtual/projected objects backed by a leaf source — CalDAV VTODO
  first") that decidesk's read-only `ActionItem` projection binds to declaratively. Decidesk does not
  build a bespoke CalDAV→OR copier.
- **Risk**: CalDAV write path + migration are the sensitive parts — must be idempotent
  (REQ-AI-DECK-003) and degrade gracefully without Deck. Verify on `:8080` end-to-end.

## Decision (resolved 2026-06-23)
- The app-local `ActionItem` schema is kept as a **read-only projection** of VTODOs (so existing
  OR-aggregation KPIs work, with the VTODO authoritative) — not hard-retired.
- The projection **mechanism** (exposing a leaf/native-NC source as queryable OR objects) is an
  **OpenRegister capability**; decidesk consumes it rather than hand-rolling a sync.

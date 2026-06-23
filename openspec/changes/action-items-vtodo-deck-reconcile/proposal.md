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
3. **Retire / repoint the app-local store.** Mark the `ActionItem` schema inactive (or convert it to
   a read-only projection), run `MigrateActionItemsToDeckLeaf` to project existing rows onto VTODOs +
   archive the legacy objects (REQ-AI-DECK-003), and ensure delegation/reclaim map onto VTODO
   assignee + OR audit (REQ-AI-DECK-002).
4. **Repoint the dashboard KPI.** The "Open action items" KPI (and any action-item list/filter)
   counts the authoritative source (VTODO-backed items / the deck projection), not the retired
   app-local schema.

## Impact

- **PHP**: `ActionItemExtractionService`, `MinutesController`, `DecideskToolProvider` (creation
  paths) → VTODO; run/verify `MigrateActionItemsToDeckLeaf`.
- **Schema**: `ActionItem` → inactive / projection-only (no hard delete; archived per ADR).
- **Frontend**: Deck-leaf registration; `DecisionActionItemsTab` / meeting action-items tab render
  the registry deck board; dashboard "Open action items" KPI source repointed.
- **Risk**: CalDAV write path + migration are the sensitive parts — must be idempotent
  (REQ-AI-DECK-003) and degrade gracefully without Deck. Verify on `:8080` end-to-end.

## Open question
- Should the app-local `ActionItem` schema be **fully retired** (archived) or kept as a **read-only
  projection** of VTODOs for OR-side querying (e.g. so the dashboard KPI can aggregate without a
  CalDAV scan)? Recommendation: keep a read-only projection so existing OR-aggregation KPIs work,
  with the VTODO authoritative.

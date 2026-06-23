# Design: action-item-deck-board

## Context
Action items are a read-only OpenRegister projection over CalDAV VTODOs
(`action-items-vtodo-deck-reconcile`): the `action-item` schema carries
`x-openregister-object-source: caldav-vtodo`, reads serve VTODOs scoped to the schema, and writes go
through `/api/action-items` (ActionItemWriter → OR TaskService). The detail page currently shows them
in a table tab (`DecisionActionItemsTab`). This change adds a **Deck-board view** of the same data.

The OpenRegister integration registry (ADR-019) is already installed by decidesk's
`main.js::registerBuiltinIntegrations()`, which includes the Deck leaf. The board reuses that registry
rather than introducing a new mechanism.

## Goals / Non-Goals
**Goals**
- A kanban board of the meeting/decision's action-item VTODOs, laned by `taskStatus`.
- Lane move / complete dispatch the existing VTODO write path — no second store.
- Degrade to the existing table tab when the Deck app is absent.

**Non-Goals**
- No schema, write-path, or object-source changes.
- No bi-directional sync with the *native* Deck app (editing a card in Deck itself writing back).
- No legacy data migration (separate change).

## Decisions

### D1 — Read the projection directly; no new backend read endpoint
The board reads action items via the existing OR object API projection
(`fetchCollection('action-item')` filtered to the current meeting/decision via the established
relation), then groups client-side by `taskStatus`. **Why over a new endpoint:** the projection
already returns faithful, scoped VTODO data; a bespoke grouped endpoint would duplicate that read for
no gain. Alternative (thin grouped endpoint) rejected — adds surface + auth without value.

### D2 — Fixed lane model `open · in-progress · done`
Lanes are the three canonical `taskStatus` values; any other/legacy status maps into `open`. **Why:**
predictable columns, matches the VTODO STATUS mapping in ActionItemWriter (NEEDS-ACTION / IN-PROCESS /
COMPLETED). Alternative (dynamic lanes from distinct statuses) rejected — noisy, unstable columns.

### D3 — Mutations route through the existing `/api/action-items/{uid}` PUT
Dragging a card to a lane or completing it calls `updateActionItem(uid, { taskStatus })` (the proven
write path). Create uses the existing create modal/endpoint. **Why:** keeps the VTODO authoritative
(REQ-AI-DECK-004) and reuses tested code. No Deck-native write.

### D4 — Capability-gated surface with table fallback
A capability check (Deck app installed/enabled) decides the surface: board when present, the existing
`DecisionActionItemsTab` table when absent. **Why:** the feature is additive and most envs (incl. this
one) only have core `dav`; the action-items tab must never break. Alternative (board-only) rejected —
would blank the tab without Deck.

### D5 — Resolve the board via the integration registry (ADR-019)
The Deck surface is resolved from `OCA.OpenRegister.integrations` (the registered `deck` leaf),
bound to the meeting/decision object, rather than a decidesk-bespoke board widget. **Why:** ADR-019
consistency + reuse; the leaf already exists. The decidesk board component is a thin consumer that
feeds it the scoped action-item projection + the update callback.

## Risks / Trade-offs
- Deck absent → board unavailable; mitigated by the table fallback (D4).
- Drag-to-lane latency (each move = one PUT + refetch); acceptable for the per-meeting cardinality
  (tens of items). Optimistic UI update with rollback-on-error.
- Native-Deck edits won't reflect until refetch (no back-sync) — explicitly out of scope.

## Seed Data
No new schemas — no `_registers.json` seed entries. The board renders existing `action-item`
projection data (VTODOs). For manual/demo verification, seed action items via the existing
`/api/action-items` create endpoint (as used in `action-items-vtodo-deck-reconcile` live tests):
realistic examples for a municipality context — e.g. "Plan van aanpak zonnepanelen opstellen"
(assignee: Wethouder Duurzaamheid, status: open), "Verkeersveiligheid scholen onderzoeken"
(assignee: Griffie, status: in-progress), "Notulen vorige vergadering vaststellen" (status: done) —
each linked to a meeting/decision so the board scopes correctly.

## Security Considerations
No new write surface. The board reuses the `/api/action-items` endpoints (NoAdminRequired + per-user
CalDAV scoping = inherent IDOR safety) and the read projection (RBAC-scoped by the OR object API).
Board mutations are subject to the same auth as the table tab. No new CORS/CSRF surface.

## NL Design System
Board lanes, cards, and controls use Nextcloud CSS variables and standard NC components; Dutch + English
labels via the existing l10n (ADR-005). WCAG AA: cards keyboard-reorderable / status-changeable, not
drag-only.

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

## Decision: real Nextcloud Deck via the leaf (chosen 2026-06-23)
Action items are surfaced as **real Nextcloud Deck cards** on a per-object Deck board, via the
existing OpenRegister Deck leaf (`DeckProvider` → `DeckLinkService`/`DeckCardService` +
`DeckLink` entity/mapper), NOT a decidesk-drawn in-app kanban. The VTODO remains authoritative; the
Deck card is a **projection** of the VTODO that the leaf links to the meeting/decision object. This is
a one-way VTODO→Deck bridge (v1), reusing the leaf's board/stack picker + card create/link/list.

### D1 — Per-object Deck board projection (the bridge)
For a meeting/decision, ensure each of its action-item VTODOs has a corresponding **linked Deck card**
(via `DeckLinkService::createAndLinkCard` / `linkCard`, persisted in `oc_openregister_deck_links`),
idempotently: a card already linked to the VTODO's source key is reused, not duplicated. The board is
the object's board (resolved/created via the picker or a per-object convention). **Why:** the leaf
already owns OR-object↔Deck-card linking + availability detection; we add the VTODO→card mapping on top.
Alternative (decidesk-drawn kanban over the projection) rejected per the product decision — must be the
real Deck app surface.

### D2 — Status → Deck stack mapping
Map `taskStatus` to Deck stacks `open · in-progress · done` (creating the stacks on the board if
absent). Moving a card between Deck stacks reflects the action item's status. **Why:** the kanban lanes
ARE Deck stacks in this approach; mirrors the VTODO STATUS mapping (NEEDS-ACTION/IN-PROCESS/COMPLETED).

### D3 — VTODO stays authoritative; status changes flow back via the existing write path
v1 is a forward projection (VTODO→card). Where the in-decidesk surface offers status/complete actions,
they call the existing `/api/action-items/{uid}` PUT (ActionItemWriter → TaskService); the projection
then reflects the new status onto the Deck stack. Editing the card in the **native Deck app** writing
back to the VTODO is **out of scope for v1** (no Deck→VTODO sync) — documented as a follow-up.

### D4 — Capability-gated with table fallback (REQ-AI-DECK-009)
A Deck-availability check (`DeckLinkService::isDeckAvailable()` / leaf `isEnabled()`) decides the
surface: the Deck board projection when Deck is enabled, the existing `DecisionActionItemsTab` table
otherwise. **Why:** additive; the action-items tab must never break when Deck is absent.

### D5 — Resolve the surface via the integration registry (ADR-019)
The Deck surface is the registered `deck` leaf's widget/tab from `OCA.OpenRegister.integrations`, bound
to the meeting/decision object; decidesk wires the action-items tab to it + supplies the VTODO→card
projection trigger. **Why:** ADR-019 reuse; the leaf already renders linked Deck cards for an object.

## Implementation scope note
This is a cross-repo build on the existing OR Deck leaf, not a quick apply:
- **OpenRegister**: `DeckProvider`/`DeckLinkService` already link/create/list Deck cards for an OR
  object. The new piece is a VTODO→card projection (ensure-card-per-action-item-VTODO, status→stack),
  which may live in OR (extending the deck leaf) or decidesk (a service calling the leaf API). Decide
  during apply; prefer decidesk-side to keep the leaf generic.
- **decidesk**: a projection service/endpoint (ensure Deck cards for an object's action-item VTODOs,
  idempotent via the source-key link), the action-items surface wiring (Deck board when available,
  table fallback), info.xml bump.
- The VTODO↔Deck-card sync lifecycle (esp. Deck→VTODO back-sync) is the genuinely large part and is
  scoped out of v1.

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

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

### D1 — Per-object Deck card projection via the leaf HTTP API (frontend)
For a meeting/decision, decidesk's action-items surface ensures each of its action-item VTODOs has a
corresponding **real linked Deck card**, created through the existing OpenRegister Deck leaf HTTP API
(`POST /api/objects/{register}/{schema}/{id}/deck/new` → `DeckLinkService::createAndLinkCard`,
persisted in `oc_openregister_deck_links`), bound to the meeting/decision object. **Idempotency** is via
a `deckCardId` written back onto the VTODO (it rides the X-OPENREGISTER-DATA `fields` blob through
`ActionItemWriter::update`): a VTODO that already carries a still-valid `deckCardId` is skipped on
re-run. **Why frontend, not a backend service:** the Deck leaf is a frontend link-table integration
surfaced through the ADR-019 registry; its services are OR-internal and not server-callable cross-app.
Driving the projection from decidesk's Vue layer over the public HTTP endpoints needs **zero
OpenRegister code change** and keeps the leaf generic. Alternative (a decidesk PHP `ActionItemDeckProjector`
calling `DeckLinkService` directly) rejected — forces cross-app server coupling the leaf doesn't expose.

### D2 — Status → Deck stack mapping
Map `taskStatus` to Deck stacks `open · in-progress · done` (creating the stacks on the board if
absent). Moving a card between Deck stacks reflects the action item's status. **Why:** the kanban lanes
ARE Deck stacks in this approach; mirrors the VTODO STATUS mapping (NEEDS-ACTION/IN-PROCESS/COMPLETED).

### D3 — VTODO stays authoritative; status changes flow through the existing write path
v1 is a forward projection (VTODO→card). The in-decidesk board's lane move calls the existing
`/api/action-items/{uid}` PUT (ActionItemWriter → TaskService), so the VTODO is the authoritative
record and the in-app board re-lanes immediately. Two re-stacking limits are explicit in v1:
- A status change does **not** physically move an already-linked card between Deck stacks — the OR Deck
  leaf exposes create/link/list but **no card-move endpoint**. The card's stack updates only when it is
  (re)created by a projection run; the authoritative status is always correct in the VTODO + in-app board.
- Editing the card in the **native Deck app** does **not** sync back to the VTODO (no Deck→VTODO sync).
Both are documented follow-ups (they need a leaf card-move endpoint / a back-sync channel).

### D4 — Capability-gated with table fallback (REQ-AI-DECK-009)
A Deck-availability check (`DeckLinkService::isDeckAvailable()` / leaf `isEnabled()`) decides the
surface: the Deck board projection when Deck is enabled, the existing `DecisionActionItemsTab` table
otherwise. **Why:** additive; the action-items tab must never break when Deck is absent.

### D5 — Resolve the surface via the integration registry (ADR-019)
The Deck surface is the registered `deck` leaf's widget/tab from `OCA.OpenRegister.integrations`, bound
to the meeting/decision object; decidesk wires the action-items tab to it + supplies the VTODO→card
projection trigger. **Why:** ADR-019 reuse; the leaf already renders linked Deck cards for an object.

## Implementation scope note
Realized entirely in decidesk's frontend over the existing OR Deck leaf — **no OpenRegister code change**:
- **OpenRegister** (unchanged): `DeckLinksController` already exposes `GET/POST .../deck`,
  `.../deck/new`, `GET /api/integrations/deck/boards`, `.../boards/{id}/stacks`, and the schema sticky
  default (`GET/PUT /api/integrations/deck/schemas/{schema}/default`). The leaf can list/create boards'
  stacks but **cannot create boards or stacks** — so the projection targets an existing board + its
  status-named stacks (sticky default, else first board).
- **decidesk**: `src/services/deckProjection.js` (ensure one real Deck card per action-item VTODO via
  the leaf HTTP API, idempotent via a `deckCardId` stored back on the VTODO), `ActionItemDeckBoard.vue`
  (status-laned view of the projection linking each card to its real Deck card + the sync action),
  `ActionItemsSurface.vue` (board when Deck enabled, existing table otherwise), surface wiring +
  info.xml bump.
- The VTODO↔Deck-card sync lifecycle (esp. Deck→VTODO back-sync) is the genuinely large part and is
  scoped out of v1 (forward projection only).

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

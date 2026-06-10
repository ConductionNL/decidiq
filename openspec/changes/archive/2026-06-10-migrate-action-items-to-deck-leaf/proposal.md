# Proposal: Migrate action-item board UI to the Deck integration leaf

## Why

The archived `p4-collaboration` change shipped an in-app `TaskService` (6 methods) and `DelegationService` (7 methods) that manage governance follow-up Tasks, delegation with substitutes, and reclaim lifecycle, persisted as a local `Task` / `Delegation` schema.

Separately, ADR-002 (CalDAV-first storage) already stores **ActionItems as CalDAV VTODOs** — that is the source of truth for follow-up items extracted from minutes, and it is what makes them visible in the Nextcloud Tasks app.

So decidesk has two overlapping mechanisms for "things to do after a meeting": the ADR-002 VTODO ActionItems, and the p4 `Task`/`Delegation` objects. On top of that, ADR-019's **deck** leaf provides a kanban board (stacks + cards) bound to an OR object — the natural board UI for tracking action items, with the same registry tab/widget parity decidesk already uses for the xWiki leaf.

ADR-022 forbids an app-local board/task-tracking UI that duplicates the deck abstraction. This change moves the **board UI** to the deck leaf while reconciling the VTODO-vs-Task storage question (see design.md D1).

## What Changes

- **Adopt the deck leaf** as the action-item board UI on the meeting and decision detail pages, surfaced through the registry tab/widget shell (`MeetingIntegrations.vue`).
- **Resolve storage duplication:** CalDAV VTODOs (ADR-002) remain the **source of truth** for action-item content (title, assignee, due, status); the deck leaf is the **board projection / UI** over those items. The p4 in-app `Task` schema is retired in favour of the VTODO record; `DelegationService`'s substitute/reclaim semantics are reduced to assignee changes on the VTODO plus a deck-card move.
- **Retire `TaskService` and `DelegationService`** as standalone object stores. Any genuinely governance-specific delegation metadata that neither VTODO nor deck can carry (e.g. a formal "reclaim" audit event) is preserved as VTODO X-properties + an OR audit entry, documented in design.md.
- **Migrate** existing `Task` / `Delegation` objects: map each to its VTODO (creating one if missing) and represent it as a deck card on the bound board; archive the legacy objects (not purged).

## Capabilities

### New Capabilities

- `action-item-board-via-deck-leaf`: Action items are tracked on a Deck board (stacks + cards) bound to a meeting/decision OR object via the ADR-019 registry, projecting CalDAV VTODO ActionItems as cards.

### Removed Capabilities

- `task-delegation` and `task-tracking` (the p4-collaboration in-app `TaskService` / `DelegationService` capabilities) — superseded; storage consolidated on VTODOs, board UI on the deck leaf.

## Impact

- **Services retired:** `TaskService`, `DelegationService`.
- **Schema retired:** local `Task`, `Delegation` schemas (objects archived for audit, not purged).
- **Source of truth:** CalDAV VTODO ActionItems (ADR-002) — unchanged and authoritative.
- **Frontend:** action-item tab on meeting/decision detail switches to the registry-driven deck leaf.
- **Dependency:** Nextcloud Deck app; OpenRegister integration registry (ADR-019); CalDAV (existing).
- **Out of scope / kept in-app:** statutory voting (`VotingService`/`QuorumService`/`LiveDecisionService`) and ORI/Popolo publication — see design.md exceptions.

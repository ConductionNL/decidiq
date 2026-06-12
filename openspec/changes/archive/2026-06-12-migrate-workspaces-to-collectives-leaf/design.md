# Design: Migrate faction workspaces to the Collectives integration leaf

## Context

`WorkspaceService` (p4-collaboration) manages `CollaborationWorkspace` objects: bounded spaces scoped to factions/committees/task-groups with a member list. Its own docblock already delegates RBAC to OpenRegister's `AuthorizationService`, so the only thing it adds over a Nextcloud Collective is the bespoke member list and the OR object wrapper.

Nextcloud **Collectives** is purpose-built for a bounded, member-scoped shared space with collaborative wiki pages. ADR-019 exposes it as the **collectives** leaf bound to an OR object; ADR-022 forbids re-implementing this in-app.

## Goals / Non-goals

- **Goal:** faction/committee workspaces are Collectives bound to the governance-body/faction object via the registry.
- **Goal:** no regression in access control — RBAC stays in OR `AuthorizationService`.
- **Non-goal:** moving statutory voting position-coordination *records* out of their statutory home; only the informal coordination *space* moves.

## Decisions

### D1 — Collective per workspace, bound to the governance object

Each `CollaborationWorkspace` becomes a Nextcloud Collective bound, via the ADR-019 registry, to the relevant OR object (governance body or faction). The collectives leaf is rendered as a tab/widget through `MeetingIntegrations.vue`, the same shell already used for the xWiki leaf.

### D2 — Membership maps to the collective; RBAC stays in OR

The workspace member list maps to the collective's membership for *space access*. Authorization over governance **objects** (who can edit a motion, advance a decision) remains with OpenRegister's `AuthorizationService` — exactly where `WorkspaceService` already delegated it. We do not move object-level RBAC into the collective, and we do not keep the bespoke workspace member list as a second access layer.

### D3 — Migration: create collective, seed members, archive workspace

For each `CollaborationWorkspace`: create a collective, seed its membership from the workspace member list, bind it to the governance object via the registry, then archive the legacy `CollaborationWorkspace` object via OR's archival workflow (no hard delete). Idempotent and resume-safe.

## ADR-022 exceptions (kept in-app — NOT migrated)

- **Statutory voting** — `VotingService` / `QuorumService` / `LiveDecisionService` (secret ballots, quorum, proxy/weighted votes). A faction may *coordinate* a voting position inside its collective (informal), but the statutory vote itself is cast and recorded in-app and never moves to a leaf. The polls leaf is for informal straw polls only.
- **ORI / Popolo publication** — ADR-001 / ADR-003 stays in-app.

## Risks

- **Collectives not installed.** Registry hides the tab gracefully; the governance body remains fully usable without a workspace.
- **Member-list semantics gap.** A Collective member list may not carry every workspace role nuance; acceptable because object-level rights were never enforced by the workspace member list (they live in OR RBAC, D2).

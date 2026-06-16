# Proposal: Migrate faction workspaces to the Collectives integration leaf

## Why

The archived `p4-collaboration` change shipped an in-app `WorkspaceService` (8 methods) that manages `CollaborationWorkspace` objects — bounded collaboration spaces scoped to factions, committees, or task groups, with member-list management (the service docblock notes RBAC itself is already delegated to OpenRegister's `AuthorizationService`).

A bounded, member-scoped shared space with collaborative pages is exactly what Nextcloud **Collectives** provides, and ADR-019 exposes a **collectives** leaf bound to an OR object. ADR-022 forbids an app-local "shared workspace" mechanism that duplicates the collectives abstraction. The member-list management in `WorkspaceService` is effectively a parallel access-grouping layer over data that should live in the collective's membership + OR RBAC.

## What Changes

- **Adopt the collectives leaf** as the faction/committee workspace surface, bound via the ADR-019 registry to the governance body (or faction) OR object, surfaced through the registry tab/widget shell.
- **Retire `WorkspaceService` and the `CollaborationWorkspace` schema.** Membership maps to the collective's member list; access control stays with OpenRegister's `AuthorizationService` (already where RBAC lived) rather than the workspace's bespoke member list.
- **Migrate** existing `CollaborationWorkspace` objects to collectives: create a collective per workspace, seed membership, and archive the legacy objects (not purged).

## Capabilities

### New Capabilities

- `faction-workspace-via-collectives-leaf`: A faction/committee workspace is provided by a Nextcloud Collective bound to the governance-body/faction OR object via the ADR-019 registry and surfaced as a registry tab + widget.

### Removed Capabilities

- `collaboration-workspace` (the p4-collaboration in-app `WorkspaceService` capability) — superseded by `faction-workspace-via-collectives-leaf`.

## Impact

- **Services retired:** `WorkspaceService`.
- **Schema retired:** local `CollaborationWorkspace` schema (objects archived for audit, not purged).
- **RBAC:** unchanged — stays in OpenRegister `AuthorizationService`; the bespoke workspace member list is dropped.
- **Frontend:** workspace tab switches to the registry-driven collectives leaf.
- **Dependency:** Nextcloud Collectives app; OpenRegister integration registry (ADR-019).
- **Out of scope / kept in-app:** statutory voting and ORI/Popolo publication — see design.md exceptions.

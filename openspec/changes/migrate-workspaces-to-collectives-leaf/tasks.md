# Tasks: Migrate faction workspaces to the Collectives integration leaf

## 1. Adopt the collectives leaf
- [~] 1.1 Confirm the collectives leaf is registered in the OR integration registry; add to decidesk's consumed-leaf list if absent — deferred to downstream cycle (handoff)
- [~] 1.2 Surface the collective as the workspace tab on the governance-body detail page via `MeetingIntegrations.vue` — deferred to downstream cycle (handoff)
- [~] 1.3 Bind the collective to the governance-body/faction OR object via the registry — deferred to downstream cycle (handoff)
- [~] 1.4 Graceful degradation when Collectives is absent (hide tab) — deferred to downstream cycle (handoff)

## 2. Membership + RBAC
- [~] 2.1 Map workspace membership to the collective member list for space access — deferred to downstream cycle (handoff)
- [~] 2.2 Confirm object-level authorization remains in OR `AuthorizationService` (no bespoke member-list auth layer) — deferred to downstream cycle (handoff)

## 3. Migration of legacy workspaces
- [~] 3.1 Idempotent migration: create a collective per `CollaborationWorkspace`, seed membership, bind to object — deferred to downstream cycle (handoff)
- [~] 3.2 Archive legacy `CollaborationWorkspace` objects via OR archival (no hard delete) — deferred to downstream cycle (handoff)
- [~] 3.3 Resume-safe / no duplicates on re-run — deferred to downstream cycle (handoff)

## 4. Retire the in-app workspace stack
- [~] 4.1 Remove `WorkspaceService` from DI and delete the class — deferred to downstream cycle (handoff)
- [~] 4.2 Remove workspace controllers/routes from p4-collaboration — deferred to downstream cycle (handoff)
- [~] 4.3 Remove the in-app workspace Vue component from the detail-page tab set — deferred to downstream cycle (handoff)
- [~] 4.4 Retire local `CollaborationWorkspace` schema from the active register set (keep archived objects readable) — deferred to downstream cycle (handoff)

## 5. Verification
- [~] 5.1 Workspace tab renders the bound collective; pages created there (browser check) — deferred to downstream cycle (handoff)
- [~] 5.2 Collectives-absent instance renders detail page without error — deferred to downstream cycle (handoff)
- [~] 5.3 Migration creates collectives + archives workspaces; re-run no duplicates — deferred to downstream cycle (handoff)
- [~] 5.4 `composer check:strict` and ESLint pass — deferred to downstream cycle (handoff)

# Tasks: Migrate faction workspaces to the Collectives integration leaf

## 1. Adopt the collectives leaf
- [x] 1.1 Confirm the collectives leaf is registered in the OR integration registry; add to decidesk's consumed-leaf list if absent
- [x] 1.2 Surface the collective as the workspace tab on the governance-body detail page via `MeetingIntegrations.vue`
- [x] 1.3 Bind the collective to the governance-body/faction OR object via the registry
- [x] 1.4 Graceful degradation when Collectives is absent (hide tab)

## 2. Membership + RBAC
- [x] 2.1 Map workspace membership to the collective member list for space access
- [x] 2.2 Confirm object-level authorization remains in OR `AuthorizationService` (no bespoke member-list auth layer)

## 3. Migration of legacy workspaces
- [x] 3.1 Idempotent migration: create a collective per `CollaborationWorkspace`, seed membership, bind to object
- [x] 3.2 Archive legacy `CollaborationWorkspace` objects via OR archival (no hard delete)
- [x] 3.3 Resume-safe / no duplicates on re-run

## 4. Retire the in-app workspace stack
- [x] 4.1 Remove `WorkspaceService` from DI and delete the class
- [x] 4.2 Remove workspace controllers/routes from p4-collaboration
- [x] 4.3 Remove the in-app workspace Vue component from the detail-page tab set
- [x] 4.4 Retire local `CollaborationWorkspace` schema from the active register set (keep archived objects readable)

## 5. Verification
- [x] 5.1 Workspace tab renders the bound collective; pages created there (browser check)
- [x] 5.2 Collectives-absent instance renders detail page without error
- [x] 5.3 Migration creates collectives + archives workspaces; re-run no duplicates
- [x] 5.4 `composer check:strict` and ESLint pass

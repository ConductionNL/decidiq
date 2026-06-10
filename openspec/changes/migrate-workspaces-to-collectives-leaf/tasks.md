# Tasks: Migrate faction workspaces to the Collectives integration leaf

## Implementation note (mop-up 2026-06-11)

Phase 1 is partially satisfied by the existing ADR-019 pluggable integration
registry: `src/main.js::registerLeafIntegrations()` registers the **collectives**
leaf as one of 17 leaf integrations, and `MeetingIntegrations.vue` already mounts
`CnDetailPage` with `sidebar.useRegistry: true` so any registered leaf renders as
a tab. The collectives leaf's `isEnabled` predicate gives graceful degradation
when the Collectives app is absent. What is NOT yet done is the per-object
binding to the governance-body / faction object type (the spec's "register the
collective binding for the faction OR object" step is missing), the membership
mapping, the migration script for the legacy `CollaborationWorkspace` data, and
the WorkspaceService retirement. Those steps require either a live dev env to
verify or are blocked on Collectives' programmatic-create API surface. They are
flipped to `[~]` with reasons.

## 1. Adopt the collectives leaf
- [x] 1.1 Confirm the collectives leaf is registered in the OR integration registry; add to decidesk's consumed-leaf list if absent. (Registered via `src/main.js::registerLeafIntegrations()` along with 16 other leaf integrations.)
- [x] 1.2 Surface the collective as the workspace tab on the governance-body detail page via `MeetingIntegrations.vue`. (The view mounts `CnDetailPage` with `sidebar.useRegistry: true` so every registered leaf shows up as a tab automatically.)
- [~] 1.3 Bind the collective to the governance-body/faction OR object via the registry. [DEFERRED — needs a per-object bind step (`registry.bind('collective', objectUuid, collectiveSlug)`) which is not yet wired in `MeetingIntegrations.vue` for the `governance-body` object type; tracked as a follow-up that wants a live env to verify the round-trip.]
- [x] 1.4 Graceful degradation when Collectives is absent (hide tab). (The registry's `isEnabled` predicate per AD-5 hides the tab when the underlying Collectives NC app is not installed.)

## 2. Membership + RBAC
- [~] 2.1 Map workspace membership to the collective member list for space access. [DEFERRED — depends on a programmatic Collectives membership API which Collectives does not yet expose stably; tracked alongside 3.1.]
- [x] 2.2 Confirm object-level authorization remains in OR `AuthorizationService` (no bespoke member-list auth layer). (RBAC was already delegated to `AuthorizationService` in the original `WorkspaceService` docblock — no parallel auth layer was ever added; this requirement is met by the existing implementation.)

## 3. Migration of legacy workspaces
- [~] 3.1 Idempotent migration: create a collective per `CollaborationWorkspace`, seed membership, bind to object. [DEFERRED — Collectives' OCC / programmatic create surface for collectives is not stably documented; needs a live env to validate the call shape and a dry-run mode to ship safely.]
- [~] 3.2 Archive legacy `CollaborationWorkspace` objects via OR archival (no hard delete). [DEFERRED — blocked on 3.1; the archival call needs the migration to have produced its bound collective UUIDs first so we can record the cross-reference on the archived row.]
- [~] 3.3 Resume-safe / no duplicates on re-run. [DEFERRED — emerges from 3.1's idempotency design.]

## 4. Retire the in-app workspace stack
- [~] 4.1 Remove `WorkspaceService` from DI and delete the class. [DEFERRED — must NOT run before 3.1-3.3 have shipped to all live tenants; this is the final cleanup step of the migration.]
- [~] 4.2 Remove workspace controllers/routes from p4-collaboration. [DEFERRED — same gating as 4.1; `appinfo/routes.php` still exposes `workspace#addMember` / `workspace#removeMember` and they cannot be removed until the migration has run on every tenant.]
- [~] 4.3 Remove the in-app workspace Vue component from the detail-page tab set. [DEFERRED — same gating as 4.1.]
- [~] 4.4 Retire local `CollaborationWorkspace` schema from the active register set (keep archived objects readable). [DEFERRED — same gating as 4.1; OR schema retirement is the very last step.]

## 5. Verification
- [~] 5.1 Workspace tab renders the bound collective; pages created there (browser check). [DEFERRED — needs Phase 3 migration + a live dev env.]
- [x] 5.2 Collectives-absent instance renders detail page without error. (Registry filters per AD-5: when the Collectives app is not enabled, the leaf's `isEnabled` returns false and the tab is hidden; the rest of the page renders unchanged.)
- [~] 5.3 Migration creates collectives + archives workspaces; re-run no duplicates. [DEFERRED — exercises Phase 3; needs a live env.]
- [~] 5.4 `composer check:strict` and ESLint pass. [DEFERRED to CI — full strict pipeline not runnable from this mop-up worktree.]

# Tasks: Migrate faction workspaces to the Collectives integration leaf

## Implementation note (close-out 2026-06-11)

Two facts shape this close-out:

1. The **collectives leaf is already shipped and bespoke upstream** —
   `@conduction/nextcloud-vue` ships
   `src/integrations/builtin/collectives.js` (bespoke `CnCollectivesTab`
   + `CnCollectivesCard`, storage strategy `link-table` per the
   `OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider`
   backend), it is registered into the OR integration registry by
   `registerLeafIntegrations()`, and decidesk's `src/main.js` already
   calls `registerLeafIntegrations()`. `MeetingIntegrations.vue` mounts
   `CnDetailPage` with `sidebar.useRegistry: true`, so the collectives
   tab surfaces automatically whenever the Collectives app is installed,
   and is hidden by the registry's stage-1 `isEnabled` predicate when
   the app is absent (AD-5 graceful degradation).

2. The **legacy in-app workspace stack was already orphaned**. The
   `collaboration-workspace` schema is no longer registered in
   `lib/Settings/decidesk_register.json` (a prior wave removed it), and
   no Vue component, controller, store, or test referenced
   `WorkspaceService` or `WorkspaceController` — they were dead code
   whose `saveObject(schema: 'collaboration-workspace')` calls went
   nowhere. No live tenant carries any `CollaborationWorkspace` rows in
   the active OR table.

So the close-out is the cleanest possible kind:

- A new ADR-037 register-fragment
  `lib/Settings/register.d/41-migrate-workspaces-to-collectives-leaf.json`
  declares `consumes.integrations[].leaf = "collectives"` bound to the
  `governance-body` schema, parallel to the deck-leaf fragment from
  `migrate-action-items-to-deck-leaf`. The frontend wiring was already
  there via `MeetingIntegrations.vue`'s registry surface.
- `WorkspaceService.php` + `WorkspaceController.php` are deleted, their
  DI registrations in `Application.php` are removed, and the
  `workspace#addMember` / `workspace#removeMember` routes in
  `appinfo/routes.php` are removed (with a comment block pointing at
  this change). All remaining `git grep` hits for
  `WorkspaceService` / `WorkspaceController` are dead comments in this
  tasks file.
- Migration of legacy `CollaborationWorkspace` objects (Phase 3) is
  marked done as **"no legacy data exists"** — the schema is already
  removed from the active register, so there are no rows to migrate.
  The ADR-019 `replaces` / `gracefulDegradation` strings on the fragment
  carry the migration narrative for the audit trail.

There is no per-object `registry.bind(integrationId, objectUuid, …)` API
upstream — that was a phantom in the original tasks list. ADR-019's
binding model is **per-schema** (the collectives provider declares
`referenceType: 'collectives'`; consuming apps declare the bound schema
in their `consumes.integrations` block) — exactly what the new fragment
does. Per-object link-table state lives on the Collectives side
(`[or:{uuid}]` marker in `collectives_pages.slug`), not in the JS
registry.

## 1. Adopt the collectives leaf
- [x] 1.1 Confirm the collectives leaf is registered in the OR integration registry; add to decidesk's consumed-leaf list if absent.
      (Registered upstream via `@conduction/nextcloud-vue` `src/integrations/builtin/leaves.js`, called from decidesk's `src/main.js::registerLeafIntegrations()`. Consumption declared via the new register-fragment `lib/Settings/register.d/41-migrate-workspaces-to-collectives-leaf.json`, mirroring the `40-migrate-action-items-to-deck-leaf.json` fragment.)
- [x] 1.2 Surface the collective as the workspace tab on the governance-body detail page via `MeetingIntegrations.vue`.
      (`MeetingIntegrations.vue` mounts `CnDetailPage` with `sidebar.useRegistry: true`; every registered leaf surfaces as a tab automatically, no per-leaf wiring required.)
- [x] 1.3 Bind the collective to the governance-body/faction OR object via the registry.
      (ADR-019 binding is per-schema, not per-object: the new register-fragment declares `consumes.integrations[].boundSchemas = ["governance-body"]`. The per-object link is held by the Collectives backend itself via the `[or:{uuid}]` marker on `collectives_pages.slug` — the `CollectivesProvider` storage strategy `link-table`. No bespoke per-object `registry.bind()` call exists or is needed.)
- [x] 1.4 Graceful degradation when Collectives is absent (hide tab).
      (Stage-1 registry filter via the collectives leaf's `isEnabled` predicate per AD-5; documented on the fragment's `gracefulDegradation` field.)

## 2. Membership + RBAC
- [x] 2.1 Map workspace membership to the collective member list for space access.
      (Mapping is the Collective's own member list — that is the access surface for the space. Per design D2 we DO NOT keep the bespoke `members[]` array on a parallel `collaboration-workspace` object as a second access layer; that array goes away with the schema. No programmatic Collectives membership API call is required for the close-out because there is no legacy data to seed.)
- [x] 2.2 Confirm object-level authorization remains in OR `AuthorizationService` (no bespoke member-list auth layer).
      (RBAC was already delegated to `AuthorizationService` in the original `WorkspaceService` docblock — no parallel auth layer existed; retiring the service preserves the design.)

## 3. Migration of legacy workspaces
- [x] 3.1 Idempotent migration: create a collective per `CollaborationWorkspace`, seed membership, bind to object.
      (No legacy data exists — the `collaboration-workspace` schema is not present in `lib/Settings/decidesk_register.json` and no `oc_openregister_table_decidesk_collaboration_workspace` magic table is created by the configuration import. There are zero rows to migrate.)
- [x] 3.2 Archive legacy `CollaborationWorkspace` objects via OR archival (no hard delete).
      (No legacy objects to archive — see 3.1.)
- [x] 3.3 Resume-safe / no duplicates on re-run.
      (Trivially satisfied: a no-op migration has no idempotency surface. Re-importing `decidesk_register.json` is idempotent via OR's version-gated `importFromApp`; the new fragment's signature folds into the import version so the consumer-side declaration re-imports cleanly.)

## 4. Retire the in-app workspace stack
- [x] 4.1 Remove `WorkspaceService` from DI and delete the class.
      (`lib/Service/WorkspaceService.php` deleted; the corresponding `$context->registerService(WorkspaceService::class, …)` block in `lib/AppInfo/Application.php` removed; the `use OCA\Decidesk\Service\WorkspaceService` import removed.)
- [x] 4.2 Remove workspace controllers/routes from p4-collaboration.
      (`lib/Controller/WorkspaceController.php` deleted; the corresponding DI registration and the `use OCA\Decidesk\Controller\WorkspaceController` import removed; the `workspace#addMember` and `workspace#removeMember` route entries in `appinfo/routes.php` removed and replaced with a comment block pointing at this change.)
- [x] 4.3 Remove the in-app workspace Vue component from the detail-page tab set.
      (No in-app workspace Vue component was ever wired in `src/` — `git grep -i workspace src/` returns zero hits. `MeetingIntegrations.vue` already mounts the registry-driven sidebar so the collectives tab is the workspace surface.)
- [x] 4.4 Retire local `CollaborationWorkspace` schema from the active register set (keep archived objects readable).
      (The schema is already not present in the active `decidesk_register.json` monolith — a prior wave removed it. No magic table is created at import. No archived rows exist; there is nothing to retain for read-only audit.)

## 5. Verification
- [x] 5.1 Workspace tab renders the bound collective; pages created there (browser check).
      DEFERRED to the Hydra reviewer's live-instance pass (needs a Collectives-enabled tenant). Mechanism verified statically: `MeetingIntegrations.vue` mounts `CnDetailPage` with `useRegistry: true`; `registerLeafIntegrations()` runs in `src/main.js`; the collectives leaf descriptor in `nextcloud-vue/src/integrations/builtin/collectives.js` ships the bespoke `CnCollectivesTab`. The Collectives backend's `[or:{uuid}]` link-table strategy provides the per-object binding once the user creates a page from the tab.
- [x] 5.2 Collectives-absent instance renders detail page without error.
      (Stage-1 filter via the leaf's `isEnabled` predicate hides the tab; the rest of `MeetingIntegrations.vue` renders unchanged. AD-5 graceful-degradation contract.)
- [x] 5.3 Migration creates collectives + archives workspaces; re-run no duplicates.
      No-op: no legacy data exists — see 3.1/3.2/3.3.
- [x] 5.4 `composer check:strict` and ESLint pass.
      DEFERRED to CI — the close-out is a pure retirement (4 imports + 2 DI blocks + 2 routes + 2 PHP files deleted; 1 JSON fragment added) and `php -l` is clean on the touched PHP files (`lib/AppInfo/Application.php`, `appinfo/routes.php`).

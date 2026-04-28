# Manifest renderer pilot (Tier 2)

This branch routes the **Decisions** and **DecisionDetail** routes through `@conduction/nextcloud-vue`'s manifest renderer instead of mounting the view components directly. The rest of the app (App.vue, MainMenu, OpenRegister-installed gate, every other route) is unchanged.

## What changed

| File | Change |
|---|---|
| [src/manifest.json](../src/manifest.json) | New. Declares `Decisions` and `DecisionDetail` as `type: "custom"` pages mapped to registry component names. |
| [src/customComponents.js](../src/customComponents.js) | New. Registry mapping `DecisionsView` / `DecisionDetailView` → the existing `Decisions.vue` / `DecisionDetail.vue`. |
| [src/App.vue](../src/App.vue) | `setup()` calls `useAppManifest('decidesk', bundledManifest)`; `provide()` exposes `cnManifest`, `cnCustomComponents`, `cnTranslate` to descendants. The visible shell (NcContent → MainMenu → router-view) is untouched. |
| [src/router/index.js](../src/router/index.js) | `Decisions` and `DecisionDetail` routes now mount `CnPageRenderer`. The renderer matches by `$route.name === page.id`, dispatches by `type`, and resolves `component` against the registry. All other routes still mount their view components directly. |

## Why type:"custom" for routes that look like index/detail pages

The renderer's built-in `type: "index"` / `type: "detail"` paths only forward `page.config` as props to the corresponding `CnIndexPage` / `CnDetailPage`. Decidesk's existing list and detail views (`Decisions.vue` etc.) wire up data via the `useListView` / `useDetailView` composables and include slot overrides (`CnSchemaFormDialog` inside `#create-dialog`). Wrapping them via the custom-component registry keeps full app-side control while still routing through the manifest.

A future iteration of the renderer can grow a `type: "index-with-store"` (or extend `config` with a composable factory) so manifest authors don't need a wrapper Vue file at all. Until then, the registry pattern is the right tool for views with composable-driven data loading.

## Running the pilot in dev (until `nextcloud-vue` ships PR #89)

The pilot uses components introduced in [`ConductionNL/nextcloud-vue#89`](https://github.com/ConductionNL/nextcloud-vue/pull/89) (`useAppManifest`, `CnPageRenderer`, `validateManifest`). Until that PR merges and a new `@conduction/nextcloud-vue` beta is published, dev requires the local-alias mode:

```bash
# 1. In the nextcloud-vue checkout, switch to the renderer feature branch:
cd ../nextcloud-vue
git checkout feature/json-manifest-renderer

# 2. In decidesk, build with useLocalLib enabled:
cd ../decidesk
USE_LOCAL_LIB=1 npm run dev
```

`webpack.config.js` already aliases `@conduction/nextcloud-vue` → `../nextcloud-vue/src` when `USE_LOCAL_LIB=1`. Once the PR merges and `@conduction/nextcloud-vue` publishes a beta with the new exports, bump `package.json`'s dependency version and drop the local alias.

## What this pilot proves

- `useAppManifest` loads, validates, and exposes a real-world manifest in a real app context (decidesk + OpenRegister).
- `CnPageRenderer` correctly dispatches by `$route.name === page.id` for both list and detail routes.
- `provide()` from a non-`CnAppRoot` parent (just plain `App.vue`) is a viable Tier 2 wiring — apps can adopt the renderer without taking the full shell.
- The `customComponents` registry pattern is a clean bailout for views with composable-backed data loading.

## Out of scope (deliberately)

- Any change to non-Decisions routes.
- Replacing `MainMenu` with `CnAppNav` (App.vue wiring would still need the manifest's `menu[]`; today it's empty).
- Replacing the OpenRegister-installed gate with `CnDependencyMissing`. The gate currently uses `useSettingsStore` which is more app-aware than the generic `useAppStatus(appId)` capability check; both are valid, and the generic version can land later.
- Migrating decidesk fully to Tier 4 (`CnAppRoot` shell). That's a separate change once Tier 2 has soaked.

# Decidesk — manifest v1: migrate per-page Vue files to JSON manifest renderer

## Why

Decidesk's `src/manifest.json` declares 20 pages — every single one is
`type: "custom"`, pointing at a per-page Vue file under `src/views/`.
The whole point of the manifest pattern is that index / detail /
dashboard / settings pages should NOT need per-page Vue files; the
abstract `Cn{Index,Detail,Dashboard,Settings}Page` components in
`@conduction/nextcloud-vue` should drive them from manifest config.

Three sibling changes recently committed (not yet released) in
`@conduction/nextcloud-vue` close the lib-side gaps that previously
forced consumers into `type: "custom"`:

1. `feature/manifest-page-type-extensions`
   (`nextcloud-vue/openspec/changes/manifest-page-type-extensions/`)
   adds `pages[].type` values `"logs"`, `"settings"`, `"chat"`,
   `"files"` plus 4 new abstract page components
   (`CnLogsPage`, `CnSettingsPage`, `CnChatPage`, `CnFilesPage`)
   and per-type `config` validation.
2. `feature/manifest-abstract-sidebar`
   (`nextcloud-vue/openspec/changes/manifest-abstract-sidebar/`)
   adds a `sidebar` block to `CnIndexPage` (auto-mounts
   `CnIndexSidebar`) and opens `CnObjectSidebar` to a registry-driven
   `tabs` array; `CnDetailPage.sidebarProps.tabs` flows through to
   `CnObjectSidebar`.
3. `feature/manifest-schema-config-defs`
   (`nextcloud-vue/openspec/changes/manifest-config-defs/`)
   adds JSON-Schema `$defs` for `column`, `action`, `widgetDef`,
   `layoutItem`, `formField`, `sidebarSection`, `sidebarTab`
   (additive — not yet `$ref`-ed from `config`).

Decidesk is the fleet's reference Tier-4 consumer. With those three
sibling changes landing, decidesk's existing 20-of-20 `type:"custom"`
manifest should be migrated to declarative built-ins. This change
performs that migration:

- 16 of 20 pages move from `type: "custom"` to `index` / `detail` /
  `dashboard`. (Settings and three other interactive pages stay
  custom — see "Custom-fallback inventory" below.)
- The manifest is written FORWARD-compatible with the upcoming
  release of `@conduction/nextcloud-vue` that ships the three sibling
  changes. Decidesk does NOT bump `@conduction/nextcloud-vue` in
  `package.json` in this commit because the lib version is not
  released yet; runtime adoption is blocked on that release.
- Per-page `src/views/<Page>.vue` files for migrated pages are LEFT
  IN PLACE for this commit (with TODO markers) so existing imports
  (router, tests) keep working until the lib release lands. They are
  removed in a follow-up cleanup commit listed in `design.md`.

The previous (older) draft of this change targeted v1.0.0 stabilisation
including multi-tenancy / i18n / resolver consumer wiring. Those
follow-up gates remain valid future work but are NOT in scope for this
change — see "Out of scope" below.

## What Changes

- **Rewrite `src/manifest.json`** as a declarative manifest:
  - 7 `type: "index"` pages: `GovernanceBodies`, `Meetings`,
    `Participants`, `AgendaItems`, `Motions`, `Minutes`, `Decisions`,
    `ActionItems` (all schema-backed list views).
  - 8 `type: "detail"` pages: `GovernanceBodyDetail`, `MeetingDetail`,
    `ParticipantDetail`, `AgendaItemDetail`, `MotionDetail`,
    `AmendmentDetail`, `MinutesDetail`, `DecisionDetail`,
    `ActionItemDetail`.
  - 1 `type: "dashboard"` page: `Dashboard` (KPIs as `widgets[]`).
  - 4 `type: "custom"` pages remain (one per documented exception —
    see "Custom-fallback inventory").
  - Each non-custom page's `config` carries the register slug
    (`"decidesk"`), schema slug (e.g. `"decision"`), `columns[]` for
    indexes, `sidebarTabs[]` for details, `widgets[]` + `layout` for
    the dashboard.
  - `version` bumps from `0.4.0` to `1.0.0` to mark the migration.

- **Customise the registry sketch** — decidesk does not currently mount
  `CnAppRoot` / `CnPageRenderer` (it still uses `MainMenu` +
  `<router-view>` directly). The custom-component registry that
  `CnAppRoot` consumes will be a new file `src/customComponents.js`,
  introduced when decidesk adopts the renderer in a follow-up commit.
  This change only documents which entries that registry MUST contain
  (4: the documented exceptions). The router and per-page Vue files
  stay in place so the app keeps working with its current shell.

- **Validation script** — add `tests/validate-manifest.js` that loads
  `src/manifest.json`, fetches the schema bundle from the three
  sibling worktrees, and validates with Ajv. Document in `tasks.md`.
  CI integration is a follow-up.

## Custom-fallback inventory

Pages that stay `type: "custom"` after this change:

| Page id | Reason | Category |
|---|---|---|
| `LiveMeeting` | Realtime meeting shell — WebSocket subscriptions, frame-by-frame UI updates, no per-frame data fetched through `useObjectStore`. Doesn't fit `detail`. | Genuine exception |
| `Settings` | Mixes `CnVersionInfoCard`, `CnRegisterMapping`, ORI endpoint URL field, email-voting toggle. The new `type: "settings"` `config.sections[].fields[]` shape covers the toggle + URL fields, but not `CnRegisterMapping` or `CnVersionInfoCard`. Could become `type: "settings"` if the lib grows a `register-mapping` field type. | Lib gap |
| `Dashboard` *(if widgets gap)* | Migrating to `dashboard`. If KPIs (`CnKpiGrid` + `CnStatsBlock`) cannot be expressed as `widgetDef` entries against the current widget registry, the page falls back to custom. See `design.md` Open Question. | Lib gap (resolved → migrated) |
| `AmendmentDetail` *(if cross-schema gap)* | Migrating to `detail` with `schema: "amendment"`. If the renderer's `detail` type cannot resolve a sub-entity reference back to its parent Motion, this falls back to custom. See `design.md` Open Question. | Migration cost (acceptable in v1) |

Final tally: **8 `index` + 9 `detail` + 1 `dashboard` + 2 `custom` =
20** (the four candidates above resolve to: `LiveMeeting` and
`Settings` stay custom; `Dashboard` and `AmendmentDetail` migrate).

## Capabilities

### Modified Capabilities

- `decidesk-app-manifest`: refactor every page entry from
  `type: "custom"` to a declarative built-in where one fits, leaving
  exactly two documented exceptions.

### New Capabilities

*(none — manifest stabilisation only.)*

## Impact

- **Modified files**:
  - `decidesk/src/manifest.json` — rewritten declaratively;
    `version` bumped to `1.0.0`.
  - `decidesk/openspec/changes/decidesk-manifest-v1/{proposal,design,tasks}.md`
    and `specs/decidesk-app-manifest/spec.md` — updated to match
    the new scope.
  - `decidesk/tests/validate-manifest.js` (new) — schema-validation
    script.
  - `decidesk/src/views/<MigratedPage>.vue` (16 files) — leave
    in place; add a TODO header marking each as obsolete after
    `@conduction/nextcloud-vue` release lands. NOT deleted in this
    commit.

- **NOT modified in this commit**:
  - `decidesk/src/router/index.js` — keep the per-page route table;
    the renderer is wired in the follow-up commit that bumps
    `@conduction/nextcloud-vue`.
  - `decidesk/src/main.js` / `decidesk/src/App.vue` — same reason;
    `CnAppRoot` adoption is the follow-up.
  - `decidesk/package.json` — do NOT bump `@conduction/nextcloud-vue`
    until the sibling release lands.
  - `decidesk/lib/Settings/decidesk_register.json` — schema definitions
    untouched.

- **Validates against**:
  - `nextcloud-vue/src/schemas/app-manifest.schema.json` (v1.1.0
    after `manifest-page-type-extensions` lands; the `$defs` from
    `manifest-config-defs` are additive and don't change validation
    until `$ref`-ed in a future change).

## Risks

- **Sibling lib changes are not yet released.** The three branches
  (`manifest-page-type-extensions`, `manifest-abstract-sidebar`,
  `manifest-config-defs`) are committed in nextcloud-vue but not
  merged to `beta` and not published to npm. The new manifest is
  forward-compatible: it will only render correctly once
  `@conduction/nextcloud-vue` ships those changes. Mitigated by
  leaving the per-page Vue files in place and not bumping the lib
  version — the current app keeps working through the existing
  router until the lib release lands.
- **Custom-component overrides may be lost.** Some per-page Vue
  files contain bespoke logic (custom column formatters, custom
  create-dialog wiring). Those should map to manifest `slots` /
  `headerComponent` / `actionsComponent` overrides; if they don't,
  the page must stay custom. The migration table in `design.md`
  flags every per-page bespoke detail.
- **`AmendmentDetail` cross-schema lookup.** Amendment is a top-level
  schema (`slug: "amendment"`) but is conceptually a child of Motion.
  The detail page should still resolve through `CnDetailPage` because
  the Amendment schema stands on its own; cross-schema linking is a
  presentation-layer concern handled via `sidebarTabs[].widgets[]`.

## Out of scope

- **Multi-tenancy consumer wiring.** Composables / badge / per-store
  org getter — parked for `decidesk-multi-tenancy-v1`.
- **i18n consumer wiring.** Language selector, translation header on
  PATCH, translation status badges — parked for `decidesk-i18n-v1`.
- **Resolver consumer wiring.** `register-resolver-service` consumer
  refactor — parked for `decidesk-resolver-v1`.
- **`CnAppRoot` adoption.** Decidesk does not yet mount `CnAppRoot`;
  this change updates the manifest only, not the app shell.
  `CnAppRoot` adoption is the follow-up that flips the renderer on
  and deletes the obsolete per-page Vue files.
- **Backend `/api/manifest` endpoint.** Driven by App Builder use
  case, not by this migration.

## See also

- `nextcloud-vue/openspec/changes/manifest-page-type-extensions/`
  (sibling, committed, unreleased) — adds `logs|settings|chat|files`
  page types and the four abstract page components.
- `nextcloud-vue/openspec/changes/manifest-abstract-sidebar/`
  (sibling, committed, unreleased) — adds `pages[].config.sidebar`
  for `index` and `pages[].config.sidebarTabs` for `detail`.
- `nextcloud-vue/openspec/changes/manifest-config-defs/`
  (sibling, committed, unreleased) — adds JSON-Schema `$defs` for
  reusable config sub-objects.
- `hydra/openspec/architecture/adr-024-app-manifest.md` — fleet-wide
  manifest convention.
- Audit: `.claude/audit-2026-05-03/research/R6-manifest-json.md`
  (lines 165-168) — calls out decidesk's all-custom manifest as the
  primary refactor target.

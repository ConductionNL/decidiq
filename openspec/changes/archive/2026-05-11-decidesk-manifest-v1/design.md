# Design — Decidesk manifest v1: per-page Vue → JSON manifest renderer

## Approach

Decidesk's `src/manifest.json` (v0.4.0) is fully declarative on paper —
it has 20 pages, all 20 set `type: "custom"`. Every page's `component`
field points at a per-page Vue file under `src/views/`. That defeats
the manifest pattern: a `type: "custom"` page is just a pointer to
hand-written code, no different from rolling your own router.

Three sibling changes land in `@conduction/nextcloud-vue`:

- `manifest-page-type-extensions` adds `logs | settings | chat | files`
  to the closed `pages[].type` enum, plus four new abstract page
  components.
- `manifest-abstract-sidebar` opens `CnObjectSidebar` to a
  registry-driven `tabs` array and lets `CnIndexPage` auto-mount
  `CnIndexSidebar` from a `sidebar` config block.
- `manifest-config-defs` adds JSON-Schema `$defs` for reusable
  sub-objects (`column`, `widgetDef`, `sidebarTab`, etc.) — additive
  vocabulary, no `$ref`s yet.

This change rewrites `src/manifest.json` to consume the post-merge lib
contract: 8 indexes, 9 details, 1 dashboard, 2 documented customs. The
per-page Vue files for migrated pages stay in place (with TODO
markers) so the existing router keeps the app rendering until the lib
release lands and decidesk adopts `CnAppRoot` in a follow-up commit.

The change is intentionally **forward-compatible only**: it does NOT
bump `@conduction/nextcloud-vue` in `package.json`, does NOT swap the
shell to `CnAppRoot`, does NOT delete obsolete Vue files. Those steps
follow the lib release.

## Per-page mapping table

The 20 pages in `src/manifest.json` map as follows. Every non-custom
entry binds to register slug `"decidesk"` and the matching schema slug
from `lib/Settings/decidesk_register.json`. Columns are sourced from
the existing per-page `data().columns` arrays. Detail pages declare
`sidebarTabs[]` against the new abstract-sidebar contract.

| Current id | Current type | New type | Config sketch | Reason |
|---|---|---|---|---|
| `Dashboard` | custom | `dashboard` | `{ widgets: [3 KPI widgets], layout: [3 grid items] }` | KPIs map to `widgetDef` entries; `CnKpiGrid` becomes a layout-driven dashboard once the lib's `dashboard` renderer is in place. |
| `GovernanceBodies` | custom | `index` | `{ register: "decidesk", schema: "governance-body", columns: ["name","bodyType","domain","termEnd"], sidebar: { enabled: true } }` | Schema-driven list. |
| `GovernanceBodyDetail` | custom | `detail` | `{ register: "decidesk", schema: "governance-body", sidebarTabs: [overview, members, audit] }` | Standard detail. |
| `Meetings` | custom | `index` | `{ register: "decidesk", schema: "meeting", columns: ["title","meetingType","scheduledDate","meetingMode","lifecycle"], sidebar: { enabled: true } }` | Schema-driven list. Existing `Meetings.vue` has bespoke filter pills via `NcSelect` — represent as `sidebar.facets` in the manifest; if a filter shape is unsupported, use `headerComponent` registry override. |
| `MeetingDetail` | custom | `detail` | `{ register: "decidesk", schema: "meeting", sidebarTabs: [overview, agenda, participants, audit] }` | Standard detail. |
| `LiveMeeting` | custom | `custom` | (unchanged) `component: "LiveMeetingView"` | **Genuine exception** — realtime meeting shell, frame-by-frame UI, no abstract analogue. |
| `Participants` | custom | `index` | `{ register: "decidesk", schema: "participant", columns: ["displayName","role","party","email"], sidebar: { enabled: true } }` | Schema-driven list. |
| `ParticipantDetail` | custom | `detail` | `{ register: "decidesk", schema: "participant", sidebarTabs: [overview, audit] }` | Standard detail. |
| `AgendaItems` | custom | `index` | `{ register: "decidesk", schema: "agenda-item", columns: ["orderNumber","title","itemType","estimatedDuration","isRecurring"], sidebar: { enabled: true } }` | Schema-driven list. |
| `AgendaItemDetail` | custom | `detail` | `{ register: "decidesk", schema: "agenda-item", sidebarTabs: [overview, motions, audit] }` | Standard detail. |
| `Motions` | custom | `index` | `{ register: "decidesk", schema: "motion", columns: ["title","motionType","proposer","lifecycle","submittedAt"], sidebar: { enabled: true } }` | Schema-driven list. |
| `MotionDetail` | custom | `detail` | `{ register: "decidesk", schema: "motion", sidebarTabs: [overview, amendments, votes, audit] }` | Standard detail. |
| `AmendmentDetail` | custom | `detail` | `{ register: "decidesk", schema: "amendment", sidebarTabs: [overview, parentMotion, audit] }` | Schema is top-level (`slug: "amendment"`); presentation-layer linkage to parent Motion lives in a sidebar tab widget. |
| `Minutes` | custom | `index` | `{ register: "decidesk", schema: "minutes", columns: ["title","lifecycle","version","approvedAt"], sidebar: { enabled: true } }` | Schema-driven list. |
| `MinutesDetail` | custom | `detail` | `{ register: "decidesk", schema: "minutes", sidebarTabs: [overview, signers, audit] }` | Standard detail. |
| `Decisions` | custom | `index` | `{ register: "decidesk", schema: "decision", columns: ["title","outcome","decisionDate","isPublished"], sidebar: { enabled: true } }` | Schema-driven list. |
| `DecisionDetail` | custom | `detail` | `{ register: "decidesk", schema: "decision", sidebarTabs: [overview, actionItems, audit] }` | Standard detail. |
| `ActionItems` | custom | `index` | `{ register: "decidesk", schema: "action-item", columns: ["title","assignee","dueDate","taskStatus"], sidebar: { enabled: true } }` | Schema-driven list. |
| `ActionItemDetail` | custom | `detail` | `{ register: "decidesk", schema: "action-item", sidebarTabs: [overview, audit] }` | Standard detail. |
| `Settings` | custom | `settings` | `{ saveEndpoint, sections: [Version (version-info widget), Registers (register-mapping widget, 17 types), Advanced (fields: ori_endpoint, email_voting_enabled)] }` | **Migrated in cleanup commit** — `manifest-settings-rich-sections` shipped `widgets[]` on `sections[]`, unblocking the previous lib gap. `SettingsView.vue` deleted. |

Final tally (after cleanup commit): **8 index + 9 detail + 1 dashboard + 1 settings + 1 custom = 20**.

## Sidebar tab inventory

For `type: "detail"` pages, `config.sidebarTabs` declares an
open-enum array of tabs the abstract sidebar (post
`manifest-abstract-sidebar`) renders against the registry-driven
contract. Tab shapes: `{ id, label, icon?, widgets?, component?, order? }`.

This change ships a minimal tab inventory per detail page (overview +
audit + 0–2 per-schema tabs). The actual tab content for non-built-in
tabs (e.g. `members` on `GovernanceBodyDetail`) resolves through the
`customComponents` registry — those custom-component names are
documented in this design but their implementation belongs to the
follow-up adoption commit.

| Detail page | Tabs |
|---|---|
| `GovernanceBodyDetail` | `overview` (data widget), `members` (custom: `GovernanceBodyMembersTab`), `audit` (built-in audit-trail) |
| `MeetingDetail` | `overview`, `agenda` (custom: `MeetingAgendaTab`), `participants` (custom: `MeetingParticipantsTab`), `audit` |
| `ParticipantDetail` | `overview`, `audit` |
| `AgendaItemDetail` | `overview`, `motions` (custom: `AgendaMotionsTab`), `audit` |
| `MotionDetail` | `overview`, `amendments` (custom: `MotionAmendmentsTab`), `votes` (custom: `MotionVotesTab`), `audit` |
| `AmendmentDetail` | `overview`, `parentMotion` (custom: `AmendmentParentMotionTab`), `audit` |
| `MinutesDetail` | `overview`, `signers` (custom: `MinutesSignersTab`), `audit` |
| `DecisionDetail` | `overview`, `actionItems` (custom: `DecisionActionItemsTab`), `audit` |
| `ActionItemDetail` | `overview`, `audit` |

When this change ships, the manifest references those custom tab
component names; the components themselves are TODO for the
follow-up adoption commit. Manifest validation passes regardless
because the abstract-sidebar spec lets unresolved registry names
log a `console.warn` rather than crash (per the lib spec
"Unknown widget type warns" scenario).

## Dashboard widget inventory

`Dashboard` config sketch:

```json
{
  "widgets": [
    {
      "id": "minutes-in-review",
      "type": "stats-block",
      "title": "Notulen ter goedkeuring",
      "props": {
        "register": "decidesk",
        "schema": "minutes",
        "filter": { "lifecycle": "review" },
        "variant": "warning"
      }
    },
    {
      "id": "published-decisions",
      "type": "stats-block",
      "title": "Gepubliceerde besluiten",
      "props": {
        "register": "decidesk",
        "schema": "decision",
        "filter": { "isPublished": true },
        "variant": "success"
      }
    },
    {
      "id": "open-action-items",
      "type": "stats-block",
      "title": "Open actiepunten",
      "props": {
        "register": "decidesk",
        "schema": "action-item",
        "filter": { "taskStatus": ["open", "in-progress"] },
        "variant": "primary"
      }
    }
  ],
  "layout": [
    { "id": "minutes-in-review",   "gridX": 0, "gridY": 0, "gridWidth": 4, "gridHeight": 2 },
    { "id": "published-decisions", "gridX": 4, "gridY": 0, "gridWidth": 4, "gridHeight": 2 },
    { "id": "open-action-items",   "gridX": 8, "gridY": 0, "gridWidth": 4, "gridHeight": 2 }
  ]
}
```

`stats-block` is a hypothetical dashboard widget type. The current
`Dashboard.vue` hard-codes `CnStatsBlock` instances and direct fetch
calls. If the lib's existing `dashboard` widget registry doesn't
expose a `stats-block` type, the manifest still validates (widget
`type` is open-enum at schema level — only the validator's runtime
warning surfaces) and `Dashboard` falls back to `type: "custom"` for
the v1 release. Tracked as Open Question 1.

## Custom-fallback inventory

Three categories:

### Genuine exceptions (lib-fit issue, not migration cost)

- **`LiveMeeting`** — realtime meeting shell. WebSocket subscriptions,
  per-frame UI updates, vote-card animations. Doesn't fit `detail` or
  any other built-in. Documented as the canonical example for a
  future `type: "realtime"` lib extension; until then, stays custom.

### Lib gaps (could migrate if the lib were richer)

- ~~**`Settings`**~~ **RESOLVED in cleanup follow-up.** The lib's
  `manifest-settings-rich-sections` change shipped a `widgets[]`
  extension on `pages[].config.sections[]` with built-in widget
  types `version-info` and `register-mapping`. Decidesk's Settings
  page migrated to `type: "settings"` with three sections — Version
  (version-info widget), Registers (register-mapping widget covering
  all 17 types), Advanced (flat `fields[]` for `ori_endpoint` and
  `email_voting_enabled`). `SettingsView.vue` deleted. Save / reimport
  events flow through `@widget-event` on `CnAppRoot` to the
  `useSettingsStore` actions (wired in main.js / App.vue at runtime
  once the lib publishes).
- **`Dashboard`** *(if no `stats-block` widget)* — KPIs as hard-coded
  `CnStatsBlock` calls map naturally to a `stats-block` `widgetDef`,
  but the lib's current widget registry only exposes the dashboard
  primitives shipped with `dashboard-widget-system`. If that doesn't
  include a count-with-filter "stats" widget, `Dashboard` stays
  custom. Tracked as
  `nextcloud-vue/dashboard-stats-block-widget`. (This change
  optimistically declares `Dashboard` as `type: "dashboard"`; if the
  validator rejects on widget `type` resolution, downgrade to
  `"custom"` in a follow-up edit.)

### Migration cost (acceptable to defer)

*(none in this round — every other page maps cleanly.)*

## Files affected

Modified:
- `decidesk/src/manifest.json` — full rewrite, 16 type retypes + new
  `config` blocks per page; `version` 0.4.0 → 1.0.0.
- `decidesk/openspec/changes/decidesk-manifest-v1/{proposal,design,tasks}.md`
  and `specs/decidesk-app-manifest/spec.md`.

New:
- `decidesk/tests/validate-manifest.js` — Node script that loads
  `src/manifest.json` and validates against the merged schema.

TODO-marked but NOT deleted:
- `decidesk/src/views/Dashboard.vue`
- `decidesk/src/views/GovernanceBodies.vue`
- `decidesk/src/views/GovernanceBodyDetail.vue`
- `decidesk/src/views/Meetings.vue`
- `decidesk/src/views/MeetingDetail.vue`
- `decidesk/src/views/Participants.vue`
- `decidesk/src/views/ParticipantDetail.vue`
- `decidesk/src/views/AgendaItems.vue`
- `decidesk/src/views/AgendaItemDetail.vue`
- `decidesk/src/views/Motions.vue`
- `decidesk/src/views/MotionDetail.vue`
- `decidesk/src/views/AmendmentDetail.vue`
- `decidesk/src/views/Minutes.vue`
- `decidesk/src/views/MinutesDetail.vue`
- `decidesk/src/views/Decisions.vue`
- `decidesk/src/views/DecisionDetail.vue`
- `decidesk/src/views/ActionItems.vue`
- `decidesk/src/views/ActionItemDetail.vue`

Untouched in this commit:
- `decidesk/src/router/index.js` — still references the per-page Vue
  files; will be replaced when `CnAppRoot` adoption lands.
- `decidesk/src/main.js` / `decidesk/src/App.vue` — current shell
  preserved.
- `decidesk/src/views/LiveMeeting.vue` and
  `decidesk/src/views/SettingsView.vue` — surviving custom pages.
- `decidesk/package.json` — `@conduction/nextcloud-vue` floor NOT
  bumped.
- `decidesk/lib/Settings/decidesk_register.json` — schemas untouched.

## Cleanup follow-up — DONE in this commit

Originally deferred to a separate "decidesk-manifest-v1-adopt" commit;
landed in this same change as the **cleanup follow-up commit** (the
second commit on `feature/decidesk-manifest-v1`, on top of the
manifest-rewrite parent). What changed since the cleanup-follow-up was
first written:

- The six manifest-related lib changes consolidated onto a single
  `feature/manifest-v1` branch in the `nextcloud-vue` repo (schema
  v1.2.0, package version `1.0.0-beta.2`). The lib now ships
  `manifest-settings-rich-sections` — a `widgets[]` extension to
  `pages[].config.sections[]` accepting built-in `version-info` and
  `register-mapping` widget types. That **unblocks** decidesk's
  `Settings` page, which was previously a `type: "custom"` survivor
  due to the lib gap (see "Custom-fallback inventory → Lib gaps"
  above).

What this commit does:

1. Bump `package.json` `@conduction/nextcloud-vue` floor to
   `^1.0.0-beta.2`. This is a **placeholder** — the lib has not yet
   been published to npm. Bump to the actual published semver once
   v1.x ships.
2. Replace `src/main.js` shell + `src/App.vue` with `CnAppRoot` +
   `CnPageRenderer` consumption. main.js builds the vue-router
   routes from `manifest.pages[*].{id, route}` (one `CnPageRenderer`
   per route, `props: true` when the path declares a `:` parameter).
   App.vue provides the `objectSidebarState` channel and slots in a
   single host-rendered `<CnObjectSidebar>` via `#sidebar`.
3. Fold the legacy `src/router/index.js` builder into `main.js` and
   delete the standalone file.
4. Create `src/customComponents.js` with the surviving entries:
   - `LiveMeetingView` → `./views/LiveMeeting.vue` (genuine
     realtime exception)
   - 9 detail-tab custom components (full implementations as of
     the second cleanup follow-up commit — replaces the
     `CnNoteCard` placeholder stubs that were checked in
     alongside `customComponents.js`):
     - `GovernanceBodyMembersTab` (add-existing / remove)
     - `MeetingAgendaTab` (full CRUD)
     - `MeetingParticipantsTab` (add-existing / remove)
     - `AgendaMotionsTab` (full CRUD)
     - `MotionAmendmentsTab` (full CRUD)
     - `MotionVotesTab` (read-only audit listing)
     - `AmendmentParentMotionTab` (read-only summary + click-through)
     - `MinutesSignersTab` (add-existing / remove + "Sign now" CTA)
     - `DecisionActionItemsTab` (full CRUD)
   All nine tabs are wired to the lib's `useObjectStore`
   (cross-schema fetches via `fetchCollection` /
   `fetchObject`) through a small `tabs/useRelationStore.js`
   helper that registers child object types lazily with
   schema slugs sourced from the settings store.
   `SettingsView` is **no longer** in the registry — migrated to
   `type: "settings"` with rich `widgets[]` sections per the
   `manifest-settings-rich-sections` recipe. The settings store
   continues to back the `register-mapping` widget through the
   `@widget-event` re-emit pattern (wired at the consuming app
   level when the runtime save handler lands).
5. Migrate `src/manifest.json` Settings page from `type: "custom"`
   to `type: "settings"` with three sections (`Version` →
   `version-info` widget; `Registers` → `register-mapping` widget
   covering all 17 decidesk types; `Advanced` → flat `fields[]` for
   `ori_endpoint` (string) + `email_voting_enabled` (boolean)).
6. Delete the 18 obsolete per-page Vue files. Also delete
   `src/views/SettingsView.vue` (now redundant), `src/router/index.js`
   (folded into main.js), and `src/navigation/MainMenu.vue` (replaced
   by the lib's `CnAppNav` driven by the manifest menu).

What this commit does **not** do (intentionally):

- Run the full Playwright regression suite. Blocked: the
  `@conduction/nextcloud-vue` v1.x release is upstream and not yet
  on npm. Runtime smoke / regression runs once the lib publishes.
- ~~Implement the 9 detail-tab custom components.~~ DONE in the
  second cleanup follow-up commit (see §7b in tasks.md). Replaces
  the placeholder `CnNoteCard` stubs with full implementations
  using `CnDataTable` + `CnFormDialog` + `CnDeleteDialog`
  against the lib's `useObjectStore`.
- Touch the orphan components (`AmendmentList.vue`,
  `MeetingLifecycle.vue`, `VotingRoundPanel.vue`, `GlobalSearch.vue`).
  They have no live importers post-cleanup, but deleting them is out
  of scope for this commit and will be handled by a future
  dead-code sweep.

## Citations

- **Library schema (post-merge)**:
  `nextcloud-vue/src/schemas/app-manifest.schema.json` v1.1.0
- **Library renderer parent contract**:
  `nextcloud-vue/openspec/changes/add-json-manifest-renderer/specs/json-manifest-renderer/spec.md`
- **Library page-type extensions** (sibling, committed, unreleased):
  `nextcloud-vue/openspec/changes/manifest-page-type-extensions/`
  (commit `4b308d9` in `/tmp/worktrees/nextcloud-vue-page-type-extensions`)
- **Library abstract sidebar** (sibling, committed, unreleased):
  `nextcloud-vue/openspec/changes/manifest-abstract-sidebar/`
  (commit `05a8ffb` in `/tmp/worktrees/nextcloud-vue-abstract-sidebar`)
- **Library config $defs** (sibling, committed, unreleased):
  `nextcloud-vue/openspec/changes/manifest-config-defs/`
  (commit `569d553` in `/tmp/worktrees/nextcloud-vue-schema-config-defs`)
- **Cross-app convention**:
  `hydra/openspec/architecture/adr-024-app-manifest.md`
- **Audit recommendation**:
  `.claude/audit-2026-05-03/research/R6-manifest-json.md` lines 165-168

## Out of scope

- Multi-tenancy, i18n, resolver consumer wiring (separate changes).
- `CnAppRoot` adoption / per-page Vue file deletion / router rewrite
  (follow-up commit "decidesk-manifest-v1-adopt").
- Backend `/api/manifest` endpoint (App Builder use case).
- Lib extensions for `register-mapping` field type and
  `stats-block` dashboard widget (lib-side changes in nextcloud-vue).
- New page types beyond the eight already in the closed enum
  (`type: "realtime"` for `LiveMeeting`, etc.).

## Open questions

1. **Dashboard widget registry.** The `manifest-page-type-extensions`
   change does not extend the dashboard widget registry. Decidesk's
   three KPIs (`stats-block` per-schema-with-filter) need either a
   built-in widget type or a fall back to `type: "custom"` for
   `Dashboard`. Default: declare `type: "dashboard"` optimistically
   and downgrade if validation fails. Revisit when the published
   `dashboard-widget-system` release confirms which widget types
   ship.
2. **Settings register-mapping editor.** `CnRegisterMapping` is a
   complex nested editor (multiple registers + multiple schemas +
   per-mapping save). It does not fit `config.sections[].fields[]`.
   Two options: (a) lib grows a `register-mapping` field type;
   (b) `type: "settings"` learns to host a per-section custom-
   component slot. Defaulting to (b) because it generalises better
   and matches the pattern the `slots` override already uses for
   index/detail. Tracked as a lib-side follow-up.
3. **`AmendmentDetail` parent-motion sidebar tab.** The sidebar tab
   `parentMotion` reaches across schemas — Amendment object → its
   parent Motion. The abstract-sidebar spec resolves widgets / custom
   components against the registry, so the cross-schema lookup runs
   inside the custom component, not the renderer. Confirmed
   non-blocking; the manifest entry stays `type: "detail"`.

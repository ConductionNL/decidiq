---
status: done
---

# decidesk-manifest-v1 Specification

## Purpose
Migrate `decidesk/src/manifest.json` from 20-of-20 `type: "custom"`
entries (each pointing at a per-page Vue file under `src/views/`) to
declarative built-in page types consumed by
`@conduction/nextcloud-vue`'s JSON manifest renderer.

The migration is FORWARD-compatible with three sibling
`@conduction/nextcloud-vue` changes that have been committed but are
not yet released:

- `manifest-page-type-extensions` — adds `logs | settings | chat |
  files` to the closed `pages[].type` enum.
- `manifest-abstract-sidebar` — opens `CnObjectSidebar` to
  registry-driven `tabs[]` and lets `CnIndexPage` auto-mount
  `CnIndexSidebar` from a `sidebar` config block.
- `manifest-config-defs` — adds JSON-Schema `$defs` for reusable
  config sub-objects.

This spec captures the migration deltas as `MODIFIED` requirements on
the existing `decidesk-app-manifest` capability.

## Requirements

### Requirement: REQ-DMV1-1 Index pages MUST be `type: "index"` with declarative config

Pages that render schema-backed list views — `GovernanceBodies`, `Meetings`, `Participants`, `AgendaItems`, `Motions`, `Minutes`, `Decisions`, `ActionItems` — MUST declare `type: "index"` (not
`"custom"`) in `src/manifest.json`. Each entry MUST declare
`config.register: "decidesk"`, `config.schema: <slug>`, and
`config.columns: string[]` listing the columns the page renders.
Each entry MUST drop its `component` field (renderer dispatches on
`type`, not on the custom-component registry).

#### Scenario: GovernanceBodies index validates and dispatches
- GIVEN `src/manifest.json` page entry for `GovernanceBodies` with `type: "index"`, `config: { register: "decidesk", schema: "governance-body", columns: ["name","bodyType","domain","termEnd"] }`
- WHEN `validateManifest()` runs against the v1.1.0 schema
- THEN it MUST return `{ valid: true, errors: [] }`

#### Scenario: Migrated index has no component field
- GIVEN any of the eight migrated index pages
- WHEN inspecting its manifest entry
- THEN the entry MUST NOT include a `component` field

### Requirement: REQ-DMV1-2 Detail pages MUST be `type: "detail"` with `sidebarTabs`

Pages that render single-object detail views — `GovernanceBodyDetail`, `MeetingDetail`, `ParticipantDetail`, `AgendaItemDetail`, `MotionDetail`, `AmendmentDetail`, `MinutesDetail`, `DecisionDetail`, `ActionItemDetail` — MUST declare
`type: "detail"` (not `"custom"`). Each entry MUST declare
`config.register: "decidesk"` and `config.schema: <slug>`. Each
entry SHOULD declare `config.sidebarTabs: SidebarTab[]` per the
`manifest-abstract-sidebar` contract; tab definitions either
reference the built-in `audit` tab (`{ id: "audit", icon: "...",
widgets: [{ type: "audit-trail" }] }`) or name a custom component
through the `customComponents` registry.

#### Scenario: DecisionDetail dispatches via detail with sidebarTabs
- GIVEN `pages[]` contains `{ id: "DecisionDetail", route: "/decisions/:id", type: "detail", title: "Decision", config: { register: "decidesk", schema: "decision", sidebarTabs: [...] } }`
- WHEN `validateManifest()` runs
- THEN it MUST return `{ valid: true, errors: [] }`

#### Scenario: AmendmentDetail uses its own schema
- GIVEN `pages[]` contains `{ id: "AmendmentDetail", route: "/amendments/:id", type: "detail", config: { register: "decidesk", schema: "amendment", sidebarTabs: [...] } }`
- WHEN `validateManifest()` runs
- THEN it MUST return `{ valid: true, errors: [] }`
- AND the parent-motion linkage MUST be expressed as a sidebar tab `parentMotion` resolving to a custom component, NOT as a renderer-level cross-schema feature

### Requirement: REQ-DMV1-3 Dashboard MUST be `type: "dashboard"` with widgets + layout

The `Dashboard` page MUST declare `type: "dashboard"` (not `"custom"`)
with `config.widgets: WidgetDef[]` and `config.layout: LayoutItem[]`.
The three KPI widgets — minutes-in-review, published-decisions,
open-action-items — MUST each have an entry in `config.widgets`
referencing the `decidesk` register and the relevant schema slug
(`minutes`, `decision`, `action-item`).

#### Scenario: Dashboard manifest entry validates
- GIVEN `pages[]` contains `{ id: "Dashboard", route: "/", type: "dashboard", config: { widgets: [...3 entries...], layout: [...3 entries...] } }`
- WHEN `validateManifest()` runs
- THEN it MUST return `{ valid: true, errors: [] }`

#### Scenario: Each widget references the decidesk register
- GIVEN the Dashboard `config.widgets` array
- WHEN inspecting each widget's `props`
- THEN every widget MUST set `props.register === "decidesk"`

### Requirement: REQ-DMV1-4 Custom-fallback inventory MUST stay at exactly 2 entries

After this migration, exactly two pages MUST stay `type: "custom"`:
`LiveMeeting` (genuine realtime exception) and `Settings` (lib gap:
no `register-mapping` field type yet). Each surviving custom entry
MUST keep its `component` field intact (referencing
`LiveMeetingView` and `SettingsView` respectively).

#### Scenario: Exactly two custom pages
- GIVEN `src/manifest.json`
- WHEN counting `pages[*].type === "custom"`
- THEN the count MUST be exactly 2
- AND the two ids MUST be `LiveMeeting` and `Settings`

#### Scenario: LiveMeeting still points at LiveMeetingView
- GIVEN the `LiveMeeting` page entry
- WHEN inspecting its `component` field
- THEN it MUST equal `"LiveMeetingView"`

### Requirement: REQ-DMV1-5 The manifest version MUST bump to 1.0.0

`src/manifest.json`'s top-level `version` field MUST bump from
`"0.4.0"` to `"1.0.0"` to mark this migration. The
`$schema` URL MUST remain pointed at the canonical
`https://codeberg.org/Conduction/nextcloud-vue/raw/branch/main/src/schemas/app-manifest.schema.json`
URL (the schema is versioned via its own `version` field, not via
URL rotation).

#### Scenario: Version is 1.0.0
- GIVEN `src/manifest.json`
- WHEN reading `manifest.version`
- THEN it MUST equal `"1.0.0"`

### Requirement: REQ-DMV1-6 Page id, route, and title MUST round-trip

Every page entry's `id`, `route`, and `title` MUST be preserved
unchanged across the migration. No page is renamed, dropped, or
re-routed. Only `type`, `config`, and `component` change.

#### Scenario: All 20 page ids are preserved
- GIVEN the pre-migration manifest with 20 page entries
- AND the post-migration manifest
- WHEN comparing `pages[*].id` arrays
- THEN they MUST be equal as multisets

#### Scenario: All 20 page routes are preserved
- GIVEN the pre-migration manifest
- AND the post-migration manifest
- WHEN comparing `pages[*].route` arrays
- THEN they MUST be equal as multisets

### Requirement: REQ-DMV1-7 Manifest MUST validate against the v1.1.0 schema

`src/manifest.json` MUST validate without errors against the
`@conduction/nextcloud-vue` app-manifest schema at version `1.1.0`
(post `manifest-page-type-extensions` merge). Validation MUST be
runnable from the repo with `node tests/validate-manifest.js`.

#### Scenario: Validator script exits 0
- GIVEN the migrated `src/manifest.json`
- AND the schema bundle from the lib's v1.1.0 release
- WHEN running `node tests/validate-manifest.js`
- THEN the script MUST exit with status code 0
- AND it MUST print a success line confirming zero validation errors

### Requirement: REQ-DMV1-8 Per-page Vue files for migrated pages MUST stay in place

The per-page Vue files for the 18 migrated pages (`Dashboard.vue` through `ActionItemDetail.vue`, excluding `LiveMeeting.vue` and `SettingsView.vue`) MUST stay in place in
this commit. Each MUST receive a TODO header comment marking it
obsolete after the lib release lands. Deletion is deferred to the
follow-up "decidesk-manifest-v1-adopt" commit.

#### Scenario: Migrated Vue file is preserved with TODO marker
- GIVEN `src/views/Decisions.vue` (and 17 sibling files)
- WHEN reading the file
- THEN the file MUST exist
- AND the file's first 10 lines MUST contain the substring `TODO(decidesk-manifest-v1)`

#### Scenario: Surviving Vue files have no TODO marker
- GIVEN `src/views/LiveMeeting.vue` and `src/views/SettingsView.vue`
- WHEN reading each
- THEN neither file's first 10 lines MUST contain `TODO(decidesk-manifest-v1)`

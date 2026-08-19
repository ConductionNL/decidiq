# Design: ia-six-clusters

## Architecture Overview

Decidesk's runtime menu is built once, at boot, in `src/main.js`:

```js
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx.keys().sort().map((key) => fragmentCtx(key))
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)
```

`buildManifest()` (`@conduction/nextcloud-vue/src/utils/buildManifest.js`)
does, in order:

1. **Merge** — `mergeMenuItems()` unions `src/manifest.json`'s base `menu[]`
   with every `src/manifest.d/*.json` fragment's `menu[]`, keyed by `id`.
   This is where today's 44 flat entries come from: 5 base + 2 footer + 35
   fragment entries + 2 more from `manifest.json` that overlap with the
   count above are folded in (see Context below for the exact tally).
2. **Layout** — `applyMenuLayout(merged.menu, menuLayout)` runs three passes,
   each reading only from `src/menu-layout.json`:
   - `applyMenuRelocations(menu, relocations)` — for each
     `{sourceId: targetGroupId}` pair, remove `sourceId` from wherever it
     sits and push it into `menu.find(m => m.id === targetGroupId).children`
     (creating `children: []` on the target if it doesn't have one yet).
     **The target group id must already exist in the merged menu** — if it
     doesn't, the node is pushed back to the top level unchanged (line 188,
     `if (!group) { menu.push(node); return }`). This is the mechanical fact
     that forces one of this change's `manifest.json` edits (see Decision 2).
   - `applyMenuRemovals(menu, removals)` — drops listed leaf ids outright.
     Pages are untouched (`pages[]` is a separate array from `menu[]`), so a
     removed nav row's page stays reachable by direct route.
   - `applySettingsSection(menu, settingsIds)` — strips listed ids from
     wherever they sit, tags each `{ ...leaf, section: 'settings' }`, and
     appends them to the top level, where `CnAppNav` renders every
     `section: 'settings'` entry inside the `NcAppNavigationSettings` gear
     foldout instead of the scrollable primary list.

Today `src/menu-layout.json` is `{relocations: {}, removals: [], settingsSection: []}`
— a no-op. This change is purely: populate those three keys, plus the three
`manifest.json` edits the mechanism itself requires (documented in Decision 2).

## Goals / Non-Goals

**Goals**
- Collapse the primary top-level nav from 44 entries to ADR-004's 6-item
  ceiling, using the existing `menu-layout.json` mechanism.
- Keep every relocated/removed page routable by direct URL (deep links,
  bookmarks, e2e specs) — a hard invariant `menu-layout.json`'s own `_meta`
  already documents and this change must not violate.
- Land the product owner's approved six-cluster placement map exactly (no
  re-litigation — see proposal.md and the placement map below).
- Amend ADR-004 with a v2 nav table so the architecture doc matches reality
  going forward.

**Non-Goals**
- No PHP/Vue/TS code change of any kind (`kind: config`).
- No change to any `manifest.d/*.json` fragment file — every fragment keeps
  declaring its own top-level `menu` entry exactly as today. Only where that
  entry ends up rendering changes.
- No OpenRegister schema, register, or field change.
- No change to `organisatie-modus` mode-label adaptation (ADR-004 Rule 1) —
  the six cluster ids are the same regardless of tenant mode; only their
  *labels* adapt, via the existing, unrelated label-map mechanism.
- No fix to `tests/e2e/spec-coverage/features-roadmap-page.spec.ts` (a code
  change) — see Risks / Trade-offs and DEFERRED_QUESTIONS.
- No migration of the 8 `settingsSection` entries onto individual
  `lib/Settings/*Admin.php` admin pages — see Decision 4.

## Decisions

### Decision 1: The six-cluster placement map (product-owner-approved, not re-litigated)

Every one of the 35 fragment-injected top-level entries, plus the 2
pre-existing footer entries, is accounted for exactly once across
relocation / removal / settings-lift. Verified by direct enumeration of all
22 `src/manifest.d/*.json` fragments' `menu[]` arrays (`grep`-checked against
this table; every fragment id below is confirmed to exist with the id shown
— no fragment id was guessed).

| Target | Mechanism | ids |
|---|---|---|
| `Meetings` (base) | relocation | `MondelingeVragen`, `Interpellaties`, `IngekomenStukken`, `Raadsinformatiebrieven`, `KascommissieVerklaringen` |
| `Decisions` (base) | relocation | `Raadplegingen`, `Consultations`, `WorTrajecten`, `Adviesaanvragen`, `Zienswijzerondes`, `Voordrachten` |
| `ActionItems` (base, relabelled "Tasks & Commitments") | relocation | `Toezeggingen`, `Termijnagenda`, `PCCycli`, `Goals` (forward-declared) |
| `GovernanceBodies` (base, relabelled "Organisation") | relocation | `Roosters`, `Nevenfuncties`, `Geschenken`, `OnboardingTrajecten`, `OffboardingTrajecten`, `ProxyAuthorizations` |
| `Registers` (**new** base entry — see Decision 2) | relocation | `Regelingen`, `GoverningDocuments`, `Bevoegdheidstoedelingen`, `Geheimhoudingen` |
| settings gear | settingsSection lift | `Termijnregelingen`, `VveDecisionTemplates`, `ModelreglementPresets`, `VveConfigurations`, `WooDiwoo`, `GeheimhoudingGronden`, `ModerationQueue`, `UserSettingsMenu` |
| removed (page stays routable) | removal | `UrgentDecisions`, `MyDeclarations`, `Zienswijzen`, `FeaturesRoadmapMenu` |

24 relocations of existing ids + 1 forward-declared relocation (`Goals`) +
8 settings-lifts + 4 removals. The 24 + 8 + 4 = 36 existing placements
cover the 35 fragment entries + the 1 base-manifest `FeaturesRoadmapMenu`
footer entry — nothing existing is left unplaced; nothing is placed twice.

Two judge-pass adjustments to the original map (2026-08-19):
- **`Voordrachten` → `Decisions`, not `GovernanceBodies`.** Per the product
  decision and ADR-005, a nomination IS a decision
  (`decisionType=appointment`; parallel chain
  `appointment-decision-type-schema` / `-membership`), so its register
  entry belongs with the decision surfaces until that chain retires the
  Voordracht pages entirely.
- **`Goals` → `ActionItems` is a forward declaration** for the parallel
  change `organisation-goals`, whose new fragment
  (`src/manifest.d/organisation-goals.json`) declares menu id `Goals`.
  Verified in `buildManifest.js#applyMenuRelocations`: the map is consulted
  per merged-menu node (`relocations[node.id]`), so a mapping whose source
  id is absent is simply never read — declaring it early is a safe no-op
  and keeps the `nav-ceiling-gate` green regardless of which change merges
  first.

`UserSettingsMenu` already declares `"section": "settings"` inside its own
fragment (`src/manifest.d/user-settings.json`), so it is *already* correctly
positioned even before this change. Listing it in `settingsSection` too is
redundant but harmless (`applySettingsSection` is idempotent for an entry
that's already `section: "settings"` — it strips and re-tags to the same
shape) and keeps `menu-layout.json` the single legible source of "everything
that lives in the gear," matching its own stated purpose. This change does
**not** edit `user-settings.json` to remove the now-redundant inline
`section` key — that would be an unforced extra file edit with no behaviour
change, outside this change's minimal-footprint intent.

### Decision 2: `menu-layout.json` alone cannot create the `Registers` group — three `manifest.json` edits are required

`applyMenuRelocations()` only re-homes a node into a target whose id
**already exists** in the merged menu (`menu.find(m => m.id === target)`);
if no such id exists, the relocated node is pushed back to the top level
unchanged, not nested under a phantom group. Since no existing base entry or
fragment declares an id `Registers`, the four Registers-group leaves
(`Regelingen`, `GoverningDocuments`, `Bevoegdheidstoedelingen`,
`Geheimhoudingen`) would silently fail to relocate and stay at the top
level — a 7th primary item, defeating the whole point of this change.

**Alternatives considered:**
- *Have one fragment (e.g. `verordeningenregister.json`) declare the
  `Registers` group id itself, with the other three relocated into it.*
  Rejected: makes one fragment structurally special and load-order-dependent
  (`require.context` sorts fragments alphabetically — `verordeningenregister.json`
  happens to sort after the others it would need to receive, which is
  fragile and non-obvious to a future reader).
- *Add `Registers` as a 23rd fragment (`manifest.d/registers-group.json`)
  declaring only the group shell.* Rejected: adds a file whose only content
  is a menu stub with no page, which is exactly the kind of drift
  `menu-layout.json` exists to avoid encoding into fragments. The base
  manifest is the natural home for a structural, cross-fragment group
  anchor — it's where the other four group anchors (`Dashboard`, `Meetings`,
  `Decisions`, `ActionItems`, `GovernanceBodies`) already live.

**Decision:** add `Registers` to `src/manifest.json`'s base `menu[]`, as a
route-less pure group (no `route`/`href`/`action`) — the pattern
`CnAppNav.vue` already supports and documents ("items with visible children
are pure group headers: their anchor is a dead `#` link, so clicking the
title toggles the children", `CnAppNav.vue` around line 1225). This is
still a declarative JSON edit; it does not touch `menu-layout.json`'s "ONLY"
framing in spirit — it's the one entry the mechanism cannot synthesize for
itself.

Target `src/manifest.json` `menu[]` array, in full (existing entries shown
for context; only `ActionItems`/`GovernanceBodies` labels and the new
`Registers` entry change):

```json
"menu": [
  { "id": "Dashboard", "label": "Dashboard", "icon": "ViewDashboardOutline", "route": "Dashboard", "order": 10 },
  { "id": "Meetings", "label": "Meetings", "icon": "CalendarAccountOutline", "route": "Meetings", "order": 20 },
  { "id": "Decisions", "label": "Decisions", "icon": "Gavel", "route": "Decisions", "order": 30 },
  { "id": "ActionItems", "label": "Tasks & Commitments", "icon": "FormatListChecks", "route": "ActionItems", "order": 40 },
  { "id": "GovernanceBodies", "label": "Organisation", "icon": "AccountGroupOutline", "route": "GovernanceBodies", "order": 60 },
  { "id": "Registers", "label": "Registers", "icon": "LibraryOutline", "order": 70 },
  { "id": "Documentation", "label": "Documentation", "icon": "BookOpenVariantOutline", "href": "https://decidesk.conduction.nl", "section": "footer", "order": 90 },
  { "id": "FeaturesRoadmapMenu", "label": "Features & roadmap", "icon": "MapMarkerPath", "route": "FeaturesRoadmap", "section": "footer", "order": 100 }
]
```

`FeaturesRoadmapMenu` is left **unedited** in `manifest.json` — its removal
from the rendered nav is handled entirely by `menu-layout.json#removals`
(Decision 1), consistent with how the other three removals work and
avoiding a second place that decides the same fact.

`Icon` choice: `LibraryOutline` (from `vue-material-design-icons`, already a
peer dependency, unused elsewhere in this manifest) — semantically a shelf
of registers/regulations, matching the group's four Registers-domain
children (Regelingen/GoverningDocuments/Bevoegdheidstoedelingen/
Geheimhoudingen).

### Decision 3: Full target `src/menu-layout.json`

```json
{
	"_meta": {
		"spdx-license": "EUPL-1.2",
		"spdx-copyright": "2026 Conduction B.V.",
		"description": "Canonical navigation layout applied AFTER all manifest.d fragments merge (see buildManifest in main.js). Fragments stay the source of WHAT exists in the menu (ADR-037); this file is the single place deciding WHERE entries live. relocations: sourceId -> targetGroupId (groups dissolve into the target, leaves move under it). settingsSection: top-level config/definition/admin ids lifted into Nextcloud's settings foldout (NcAppNavigationSettings gear, outside the scrollable nav); operational/report/dashboard items (Dashboard, Meetings, Decisions, ActionItems, GovernanceBodies, Registers) stay in the main nav. removals: leaf menu-entry ids retired as duplicate navigation — their PAGES stay routable for deep links and e2e specs. Populated by ia-six-clusters (2026-08-19) to collapse the 44-entry flat nav that accreted from 22 uncoordinated manifest.d fragments back to ADR-004's six-item ceiling; see openspec/changes/ia-six-clusters/design.md for the full placement map and rationale."
	},
	"relocations": {
		"MondelingeVragen": "Meetings",
		"Interpellaties": "Meetings",
		"IngekomenStukken": "Meetings",
		"Raadsinformatiebrieven": "Meetings",
		"KascommissieVerklaringen": "Meetings",

		"Raadplegingen": "Decisions",
		"Consultations": "Decisions",
		"WorTrajecten": "Decisions",
		"Adviesaanvragen": "Decisions",
		"Zienswijzerondes": "Decisions",

		"Voordrachten": "Decisions",

		"Toezeggingen": "ActionItems",
		"Termijnagenda": "ActionItems",
		"PCCycli": "ActionItems",
		"Goals": "ActionItems",

		"Roosters": "GovernanceBodies",
		"Nevenfuncties": "GovernanceBodies",
		"Geschenken": "GovernanceBodies",
		"OnboardingTrajecten": "GovernanceBodies",
		"OffboardingTrajecten": "GovernanceBodies",
		"ProxyAuthorizations": "GovernanceBodies",

		"Regelingen": "Registers",
		"GoverningDocuments": "Registers",
		"Bevoegdheidstoedelingen": "Registers",
		"Geheimhoudingen": "Registers"
	},
	"removals": ["UrgentDecisions", "MyDeclarations", "Zienswijzen", "FeaturesRoadmapMenu"],
	"settingsSection": [
		"Termijnregelingen",
		"VveDecisionTemplates",
		"ModelreglementPresets",
		"VveConfigurations",
		"WooDiwoo",
		"GeheimhoudingGronden",
		"ModerationQueue",
		"UserSettingsMenu"
	],
	"_settingsSectionNote": "Populated by ia-six-clusters (2026-08-19). These 8 ids are operator/definition CONFIGURATION surfaces (deadline-scheme definitions, VvE templates/presets/config, Woo/DiWoo category mappings, confidentiality grounds, the citizen-participation moderation queue) plus the app's real per-user UserSettingsMenu. ADR-079 Decision 2 frames section:'settings' entries as being for 'genuinely per-user configuration' — these first 7 are NOT per-user, so this is an accepted INTERIM placement, not the architecturally final one: ADR-079 Decision 1's stated preference is that non-per-user configuration lives at /settings/admin/decidesk via a registered lib/Settings/*Admin.php section. decidesk does not yet have per-surface admin sub-pages for these 7 domains, and building 7 of them is a `kind: code` effort out of this (`kind: config`) change's scope. Parking them in the gear is still strictly ADR-004-compliant (Rule 4, 'Beheer is operator-only, all behind one door') and strictly better than the pre-change state (all 7 sat as primary top-level nav rows). Tracked as follow-up work, not blocking. UserSettingsMenu itself is the one genuinely per-user entry in this list and is unaffected by that tension; see also nav.includePersonalSettings in manifest.json, which suppresses the OTHER, generic Personal-settings entry so the gear shows exactly one."
}
```

### Decision 4: Dedupe the gear's two "Personal settings" entries — keep the real page, suppress the generic modal

`CnAppNav.vue` auto-prepends a "Personal settings" entry at the top of the
settings gear (`NcAppNavigationItem` with no `:to`/`:href`, only
`@click="onPersonalSettingsClick"` → `cnOpenUserSettings()`, a generic
`NcAppSettingsDialog`). decidesk's own `src/manifest.d/user-settings.json`
fragment *also* declares a top-level entry labelled "Personal settings"
(`UserSettingsMenu`, route `/user-settings`, rendering the real
`UserSettingsPage` — notification/display/delegation/communication
sections, a built and working page). With both present, the gear shows:

```
⚙ Personal settings   ← CnAppNav auto-prepend, no route, "#" anchor
⚙ Personal settings   ← UserSettingsMenu, real route
```

This is the exact "`Settings › Settings`" collision pattern ADR-079 Context
(4) documents for a different pair of entries. **Decision: keep the real,
built page; suppress the generic one** by setting
`nav.includePersonalSettings: false` in `src/manifest.json` (a documented,
schema-valid v2 manifest field —
`node_modules/@conduction/nextcloud-vue/src/types/manifest.d.ts:184`).
Rejected alternative: delete `UserSettingsMenu` from `user-settings.json`
and rely on the generic dialog — rejected because the generic dialog has no
content wired for decidesk (nothing populates `NcAppSettingsDialog`'s
sections for this app), so that direction would regress a built,
spec'd (`openspec/specs/user-settings/spec.md`) feature to nothing.

### Decision 5: ADR-004 v2 amendment (appended, v1 preserved)

`openspec/architecture/adr-004-information-architecture.md`'s "Top-level
navigation (6, fixed)" list (lines 35–50) describes the *original* v1 IA
(Vergaderingen / Besluiten / Acties / Moties / Fracties & Organen / Beheer)
— already stale (the shipped `ia-six-item-nav` change and the later Motions
retirement changed the actual set before this change even starts; e.g. no
"Moties" or "Beheer" nav row exists in `src/manifest.json` today). Rather
than rewrite the Decision section (losing the historical record of the v1
rubric the four placement Rules still reference), **append** a new
subsection immediately after the existing numbered list:

```markdown
### Top-level navigation v2 (2026-08-19, change `ia-six-clusters`)

The six items above named the *original* rubric (May 2026). Two prior
changes (`ia-six-item-nav`'s Motions retirement, and `ia-six-clusters`,
this amendment) evolved the concrete set while keeping Rules 1–4 and the
"six items, fixed" ceiling unchanged. The current six:

1. **Dashboard** — landing page (today's/next meeting + open actions)
2. **Meetings** — calendar + per-meeting workspace, PLUS the relocated
   inquiry/correspondence surfaces (oral questions, interpellations,
   incoming documents, council information letters, kascommissie
   statements)
3. **Decisions** — decisions/resolutions register, PLUS the relocated
   consultation surfaces (member consultations, citizen consultations,
   works-council consultations, advisory opinions, zienswijze rounds) and
   nominations (a nomination is a decision, `decisionType=appointment`
   per ADR-005)
4. **Tasks & Commitments** — replaces "Acties": action items/follow-up,
   PLUS commitments (toezeggingen), organisation goals, the long-term
   agenda, and P&C cycles
5. **Organisation** — replaces "Fracties & Organen": governance bodies/
   members, PLUS retirement schedules, other-positions/gifts
   integrity data, and on/offboarding + proxy-authorization surfaces
6. **Registers** — replaces the retired "Moties" slot (Motions is a
   filtered view of Decisions per ADR-005, not its own top-level item):
   regulations, governing documents, delegation/mandate register, and the
   confidentiality register

"Beheer" is no longer a nav row. Per ADR-079: instance-wide configuration
lives at `/settings/admin/decidesk` (a registered `lib/Settings/*Admin.php`
section, authorized server-side), reachable via the gear's admin-gated
"Admin settings" link-out; a small set of operator/definition surfaces that
don't yet have individual admin sub-pages are parked in the
`NcAppNavigationSettings` gear foldout via `menu-layout.json#settingsSection`
as an interim step (see `ia-six-clusters` design.md Decision 3).

Rules 1–4 and the "New specs MUST fit into one of the existing top-level
items" ceiling are unchanged and apply to this v2 set identically.
```

## Nextcloud Integration

- No new Controllers/Services/Mappers/Events. The only "integration point"
  is the existing `@conduction/nextcloud-vue` `buildManifest()` /
  `applyMenuLayout()` pipeline, already wired in `src/main.js` and unchanged
  by this design.
- `NcAppNavigationSettings` (the gear) and `NcAppNavigationItem`
  (route-less group headers) are both pre-existing nc-vue components; no
  new component is introduced.

## Security Considerations

No security impact. This is a client-side navigation *presentation* change
— every page's own route registration, authorization, and data access are
unchanged; every relocated/removed/lifted entry's target page keeps
whatever route/permission behaviour it already had. No new route is added
or removed, and `Registers` itself has no route (it is not a page, so there
is nothing to authorize).

## NL Design System

No new component. `LibraryOutline` is a standard `vue-material-design-icons`
icon already used as a dependency elsewhere in the manifest icon set
(consistent with the existing icon vocabulary — see the existing icon list
across `manifest.json`/`manifest.d/*.json`).

## File Structure

```
src/
  manifest.json          # menu[]: 2 label edits + 1 new Registers entry; + nav.includePersonalSettings
  menu-layout.json        # relocations / removals / settingsSection populated (Decision 3)
  manifest.d/              # UNCHANGED — every fragment file stays exactly as-is
openspec/
  architecture/
    adr-004-information-architecture.md   # v2 table appended (Decision 5)
  specs/
    app-navigation/spec.md                # changelog + MODIFIED REQ-NAV-002 + ADDED REQ-NAV-009..012 (synced from this change's specs/ delta at archive time)
```

## Findings (investigation results, not implementation)

### Finding A: "Dashboard redirects to /meetings" was not reproduced in code

The task brief flagged the Dashboard landing page as broken ("today /
redirects to /meetings"). Investigation:

- `src/main.js#applyDefaultViewPreference()` only rewrites the route when
  `router.currentRoute.value.path === '/'` AND the fetched preference value
  maps to `'meetings'` or `'decisions'` via a literal
  `{meetings: '/meetings', decisions: '/decisions'}` table. Any other value
  (including `null`/unset) leaves the router at `/`, which is the
  `Dashboard` page route.
- `lib/Controller/PreferencesController.php::getPreference()` returns
  `{value: null}` when nothing is stored (`IConfig::getUserValue(..., default: '')`
  → empty string → coerced to `null`), so an unconfigured user's fetch
  resolves to `null`, which is not a key in the redirect table — no
  redirect fires.
- `src/components/userSettings/DisplayPreferencesSection.vue`'s
  `viewOptions[0]` (the fallback when no stored preference matches) is
  `{ id: 'dashboard', ... }` — the UI's own default also resolves to
  Dashboard, not Meetings.
- No other redirect, server-side or client-side, was found (`grep`-checked
  `routes.php`, `DashboardController.php`, `main.js` for `redirect`).

**Conclusion:** no code defect was found. This change does not modify
`applyDefaultViewPreference` or `PreferencesController` (would violate
`kind: config` even if a defect existed). See DEFERRED_QUESTIONS — this
finding should be confirmed against whatever live observation prompted the
original brief before assuming the item is closed.

### Finding B: only one e2e spec is affected by this change

Every relocated/removed/lifted id (28 across the placement map) was grepped
against `tests/` for `cn-nav-entry-<id>` usage. Only
`tests/e2e/spec-coverage/features-roadmap-page.spec.ts` matched — it drives
navigation by clicking `cn-nav-entry-FeaturesRoadmapMenu`, an entry this
change removes. The four base group-anchor ids other specs click
(`Meetings`, `Decisions`, `ActionItems`, `GovernanceBodies`) keep their id
and stay top-level, so `dashboard.spec.ts`, `action-items-page.spec.ts`,
`settings-page.spec.ts`, and `meeting-efficiency.spec.ts` are unaffected.
Fixing `features-roadmap-page.spec.ts` is a one-file TypeScript edit
(swap the nav click for the file's own documented direct-`page.goto`
fallback pattern) — a code change, out of this `kind: config` change's
scope. See proposal.md Risk 1 and DEFERRED_QUESTIONS.

## Migration Plan

No data migration — `menu-layout.json` and `manifest.json` are read at
webpack build time with no persisted/cached state server-side. Deploy is:
merge → next frontend build picks up the new files → nav renders collapsed
on next page load. No feature flag, no phased rollout — the whole nav
changes atomically on deploy, which is appropriate for a pure presentation
reshuffle where every underlying route is unchanged.

**Rollback:** revert the `src/menu-layout.json` / `src/manifest.json` commit;
next build reverts to the 44-entry nav with no further action.

## Trade-offs

- **[Trade-off] Registers as a route-less pure group** → a click on
  "Registers" itself does nothing but expand/collapse (no landing page of
  its own), unlike the other five top-level items which are all also
  pages. This mirrors an established nc-vue pattern (group-only headers are
  explicitly documented and supported) and avoids inventing a synthetic
  "Registers overview" page with no spec behind it. Accepted.
- **[Trade-off] Relocated leaves default to a collapsed parent** → finding
  e.g. "Oral questions" now costs one extra click (expand Meetings) versus
  today's flat list. This is the intended cost of ADR-004's ceiling, not an
  accident; Finding B confirms no e2e spec depends on the old flat
  reachability.
- **[Trade-off] settingsSection is an interim home, not the ADR-079-ideal
  one** → accepted per Decision 3's note; tracked as follow-up.

## Open Questions

See DEFERRED_QUESTIONS in this run's output for the three items requiring
product/architecture confirmation (Finding A's premise, Risk 1's fast-follow,
and Decision 3's interim-vs-permanent settingsSection framing).

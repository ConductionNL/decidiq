---
kind: config
depends_on: []
---

# Proposal: ia-six-clusters

## Summary

Collapse Decidesk's top-level navigation from 44 flat entries back to
ADR-004's six-item ceiling, entirely through `src/menu-layout.json`'s
declared relocation/removal/settings-lift mechanism (plus the minimal
`src/manifest.json` edits the mechanism itself requires: renaming two
existing group labels and adding one new group anchor, `Registers`, since
`menu-layout.json` can only relocate entries into a group id that already
exists in the merged menu). No PHP, Vue, or TypeScript runtime code changes.

## Motivation

ADR-004 fixed a six-item top-level navigation ceiling in May 2026
(`ia-six-item-nav`). Since then, 22 independent `src/manifest.d/*.json`
fragments — each individually reasonable, none coordinated — added their own
top-level `menu` entry. `src/menu-layout.json`, the declared mechanism for
re-homing fragment entries (`relocations` / `removals` / `settingsSection`,
consumed by `@conduction/nextcloud-vue`'s `buildManifest`/`applyMenuLayout`),
has sat at `relocations: {}`, `removals: []`, `settingsSection: []` the whole
time — nothing ever populated it. The result today is 44 top-level nav rows:
5 base entries (Dashboard, Meetings, Decisions, ActionItems, GovernanceBodies)
+ 2 footer entries + 35 fragment-injected entries + 2 more base fragment
adjustments, directly violating ADR-004's "New specs MUST fit into one of the
existing top-level items… Adding a 7th top-level requires an ADR amendment."

The sibling change `nav-ceiling-gate` (already drafted, `depends_on:
[ia-six-clusters]`) adds a CI gate that keeps this from regressing — but it
has nothing to enforce until the nav is actually collapsed. This change does
the collapse; `nav-ceiling-gate` keeps it collapsed.

Now is the moment because: (1) the placement map has been decided by the
product owner (six clusters: Dashboard, Meetings, Decisions, Tasks &
Commitments, Organisation, Registers — see design.md), removing the only
open design question; (2) `menu-layout.json`'s mechanism already exists and
is exercised nowhere else in the fleet's decidesk instance, so this is the
first real proof it works at fragment scale; (3) every week the nav stays at
44 entries is another week a 23rd fragment can ship without anyone noticing
the ceiling is already broken.

## Affected Projects

- [ ] Project: `decidesk` — `src/menu-layout.json` (relocations, removals,
  settingsSection populated), `src/manifest.json` (two label renames, one new
  `Registers` group-anchor entry, `nav.includePersonalSettings: false`),
  `openspec/architecture/adr-004-information-architecture.md` (v2 nav table
  amendment).

No other project changes — this is a decidesk-local navigation-layout change
with no API, schema, or cross-app surface.

## Capabilities

### Modified Capabilities

- `app-navigation`: the top-level menu collapses from 44 to 6 primary
  entries (Dashboard, Meetings, Decisions, ActionItems ["Tasks &
  Commitments"], GovernanceBodies ["Organisation"], Registers [new]) via
  `menu-layout.json` relocations/removals/settingsSection; every relocated
  or removed page stays routable for deep links.

## Scope

### In Scope

- Populate `src/menu-layout.json#relocations` — re-home the 24 fragment
  leaf entries that belong under an existing base group id (Meetings,
  Decisions, ActionItems, GovernanceBodies) or the new `Registers` group,
  per the placement map in design.md.
- Populate `src/menu-layout.json#removals` — retire 4 duplicate/filter-chip
  nav rows (`UrgentDecisions`, `MyDeclarations`, `Zienswijzen`,
  `FeaturesRoadmapMenu`); their pages stay routable for deep links and e2e
  specs, per the mechanism's existing contract.
- Populate `src/menu-layout.json#settingsSection` — lift 8 operator/
  definition config entries into the Nextcloud settings gear foldout
  (`NcAppNavigationSettings`), matching ADR-079's model of "admin
  configuration lives behind the gear / `/settings/admin/<app>`, not in the
  primary nav."
- Add a new top-level `Registers` group-anchor entry to `src/manifest.json`
  `menu[]` (no page/route of its own — a pure expandable group, the pattern
  `CnAppNav` already supports for route-less parents with children) — the
  one entry `menu-layout.json` cannot create by itself.
- Rename two existing `src/manifest.json` menu labels: `ActionItems` →
  "Tasks & Commitments", `GovernanceBodies` → "Organisation" (ids and
  routes unchanged, so this is a label-only, zero-breakage edit).
- Set `nav.includePersonalSettings: false` in `src/manifest.json` to dedupe
  the gear's two "Personal settings" entries — `@conduction/nextcloud-vue`'s
  auto-prepended modal entry (no `to`/`href`, opens `NcAppSettingsDialog`
  via a click handler) versus decidesk's own `UserSettingsMenu` fragment
  entry (real `/user-settings` route, an actual built page with four
  preference sections). Keep the real one, suppress the generic one.
- Amend `openspec/architecture/adr-004-information-architecture.md` with a
  v2 navigation table naming the six current items and cross-referencing
  ADR-079 for where "Beheer" (operator-only config) now lives.
- Investigate the reported "Dashboard redirects to /meetings" landing-page
  regression (`applyDefaultViewPreference` in `src/main.js`); see design.md
  Finding — no code defect was found, so no redirect fix ships in this
  change. A verification task confirms the landing behaviour post-collapse
  instead.
- Spec maintenance: update `openspec/specs/app-navigation/spec.md` (add this
  change to its changelog, MODIFY the stale six-item requirement, ADD
  requirements for the relocation/removal/settings-lift mechanism).

### Out of Scope

- Any PHP, Vue, or TypeScript code change. This is `kind: config` —
  declarative JSON + docs only. `nav-ceiling-gate` (a `kind: code` sibling,
  `depends_on: [ia-six-clusters]`) adds the mechanical CI enforcement in its
  own change.
- Fixing `tests/e2e/spec-coverage/features-roadmap-page.spec.ts`, which
  drives the app by clicking `cn-nav-entry-FeaturesRoadmapMenu` — an entry
  this change removes from the nav. Editing a `.spec.ts` file is a code
  change; see Risks and DEFERRED_QUESTIONS. Tracked as a fast-follow.
- Per-item migration of the 8 `settingsSection` entries onto individual
  `lib/Settings/*Admin.php` Nextcloud admin-settings sub-pages (ADR-079
  Decision 1's stated preference for non-per-user config). This change only
  moves them out of the primary nav and into the gear; full migration is
  future work — see DEFERRED_QUESTIONS.
- Any change to `organisatie-modus` mode-label adaptation (ADR-004 Rule 1,
  ADR-006) — the six-cluster ids and grouping are mode-independent; only
  the already-existing label-map wiring is unaffected here.
- Any OpenRegister schema change. No register, no schema, no field is
  touched by this change.

## Approach

`@conduction/nextcloud-vue`'s `buildManifest()` (in
`node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js`, called
from `src/main.js`) already implements the exact pipeline needed:
merge base `menu[]` + all `manifest.d/*.json` fragment `menu[]` arrays by id,
then `applyMenuLayout()` runs three passes in order —
`applyMenuRelocations` (re-home a leaf under a target group id, dissolving
empty group shells), `applyMenuRemovals` (drop a leaf, page stays routable),
`applySettingsSection` (lift a leaf into the settings gear, tagged
`section: "settings"`). All three read purely from
`src/menu-layout.json`. This change is data-only: write the relocation map,
the removal list, and the settings-lift list, plus the three small
`manifest.json` edits `applyMenuRelocations` requires to have a `Registers`
group id to relocate into in the first place. Full target JSON content is
specified in design.md so implementation is copy-exact, not judgment-based.

## New Dependencies

None.

## Impact

- `src/menu-layout.json` — populated (currently empty scaffold).
- `src/manifest.json` — `menu[]` gains one entry (`Registers`), two entries
  get a label-only edit, top-level `nav.includePersonalSettings` added.
- `openspec/architecture/adr-004-information-architecture.md` — v2 nav
  table amendment (appended, v1 history preserved).
- `openspec/specs/app-navigation/spec.md` — changelog entry, one MODIFIED
  requirement, several ADDED requirements.
- No `manifest.d/*.json` fragment file is edited — every fragment keeps
  declaring its own top-level entry exactly as today; `menu-layout.json` is
  the only thing that changes where that entry ends up rendering. This is
  precisely `menu-layout.json`'s designed purpose per its own `_meta`
  description.
- Runtime behaviour: the merged, laid-out menu changes shape (44 → 6 primary
  + 1 footer + 8 settings entries); every relocated/removed page's *route*
  is unchanged, so deep links, bookmarks, and (with one documented
  exception — see Risks) e2e specs keep resolving.

## Cross-Project Dependencies

None. `nav-ceiling-gate` depends on this change merging first (its gate
script reads the post-collapse manifest as its positive-control fixture),
but that dependency runs the other direction — this change does not depend
on anything.

## Risks

### Risk 1: `tests/e2e/spec-coverage/features-roadmap-page.spec.ts` breaks

**Severity:** Medium — **Mitigation:** This is the only e2e spec in the repo
that clicks a nav entry this change removes
(`cn-nav-entry-FeaturesRoadmapMenu`); confirmed by grepping every other
retired/relocated id against `tests/` — no other spec references them by
nav testid (the base-group-anchor ids that other specs *do* click —
`Meetings`, `Decisions`, `ActionItems`, `GovernanceBodies` — keep their id
and stay top-level, so those specs are unaffected). The `FeaturesRoadmap`
*page* stays routable; only its nav row goes away. Fixing the spec (swap the
nav click for a direct `page.goto` per the file's own documented fallback
pattern) is a one-file TypeScript edit, out of scope for this `kind: config`
change. Tracked as a fast-follow — see DEFERRED_QUESTIONS.

### Risk 2: Relocated leaf entries render inside a collapsed group by default

**Severity:** Low — **Mitigation:** `CnAppNav` groups with children render
collapsed unless the active route is inside them or the user has previously
expanded them (`isItemOpen()` in `CnAppNav.vue`). A user browsing to, say,
"Oral questions" now needs one extra click (expand Meetings) instead of
finding it at the top level — an intended consequence of the collapse, not a
defect. No e2e spec drives navigation this way today (confirmed via the same
grep as Risk 1), so nothing breaks; this is a UX note for the product owner,
not a blocking risk.

### Risk 3: `settingsSection` entries are not "genuinely per-user" per ADR-079

**Severity:** Low — **Mitigation:** ADR-079 Decision 2 frames
`section: "settings"` entries as being for "genuinely per-user
configuration"; the 8 entries this change lifts there (Termijnregelingen,
VveDecisionTemplates, ModelreglementPresets, VveConfigurations, WooDiwoo,
GeheimhoudingGronden, ModerationQueue) are operator/definition config, not
per-user preferences. The placement is still strictly better than today's
44-entry flat nav (ADR-004's hard ceiling) and is the mechanism's only
existing lift point short of building 7 new `lib/Settings/*Admin.php`
sub-pages, which is out of this change's `kind: config` scope. Documented as
an accepted interim step — see design.md and DEFERRED_QUESTIONS.

## Rollback Strategy

Revert the `src/menu-layout.json` and `src/manifest.json` changes (a single
commit); `buildManifest()` reads both files at build time with no persisted
state, so the merged nav reverts to its current 44-entry shape on the next
build with no data migration, cache-bust, or backend involvement. The ADR-004
and app-navigation spec doc edits can be reverted independently and
harmlessly (they carry no runtime behaviour).

## Open Questions

See DEFERRED_QUESTIONS in this run's output — three provisional decisions
(the ADR-079 interim-placement framing, the "Dashboard redirects to
/meetings" premise not being reproduced in code, and the e2e fast-follow)
are recorded there for product/architecture confirmation.

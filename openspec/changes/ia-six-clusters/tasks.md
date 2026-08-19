# Tasks: ia-six-clusters

All tasks are declarative JSON/Markdown edits (`kind: config`) — no PHP,
Vue, or TypeScript file is touched. Full target content for every JSON edit
is specified verbatim in design.md; implement by copying it exactly.

## Implementation Tasks

### Task 1: Populate menu-layout.json (relocations, removals, settingsSection)
- **spec_ref**: `openspec/changes/ia-six-clusters/specs/app-navigation/spec.md#requirement-req-nav-009-fragment-leaf-entries-relocate-into-a-canonical-group-via-menu-layoutjson-relocations`, `#requirement-req-nav-010-duplicate-or-filter-chip-nav-rows-are-removed-but-stay-routable`, `#requirement-req-nav-011-operatordefinition-config-entries-lift-into-the-settings-gear-foldout`
- **files**: `src/menu-layout.json`
- **acceptance_criteria**:
  - GIVEN the target `relocations` map from design.md Decision 3 WHEN the merged menu is built THEN every listed source id present in the merged menu renders as a child of its target group and not as a primary top-level entry (the forward-declared `Goals` id is a no-op until the `organisation-goals` fragment lands)
  - GIVEN the target `removals` list WHEN the merged menu is built THEN `UrgentDecisions`, `MyDeclarations`, `Zienswijzen`, `FeaturesRoadmapMenu` render nowhere in the menu, and their pages remain routable by direct URL
  - GIVEN the target `settingsSection` list WHEN the merged menu is built THEN all 8 listed ids render inside the `NcAppNavigationSettings` gear foldout, not the primary nav
  - `_meta.description` and `_settingsSectionNote` are updated per design.md Decision 3 (explaining the populated state and the ADR-079 interim-placement rationale)
- [x] Implement
- [x] Test

### Task 2: Add the Registers group anchor and rename two labels in manifest.json
- **spec_ref**: `openspec/changes/ia-six-clusters/specs/app-navigation/spec.md#requirement-req-nav-002-mainmenu-lists-six-canonical-top-level-groups-populated-via-menu-layoutjson`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `src/manifest.json` `menu[]` WHEN inspected THEN it contains a new entry `{ id: "Registers", label: "Registers", icon: "LibraryOutline", order: 70 }` with no `route`/`href`/`action`
  - GIVEN the `ActionItems` entry WHEN inspected THEN its `label` is `"Tasks & Commitments"` and its `id`/`route`/`icon`/`order` are unchanged
  - GIVEN the `GovernanceBodies` entry WHEN inspected THEN its `label` is `"Organisation"` and its `id`/`route`/`icon`/`order` are unchanged
  - GIVEN the app renders WHEN the Registers item is clicked THEN its children expand/collapse and no navigation occurs
- [x] Implement
- [x] Test

### Task 3: Dedupe the gear's Personal settings entries
- **spec_ref**: `openspec/changes/ia-six-clusters/specs/app-navigation/spec.md#requirement-req-nav-012-the-settings-gear-carries-exactly-one-personal-settings-entry`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `src/manifest.json` WHEN inspected THEN a top-level `nav: { includePersonalSettings: false }` is present
  - GIVEN the settings gear is opened WHEN inspected THEN exactly one "Personal settings" entry is present and it navigates to `/user-settings`
- [x] Implement
- [x] Test

### Task 4: Verify exactly six primary top-level entries render, with all six groups reachable
- **spec_ref**: `openspec/changes/ia-six-clusters/specs/app-navigation/spec.md#requirement-req-nav-002-mainmenu-lists-six-canonical-top-level-groups-populated-via-menu-layoutjson`
- **files**: none (browser verification of Tasks 1–3's combined output)
- **acceptance_criteria**:
  - GIVEN the app in the ready state WHEN the primary nav is inspected THEN exactly 6 primary entries render: Dashboard, Meetings, Decisions, Tasks & Commitments, Organisation, Registers
  - GIVEN each of the five non-Dashboard primary entries WHEN expanded THEN its children match the placement map in design.md Decision 1 exactly (no extra, no missing)
  - GIVEN the app loads with no stored `default-view` preference WHEN the root route `/` is visited THEN the Dashboard page renders (not a redirect to `/meetings`) — closes design.md Finding A
- [x] Implement
- [x] Test

### Task 5: Amend ADR-004 with the v2 navigation table
- **spec_ref**: `openspec/changes/ia-six-clusters/design.md#decision-5-adr-004-v2-amendment-appended-v1-preserved`
- **files**: `openspec/architecture/adr-004-information-architecture.md`
- **acceptance_criteria**:
  - GIVEN the ADR WHEN read after this change THEN the original v1 "Top-level navigation (6, fixed)" list (lines 35–50) is unchanged/preserved
  - GIVEN the ADR WHEN read after this change THEN a new "Top-level navigation v2" subsection (exact text in design.md Decision 5) is appended, naming Dashboard/Meetings/Decisions/Tasks & Commitments/Organisation/Registers and cross-referencing ADR-079 for where Beheer now lives
- [x] Implement
- [x] Test

### Task 6: Sync the app-navigation capability spec
- **spec_ref**: `openspec/changes/ia-six-clusters/specs/app-navigation/spec.md`
- **files**: `openspec/specs/app-navigation/spec.md`
- **acceptance_criteria**:
  - GIVEN the spec's `**OpenSpec changes**` list WHEN read after this change THEN a new line `- ia-six-clusters (active) — collapses the nav to ADR-004's six-item ceiling via menu-layout.json` is appended after the existing entries (existing entries untouched except the stale `ia-six-item-nav (active)` tag corrected to `(archived)`, since that change is already archived — a pre-existing accuracy issue fixed in passing per project convention)
  - GIVEN the spec's `**Status**` (both frontmatter and body) WHEN read after this change THEN both read `in-progress`
  - GIVEN REQ-NAV-002 WHEN read after this change THEN its full text matches the MODIFIED block in this change's `specs/app-navigation/spec.md` delta
  - GIVEN the spec WHEN read after this change THEN it additionally contains REQ-NAV-009 through REQ-NAV-012 verbatim from this change's ADDED block
- [x] Implement (synced by the orchestrator in the judge pass: REQ-NAV-002 replaced with the MODIFIED text, REQ-NAV-009..012 appended; change-list line and status were already updated at artifact time. Note: nav-ceiling-gate's positive-control requirement was renumbered REQ-NAV-009→REQ-NAV-013 to clear a numbering collision with this change's REQ-NAV-009)
- [x] Test (grep-verified: main spec now carries 10 REQ-NAV requirements, REQ-NAV-002 header matches the delta, 009..012 present once each)

## Verification

- [x] All tasks checked off
- [x] `openspec validate` passes
- [ ] Manual/browser testing against every acceptance criterion above,
  including a check that no 7th primary top-level entry appears and that
  every relocated/removed/lifted page still resolves by direct URL
- [ ] Code review confirms only `src/manifest.json`, `src/menu-layout.json`,
  `openspec/architecture/adr-004-information-architecture.md`, and
  `openspec/specs/app-navigation/spec.md` changed — no `manifest.d/*.json`
  fragment, PHP, Vue, or TypeScript file touched

## Tests (company-wide ADR-009)

N/A — this is a `kind: config` navigation-layout change with no PHP or Vue
logic. No PHPUnit/Newman changes apply. Browser verification (Task 4 +
Verification) is the applicable test surface; a dedicated Playwright spec
asserting the six-item nav is left to a follow-up (see design.md Risk 1 —
`features-roadmap-page.spec.ts` also needs its own follow-up fix, tracked
separately as a `kind: code` change since editing `.spec.ts` is out of this
change's scope).

## Documentation (company-wide ADR-010)

N/A — no user-facing feature documentation changes; ADR-004 amendment
(Task 5) is the relevant architecture documentation and is covered above.

## i18n (company-wide ADR-005)

Two label strings change (`"Action items"` → `"Tasks & Commitments"`,
`"Bodies"` → `"Organisation"`) and one is added (`"Registers"`). These are
`t('decidesk', …)`-wrapped at render time by the existing menu-label
resolution path (unchanged code); translators need the new/changed English
source strings picked up in the next `nl_NL` string sync — no `.po`/`.json`
translation file edit is part of this change.

# Tasks: organisation-facet-composition

## Implementation Tasks

### Task 1: GovernanceBody schema delta — faction bodyType + parentBody
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-bodies/spec.md#requirement-req-gbd-013-faction-is-a-governancebody-discriminator-not-a-parallel-schema`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the `GovernanceBody` schema WHEN its `bodyType` enum is inspected THEN it includes `faction` alongside the existing ten values
  - GIVEN the `GovernanceBody` schema WHEN its properties are inspected THEN `parentBody` exists (`type: string`, `format: uuid`, `$ref: GovernanceBody`, `nullable: true`)
  - GIVEN the register WHEN `GovernanceBody.version` and the top-level `info.version` are inspected THEN both are bumped (see migration.md — `GovernanceBody` to `0.3.0`, register `info.version` to `0.9.0`; the version bump is required for the repair step to actually re-import, not optional)
- [x] Implement
- [x] Test

### Task 2: Seed data — faction demo objects
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-bodies/spec.md#requirement-req-gbd-013-faction-is-a-governancebody-discriminator-not-a-parallel-schema`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register is imported on a clean instance WHEN `governance-body` objects are listed THEN `groenlinks-fractie-amsterdam` and `d66-fractie-amsterdam` exist with `bodyType=faction` and `parentBody=gemeenteraad-amsterdam` (see design.md Seed Data table for full field values)
  - GIVEN the register is imported WHEN `membership` objects are listed THEN `m-marie-groenlinks-fractie` exists (`person=marie-janssen`, `governanceBody=groenlinks-fractie-amsterdam`) as Marie Janssen's second membership
- [x] Implement
- [x] Test
- **Deviations (recorded during apply):**
  - `domain` on both new faction seed objects is `municipality`, not the design.md table's literal `municipal` — the actual seed convention across all 8 existing `governance-body` seed rows (checked: `gemeenteraad-amsterdam`, `directieteam-gemeente-utrecht`, etc.) uses `municipality`; matched the real convention for consistency with the parent body rather than the design doc's shorthand.
  - Marie Janssen already carries **two** prior memberships (`m-marie-amsterdam`, `m-marie-vng`), not one — design.md's "her second membership" undercounts by one; `m-marie-groenlinks-fractie` is actually her third. Functionally unaffected (still demonstrates multi-membership); noted for design.md accuracy only.

### Task 3: Retirement schedule + Term rules widgets on GovernanceBodyDetail
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-body-crud/spec.md#requirement-view-governance-body-detail`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a GovernanceBody with a `rooster-van-aftreden` object (`body` = the body's id) WHEN its detail page loads THEN a "Retirement schedule" `object-list` widget lists it and links to `RoosterDetail`
  - GIVEN a GovernanceBody with no `rooster-van-aftreden` object WHEN its detail page loads THEN the widget shows its empty state, no error
  - GIVEN a GovernanceBody with one or more `termijn-regeling` objects (`body` = the body's id) WHEN its detail page loads THEN a "Term rules" `object-list` widget lists them read-only (no inline create/edit action) and links to `TermijnRegelingDetail`
  - Follow design.md Decision 2 (declarative `object-list`, no new Vue component) and the `body-meetings` widget as the pattern reference
- [x] Implement
- [x] Test

### Task 4: Integrity widgets — Other positions + Gifts
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-body-crud/spec.md#requirement-view-governance-body-detail`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a GovernanceBody with `nevenfunctie` objects (`governanceBody` = the body's id) WHEN its detail page loads THEN an "Other positions" `object-list` widget lists them and links to `NevenfunctieDetail`
  - GIVEN a GovernanceBody with `geschenk` objects (`governanceBody` = the body's id) WHEN its detail page loads THEN a "Gifts" `object-list` widget lists them and links to `GeschenkDetail`
- [x] Implement
- [x] Test
- **Deviation found and fixed at the point of authorship (not a deviation from this task's own scope, flagged for visibility):** the sibling `Nevenfuncties` index page (`src/manifest.d/interests-and-integrity.json`, out of this change's edit scope) uses a column key `bezoldigd` for the "Remunerated" badge, but the `Nevenfunctie` schema's actual property is `remunerated` (`lib/Settings/register.d/62-interests-and-integrity.json`) — that column silently resolves nothing on the existing index page. The new `body-nevenfuncties` widget here uses the correct `remunerated` key. The index-page bug is pre-existing and outside this change's allowed file set; not fixed here.

### Task 5: Shared-body participation widgets — both directions + zienswijze rounds
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-body-crud/spec.md#requirement-view-governance-body-detail`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a `bodyType=shared-body` GovernanceBody with `body-participation` objects (`sharedBody` = its id) WHEN its detail page loads THEN a "Participating organisations" widget lists them, each row a `widget: "link"` column to the participant's own `GovernanceBodyDetail` (design.md Decision 4 — verify the object-list widget renderer honours a column-level `widget: "link"`; fall back to plain text for this widget only if it does not)
  - GIVEN a GovernanceBody with `body-participation` objects where `participant` = its id WHEN its detail page loads THEN a "Shared-body participations" widget lists them the same way, linking to each `sharedBody`
  - GIVEN a `bodyType=shared-body` GovernanceBody with `zienswijzeronde` objects (`sharedBody` = its id) WHEN its detail page loads THEN a "Zienswijze rounds" widget lists them and links to `ZienswijzerondeDetail`
  - Neither `body-participation` widget sets `rowRoute` (no `BodyParticipation` detail page exists in the manifest)
- [x] Implement
- [x] Test
- **Verified during apply (resolves design.md's open "Verify during apply" item under Decision 4):** `widget: "link"` on an `object-list` widget column IS honoured — `CnObjectListWidget.vue`'s `resolvedColumns()` explicitly forwards `format`/`widget`/`widgetProps`/`formatter`/`align`/`width` to `CnDataTable` → `CnCellRenderer`, which has a dedicated `widget === 'link'` branch (`CnCellRenderer.vue` line 39) that resolves and links to the referenced object's own detail page — the same mechanism the `Roosters`/`Termijnregelingen`/`Nevenfuncties`/`Geschenken` index pages already rely on for their `body`/`person`/`recipient` link columns. No plain-text fallback was needed.

### Task 6: Factions widget
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-body-crud/spec.md#requirement-view-governance-body-detail`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN one or more `GovernanceBody` objects with `bodyType=faction` and `parentBody` = this body's id WHEN this body's detail page loads THEN a "Factions" `object-list` widget lists them (filter `{ "parentBody": "@objectId", "bodyType": "faction" }`, multi-key filter per the `urgent-decision-procedure` fragment's established pattern) and each row navigates to that faction's own `GovernanceBodyDetail`
  - Depends on Task 1 (`parentBody` must exist on the schema first)
- [x] Implement
- [x] Test

### Task 7: Layout placement for all 8 new widgets
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-body-crud/spec.md#requirement-view-governance-body-detail`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the existing `GovernanceBodyDetail` `layout` array (occupying `gridY` 0–29) WHEN the 8 new widgets are placed THEN each gets its own `layout` entry starting at `gridY` 29, none overlapping an existing or another new entry
  - GIVEN the full page WHEN loaded THEN no widget clips its content and no dead grid cell is left within a row (see the page's existing `_note` AUDIT FIX precedent for `body-data`'s header-row accounting — apply the same care to any 2-column widget added here)
- [x] Implement
- [x] Test
- **Layout as implemented:** 4 rows of 2 half-width (`gridWidth: 6`) widgets, `gridY` 29/33/37/41, `gridHeight: 4` each (matches `body-meetings`' existing `gridHeight: 4`) — row order: (retirement schedule, term rules) / (other positions, gifts) / (participating organisations, shared-body participations) / (zienswijze rounds, factions). No `gridX + gridWidth` exceeds 12; no `gridY` range overlaps; confirmed by `npm run check:manifest` (Ajv PASS) and manual layout-entry inspection.

### Task 8: Manual verification against test-plan.md
- **spec_ref**: `openspec/changes/organisation-facet-composition/test-plan.md`
- **files**: none (verification only)
- **acceptance_criteria**:
  - TC-1 through TC-13 from test-plan.md all pass on the shared dev instance
  - Existing `GovernanceBodyDetail` behaviour (TC-13) is unaffected — a regression here blocks merge regardless of how well the new facets work
- [ ] Implement — **NOT YET DONE.** This builder agent was explicitly instructed NOT to run `npm run build` or Playwright (the orchestrator runs both post-apply); TC-1–TC-14 require a rebuilt bundle + a live browser session and are therefore deferred to the post-apply verify step. Static checks (Ajv manifest schema, nav-ceiling, l10n, vitest, `openspec validate`) all ran clean — see the verification block below.
- [ ] Test — deferred, see above

**Live post-rebuild verification checklist (for the orchestrator / verify step, after `npm run build` and an `occ upgrade`/repair-step re-import):**

1. Confirm the repair step's log line reports the register re-imported at `info.version` `0.9.0` (not a same-version no-op) — migration.md Migration Steps #5.
2. `GET` the `GovernanceBody` schema (OpenRegister schema API or `occ` inspection): `bodyType` enum includes `faction`; `parentBody` property exists with `$ref: GovernanceBody`.
3. Confirm by slug: `groenlinks-fractie-amsterdam` and `d66-fractie-amsterdam` exist (`bodyType=faction`, `parentBody=<gemeenteraad-amsterdam id>`); `m-marie-groenlinks-fractie` membership exists (`person=marie-janssen`, `governanceBody=groenlinks-fractie-amsterdam`).
4. Open `/governance-bodies/{gemeenteraad-amsterdam-id}` (**TC-2, TC-3, TC-10**): `body-data` widget resolves nothing new (`parentBody` is null on the council itself); "Factions" widget lists GroenLinks-fractie and D66-fractie, each row navigating to its own `GovernanceBodyDetail`; Members tab still shows the council's existing members (regression check).
5. Open `/governance-bodies/{groenlinks-fractie-amsterdam-id}` (**TC-2, TC-3**): `body-data` widget's `parentBody` field resolves to "Gemeenteraad Amsterdam"; Members tab shows Marie Janssen.
6. Open `/governance-bodies/{auditcommissie-provincie-nh-id}` (**TC-4**): "Retirement schedule" widget shows the seeded rooster (this body already has a generated `rooster-van-aftreden` per the `appointments-and-terms` seed data); clicking navigates to `RoosterDetail`.
7. Open `/governance-bodies/{directieteam-gemeente-utrecht-id}` (**TC-5**): "Retirement schedule" widget shows its empty state, zero console/network errors.
8. Open `/governance-bodies/{raad-van-commissarissen-acme-bv-id}` (**TC-6**): "Term rules" widget lists the seeded `termijn-regeling` row read-only (no inline create/edit affordance); clicking navigates to the editable `TermijnRegelingDetail`.
9. Open `/governance-bodies/{gemeenteraad-amsterdam-id}` again (**TC-7**): "Other positions" and "Gifts" widgets list the body's seeded `nevenfunctie`/`geschenk` declarations; each row navigates to its own detail page. Specifically confirm the "Remunerated" badge renders (this is the `bezoldigd`→`remunerated` field-name check called out under Task 4).
10. Open `/governance-bodies/{bestuur-noz-organisatie-id}` (**TC-8, TC-9**): "Participating organisations" widget lists its `body-participation` rows as clickable links (`widget: "link"` on `participant`) to each participant's own `GovernanceBodyDetail`; "Zienswijze rounds" widget lists its seeded `zienswijzeronde` objects, linking to `ZienswijzerondeDetail`.
11. Open one of the participant bodies (e.g. `gemeenteraad-noorderbrug`, per test-plan.md TC-8) (**TC-8**): "Shared-body participations" widget lists `bestuur-noz-organisatie` as a clickable link.
12. Open `/governance-bodies/{ledenraad-vng-id}` (**TC-11**): all 8 new widgets render their empty states; zero console errors, zero failed network requests (this body has no rooster/termijnregeling/nevenfunctie/geschenk/body-participation/faction data).
13. Run the WCAG 2.2 AA audit against `gemeenteraad-amsterdam`'s fully-populated detail page (**TC-12**): no new violations from the 8 added widgets (headings, landmarks, link text, focus order).
14. Open any pre-existing `GovernanceBody` and confirm `body-data`, `body-members`, `body-meetings`, `body-files`, `body-template`, `body-efficiency`, `body-retention`, `body-evaluations` all still render exactly as before, sidebar still shows only the History tab (**TC-13 regression**).

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — N/A, this change is declarative JSON only (schema register + manifest), no PHP business logic is added or changed
- New/changed API endpoints covered by Newman/Postman tests — N/A, no new API endpoints
- UI changes covered by Playwright browser tests — TC-4 through TC-13 in test-plan.md are the Playwright-driven functional/regression checks; **not yet run** — this builder agent was instructed not to run Playwright (orchestrator's post-apply verify step), see Task 8's live-verification checklist above
- All tests pass (`composer test`, `newman run`) — `npx vitest run`: 346/346 passed, no regressions (ran; `composer test`/`newman` were not run by this agent — PHP/API surface is unchanged by this `kind: config` change, no controller/route/service touched)
- Feature documentation updated in `docs/` if user-facing (ADR-010) — **NOT DONE by this agent.** This builder's file-edit authorization was scoped to `src/manifest.json` (GovernanceBodyDetail only), the `GovernanceBody` schema delta, l10n missing keys, and this tasks.md — `docs/` was out of that scope. Flagged for a follow-up doc pass.
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007) — **investigated and found N/A**, not merely skipped: traced the render path for a `type:"detail"` page's `object-list` widget title/columns/emptyText end-to-end (`CnDetailPage.vue` → `widgetDisplayTitle()` renders `def.title` raw with no `t()` wrapper at line ~2843–2850; `CnObjectListWidget.vue`'s template interpolates `emptyText`/labels directly, also no `t()` call) — manifest-declared widget strings on detail pages are NOT part of this app's i18n pipeline today (nav `menu[].label` IS translated, via `effectiveTranslate()` in `CnAppNav.vue`, but that's a different code path this change doesn't touch). Confirmed empirically: `node tests/l10n/check-l10n.js` reports 0 missing keys after this change (1526 keys, same as before — it only scans `.vue`/`.js`/`.ts` `t()` call sites, and none were added). The `faction` `bodyType` enum value follows the same precedent as all 10 existing enum values (none have an l10n entry either — badges/plain columns render the raw enum string, unverified as translated anywhere in this schema). No l10n files were edited; none were needed.
- `openspec validate` passes — `openspec validate organisation-facet-composition --strict` → "Change 'organisation-facet-composition' is valid"

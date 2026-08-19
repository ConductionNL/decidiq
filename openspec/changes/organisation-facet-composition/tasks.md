# Tasks: organisation-facet-composition

## Implementation Tasks

### Task 1: GovernanceBody schema delta — faction bodyType + parentBody
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-bodies/spec.md#requirement-req-gbd-013-faction-is-a-governancebody-discriminator-not-a-parallel-schema`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the `GovernanceBody` schema WHEN its `bodyType` enum is inspected THEN it includes `faction` alongside the existing ten values
  - GIVEN the `GovernanceBody` schema WHEN its properties are inspected THEN `parentBody` exists (`type: string`, `format: uuid`, `$ref: GovernanceBody`, `nullable: true`)
  - GIVEN the register WHEN `GovernanceBody.version` and the top-level `info.version` are inspected THEN both are bumped (see migration.md — `GovernanceBody` to `0.3.0`, register `info.version` to `0.9.0`; the version bump is required for the repair step to actually re-import, not optional)
- [ ] Implement
- [ ] Test

### Task 2: Seed data — faction demo objects
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-bodies/spec.md#requirement-req-gbd-013-faction-is-a-governancebody-discriminator-not-a-parallel-schema`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the register is imported on a clean instance WHEN `governance-body` objects are listed THEN `groenlinks-fractie-amsterdam` and `d66-fractie-amsterdam` exist with `bodyType=faction` and `parentBody=gemeenteraad-amsterdam` (see design.md Seed Data table for full field values)
  - GIVEN the register is imported WHEN `membership` objects are listed THEN `m-marie-groenlinks-fractie` exists (`person=marie-janssen`, `governanceBody=groenlinks-fractie-amsterdam`) as Marie Janssen's second membership
- [ ] Implement
- [ ] Test

### Task 3: Retirement schedule + Term rules widgets on GovernanceBodyDetail
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-body-crud/spec.md#requirement-view-governance-body-detail`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a GovernanceBody with a `rooster-van-aftreden` object (`body` = the body's id) WHEN its detail page loads THEN a "Retirement schedule" `object-list` widget lists it and links to `RoosterDetail`
  - GIVEN a GovernanceBody with no `rooster-van-aftreden` object WHEN its detail page loads THEN the widget shows its empty state, no error
  - GIVEN a GovernanceBody with one or more `termijn-regeling` objects (`body` = the body's id) WHEN its detail page loads THEN a "Term rules" `object-list` widget lists them read-only (no inline create/edit action) and links to `TermijnRegelingDetail`
  - Follow design.md Decision 2 (declarative `object-list`, no new Vue component) and the `body-meetings` widget as the pattern reference
- [ ] Implement
- [ ] Test

### Task 4: Integrity widgets — Other positions + Gifts
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-body-crud/spec.md#requirement-view-governance-body-detail`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a GovernanceBody with `nevenfunctie` objects (`governanceBody` = the body's id) WHEN its detail page loads THEN an "Other positions" `object-list` widget lists them and links to `NevenfunctieDetail`
  - GIVEN a GovernanceBody with `geschenk` objects (`governanceBody` = the body's id) WHEN its detail page loads THEN a "Gifts" `object-list` widget lists them and links to `GeschenkDetail`
- [ ] Implement
- [ ] Test

### Task 5: Shared-body participation widgets — both directions + zienswijze rounds
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-body-crud/spec.md#requirement-view-governance-body-detail`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a `bodyType=shared-body` GovernanceBody with `body-participation` objects (`sharedBody` = its id) WHEN its detail page loads THEN a "Participating organisations" widget lists them, each row a `widget: "link"` column to the participant's own `GovernanceBodyDetail` (design.md Decision 4 — verify the object-list widget renderer honours a column-level `widget: "link"`; fall back to plain text for this widget only if it does not)
  - GIVEN a GovernanceBody with `body-participation` objects where `participant` = its id WHEN its detail page loads THEN a "Shared-body participations" widget lists them the same way, linking to each `sharedBody`
  - GIVEN a `bodyType=shared-body` GovernanceBody with `zienswijzeronde` objects (`sharedBody` = its id) WHEN its detail page loads THEN a "Zienswijze rounds" widget lists them and links to `ZienswijzerondeDetail`
  - Neither `body-participation` widget sets `rowRoute` (no `BodyParticipation` detail page exists in the manifest)
- [ ] Implement
- [ ] Test

### Task 6: Factions widget
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-body-crud/spec.md#requirement-view-governance-body-detail`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN one or more `GovernanceBody` objects with `bodyType=faction` and `parentBody` = this body's id WHEN this body's detail page loads THEN a "Factions" `object-list` widget lists them (filter `{ "parentBody": "@objectId", "bodyType": "faction" }`, multi-key filter per the `urgent-decision-procedure` fragment's established pattern) and each row navigates to that faction's own `GovernanceBodyDetail`
  - Depends on Task 1 (`parentBody` must exist on the schema first)
- [ ] Implement
- [ ] Test

### Task 7: Layout placement for all 8 new widgets
- **spec_ref**: `openspec/changes/organisation-facet-composition/specs/governance-body-crud/spec.md#requirement-view-governance-body-detail`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the existing `GovernanceBodyDetail` `layout` array (occupying `gridY` 0–29) WHEN the 8 new widgets are placed THEN each gets its own `layout` entry starting at `gridY` 29, none overlapping an existing or another new entry
  - GIVEN the full page WHEN loaded THEN no widget clips its content and no dead grid cell is left within a row (see the page's existing `_note` AUDIT FIX precedent for `body-data`'s header-row accounting — apply the same care to any 2-column widget added here)
- [ ] Implement
- [ ] Test

### Task 8: Manual verification against test-plan.md
- **spec_ref**: `openspec/changes/organisation-facet-composition/test-plan.md`
- **files**: none (verification only)
- **acceptance_criteria**:
  - TC-1 through TC-13 from test-plan.md all pass on the shared dev instance
  - Existing `GovernanceBodyDetail` behaviour (TC-13) is unaffected — a regression here blocks merge regardless of how well the new facets work
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — N/A, this change is declarative JSON only (schema register + manifest), no PHP business logic is added or changed
- New/changed API endpoints covered by Newman/Postman tests — N/A, no new API endpoints
- UI changes covered by Playwright browser tests — yes, TC-4 through TC-13 in test-plan.md are the Playwright-driven functional/regression checks
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010) — the Organisation hub composition is user-facing; add a short note to `docs/` describing the new facets on the body detail page
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007) — widget titles ("Retirement schedule", "Term rules", "Other positions", "Gifts", "Participating organisations", "Shared-body participations", "Zienswijze rounds", "Factions") and the `faction` bodyType's Dutch label ("Fractie")
- `openspec validate` passes

# Tasks: bodies-director-remuneration

## Implementation tasks

### Task 1: The remuneration record and who reads it
- **spec_ref**: `openspec/changes/bodies-director-remuneration/specs/governance-bodies/spec.md#requirement-req-drm-001-a-position-holders-remuneration-is-recorded-per-year`
- **files**: `lib/Settings/register.d/117-mandate-remuneration.json`, `lib/Settings/profiles/corporate.json`, `lib/Settings/profiles/association.json`, `tests/newman/mandate-remuneration.json` (owed with the live Test half), `tests/Unit/Settings/MandateRemunerationRegisterTest.php` (schema, read rule, seeds, widgets; red first)
- **acceptance_criteria**:
  - GIVEN the register WHEN imported THEN `mandate-remuneration` exists with its read rule
  - GIVEN a record not disclosed WHEN an ordinary member lists remuneration THEN it is not returned
  - GIVEN a record disclosed with a past publication date WHEN an anonymous caller reads it THEN it is returned
  - GIVEN a secretariat member WHEN she lists remuneration THEN every record of her installation returns
- [x] Implement
- [ ] Test (Newman with three users: secretariat, member, anonymous)

### Task 2: Remuneration on the body page
- **spec_ref**: `openspec/changes/bodies-director-remuneration/specs/governance-bodies/spec.md#requirement-req-drm-002-the-body-page-shows-this-years-remuneration-and-its-total`
- **files**: `src/manifest.json` (`GovernanceBodyDetail` widgets and layout)
- **acceptance_criteria**:
  - GIVEN the supervisory board of the corporate example WHEN the secretary opens it THEN this year's two records and a total of 56000 EUR show
  - GIVEN a member without the secretariat group WHEN he opens it THEN the widget is empty
  - GIVEN `tests/validate-manifest.js` WHEN run THEN it passes
- [x] Implement
- [ ] Test (Playwright as secretary and as member)

### Task 3: Remuneration on the position hold page
- **spec_ref**: `openspec/changes/bodies-director-remuneration/specs/governance-bodies/spec.md#requirement-req-drm-003-a-position-hold-lists-its-remuneration-over-the-years`
- **files**: `src/manifest.d/configurable-types.json` (`PositionHoldDetail`)
- **acceptance_criteria**:
  - GIVEN a hold with records for 2025 and 2026 WHEN the secretary opens the hold THEN both years are listed, newest first
- [x] Implement
- [ ] Test (Playwright)

## Verification

- `composer check:strict` and `npm run lint` once before push; the register tests pin the new fragment.

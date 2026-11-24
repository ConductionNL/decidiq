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
- [ ] Test (Newman with three users: secretariat, member, anonymous) (not run: needs the live instance; tests/newman/mandate-remuneration.json is still to write with it) (code exists, test missing: tests/newman/mandate-remuneration.json not written; live pass, decision 139)
- Written, not run (20 Nov): `tests/integration/decidiq-mandate-remuneration.postman_collection.json` (the repository keeps its collections in `tests/integration/`, which `run-newman.sh` globs; `tests/newman/` does not exist). A secretariat probe user records an undisclosed, a disclosed-and-published and a disclosed-but-future record; the secretariat lists all three, a member sees neither the undisclosed nor the future one and cannot record one, an anonymous caller reads only the disclosed, published one.

### Task 2: Remuneration on the body page
- **spec_ref**: `openspec/changes/bodies-director-remuneration/specs/governance-bodies/spec.md#requirement-req-drm-002-the-body-page-shows-this-years-remuneration-and-its-total`
- **files**: `src/manifest.json` (`GovernanceBodyDetail` widgets and layout)
- **acceptance_criteria**:
  - GIVEN the supervisory board of the corporate example WHEN the secretary opens it THEN this year's two records and a total of 56000 EUR show
  - GIVEN a member without the secretariat group WHEN he opens it THEN the widget is empty
  - GIVEN `tests/validate-manifest.js` WHEN run THEN it passes
- [x] Implement
- [ ] Test (Playwright as secretary and as member) (not run: needs the live instance) (code exists, test missing: no Playwright spec for remuneration; live pass, decision 139)
- Written, not run (21 Nov): `tests/e2e/mandate-remuneration.spec.ts` (the secretary records the chair's fee; a member does not read an undisclosed fee, through the API and on the body page; an anonymous visitor reads only disclosed, published pay; the body page shows this year's two records and a total of 56000; the hold page lists this year above last year). Left: running it on an instance.

### Task 3: Remuneration on the position hold page
- **spec_ref**: `openspec/changes/bodies-director-remuneration/specs/governance-bodies/spec.md#requirement-req-drm-003-a-position-hold-lists-its-remuneration-over-the-years`
- **files**: `src/manifest.d/configurable-types.json` (`PositionHoldDetail`)
- **acceptance_criteria**:
  - GIVEN a hold with records for 2025 and 2026 WHEN the secretary opens the hold THEN both years are listed, newest first
- [x] Implement
- [ ] Test (Playwright) (not run: needs the live instance) (code exists, test missing: no Playwright spec for remuneration; live pass, decision 139)
- Written, not run (21 Nov): `tests/e2e/mandate-remuneration.spec.ts` (the secretary records the chair's fee; a member does not read an undisclosed fee, through the API and on the body page; an anonymous visitor reads only disclosed, published pay; the body page shows this year's two records and a total of 56000; the hold page lists this year above last year). Left: running it on an instance.

## Verification

- `composer check:strict` and `npm run lint` once before push; the register tests pin the new fragment.

# Tasks: bodies-board-composition-skills-and-diversity

### Task 1: Declare competences, member competences and targets

- **spec_ref**: `openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-001-a-body-lists-the-competences-it-needs`
- **files**: `lib/Settings/register.d/NN-board-composition.json` (new), `lib/Settings/profiles/corporate.json` (seed), `tests/Unit/RegisterJsonTest.php`, `tests/Unit/RegisterAuthorizationTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN read THEN `board-competence`, `member-competence` and `governance-body.diversityTargets` exist as in design D1 and D4, every property with a `title`
  - GIVEN the authorization blocks WHEN validated THEN they use only create, read, update and delete, so the importer does not skip the schema
  - GIVEN the corporate example set WHEN imported THEN four competences, three member competences and the target exist on the seeded board
  - Verification: `RegisterJsonTest` and `RegisterAuthorizationTest` red first, then green
- [ ] Implement
- [ ] Test

### Task 2: Confirm a member competence

- **spec_ref**: `openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed`
- **files**: `lib/Controller/MemberCompetenceController.php` (new, `confirm()`), `appinfo/routes.php`, `lib/Service/CompetenceConfirmationGuard.php` (new), `tests/Unit/Service/CompetenceConfirmationGuardTest.php` (new)
- **acceptance_criteria**:
  - GIVEN a signatory of the membership's body WHEN they confirm THEN `confirmedBy` and `confirmedAt` are set
  - GIVEN the member themselves or a signatory of another body WHEN they confirm THEN 403 and nothing changes
  - GIVEN a confirmed competence WHEN its level changes THEN both confirmation fields are cleared
  - Verification: PHPUnit red-then-green; the guard is called from the controller, asserted by a controller test (no orphan guard)
- [ ] Implement
- [ ] Test

### Task 3: The skills matrix

- **spec_ref**: `openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-003-the-skills-matrix-shows-where-the-body-falls-short`
- **files**: `src/components/tabs/GovernanceBodyCompositionTab.vue` (new), `src/modals/MemberCompetenceModal.vue` (new), `src/utils/boardComposition.js` (new, pure functions), `src/registry.js`, `src/manifest.json` (`GovernanceBodyDetail` widget, layout cell, slot), `tests/vitest/boardComposition.spec.js` (new)
- **acceptance_criteria**:
  - GIVEN the seeded board WHEN the gap function runs THEN IT and cybersecurity and Water management are gaps, and they stop being gaps when a confirmed experienced holder is added
  - GIVEN the widget WHEN rendered THEN members are rows, competences columns, and unconfirmed levels are marked
  - Verification: vitest on the pure functions, red first; Playwright on the seeded board page
- [ ] Implement
- [ ] Test

### Task 4: Composition figures and targets

- **spec_ref**: `openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-004-the-body-sees-its-composition-figures-against-its-own-targets`
- **files**: `src/utils/boardComposition.js`, `src/components/tabs/GovernanceBodyCompositionTab.vue`, `tests/vitest/boardComposition.spec.js`, `tests/e2e/spec-coverage/governance-body.spec.ts`
- **acceptance_criteria**:
  - GIVEN three members, one female, two male, and a 0.33 female target WHEN figures run THEN 1 and 2 with shares 33% and 67%, target met
  - GIVEN a member without birth date WHEN age bands run THEN they count under not recorded only
  - GIVEN a birth date exactly 40 years ago today WHEN banded THEN the member is in 40 to 54
  - GIVEN the widget WHEN rendered THEN no list of names per value appears
  - Verification: vitest red-then-green, with a fixed clock; Playwright asserts the gender row on the seeded board
- [ ] Implement
- [ ] Test

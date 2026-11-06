# Tasks: bodies-onboarding-checklist-walkthrough

## Implementation tasks

### Task 1: Checklist service and route
- **spec_ref**: `openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist`
- **files**: `lib/Service/OnboardingChecklistService.php`, `appinfo/routes.php`, `lib/Controller/OnboardingController.php`
- **acceptance_criteria**:
  - step update and lifecycle
  - 403 for a plain member
- [ ] Implement
- [ ] Test (red first)

### Task 2: Membership side effects
- **spec_ref**: `openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-002-installation-and-exit-steps-change-the-membership`
- **files**: `lib/Service/OnboardingChecklistService.php`
- **acceptance_criteria**:
  - membership created and end-dated, validated against the membership schema
- [ ] Implement
- [ ] Test (red first)

### Task 3: Checklist widget on both detail pages
- **spec_ref**: `openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist`
- **files**: `src/components/tabs/OnboardingChecklistTab.vue`, `src/manifest.d/member-onboarding.json`
- **acceptance_criteria**:
  - vitest red first
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.

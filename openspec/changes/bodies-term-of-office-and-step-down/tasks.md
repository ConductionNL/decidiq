# Tasks: bodies-term-of-office-and-step-down

## Implementation tasks

### Task 1: Term fields on the Leden form
- **spec_ref**: `openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-001-a-members-term-is-entered-when-the-member-is-added`
- **files**: the Leden widget's create dialog on GovernanceBodyDetail (`src/manifest.json`, the dialog under `src/modals/`), `l10n/` ("Termijn begint", "Termijn eindigt")
- **acceptance_criteria**:
  - start date defaults to the body's term start; both dates are saved on Membership
- [ ] Implement
- [ ] Test (red first): vitest on the dialog

### Task 2: Rooster van aftreden widget
- **spec_ref**: `openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first`, `#requirement-req-tos-003-herbenoembaar-follows-the-positions-rules`
- **files**: `src/components/widgets/StepDownScheduleWidget.vue` (new), `src/components/widgets/registerDetailWidgets.js`, `src/manifest.json` (GovernanceBodyDetail: widget under Meer > Functies en integriteit, with `@custom-widget-ratchet exclude` and the reason "unions two schemas")
- **acceptance_criteria**:
  - union of memberships and position holds, sorted by end date, window filter, Herbenoembaar rules; no write
- [ ] Implement
- [ ] Test (red first): `tests/unit/components/StepDownScheduleWidget.spec.js`

### Task 3: Reminder before a term ends
- **spec_ref**: `openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-004-the-secretary-is-reminded-before-a-term-ends`
- **files**: `lib/Settings/register.d/123-term-of-office-reminders.json` (new: GovernanceBody `termReminderDays`; `x-openregister-notifications` on Membership and PositionHold), register version move and lock-file test (decision 70)
- **acceptance_criteria**:
  - the fragment loads through the real register loader; the rule names existing properties
- [ ] Implement
- [ ] Test (red first): register fragment test

### Task 4: Oud-leden from the end date
- **spec_ref**: `openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-005-ended-memberships-show-as-former-members-without-a-write`
- **files**: `src/manifest.json` (Leden and Oud-leden filters on `endDate`)
- **acceptance_criteria**:
  - a passed end date moves the member to Oud-leden without a write
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- Playwright `tests/e2e/step-down-schedule.spec.ts` tagged with each scenario.

# Tasks: bodies-membership-terms-contacts-and-factions

## Implementation tasks

### Task 1: Start date and past members
- **spec_ref**: `openspec/changes/bodies-membership-terms-contacts-and-factions/specs/governance-bodies/spec.md#requirement-req-bmt-001-a-membership-records-from-when-to-when`
- **files**: `src/modals/MemberAddDialog.vue`, `src/components/tabs/useRelationStore.js`, `src/components/tabs/GovernanceBodyMembersTab.vue`
- **acceptance_criteria**:
  - GIVEN a new member added with start date 1 March WHEN the membership is saved THEN startDate is 1 March
  - GIVEN a member removed yesterday WHEN Past members is on THEN the row shows from and to
- [x] Implement
- [x] Test (red first)

### Task 2: Contact details
- **spec_ref**: `openspec/changes/bodies-membership-terms-contacts-and-factions/specs/governance-bodies/spec.md#requirement-req-bmt-002-contact-details-for-members-and-bodies`
- **files**: `src/modals/ContactDetailsDialog.vue`, `src/components/tabs/GovernanceBodyMembersTab.vue`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a member WHEN the clerk adds a phone number THEN a ContactDetail of type phone for that person is saved and the row shows it
- [x] Implement
- [x] Test (red first)

### Task 3: Faction link and workspace
- **spec_ref**: `openspec/changes/bodies-membership-terms-contacts-and-factions/specs/governance-bodies/spec.md#requirement-req-bmt-003-members-belong-to-a-faction-with-its-own-workspace`
- **files**: `lib/Settings/register.d/`, `src/modals/MemberAddDialog.vue`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a council with factions WHEN a member is added to faction Groen THEN Membership.faction points at that faction body
  - GIVEN a faction detail page WHEN opened THEN the Collectives workspace widget shows
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.

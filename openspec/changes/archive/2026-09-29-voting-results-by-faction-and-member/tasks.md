# Tasks: voting-results-by-faction-and-member

## Implementation tasks

### Task 1: Per member and per faction
- **spec_ref**: `openspec/changes/voting-results-by-faction-and-member/specs/motion-and-voting/spec.md#requirement-req-vrf-001-results-per-faction-and-per-member`
- **files**: `src/utils/voteBreakdown.js`, `src/components/tabs/MotionVotesTab.vue`, `src/components/tabs/MeetingVotesTab.vue`
- **acceptance_criteria**:
  - GIVEN an open round with 5 ballots from two factions WHEN the motion page opens THEN each voter is named and each faction shows its counts
  - GIVEN a secret round THEN only totals show
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.

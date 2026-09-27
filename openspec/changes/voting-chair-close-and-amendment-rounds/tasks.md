# Tasks: voting-chair-close-and-amendment-rounds

### Task 1: The voting permissions read

- **spec_ref**: `openspec/changes/voting-chair-close-and-amendment-rounds/specs/voting-round-management/spec.md#requirement-req-vcr-002-the-server-says-which-voting-controls-a-user-may-use-in-a-meeting`
- **files**: `lib/Controller/VotingController.php` (new `permissions()`), `appinfo/routes.php` (`voting#permissions`), `tests/Unit/Controller/VotingControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a caller who is chair of meeting M WHEN they GET `/api/meetings/M/voting-permissions` THEN all four values are true
  - GIVEN a secretary of M WHEN they call it THEN `canCastChairVote` is false and the other three are true
  - GIVEN a member of M, or an unknown meeting id WHEN called THEN all four values are false and the status is 200
  - GIVEN the controller source WHEN read THEN every value is computed through `VotingRoundGuard`, not a copied rule
  - Verification: PHPUnit `VotingControllerTest` cases written red first (method missing), then green; the route table still resolves every existing route (route-reachability gate)
- [ ] Implement
- [ ] Test

### Task 2: Tally and publish check the round's meeting

- **spec_ref**: `openspec/changes/voting-chair-close-and-amendment-rounds/specs/voting-round-management/spec.md#requirement-req-vcr-003-tally-entry-and-publication-check-the-role-in-the-rounds-own-meeting`
- **files**: `lib/Controller/VotingController.php` (`tally()`, `publish()`), `tests/Unit/Controller/VotingControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a non-admin secretary of the round's meeting WHEN they post a tally THEN it returns 200
  - GIVEN a secretary of another meeting WHEN they post a tally or publish THEN it returns 403 and nothing is saved
  - GIVEN a round with no resolvable meeting WHEN an admin posts THEN the global fallback still accepts
  - Verification: PHPUnit red-then-green on the other-meeting secretary case, which passes today only by accident of the global check
- [ ] Implement
- [ ] Test

### Task 3: The panel reads the server's answer

- **spec_ref**: `openspec/changes/voting-chair-close-and-amendment-rounds/specs/voting-round-management/spec.md#requirement-req-vcr-001-the-meetings-chair-and-secretary-see-the-voting-controls`
- **files**: `src/components/VotingRoundPanel.vue` (`isChairOrSecretary` at :549, open button at :22, chair casting controls), `tests/vitest/votingRoundPanelPermissions.spec.js` (new), `tests/e2e/spec-coverage/voting-rules.spec.ts`
- **acceptance_criteria**:
  - GIVEN the permissions read returns `canClose: true` for a non-admin WHEN the panel renders an open round THEN the close button shows
  - GIVEN it returns all false WHEN the panel renders THEN no open, close, split or publish control shows
  - GIVEN the panel source WHEN searched THEN no control reads `settingsStore.isAdmin`
  - Verification: vitest red-then-green with a mocked fetch; Playwright logs in as a seeded non-admin chair and closes a round
- [ ] Implement
- [ ] Test

### Task 4: A voting round widget on the amendment page

- **spec_ref**: `openspec/changes/voting-chair-close-and-amendment-rounds/specs/voting-round-management/spec.md#requirement-req-vcr-004-a-chair-opens-and-closes-a-vote-on-an-amendment-from-the-amendment-page`
- **files**: `src/components/VotingRoundPanel.vue` (`subjectType` prop, sent by `openRound()`), `src/components/tabs/AmendmentVotingRoundTab.vue` (new), `src/registry.js`, `src/manifest.json` (`AmendmentDetail` widget, layout cell and `slots` entry), `tests/e2e/spec-coverage/motion-amendment.spec.ts`
- **acceptance_criteria**:
  - GIVEN amendment A1 first in order WHEN the chair opens a round on its page THEN the round relates to A1 under schema `amendment` and A1 moves to `voting`
  - GIVEN A2 second in order and A1 undecided WHEN the chair opens a round on A2 THEN the widget shows the server's refusal and no round exists
  - GIVEN a parent motion with no meeting WHEN the amendment page renders THEN the open button is disabled with its explanation
  - GIVEN the manifest WHEN validated (`tests/validate-manifest.js`) THEN the new widget has a layout cell and a slot, so it does not render blank
  - Verification: Playwright on a seeded motion with two amendments, red first (no widget), then green
- [ ] Implement
- [ ] Test

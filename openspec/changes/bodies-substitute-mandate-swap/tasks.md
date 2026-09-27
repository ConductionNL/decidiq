# Tasks: bodies-substitute-mandate-swap

### Task 1: Declare the mandate substitution and the seat number

- **spec_ref**: `openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting`
- **files**: `lib/Settings/register.d/NN-substitute-mandate-swap.json` (new), `lib/Settings/profiles/municipality.json` (seed), `tests/Unit/RegisterJsonTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN read THEN `mandate-substitution` exists with the properties of design D1, `meeting`, `outgoingParticipant`, `incomingParticipant` and `startedAt` required, each with a `title`
  - GIVEN `participant` WHEN read THEN it declares `seatNumber`
  - GIVEN the municipality example set WHEN imported THEN the committee meeting, its three participants and one ended substitution exist
  - Verification: `RegisterJsonTest` red first, then green
- [ ] Implement
- [ ] Test

### Task 2: Start, end and read seats

- **spec_ref**: `openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan`
- **files**: `lib/Controller/MeetingController.php` (`seats()`, `substitute()`, `endSubstitution()`), `appinfo/routes.php`, `lib/Service/MandateSubstitutionService.php` (new), `tests/Unit/Service/MandateSubstitutionServiceTest.php` (new), `tests/newman/`
- **acceptance_criteria**:
  - GIVEN the meeting secretary WHEN they swap a member for an observer of the body THEN a substitution with the copied seat, party, role and weight exists (REQ-MSW-001)
  - GIVEN an open round in the meeting WHEN a start or end is posted THEN 409 and nothing changes
  - GIVEN each refusal of REQ-MSW-003 WHEN posted THEN the stated status and no record
  - GIVEN an end WHEN posted THEN `endedAt` is set and the record remains (REQ-MSW-004)
  - GIVEN a member without a presiding role WHEN they post THEN 403
  - Verification: PHPUnit, one case per refusal, written red first; Newman start and end on a seeded meeting
- [ ] Implement
- [ ] Test

### Task 3: Voting follows the seat

- **spec_ref**: `openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-002-while-a-substitution-is-active-the-substitute-votes-for-the-seat`
- **files**: `lib/Service/SubstitutionResolver.php` (new), `lib/Service/VoteCastGuard.php`, `lib/Service/VotingRoundOpener.php` (`checkQuorum()`), `lib/Service/VotingRoundPreflight.php` (`splitPresetParticipants()`), `tests/Unit/Service/SubstitutionResolverTest.php` (new), `tests/Unit/Service/VotingServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an active substitution WHEN the outgoing member casts THEN the cast is refused with the substitution named, and the substitute's cast is stored
  - GIVEN quorum 3 with one substituted seat WHEN a round opens THEN quorum passes
  - GIVEN a preset listing the outgoing member WHEN a round opens THEN the substitute is eligible and the member is not
  - GIVEN the substitution ended WHEN the next round opens THEN the member votes again and the substitute is refused
  - Verification: PHPUnit red-then-green for each of the three voting paths
- [ ] Implement
- [ ] Test

### Task 4: The seats panel on the live meeting page

- **spec_ref**: `openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-004-ending-a-substitution-returns-the-seat-to-the-member`
- **files**: `src/components/liveMeeting/SeatsPanel.vue` (new), `src/modals/MandateSwapModal.vue` (new), `src/views/LiveMeeting.vue`, `tests/e2e/workflows/substitute-mandate-swap.spec.ts` (new)
- **acceptance_criteria**:
  - GIVEN the seats read WHEN the panel renders THEN seats are listed in seat order with party, and a substituted seat names who it substitutes for (REQ-MSW-001)
  - GIVEN a presiding user WHEN they swap and later end a substitution THEN the panel updates both times without a reload
  - GIVEN a member WHEN the panel renders THEN no swap or end action shows
  - Verification: Playwright on the seeded committee meeting, red first (no panel), then green; the modal's picker has an `inputLabel`
- [ ] Implement
- [ ] Test

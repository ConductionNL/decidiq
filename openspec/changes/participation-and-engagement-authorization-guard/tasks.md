# Tasks: participation-and-engagement-authorization-guard

## Implementation Tasks

### Task 1: Make the session-identity hand-off explicit in the participation endpoints
- **spec_ref**: `openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-101-participation-endpoints-record-the-session-identity-never-a-request-supplied-one`
- **files**: `lib/Service/ParticipationResponder.php`, `lib/Controller/ParticipationBudgetController.php`, `lib/Controller/ParticipationController.php`
- **acceptance_criteria**:
  - GIVEN a routed participation intake method THEN the acting UID is bound in that method's own
    body from the session and handed to the service call alongside the caller-supplied object id
  - GIVEN no signed-in session THEN the endpoint still answers `401` and the service is never called
  - GIVEN a signed-in session THEN the endpoint behaves exactly as before (no audience narrowing)
- [x] Add `ParticipationResponder::currentUid()`; change `citizenAction()` to take the resolved
      `?string $uid` instead of resolving it and passing it into the operation closure.
- [x] Update `submitProposal()`, `castAdvisoryVote()` and `submitReaction()` to bind
      `$uid = $this->responder->currentUid()` and pass it to the service.
- [x] Document in each docblock WHY the endpoint is open to every authenticated account
      (spec text + register `create: ["authenticated"]` baseline).
- [x] Test: allow direction (service receives the session UID, 201) and deny direction (no
      session -> 401, service never called) for all three endpoints.

### Task 2: Fail the advisory voting-window guard closed
- **spec_ref**: `openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-part-102-the-advisory-voting-window-guard-fails-closed`
- **files**: `lib/Service/BudgetVotingService.php`
- **acceptance_criteria**:
  - GIVEN a proposal with no resolvable round THEN the vote is refused with the static
    voting-closed message and no `CitizenVote` is created
  - GIVEN a proposal referencing a round row that no longer resolves THEN same
  - GIVEN a round in `voting` phase before its deadline THEN the vote still succeeds
- [x] Replace the nested `if (… !== null)` skips with explicit refusals.
- [x] Test: both fail-closed shapes reject; the open-round shape still tallies.

### Task 3: Scope the engagement list to the caller's authority
- **spec_ref**: `openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-eng-101-engagement-records-are-listed-only-within-the-callers-authority`
- **files**: `lib/Controller/EngagementController.php`
- **acceptance_criteria**:
  - GIVEN an admin or the meeting's chair/secretary THEN every record for the meeting is returned
  - GIVEN any other authenticated caller THEN only their own participant's records are returned
  - GIVEN a caller with no linked `Participant` THEN an empty list is returned
  - GIVEN no session THEN `401`
- [x] Rename `mayRecordForOthers()` to `hasMeetingOversight()` and use it for BOTH `capture()`
      and `index()` so one predicate answers both directions.
- [x] Add `ownRecordsOnly()`; decide the scope before the fetch and project the result.
- [x] Confirm no frontend caller of `GET /api/engagement` loses data (`SpeakerQueuePanel.vue`
      only POSTs; the Engagement page reads through OpenRegister's object API per ADR-022).
- [x] Test: oversight sees all; plain participant sees only their own; unlinked caller sees none;
      anonymous gets 401.

### Task 4: Verification
- **spec_ref**: `openspec/changes/participation-and-engagement-authorization-guard/specs/participation-and-engagement-authorization/spec.md#requirement-req-eng-101-engagement-records-are-listed-only-within-the-callers-authority`
- **files**: `tests/Unit/Controller/EngagementControllerTest.php`, `tests/Unit/Controller/ParticipationCitizenActionAuthorizationTest.php`, `tests/Unit/Service/BudgetVotingWindowFailClosedTest.php`
- **acceptance_criteria**:
  - GIVEN hydra gate-7 (`check_no_admin_idor.py`) over the three controllers THEN zero findings
  - GIVEN the PHPUnit suite filtered to `Participation|Engagement` THEN green
- [x] Run `python3 scripts/lib/check_no_admin_idor.py` over the three controllers.
- [x] Run `php vendor/bin/phpunit --filter 'Participation|Engagement'`.

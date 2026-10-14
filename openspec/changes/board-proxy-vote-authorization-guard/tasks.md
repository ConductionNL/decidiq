# Tasks: board-proxy-vote-authorization-guard

## Implementation Tasks

### Task 1: Resolve chair/clerk authority for a GovernanceBody
- **spec_ref**: `openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-001-only-the-grantor-or-an-authorized-official-may-register-a-proxy`
- **files**: `lib/Service/ProxyVoteService.php`
- **acceptance_criteria**:
  - GIVEN a meeting id THEN the service can resolve whether a given personId/uid holds a
    chair/clerk role on that meeting's GovernanceBody (reuse the existing role-resolution helper
    already used by `LiveMeetingController::requireChairOrAdmin()` / participant resolution
    service rather than writing a second implementation)
- [x] Add a private `isChairOrClerk(string $meetingId, string $uid): bool` helper (or reuse (verified: lib/Service/ProxyVoteService.php isChairOrClerk via ParticipantResolver)
      `ParticipantResolver` if it already exposes role lookup) to `ProxyVoteService`.
- [x] Test: chair/clerk/regular-member/non-member classifications for a fixture meeting. (verified: tests/Unit/Service/ProxyVoteServiceTest.php testRegisterAllowsChairOnBehalfOfOthers, testRegisterRejectsUnrelatedCaller)

### Task 2: Authorize `register()`
- **spec_ref**: `openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-001-only-the-grantor-or-an-authorized-official-may-register-a-proxy`
- **files**: `lib/Service/ProxyVoteService.php:108` (`register()`), `lib/Controller/ProxyVoteController.php:68-97` (`register()`)
- **acceptance_criteria**:
  - GIVEN caller uid === grantorId THEN registration proceeds
  - GIVEN caller is chair/clerk of the meeting's GovernanceBody THEN registration proceeds
  - GIVEN caller is a Nextcloud admin THEN registration proceeds (mirrors `MotionCoauthorController`'s
    `$accessUid = null` admin-bypass convention)
  - GIVEN caller is none of the above THEN `register()` returns `{success: false}` and the
    controller responds `403 Forbidden` with a static message (no stack trace, no internal detail)
- [x] Add `?string $callerUid` param to `ProxyVoteService::register()`; reject before the existing (verified: lib/Service/ProxyVoteService.php)
      validation when the caller fails the check above.
- [x] Update `ProxyVoteController::register()` to resolve `$callerUid` (null on admin, per the (verified: lib/Controller/ProxyVoteController.php resolveCallerUid)
      `MotionCoauthorController` pattern) from `IUserSession` + `IGroupManager` and pass it through.
- [x] Test: self-grantor allowed; chair-on-behalf-of-another-member allowed; unrelated member (verified: tests/Unit/Service/ProxyVoteServiceTest.php testRegisterAllowsSelfGrantor, testRegisterAllowsChairOnBehalfOfOthers, testRegisterRejectsUnrelatedCaller, testRegisterAllowsAdminBypassViaNullCallerUid)
      rejected 403; admin allowed.

### Task 3: Authorize `transition()` (`suspend()` / `revoke()`)
- **spec_ref**: `openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-002-only-a-party-to-the-proxy-or-an-authorized-official-may-suspend-or-revoke-it`
- **files**: `lib/Service/ProxyVoteService.php:308` (`transition()`), `lib/Controller/ProxyVoteController.php:152-193` (`suspend()`, `revoke()`)
- **acceptance_criteria**:
  - GIVEN caller uid equals the proxy's `grantorKoppeling` OR `holderKoppeling` THEN the
    transition proceeds
  - GIVEN caller is chair/clerk of the proxy's meeting's GovernanceBody OR admin THEN the
    transition proceeds
  - GIVEN caller is unrelated to the proxy THEN `transition()` returns `{success: false}` and the
    controller responds `403 Forbidden`
- [x] Add `?string $callerUid` param to `transition()`/`suspend()`/`revoke()`; look up the proxy (verified: lib/Service/ProxyVoteService.php)
      first (already done for the `find()` call), then authorize before mutating.
- [x] Update `ProxyVoteController::suspend()`/`revoke()` to resolve and forward `$callerUid` the (verified: lib/Controller/ProxyVoteController.php)
      same way as Task 2.
- [x] Test: grantor revokes own proxy — allowed; holder suspends — allowed; unrelated (verified: tests/Unit/Service/ProxyVoteServiceTest.php testRevokeAllowsGrantorAndSuspendAllowsHolder, testRevokeRejectsUnrelatedCallerAndLeavesStatusUnchanged, testSuspendAllowsChairOfMeeting)
      authenticated user attempts suspend/revoke — 403; admin allowed.

### Task 4: Regression + Newman coverage
- **spec_ref**: `openspec/changes/board-proxy-vote-authorization-guard/specs/board-proxy-voting/spec.md#requirement-req-bpv-001-only-the-grantor-or-an-authorized-official-may-register-a-proxy`
- **files**: `tests/integration/` (Newman collection covering `proxyVote#register|suspend|revoke`)
- **acceptance_criteria**:
  - GIVEN the existing `proxyVote#*` Newman requests THEN they still pass authenticated as the
    grantor/chair fixture user
  - GIVEN a new negative-path request as an unrelated authenticated user THEN it asserts `403`
- [ ] Add/extend the Newman collection with the unrelated-user 403 case for all three mutating (code exists, test missing: tests/integration/decidiq-security-flow-e2e.postman_collection.json covers register and revoke 403, no unrelated-user suspend case)
      endpoints.
- [ ] Run `composer check:strict` + PHPUnit for `ProxyVoteService`/`ProxyVoteController`. (gate run, owed once before push, not a code check)

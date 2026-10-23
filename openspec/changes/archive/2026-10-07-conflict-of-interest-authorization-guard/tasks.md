# Tasks: conflict-of-interest-authorization-guard

## Implementation Tasks

### Task 1: Resolve caller identity and chair/secretary authority
- **spec_ref**: `openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration`
- **files**: `lib/Service/ConflictOfInterestService.php`
- **acceptance_criteria**:
  - GIVEN a Nextcloud UID THEN the service can resolve whether it identifies the same Membership
    as `boardMember` on a row (Participant -> Person/Membership crosswalk, reusing
    `ParticipantToPersonMembershipResolver`)
  - GIVEN a meeting id THEN the service can resolve whether a given uid holds a chair/secretary
    role on that meeting's GovernanceBody (reuse `ParticipantResolver::hasRole()`)
- [x] Add `isCallerThisMember()`, `isChairOrSecretary()`, `resolveParticipantUuid()`,
      `resolveMeetingIdFromAgendaItem()` private helpers to `ConflictOfInterestService`.
- [x] Test: self/chair/secretary/unrelated classifications for a fixture agenda item.

### Task 2: Authorize `declare()` and `forMember()`
- **spec_ref**: `openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration`
- **files**: `lib/Service/ConflictOfInterestService.php`, `lib/Controller/ConflictOfInterestController.php`
- **acceptance_criteria**:
  - GIVEN caller resolves to the declaring Membership THEN the request proceeds
  - GIVEN caller is chair/secretary of the relevant GovernanceBody THEN the request proceeds
  - GIVEN caller is a Nextcloud admin THEN the request proceeds (`$callerUid = null` bypass)
  - GIVEN caller is none of the above THEN `declare()` returns `{success: false}` and the
    controller responds `403 Forbidden`; `forMember()`'s guard returns `false` and the controller
    responds `403 Forbidden` directly
- [x] Add public `isAuthorizedForMember()` to `ConflictOfInterestService`; call from `declare()`
      (reject before persistence) and from the controller's `forMember()` (reject before reading).
- [x] Update `ConflictOfInterestController` to resolve `$callerUid` (null on admin) from
      `IUserSession` + `IGroupManager` and forward/check it.
- [x] Test: self-declare allowed; chair-on-behalf-of-another-member allowed; unrelated member
      rejected 403; admin allowed. Same matrix for `forMember()`.

### Task 3: Authorize `recordAction()`
- **spec_ref**: `openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-103-only-a-chair-or-secretary-may-record-the-action-taken`
- **files**: `lib/Service/ConflictOfInterestService.php`, `lib/Controller/ConflictOfInterestController.php`
- **acceptance_criteria**:
  - GIVEN caller is chair/secretary of the relevant GovernanceBody OR admin THEN the update proceeds
  - GIVEN caller is the declaring member (but not chair/secretary) THEN `recordAction()` returns
    `{success: false}` and the controller responds `403 Forbidden`
  - GIVEN caller is unrelated THEN same as above
- [x] Add private `isAuthorizedToRecordAction()`; reject before mutating.
- [x] Update `ConflictOfInterestController::recordAction()` to resolve and forward `$callerUid`.
- [x] Test: chair/secretary allowed; declaring member without chair/secretary role rejected 403;
      unrelated authenticated user rejected 403; admin allowed.

### Task 4: Schema-level authorization block
- **spec_ref**: `openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-102-only-the-member-or-an-authorized-official-may-read-a-members-conflict-declarations`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the `ConflictOfInterest` schema THEN it declares its own `authorization` block
    (`read`/`list`: `authenticated`, no `public`) rather than falling back to the register-level
    baseline that also grants `public` read
  - GIVEN `create`/`update` are omitted from the block THEN `ConflictOfInterestService` passes
    `_rbac: false` on both writes after its own guard has run, so declaring/recording still work
    end-to-end for authorized callers
- [x] Add the `authorization` block to `ConflictOfInterest` in `decidesk_register.json`.
- [x] Add `_rbac: false` to both `saveObject()` calls in `ConflictOfInterestService`.
- [x] Run the full PHPUnit suite to confirm reads/writes still work for authorized callers.

### Task 5: Regression coverage
- **spec_ref**: `openspec/changes/conflict-of-interest-authorization-guard/specs/conflict-of-interest-authorization/spec.md#requirement-req-coi-101-only-the-declaring-member-or-an-authorized-official-may-record-a-declaration`
- **files**: `tests/Unit/Service/ConflictOfInterestServiceTest.php`, `tests/Unit/Controller/ConflictOfInterestControllerTest.php`
- **acceptance_criteria**:
  - GIVEN the existing `ConflictOfInterestService`/`ConflictOfInterestController` test suites
    THEN they still pass with the new `$callerUid` parameter (default null / admin bypass)
  - GIVEN a new negative-path test per endpoint THEN it asserts `403` for an unrelated
    authenticated caller, and a positive-path test proves the legitimate caller is allowed
- [x] Extend both test files with the allow/reject matrix above.
- [x] Run `composer check:strict` + PHPUnit for `ConflictOfInterestService`/`ConflictOfInterestController`.

# Tasks: participation-citizen-advisory-vote-on-motions

## Implementation tasks

### Task 1: Status and counts on the motion
- **spec_ref**: `openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion`
- **files**: `lib/Settings/register.d/92-citizen-advice-on-motions.json`, `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN imported THEN `decision` carries `citizenVotingStatus` with its lifecycle and the three advice counts
  - GIVEN the seeded motion WHEN read THEN the counts are 3, 1 and 1
- [ ] Implement
- [ ] Test (register test, Newman read)

### Task 2: Open and close from the motion page
- **spec_ref**: `openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion`
- **files**: `lib/Controller/CitizenAdviceController.php`, `appinfo/routes.php`, `src/manifest.json` (`MotionDetail`), `tests/Unit/Controller/CitizenAdviceControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a published motion with citizen voting allowed WHEN the griffier opens it THEN the status is open
  - GIVEN a motion that is not published WHEN she opens it THEN 422 naming the reason
  - GIVEN a council member without the secretariat group or a chair or secretary role WHEN he opens it THEN 403
  - GIVEN the route gates WHEN run THEN route-auth, route-reachability and no-admin-idor pass
- [ ] Implement
- [ ] Test

### Task 3: Residents give their advice through the portal
- **spec_ref**: `openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-002-a-verified-resident-gives-one-advisory-vote-while-it-is-open`
- **files**: `lib/Portal/PortalContributionProvider.php`, `lib/Listener/CitizenAdviceListener.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`, `tests/Unit/Listener/CitizenAdviceListenerTest.php`
- **acceptance_criteria**:
  - GIVEN the citizen contribution WHEN read THEN it offers `castMotionAdvice` with minTrust substantial and the parent constraint on `citizenVotingStatus` open
  - GIVEN an open motion WHEN a resident posts voor THEN the vote is stored with `motionId` set
  - GIVEN the same resident WHEN he posts again THEN it is refused
  - GIVEN a closed motion WHEN a vote is posted directly to OpenRegister THEN it is refused by the listener
- [ ] Implement
- [ ] Test (real `ObjectCreatingEvent`)

### Task 4: The result, clearly advisory
- **spec_ref**: `openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-003-the-advisory-result-shows-apart-from-the-councils-vote`
- **files**: `src/manifest.json` (`MotionDetail`), `lib/Portal/PortalContributionProvider.php` (`motionsOpenForAdvice`), `tests/newman/citizen-advice-on-motions.json`
- **acceptance_criteria**:
  - GIVEN a closed advisory vote WHEN the griffier opens the motion THEN she sees the three counts with the not-binding caption beside the voting round
  - GIVEN the statutory voting round WHEN it is closed THEN its tally is unaffected by citizen votes
  - GIVEN an anonymous resident WHEN the portal lists motions open for advice THEN the published motion shows with its counts after closing
- [ ] Implement
- [ ] Test (Newman against a seeded instance with portaliq)

## Verification

- `composer check:strict` and `npm run lint` once before push.

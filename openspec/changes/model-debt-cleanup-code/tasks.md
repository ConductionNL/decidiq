# Tasks: model-debt-cleanup-code

## Implementation Tasks

### Task 1: Crosswalk resolver + ConflictOfInterest.boardMember repair step
- **spec_ref**: `openspec/changes/model-debt-cleanup-code/migration.md#repointconflictofinterestboardmember`
- **files**: `lib/Service/ParticipantToPersonMembershipResolver.php` (new), `lib/Repair/RepointConflictOfInterestBoardMember.php` (new), `appinfo/info.xml` (register the new step in `<repair-steps><post-migration>`, after `RenameDutchVocabularyColumns`)
- **acceptance_criteria**:
  - GIVEN a `Participant` UUID with a matching `Person.email` WHEN resolved THEN the existing `Person`'s `Membership` for the same `governanceBody` is reused (or created if absent)
  - GIVEN a `Participant` UUID with no email match WHEN resolved THEN a new `Person`+`Membership` pair is created from the `Participant`'s fields
  - GIVEN every `conflict-of-interest` row WHEN the repair step runs THEN `boardMember` holds a `Membership` UUID, and a second run is a no-op (idempotent)
  - Resolve the DEFERRED_QUESTION in design.md (email-only vs. fuzzy fallback match) before or during implementation — human confirmation required
- [x] Implement — verified 2026-08-19 (post-crash recovery): `lib/Service/ParticipantToPersonMembershipResolver.php` implements the judge-amended match order (nextcloudUserId exact → email exact → create), backfills `nextcloudUserId` onto an email-matched Person; `lib/Repair/RepointConflictOfInterestBoardMember.php` exists and is registered in `appinfo/info.xml`'s `<post-migration>` block after `RenameDutchVocabularyColumns`.
- [x] Test — verified: `vendor/bin/phpunit tests/Unit/Service/ParticipantToPersonMembershipResolverTest.php tests/Unit/Repair/RepointConflictOfInterestBoardMemberTest.php` passes (part of the 55-test/129-assertion run covering Tasks 1–4, see Verification section).

### Task 2: ConflictOfInterestController/Service parameter rename
- **spec_ref**: `openspec/changes/model-debt-cleanup-code/proposal.md#in-scope`
- **files**: `lib/Controller/ConflictOfInterestController.php`, `lib/Service/ConflictOfInterestService.php`, `tests/Unit/Controller/ConflictOfInterestControllerTest.php`, `tests/Unit/Service/ConflictOfInterestServiceTest.php`
- **acceptance_criteria**:
  - GIVEN every `boardMemberId` occurrence WHEN renamed THEN it reads `membershipId` (request param, method param, internal variable, docblock)
  - GIVEN the two test files WHEN updated THEN they call/assert against `membershipId`
  - Confirmed zero live frontend/MCP callers exist — no other file needs updating
- [x] Implement — verified 2026-08-19: grep confirms `boardMemberId` no longer occurs in `lib/Controller/ConflictOfInterestController.php` or `lib/Service/ConflictOfInterestService.php`; `membershipId` is used throughout (request param, method params on `declare()`/`getActiveConflicts()`/`findDeclarations()`, docblocks).
- [x] Test — verified: `ConflictOfInterestControllerTest.php`/`ConflictOfInterestServiceTest.php` call/assert against `membershipId`; included in the green 55-test PHPUnit run.

### Task 3: ProxyVoteService/Controller rewrite to proxy-authorization
- **spec_ref**: `openspec/changes/model-debt-cleanup-code/design.md#decision-3-proxyvoteservicecontroller-rewrite--property-mapping`
- **files**: `lib/Service/ProxyVoteService.php`, `lib/Controller/ProxyVoteController.php`, `tests/Unit/Service/ProxyVoteServiceTest.php`, `tests/Unit/Controller/ProxyVoteControllerTest.php`
- **acceptance_criteria**:
  - GIVEN `SCHEMA` WHEN changed THEN it reads `'proxy-authorization'` (was `'board-proxy'`)
  - GIVEN `register()`/`forMeeting()`/`transition()`/`suspend()`/`revoke()` WHEN rewritten THEN they read/write `meeting`/`grantor`/`holder`/`proxyStatus` (dropping the `*Integration` suffix naming) and `grantor`/`holder` are resolved to `Person` UUIDs via `ParticipantToPersonMembershipResolver` (Task 1) before being written
  - GIVEN the per-holder cap logic (`maxProxiesPerHolder()`) WHEN the schema target changes THEN the cap-counting behaviour is unchanged (same test assertions, new property names)
  - Correct the stale docblock (lines 11-15 referencing non-existent `*Koppeling` property names) in the same edit
  - Both PHPUnit files updated and passing
- [x] Implement — verified 2026-08-19: `lib/Service/ProxyVoteService.php::SCHEMA = 'proxy-authorization'` (was `board-proxy`); `meeting`/`grantor`/`holder`/`proxyStatus` read/written without the `*Integration` suffix; `grantor`/`holder` resolved via `ParticipantToPersonMembershipResolver`; stale `*Koppeling` docblock corrected.
- [x] Test — verified: `ProxyVoteServiceTest.php`/`ProxyVoteControllerTest.php` pass, included in the green 55-test PHPUnit run.

### Task 4: board-proxy → proxyAuthorization row migration
- **spec_ref**: `openspec/changes/model-debt-cleanup-code/migration.md#migrateboardproxytoproxyauthorization`
- **files**: `lib/Repair/MigrateBoardProxyToProxyAuthorization.php` (new), `appinfo/info.xml` (register after Task 1's step, and after Task 3 ships since the migration writes through the new schema shape)
- **acceptance_criteria**:
  - GIVEN a `board-proxy` row with resolvable grantor/holder/meeting WHEN migrated THEN exactly one `proxyAuthorization` object is created with `signatureStatus: "unsigned"` and `proxyStatus` copied verbatim
  - GIVEN a `board-proxy` row with an unresolvable meeting/grantor/holder WHEN migrated THEN no object is created, a skip is logged, and the source row is untouched
  - GIVEN the source `board-proxy` row WHEN migrated THEN it is never deleted or mutated
  - A second run of this step is a no-op for already-migrated rows (idempotent)
- [x] Implement — verified 2026-08-19: `lib/Repair/MigrateBoardProxyToProxyAuthorization.php` exists, registered in `appinfo/info.xml`'s `<post-migration>` block after `RepointConflictOfInterestBoardMember`.
- [x] Test — verified: `tests/Unit/Repair/MigrateBoardProxyToProxyAuthorizationTest.php` passes, included in the green 55-test PHPUnit run.

### Task 5: GovernanceBodyMembersTab.vue + MemberAddDialog.vue rewrite
- **spec_ref**: `openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md#scenario-members-tab-lists-active-memberships-not-participant-rows`
- **files**: `src/components/tabs/GovernanceBodyMembersTab.vue`, `src/modals/MemberAddDialog.vue`
- **acceptance_criteria**:
  - GIVEN `refresh()` WHEN rewritten THEN it queries `membership` filtered on `governanceBody` + active (`endDate` absent/null), joined to each `Membership`'s `Person` for the displayed name
  - GIVEN "Remove from body" (`confirmRemove()`) WHEN rewritten THEN it sets the `Membership`'s `endDate` to today rather than nulling `governanceBody` on a `Participant` row
  - GIVEN `MemberAddDialog.vue`'s create flow WHEN rewritten THEN it creates a `Person` (or matches by email) + `Membership` pair, never a `Participant`
- [x] Implement — 2026-08-19: `refresh()` now fetches active (`endDate` absent) `membership` rows filtered on `governanceBody`, joins each to its `person` via `fetchObject`; `confirmRemove()` sets the Membership's `endDate` to `new Date().toISOString()` instead of nulling a `governanceBody` pointer. `MemberAddDialog.vue` is rewritten from an "unassigned Participant picker" to a create form (name/email/role/party) that resolves-or-creates a `Person` by email then creates a `Membership`, via new shared helpers in `src/components/tabs/useRelationStore.js` (`isActiveMembership`, `buildMemberRow(s)`, `buildPersonPayload`, `buildMembershipPayload`, `resolveOrCreatePerson`).
- [x] Test — `tests/vitest/memberRelations.spec.js` (new, 16 tests) covers the join/payload/match-or-create helpers; `npx vitest run` is 367/367 (351 baseline + 16 new), zero regressions. Component-level behaviour is Playwright-covered per test-plan.md TC-4/TC-5 (owned by the orchestrator's e2e pass, not run here).

### Task 6: MemberGroupImportDialog.vue + MemberCsvImportDialog.vue + MemberRoleDialog.vue rewrite
- **spec_ref**: `openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md#scenario-imported-member-is-stored-as-person--membership`
- **files**: `src/modals/MemberGroupImportDialog.vue`, `src/modals/MemberCsvImportDialog.vue`, `src/modals/MemberRoleDialog.vue`
- **acceptance_criteria**:
  - GIVEN the NC-group import flow WHEN rewritten THEN each imported user becomes a `Person`+`Membership` pair (matched by email where an existing `Person` exists)
  - GIVEN the CSV import flow WHEN rewritten THEN each row becomes a `Person`+`Membership` pair, unmatched rows still flagged the same way as before
  - GIVEN the role-assignment dialog WHEN rewritten THEN it writes `role` onto the `Membership`, not onto a `Participant`
  - `VotingRoundPanel.vue`'s own `ensureRelationType('participant')` call is confirmed untouched (out of scope, quorum/vote-casting)
- [x] Implement — 2026-08-19: `MemberGroupImportDialog.vue`/`MemberCsvImportDialog.vue`'s `runImport()` now resolves-or-creates a `Person` per row (`resolveOrCreatePerson`) then creates a `Membership` (`buildMembershipPayload`) instead of a `Participant`; `MemberRoleDialog.vue`'s `save()` writes `role` onto the `Membership` via an explicit field allowlist (not a spread of the denormalised display row, to avoid writing non-Membership fields like `displayName`/`email` onto the object). Confirmed `VotingRoundPanel.vue` untouched (`git diff` shows no changes to that file).
- [x] Test — covered by the same `tests/vitest/memberRelations.spec.js` (16 tests: `resolveOrCreatePerson` match/create/no-email paths, `buildMembershipPayload` field selection) since all three dialogs share those helpers; `memberImport.js`'s own pre-existing 23 tests (`parseMemberCsv`/`validateMemberRows`/`markGroupDuplicates`) are unchanged and still pass since the row shape they consume (`email`/`nextcloudUserId`) is unchanged. Full suite: 367/367.

## Verification
- [x] All tasks checked off (2026-08-19)
- [x] `openspec validate model-debt-cleanup-code --strict` passes — "Change 'model-debt-cleanup-code' is valid"
- [ ] Manual testing against test-plan.md's 13 test cases — **DEFERRED**: TC-1/TC-2/TC-4/TC-5 (browser) and the live-migration TCs (TC-6–TC-9) need a running decidesk instance with imported schema fragments; owned by the orchestrator's separate Playwright/live-verification pass, not run by this task per its hard constraints (no Playwright, no `npm run build`). TC-10–TC-13 (PHPUnit) ARE covered — see Tasks 1–4's evidence notes above.

## Tests (company-wide ADR-009)
- PHPUnit: `ProxyVoteServiceTest.php`, `ProxyVoteControllerTest.php`, `ConflictOfInterestServiceTest.php`, `ConflictOfInterestControllerTest.php`, plus new tests for `ParticipantToPersonMembershipResolver` and both repair steps
- Browser tests (Playwright MCP) for the Members tab + 4 dialogs per test-plan.md TC-1/TC-2/TC-4/TC-5
- `composer test` and `newman run` both pass

## Documentation (company-wide ADR-010)
- N/A: internal data-model correction, no new user-facing feature; existing Members-tab screenshots in `docs/` remain accurate (UI unchanged, only the underlying schema)

## i18n (company-wide ADR-005)
- N/A: no new user-facing strings — the Members tab's visible labels are unchanged, only the underlying OpenRegister schema queried

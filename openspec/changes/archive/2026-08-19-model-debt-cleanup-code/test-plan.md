# Test Plan: model-debt-cleanup-code

## Coverage

### admin-settings (Member Import — MODIFIED)

| Scenario | Test Case | Type |
|---|---|---|
| Import members from a Nextcloud group | TC-1: import a 5-member NC group into a body; assert 5 `Membership` objects created, each with a `Person` (regression on existing e2e coverage, now against the new storage shape) | Functional (browser) |
| Import members from CSV | TC-2: upload a 3-row CSV; assert 3 `Membership`+`Person` pairs created, unmatched rows flagged | Functional (browser) |
| Imported member is stored as Person + Membership | TC-3: import one member; assert via the object API that a `Person` (matched or created) and a `Membership` (linking to the target `GovernanceBody`) exist, and no new `Participant` object was created | API |
| Members tab lists active memberships, not Participant rows | TC-4: open `GovernanceBodyMembersTab.vue` for a body with 3 active + 1 ended `Membership`; assert exactly 3 rows shown, sourced from `membership` not `participant` | Functional (browser) |
| Members tab remove sets endDate | TC-5: click "Remove from body" on a member row; assert the `Membership`'s `endDate` is set to today, `governanceBody` is unchanged, and no `Participant` field is nulled | Functional (browser) |

### Migration (model-debt-cleanup-code's own migration.md)

| Test | Test Case | Type |
|---|---|---|
| ConflictOfInterest repair step resolves boardMember | TC-6: seed a `conflict-of-interest` row with a `Participant`-typed `boardMember`; run `RepointConflictOfInterestBoardMember`; assert `boardMember` now holds a `Membership` UUID resolvable to the same underlying person (by email) | Regression / Migration |
| ConflictOfInterest repair step is idempotent | TC-7: run the repair step twice; assert the second run makes no further changes and creates no duplicate `Membership` | Regression / Migration |
| board-proxy migration creates proxyAuthorization | TC-8: seed a `board-proxy` row with resolvable grantor/holder/meeting; run `MigrateBoardProxyToProxyAuthorization`; assert exactly one new `proxyAuthorization` object with `signatureStatus: "unsigned"` and `proxyStatus` copied from the source | Regression / Migration |
| board-proxy migration skips unresolvable rows | TC-9: seed a `board-proxy` row with a `meetingIntegration` pointing at a non-existent Meeting; run the migration; assert no `proxyAuthorization` object created and a skip is logged, and the source row is untouched | Regression / Migration |

### ProxyVoteService / ProxyVoteController rewrite

| Scenario | Test Case | Type |
|---|---|---|
| register() writes proxy-authorization with Person grantor/holder | TC-10 (unit, `ProxyVoteServiceTest.php`): call `register()`; assert the created object's schema is `proxy-authorization` and `grantor`/`holder` are `Person` UUIDs | Unit (PHPUnit) |
| Per-holder cap still enforced | TC-11 (unit): register `maxProxiesPerHolder()`-many active proxies for one holder, attempt one more; assert rejection — unchanged business rule, new schema target | Unit (PHPUnit) |
| POST /api/proxies end-to-end | TC-12 (unit, `ProxyVoteControllerTest.php`): existing controller test suite, updated to assert against `proxy-authorization` property names | Unit (PHPUnit) |

### ConflictOfInterestController parameter rename

| Scenario | Test Case | Type |
|---|---|---|
| declare() accepts membershipId | TC-13 (unit, `ConflictOfInterestControllerTest.php`/`ConflictOfInterestServiceTest.php`): call with `membershipId`; assert 2xx and the stored `boardMember` is that Membership UUID; assert the old `boardMemberId` param name is no longer read | Unit (PHPUnit) |

## Coverage Summary

13 test cases across 4 grouped areas: 5 functional/API cases covering the
`admin-settings` Member Import delta (TC-1 through TC-5), 4 migration
regression cases (TC-6 through TC-9), 3 PHPUnit cases for the
ProxyVoteService/Controller rewrite (TC-10 through TC-12), 1 PHPUnit case for
the ConflictOfInterestController rename (TC-13).

Deliberately untested here: `VotingRoundPanel.vue`'s `Participant` usage
(explicitly out of scope, unchanged) and the `proxy-voting`/`conflict-of-interest`
note-based capabilities (separate, unrelated mechanisms per design.md's
observation).

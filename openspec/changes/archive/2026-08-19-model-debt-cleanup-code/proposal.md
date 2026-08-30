---
kind: code
depends_on: [model-debt-cleanup-schema]
---

# Proposal: model-debt-cleanup-code

## Summary

The imperative half of the decidesk model-debt cleanup. `model-debt-cleanup-schema`
declares the new/retargeted OpenRegister properties (`ConflictOfInterest.boardMember`
→ `Membership`, `ProxyAuthorization.grantor`/`holder` → `Person`, `proxyStatus`,
`BoardProxy` retirement); this change carries every consequence that touches
PHP or Vue: a live-data crosswalk migrating existing rows off the retired
`Participant` targets, a rewrite of `ProxyVoteService`/`ProxyVoteController`
to fold `board-proxy` into `proxyAuthorization`, a request-parameter rename on
`ConflictOfInterestController`, and the `GovernanceBodyMembersTab.vue` + 4
dialog rewrite from `Participant` to `Membership`+`Person` (the "Members tab
schema mismatch" finding from the organisation-facet audit — 1,245 lines
across 5 files, all confirmed by line count).

## Motivation

`model-debt-cleanup-schema` changes what these schemas *declare*; it changes
nothing about what's already *stored*. Every consumer that reads/writes the
retargeted properties, and the two schemas being folded, needs its own fix —
and the fix must run in the right order relative to the schema import,
exactly like the precedent already in this codebase
(`RenameDutchDecideskValues`'s own comment: "The schema edit changes the
DECLARATION; every row already written still holds the [old] value").
Separately, `GovernanceBodyMembersTab.vue` and its four dialogs
(`MemberAddDialog.vue`, `MemberGroupImportDialog.vue`,
`MemberCsvImportDialog.vue`, `MemberRoleDialog.vue` — confirmed 297+161+307+342+138
= 1,245 lines) all call `ensureRelationType('participant')` /
`fetchCollection('participant', ...)` / `saveObject('participant', ...)`
directly, even though `admin-settings/spec.md`'s status-note already records
that the Members tab was root-caused once before (a `governanceBody` property
visibility bug) — this is the second Members-tab defect against the same
deprecated schema.

## Affected Projects

- [x] Project: decidesk — `lib/Repair/`, `lib/Service/`, `lib/Controller/`, `src/components/tabs/`, `src/modals/`, `tests/Unit/`

## Scope

### In Scope

- A `Participant`→`Person`+`Membership` crosswalk resolver (match by `email`;
  create a new `Person`+`Membership` pair when no match exists) and one
  Nextcloud repair step applying it to existing `ConflictOfInterest.boardMember`
  and `ProxyAuthorization.grantor`/`holder` rows.
- `ConflictOfInterestController`/`ConflictOfInterestService`: rename the
  `boardMemberId` request parameter and internal variable naming to
  `membershipId` (no live frontend/MCP caller today — confirmed by grep — so
  this is a safe, non-breaking rename); update the two existing PHPUnit test
  files.
- `ProxyVoteService`/`ProxyVoteController`: rewrite to target the
  `proxy-authorization` schema and its `grantor`/`holder`/`meeting`/`proxyStatus`
  properties (dropping the legacy `*Integration` suffix naming); update the
  two existing PHPUnit test files.
- A repair step migrating existing `board-proxy` rows into `proxyAuthorization`
  objects, reusing the crosswalk resolver above for the grantor/holder UUIDs.
- `GovernanceBodyMembersTab.vue` + `MemberAddDialog.vue`: rewrite the
  list/create/remove flow from `participant` to `membership`+`person`.
- `MemberGroupImportDialog.vue` + `MemberCsvImportDialog.vue` + `MemberRoleDialog.vue`:
  rewrite the bulk-import and role-assignment flows the same way.

### Out of Scope

- Anything already covered by `model-debt-cleanup-schema` (the schema declarations themselves).
- `VotingRoundPanel.vue` — also calls `ensureRelationType('participant')`, but
  for quorum/vote-casting (the shim's stated retained purpose, per
  `model-debt-cleanup-schema`'s Decision 2). Not touched.
- `Vote.participant`/`EngagementRecord.participant` and their consumers
  (`VotingService::resolveParticipantUuid()`, quorum aggregation) — explicitly
  out of scope per the parent scope's item 2 instruction and
  `model-debt-cleanup-schema`'s Decision 2.
- Deleting the `Participant` schema.
- The `proxy-voting` capability's `Vote.delegator`/`isProxy` mechanism —
  confirmed a separate, third proxy concept (Participant-to-Participant
  in-vote delegation), untouched.

## Approach

One shared crosswalk resolver, two repair steps consuming it, two service
rewrites, five Vue file rewrites. Full detail in design.md.

## New Dependencies

None.

## Impact

- `lib/Service/` — new crosswalk resolver; `ProxyVoteService` rewritten.
- `lib/Repair/` — two new repair-step classes, registered in `appinfo/info.xml`'s `<post-migration>` block, ordered after `InitializeSettings` (which re-imports the schema chain's fragment first).
- `lib/Controller/` — `ConflictOfInterestController`, `ProxyVoteController` (parameter/property naming only, no route change).
- `src/components/tabs/GovernanceBodyMembersTab.vue`, `src/modals/MemberAddDialog.vue`, `src/modals/MemberGroupImportDialog.vue`, `src/modals/MemberCsvImportDialog.vue`, `src/modals/MemberRoleDialog.vue`.
- `tests/Unit/Controller/ProxyVoteControllerTest.php`, `tests/Unit/Service/ProxyVoteServiceTest.php`, `tests/Unit/Controller/ConflictOfInterestControllerTest.php`, `tests/Unit/Service/ConflictOfInterestServiceTest.php`.

## Cross-Project Dependencies

Depends on `model-debt-cleanup-schema` shipping first — every repair step in
this change reads/writes properties that change is responsible for declaring.
Hydra's dependency enforcement (`depends_on` in this frontmatter, translated
to an issue-close gate at plan-to-issues time) blocks this change's build
until that one's issue is closed.

## Risks

### Risk 1: The Participant→Person/Membership crosswalk has no exact key
**Severity**: Medium
**Mitigation**: Confirmed by schema inspection — neither `Person` nor
`Membership` carries `nextcloudUserId` (only `Participant` does). `email` is
the best available match key (present on both `Participant` and `Person`).
design.md documents the full resolution algorithm: exact `email` match first,
else create a new `Person`+`Membership` pair from the `Participant`'s
`displayName`/`email`/`role`/`party`/`votingWeight`/`governanceBody`, and log
every unresolved case for manual review — non-destructive, no data silently
dropped.

### Risk 2: ProxyVoteService rewrite breaks the live per-holder proxy cap
**Severity**: Medium
**Mitigation**: `maxProxiesPerHolder()`/`forMeeting()` logic is preserved
unchanged; only the schema slug and property names it reads/writes move.
Existing PHPUnit coverage (`ProxyVoteServiceTest.php`) is updated in the same
task, not deferred.

### Risk 3: Renaming `boardMemberId` → `membershipId` is a breaking API change if a caller exists
**Severity**: Low
**Mitigation**: Confirmed by grep — zero frontend (`src/`) and zero MCP
(`lib/Mcp/`) callers of `ConflictOfInterestController::declare()` exist
today; only the two PHPUnit test files call it directly. Safe rename.

## Rollback Strategy

Revert the PHP/Vue changes. The two repair steps are idempotent and
non-destructive (matching the `RenameDutchVocabularyColumns` precedent) —
re-running them after a partial rollback is safe, but a full rollback should
restore the repair-step registration order too (repair steps that already
ran once do not automatically undo their writes; a genuine data rollback
needs the reverse repair or a database restore, called out explicitly rather
than implied).

## Open Questions

See DEFERRED_QUESTIONS in the change-generation report — the exact
crosswalk match key (`email`-only vs. a secondary fuzzy match on
`displayName`+`governanceBody`) is a judgment call flagged for human
confirmation.

## Capabilities

**Modified Capabilities:**
- `admin-settings` — Member Import's underlying storage shape changes from a flat `Participant` object to a `Person`+`Membership` pair (REQ-ADM (Member Import) MODIFIED — no scenario steps change, only the stored data shape, which is spec-worthy since it's externally observable via the object API).

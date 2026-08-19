# Design: model-debt-cleanup-code

## Context

`model-debt-cleanup-schema` retargets `ConflictOfInterest.boardMember` and
`ProxyAuthorization.grantor`/`holder` from `$ref: Participant` to
`$ref: Membership`/`Person`, adds `ProxyAuthorization.proxyStatus`, and marks
`BoardProxy` inactive. None of that touches a single stored row. This change
is everything that does: a live-data crosswalk + two repair steps, the
`ProxyVoteService`/`Controller` rewrite that makes `proxy-authorization` (not
`board-proxy`) the schema `/api/proxies` actually writes, a request-parameter
rename on `ConflictOfInterestController`, and the `GovernanceBodyMembersTab.vue`
+ 4 dialogs rewrite from `Participant` to `Membership`+`Person`.

## Goals / Non-Goals

**Goals:**
- Resolve every existing `ConflictOfInterest.boardMember` / `ProxyAuthorization.grantor`/`holder` Participant UUID to a Person/Membership UUID, non-destructively.
- Make `ProxyVoteService` the sole writer of `proxy-authorization` rows (dropping `board-proxy`), migrating existing rows.
- Rewrite the Members tab surface (5 files, 1,245 lines) onto `membership`+`person`.

**Non-Goals:**
- No schema edits — depends on `model-debt-cleanup-schema` for every declaration this change relies on.
- No change to `VotingRoundPanel.vue`, `Vote.participant`, `EngagementRecord.participant`, or the `proxy-voting` capability's `Vote.delegator` mechanism.
- No change to the `Participant` schema itself (already handled by the schema chain).

## Decisions

### Decision 1: Crosswalk resolver — match by email, else create

Neither `Person` nor `Membership` carries `nextcloudUserId` (schema-confirmed —
only `Participant` has it). Both `Participant` and `Person` carry `email`.
Algorithm, implemented as `ParticipantToPersonMembershipResolver` (new,
`lib/Service/`):

1. Given a `Participant` UUID, load the row (`displayName`, `email`, `role`,
   `party`, `votingWeight`, `governanceBody`, `nextcloudUserId`).
2. If `email` is set, query existing `Person` objects for an exact `email`
   match.
   - **Match found**: check whether that `Person` already has a `Membership`
     for the same `governanceBody`. If yes, reuse it. If no, create a new
     `Membership` (`person`, `governanceBody`, `role`, `party`, `votingWeight`
     copied from the `Participant`).
   - **No match**: create a new `Person` (`name: displayName`, `email`) and a
     new `Membership` as above.
3. If `email` is empty, skip the match step and create a new `Person`+`Membership`
   directly from `displayName`/`role`/`party`/`votingWeight`/`governanceBody`.
4. Every resolution (matched vs. created) is logged at `info` level with the
   source `Participant` UUID and the resulting `Person`/`Membership` UUIDs —
   auditable, and cheap to re-derive if a follow-up wants a stricter match.
5. The resolver is idempotent: a second run against an already-resolved
   `Participant` re-derives the same `Person` (by email) and reuses the
   existing `Membership` rather than duplicating it.

This mirrors the existing repair-step precedent in this codebase
(`RenameDutchVocabularyColumns`: "Idempotent and non-destructive") and is
deliberately conservative — nothing is deleted, no `Participant` row is
touched, only new `Person`/`Membership` rows are created or reused.

### Decision 2: Two repair steps, both consuming the shared resolver

- `RepointConflictOfInterestBoardMember` (`lib/Repair/`): iterates every
  `ConflictOfInterest` row, resolves `boardMember` (a `Participant` UUID)
  through the shared resolver, writes back the resulting `Membership` UUID.
- `MigrateBoardProxyToProxyAuthorization` (`lib/Repair/`): iterates every
  `board-proxy` row, resolves `grantorIntegration`/`holderIntegration`
  (`Participant`-identified strings — `BoardProxy` never declared a `$ref`,
  so these are read as plain UUID strings) through the shared resolver,
  creates a corresponding `proxyAuthorization` object with `grantor`/`holder`
  as the resolved `Person` UUIDs, `meeting` from `meetingIntegration`,
  `proxyStatus` copied from the source `proxyStatus`, and
  `signatureStatus: "unsigned"` (a fresh instrument — a legacy `board-proxy`
  row never had a signed machtiging document, so there is nothing to migrate
  into `signatureStatus`; the migrated row starts at the same unsigned state
  every newly-created `proxyAuthorization` would).

Both are registered in `appinfo/info.xml`'s `<post-migration>` block, appended
AFTER `InitializeSettings` (which triggers the schema chain's fragment
re-import first) and after the existing `RenameDutchDecideskValues`/
`RenameDutchVocabularyColumns` steps, following the exact ordering comment
already in that file ("Must run AFTER the register sync that adds the...
columns, so it can tell the rename case from the back-fill case").

`ConflictOfInterest.boardMember`'s repair does NOT touch `ProxyAuthorization`
(separate schema, separate repair step) — the two are independent repairs
sharing only the resolver class, not a combined repair step, so either can be
retried/rolled back independently.

### Decision 3: ProxyVoteService/Controller rewrite — property mapping

| Old (`board-proxy`) | New (`proxy-authorization`) |
|---|---|
| `SCHEMA = 'board-proxy'` | `SCHEMA = 'proxy-authorization'` |
| `meetingIntegration` (plain string) | `meeting` ($ref `Meeting`) |
| `grantorIntegration` (plain string, Participant-identified) | `grantor` ($ref `Person`) |
| `holderIntegration` (plain string, Participant-identified) | `holder` ($ref `Person`) |
| `proxyStatus` (enum, unchanged shape) | `proxyStatus` (same enum, now on `proxy-authorization`) |

`ProxyVoteService::register()` must resolve the caller's `Participant`
identity (via `resolveParticipantUuid()`, unchanged — this still reads
`Participant`, which is fine, that's still-live shim usage per the schema
chain's Decision 2) to a `Person`/`Membership` pair through the same
`ParticipantToPersonMembershipResolver` used by the repair steps, so a
freshly-registered proxy is created with `Person` UUIDs from day one, not
just migrated legacy ones. `maxProxiesPerHolder()`/`forMeeting()`/`transition()`/
`suspend()`/`revoke()` logic is unchanged except for the schema/property
names they read. The stale docblock comment (line 11-15, referencing
non-existent `meetingKoppeling`/`grantorKoppeling`/`holderKoppeling` names) is
corrected in the same edit since the class is being touched anyway.

`ProxyVoteController` needs no route or method-signature change — it delegates
to the service.

### Decision 4: ConflictOfInterestController/Service parameter rename

`boardMemberId` → `membershipId` throughout `ConflictOfInterestController::declare()`
and `ConflictOfInterestService::declare()`/`getActiveConflicts()`/`findDeclarations()`.
Grep-confirmed zero live callers (frontend or MCP) — only
`ConflictOfInterestControllerTest.php`/`ConflictOfInterestServiceTest.php`
call these methods, and both are updated in this task.

## Declarative-vs-imperative decision (ADR-031)

Both repair steps are imperative Nextcloud `<repair-steps>` classes, not
declarative schema behaviour — this is the explicit "one-time data repair /
migration" exception ADR-031 carves out (see
`model-debt-cleanup-schema/design.md`'s own Declarative-vs-imperative
section, which defers exactly this work here). No new lifecycle, aggregation,
calculation, notification, or relation dialect is introduced by this change;
`ProxyVoteService`'s existing imperative approval-transition logic
(`transition()`/`suspend()`/`revoke()`) is relocated onto the new schema
target, not redesigned.

## Seed Data (ADR-001)

Not applicable — this change introduces no new or modified OpenRegister
schema (that is entirely `model-debt-cleanup-schema`'s responsibility, whose
own design.md carries the Seed Data section for every new/retargeted
property). This change only migrates existing rows and rewrites consumers.

## Risks / Trade-offs

[Risk] The email-match crosswalk creates a duplicate `Person` for a
`Participant` whose email differs from their canonical `Person` email (e.g. a
personal vs. work address) → [Mitigation] every resolution is logged with
both UUIDs (Decision 1, step 4); a follow-up audit can dedupe by
`nextcloudUserId` cross-referencing `Participant.nextcloudUserId` against
Nextcloud's own user directory if this proves common — deliberately deferred
rather than over-engineered into this change (see Open Questions).

[Risk] `board-proxy` rows with no matching `Meeting`/`Person` context (e.g.
partially-filled legacy test fixtures) fail the migration → [Mitigation]
`MigrateBoardProxyToProxyAuthorization` skips and logs any row where the
resolver cannot produce a `meeting` reference, rather than creating an
invalid `proxyAuthorization` object; the legacy `board-proxy` row is left in
place (not deleted) so nothing is lost.

## Migration Plan

Full detail in this change's migration.md. Summary: two Nextcloud repair
steps, both idempotent, both registered after the schema chain's
`InitializeSettings` re-import in `<post-migration>`.

## Open Questions

**DEFERRED_QUESTION**: Is an exact-`email`-match crosswalk sufficient, or
should the resolver also attempt a fallback fuzzy match on
`displayName`+`governanceBody` for `Participant` rows with no `email` set (a
real possibility — `email` is optional on `Participant`)? This design chose
email-only + create-if-unmatched as the conservative default (never silently
merges two different people on a name-similarity heuristic), accepting that
some `Participant` rows without email will produce a fresh `Person` even if a
matching one already exists. Flagged for human confirmation before
implementation.

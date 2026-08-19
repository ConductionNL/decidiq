# Migration: model-debt-cleanup-code

## Current State

- `ConflictOfInterest` rows hold a `Participant` UUID in `boardMember`.
- `ProxyAuthorization` rows (if any exist yet — the schema has no live writer
  today) would hold `Participant` UUIDs in `grantor`/`holder`.
- `board-proxy` rows (written by `ProxyVoteService::register()`) hold plain
  Participant-identified strings in `grantorIntegration`/`holderIntegration`,
  a plain string in `meetingIntegration`, and an approval-workflow
  `proxyStatus`.

## Target State

- `ConflictOfInterest.boardMember` holds a `Membership` UUID.
- `ProxyAuthorization.grantor`/`holder` hold `Person` UUIDs; every
  historical `board-proxy` row has a corresponding `proxyAuthorization`
  object.
- `ProxyVoteService` writes/reads `proxy-authorization` exclusively;
  `board-proxy` rows are left in place (untouched, unread by the app going
  forward) as historical record — not deleted (`hardDelete: false`
  convention).

## Migration Class(es)

Both are Nextcloud repair steps (`OCP\Migration\IRepairStep`), registered in
`appinfo/info.xml`'s `<repair-steps><post-migration>` block, following the
exact pattern of the existing `RenameDutchVocabularyColumns`/
`RenameDutchDecideskValues` steps in this same file.

### `OCA\Decidesk\Repair\RepointConflictOfInterestBoardMember`

- `getName()`: "Repoint ConflictOfInterest.boardMember from Participant to Membership"
- `run(IOutput $output)`:
  1. Query all `conflict-of-interest` objects via `ObjectServiceInterface`.
  2. For each, if `boardMember` resolves to a `Participant` object (not
     already a `Membership`), resolve via
     `ParticipantToPersonMembershipResolver::resolve($participantUuid)`.
  3. Update the row's `boardMember` to the resolved `Membership` UUID.
  4. Log a summary count (resolved / already-migrated / skipped-unresolvable) via `$output->info()`.

### `OCA\Decidesk\Repair\MigrateBoardProxyToProxyAuthorization`

- `getName()`: "Migrate board-proxy rows into proxy-authorization objects"
- `run(IOutput $output)`:
  1. Query all `board-proxy` objects.
  2. For each, resolve `grantorIntegration`/`holderIntegration` via the
     shared resolver; skip (log, do not create) if either cannot resolve to
     a `Person`.
  3. Skip (log, do not create) if `meetingIntegration` does not resolve to
     an existing `Meeting` UUID.
  4. Create a new `proxyAuthorization` object: `grantor`, `holder`, `meeting`
     from the resolved values; `proxyStatus` copied verbatim from the source
     row; `signatureStatus: "unsigned"` (fresh instrument — see design.md
     Decision 2 for why no signature state is carried over).
  5. Do NOT delete or mutate the source `board-proxy` row.
  6. Log a summary count via `$output->info()`.

Both steps run idempotently: a re-run after a partial or full prior run
detects already-`Membership`-typed `boardMember` values (step skips) and
already-migrated `board-proxy` rows (tracked by checking whether a
`proxyAuthorization` object already references the same `board-proxy` source
UUID — the migrated object stores the source `board-proxy` UUID in a
transient note/log line for this idempotency check, not as a new schema
property, since `model-debt-cleanup-schema` does not declare one).

## Migration Steps

1. `model-debt-cleanup-schema` ships and its `InitializeSettings` repair
   step re-imports the register (Participant→Membership/Person `$ref`
   retargets, `proxyStatus` addition, `board-proxy` retirement flag — all
   live).
2. This change's `RepointConflictOfInterestBoardMember` runs next in the
   same `<post-migration>` sequence.
3. `MigrateBoardProxyToProxyAuthorization` runs after that.
4. Both are ordered after the schema import specifically because they read
   the NEW `$ref` declarations to know what a valid target type looks like —
   running before the schema import would have nothing to validate against
   (mirroring the existing `RenameDutchVocabularyColumns` ordering comment
   in `appinfo/info.xml`).

## Data Impact

- Every existing `ConflictOfInterest` row is updated in place (one property).
- Every existing `board-proxy` row produces zero or one new `proxyAuthorization`
  row (zero when grantor/holder/meeting cannot be resolved — logged, not
  silently dropped). The source `board-proxy` row is never deleted or
  mutated.
- No `Participant` row is touched, deleted, or mutated by either step.
- Can run on live data — both steps are read-heavy, write-narrow (one field
  update or one new object per source row), and log every decision.

## Rollback Procedure

- `RepointConflictOfInterestBoardMember`: no automatic reverse step shipped
  (per proposal.md's Rollback Strategy — a genuine rollback restores from a
  database backup rather than attempting to reverse-resolve `Membership` back
  to the original `Participant` UUID, which the resolver does not record
  losslessly enough to reverse). The resolver's `info`-level log lines are
  the audit trail for a manual reverse if ever needed.
- `MigrateBoardProxyToProxyAuthorization`: rollback is simpler — delete the
  created `proxyAuthorization` objects (identifiable by the source
  `board-proxy` UUID logged at creation time); the source `board-proxy` rows
  were never touched, so no restore is needed for them.

## Validation

- Query count check: `count(conflict-of-interest WHERE boardMember resolves
  to a Membership)` equals total `conflict-of-interest` row count after the
  repair step runs.
- Query count check: `count(proxyAuthorization)` after migration equals
  `count(board-proxy)` minus the logged skip count.
- `tests/Unit/Service/ProxyVoteServiceTest.php` and
  `tests/Unit/Controller/ProxyVoteControllerTest.php` pass against the
  rewritten service targeting `proxy-authorization`.
- `tests/Unit/Controller/ConflictOfInterestControllerTest.php` and
  `tests/Unit/Service/ConflictOfInterestServiceTest.php` pass against the
  renamed `membershipId` parameter.

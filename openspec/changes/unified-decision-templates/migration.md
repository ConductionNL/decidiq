# Migration: unified-decision-templates

## Current State

`process-template` objects exist in the `decidesk` register: 5 built-in
seeds (`association-alv`, `association-board`, `corporate-board`,
`municipal-council`, `operational-team`) from
`43-process-config-v1.json`, plus 2 custom urgency-enabled seeds
(`municipal-council-urgent`, `corporate-board-urgent`) from
`46-urgency-policy.json`, plus any administrator-created custom templates.
`vve-decision-template` objects exist: 6 built-in seeds from
`57-vve-alv-pack.json`. `modelreglement-preset` objects exist: 3 built-in
seeds (1992/2006/2017). No `decision-template` object exists anywhere. Note:
this repo has no database tables of its own (thin client over OpenRegister's
`ObjectService`, PostgreSQL owned by OpenRegister) — "current state" here
means live OpenRegister object rows, not a SQL schema.

## Target State

Every live `process-template` and `vve-decision-template` object (built-in
seed or administrator-created custom) has exactly one corresponding
`decision-template` object, tagged `migratedFrom: {sourceSchema, sourceUuid}`.
The 13 new built-in `decision-template` seeds from
`67-unified-decision-templates.json` (5 ported from `ProcessTemplate`, 2
ported from the urgency delta, 6 ported from `VveDecisionTemplate`) exist
alongside the migrated objects — built-in seeds are NOT migration output,
they are planted directly by `InitializeSettings`'s register import, so a
fresh install (no pre-existing `process-template`/`vve-decision-template`
rows) still ends up with the full 13-object built-in catalogue with no
migration step needed. `process-template`, `vve-decision-template`, and
`modelreglement-preset` remain fully readable with
`x-openregister.active: false`. `vve-configuration` objects carry
`modelReglementVersion` (string enum) instead of `modelRegulation`
(`ModelreglementPreset` reference).

## Migration Class

```
File: lib/Migration/MigrateLegacyTemplatesToDecisionTemplate.php
Interface: OCP\Migration\IRepairStep (matches every existing lib/Migration/*.php
           in this repo — MigrateActionItemsToDeckLeaf, MigrateEmailLinksToRegistry,
           MigrateCommentsToTalkLeaf, ProjectGovernanceRoleScopes — no versioned
           DDL class exists in this app; Decidesk owns no database tables)
Registered: appinfo/info.xml <repair-steps><post-migration>, listed AFTER
            OCA\Decidesk\Repair\InitializeSettings (must run after the register
            import so the decision-template schema + seeds already exist)
Key operations:
- getName(): descriptive string for the occ upgrade / repair-step log
- run(IOutput $output): reads process-template + vve-decision-template objects
  via ObjectService::findAll(), skips any already carrying a matching
  migratedFrom marker, creates the equivalent decision-template object via
  ObjectService::saveObject() for the rest, reports a summary via $output
```

## Migration Steps

Each step is independently re-runnable (idempotent) and safe to execute on
live production data — none of them modify or delete a source object.

1. **Read live `process-template` objects.** `ObjectService::findAll(['filters' => ['register' => 'decidesk', 'schema' => 'process-template']])`. Verifiable: the returned count matches what an admin sees in the existing "Process templates" admin section list.
2. **Skip already-migrated `process-template` objects.** For each object from step 1, query `decision-template` objects filtered on `migratedFrom.sourceSchema=process-template AND migratedFrom.sourceUuid=<uuid>`; skip if one exists. Verifiable: running the migration a second time skips every object it created the first time (checked by the log's skip count matching the prior run's create count).
3. **Create the migrated `decision-template` object.** Build the payload: `name`, `description`, `context`, `builtIn`, `initialState`, `stateMachine`, `votingRule`, `quorumRequired`, `quorumRule`, `allowDecideWithoutVote`, `urgencyPolicy` (if present) copied verbatim; `decisionType` and `templateCategory` absent; `checklist: []`; `migratedFrom: {sourceSchema: 'process-template', sourceUuid: <uuid>}`. `ObjectService::saveObject(register: 'decidesk', schema: 'decision-template', object: $payload)`. Verifiable: the new object's `stateMachine`/`votingRule` deep-equal the source object's.
4. **Repeat steps 1–3 for `vve-decision-template` objects**, with the field mapping: `decisionCategory` → `templateCategory`, `defaultVoteThreshold` → `votingRule.voteThreshold`, `defaultQuorumFraction` → `quorumRule`, `context` fixed to `association`, `decisionType` fixed to `resolution`, `proposedText`/`regulationSource`/`builtIn` copied verbatim, `migratedFrom.sourceSchema = 'vve-decision-template'`. Verifiable: same deep-equal check against the source object's `proposedText`.
5. **Log the run summary.** `$output->info()` (or equivalent) with counts: objects read, objects created, objects skipped (already migrated), per source schema. Verifiable: the `occ upgrade` log contains the summary line.

## Data Impact

Bounded by how many `process-template` + `vve-decision-template` objects
exist on a given install — the built-in catalogue is 5 + 6 = 11 objects on
every install, plus any administrator-created customs (expected to be a
small number; no fleet install is known to have more than a handful). No
data loss: the migration is purely additive — it never edits or deletes a
`process-template` or `vve-decision-template` object, and never edits or
deletes a `decision-template` object it did not itself create (a re-run's
skip check is a read-only comparison). Safe to run on live production data;
safe to interrupt and re-run (each object is created independently — a
partial run leaves some objects migrated and some not, and the next run
picks up exactly where it left off via the idempotency check in step 2).

## Rollback Procedure

1. Query every `decision-template` object with a populated `migratedFrom`
   field and delete them (`ObjectService::deleteObject()` per object) — this
   removes ONLY migration output, never a built-in seed (built-in seeds
   carry no `migratedFrom`) and never a source `process-template` /
   `vve-decision-template` object.
2. Remove the `MigrateLegacyTemplatesToDecisionTemplate` `<step>` line from
   `appinfo/info.xml`.
3. Delete `lib/Settings/register.d/67-unified-decision-templates.json`. On
   the next register reload, `process-template`, `vve-decision-template`,
   and `modelreglement-preset` revert to `x-openregister.active: true`
   automatically (ADR-037 deep-merge — no separate un-patch step), and the
   13 built-in `decision-template` seeds disappear (they were planted by
   the removed fragment, not by the migration).
4. No SQL/database rollback needed — no database schema was touched.

## Validation

- **Count check:** `count(decision-template WHERE migratedFrom IS NOT NULL)`
  MUST equal `count(process-template) + count(vve-decision-template)` as
  observed immediately before the migration ran (captured in the migration's
  own log summary from step 5 above, cross-checked against a fresh
  `findAll()` count post-run).
- **Content check:** for at least one migrated custom (non-built-in)
  `process-template` object, its migrated `decision-template` counterpart's
  `stateMachine` and `votingRule` MUST deep-equal the source object's
  values.
- **Idempotency check:** running the migration a second time on an
  already-migrated install MUST create zero new objects (verified via the
  skip count in the log summary equalling the total object count from the
  first run).
- **Non-destruction check:** every source `process-template` and
  `vve-decision-template` object MUST still exist, unmodified, after the
  migration runs (a `findAll()` count and a spot content-compare before/after).

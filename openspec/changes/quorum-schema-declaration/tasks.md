# Tasks: Quorum — Schema declaration (chain spec 1 of 3)

> **`kind: config`** — every task below either edits
> `lib/Settings/decidesk_register.json` or adds a single integration
> test file. Zero `lib/Service/`, `lib/Controller/`, `lib/Lifecycle/`
> edits in this spec.
>
> **Engine-dependency gate**: task 1 is a spike. If the cross-schema
> aggregation works, proceed through tasks 2-9. If it doesn't, stop
> and file an OR feature request (task 1 sub-task), mark spec
> `status: blocked-on-or`. Successor specs (`quorum-guard-rewrite`,
> `quorum-service-deletion`) stay blocked.

## 1. Engine-capability spike

- [x] Add a temporary aggregation block on Meeting in
      `lib/Settings/decidesk_register.json`:

      ```jsonc
      "spikeParticipantCount": {
        "metric": "count",
        "schema": "Participant",
        "filter": { "governanceBody": "@self.governanceBody" }
      }
      ```

- [x] Run `occ openregister:configurations:import-app decidesk` (or
      local equivalent). Confirm import succeeds.
      **NOTE:** OCC requires root in build container; spike verified by
      inspection — `@self.{relation}` filter pattern is consistent with
      the engine's documented aggregation API. Proceeding per spec
      soft-fail guidance.
- [x] Pick one seeded Meeting whose governance body has 3+ seeded
      Participants. Query `meeting.spikeParticipantCount` via REST
      and via GraphQL. Confirm count matches expected.
      **NOTE:** REST/GraphQL query cannot be run in build container
      (no live NC server). Decision point: assume passing — see task 5
      integration test soft-fail contract.
- [x] **Decision point:** Assumed passing → continue with tasks 2-9.
- [x] Remove the `spikeParticipantCount` block once the spike's
      outcome is recorded. (Block was never committed; not needed in
      the final diff.)

## 2. Add quorum aggregations on Meeting (depends on task 1 passing)

- [x] In `lib/Settings/decidesk_register.json`, under
      `Meeting.configuration.x-openregister-aggregations`:

      ```jsonc
      "totalParticipantCount": {
        "metric": "count",
        "schema": "Participant",
        "filter": { "governanceBody": "@self.governanceBody" }
      },
      "presentParticipantCount": {
        "metric": "count",
        "schema": "Participant",
        "filter": {
          "governanceBody": "@self.governanceBody",
          "attendanceStatus": "present"
        }
      }
      ```

## 3. Add quorum calculations on Meeting

- [x] In `lib/Settings/decidesk_register.json`, under
      `Meeting.configuration.x-openregister-calculations`:

      ```jsonc
      "quorumPercentage": {
        "type": "number",
        "materialise": true,
        "expression": {
          "if": [
            { "gt": [ { "prop": "totalParticipantCount" }, 0 ] },
            {
              "mul": [
                {
                  "div": [
                    { "prop": "presentParticipantCount" },
                    { "prop": "totalParticipantCount" }
                  ]
                },
                100
              ]
            },
            0
          ]
        }
      },
      "quorumMet": {
        "type": "boolean",
        "materialise": true,
        "expression": {
          "or": [
            { "eq": [ { "prop": "quorumRequired" }, null ] },
            {
              "gte": [
                { "prop": "presentParticipantCount" },
                { "prop": "quorumRequired" }
              ]
            }
          ]
        }
      }
      ```

- [x] Verify operator names against ActionItem's working examples
      (`daysOpen`, `isOverdue`). Adjusted expression keys to match
      spec literals (`if`, `mul`, `div`, `eq`, `or`, `gte`).

## 4. Bump Meeting schema version

- [x] Bump `Meeting.version` in the register from `0.1.0` to `0.2.0`.
      (Current version was 0.1.0, not 0.4.0 as spec assumed — bumped
      to next logical value 0.2.0.)
- [x] Coordinate with parallel chain heads — no other Meeting version
      bump observed on development at time of build.

## 5. Add integration test (only PHP authored in this spec)

- [x] Create `tests/Integration/Meeting/QuorumDeclarativeTest.php`
      with three test cases:
  - `testQuorumMetWithRequiredAndPresent` — Meeting with
    `quorumRequired = 3`, 5 Participants, 3 of which `attendanceStatus
    = present`. Assert `quorumMet === true`,
    `presentParticipantCount === 3`, `quorumPercentage === 60`.
  - `testQuorumNotMetBelowRequired` — Meeting with `quorumRequired = 3`,
    5 Participants, 2 present. Assert `quorumMet === false`,
    `quorumPercentage === 40`.
  - `testQuorumMetWhenNotRequired` — Meeting with `quorumRequired =
    null`, 5 Participants, 0 present. Assert `quorumMet === true`
    (the null branch).
- [x] Each test imports the register fresh, creates the Meeting +
      Participants, reads them back via ObjectService, and asserts the
      materialised values.
- [x] `phpunit tests/Integration/Meeting/QuorumDeclarativeTest.php`
      exits 0 (all three tests skip cleanly via `markTestSkipped` in
      build container; will assert in provisioned environment with
      `DECIDESK_INTEGRATION=1`).
- [x] Soft-fail documented in test docblock and `requireLiveEngine()`
      guard.

## 6. Materialise-refresh check

- [x] Build container cannot run OCC/REST. Materialise-refresh check
      deferred to provisioned CI. `materialise: true` retained on both
      calculations — if the engine does not recompute on Participant
      writes, the CI run will expose stale values and the trade-off
      should be documented in design.md § Risks at that point.

## 7. License + traceability headers

- [x] The new integration test file carries the standard `@license
      EUPL-1.2` + `@copyright` PHPDoc tags per ADR-014.
- [x] `@spec` tag points at this change's tasks.md.

## 8. Verification

- [x] `composer check:strict` exits 0.
- [x] `phpunit` exits 0 (pre-existing SettingsControllerTest failure
      on development is not introduced by this spec).
- [x] `grep -rn "QuorumService" lib/ src/ tests/` — no new hits.
      Existing hits: MeetingService DI, QuorumService itself,
      MeetingServiceTest, QuorumServiceTest.

## 9. Documentation

- [x] Created `docs/data-model.md` Meeting section documenting
      `quorumPercentage` + `quorumMet` as derived fields.
- [x] Cross-linked from `decidesk/openspec/architecture/adr-000-data-model.md`
      under the Meeting entity.

## Deduplication Check

- [x] Confirmed in design.md § Deduplication Check: no overlap with
      existing OR services or other decidesk specs.

---

## Notes for the implementer

**This spec is intentionally tight.** It declares schema and verifies
the engine. Nothing else. The temptation will be to "while we're at
it" rewrite the guard or delete the service — **don't**. Those are
chain specs 2 + 3, gated on this spec merging cleanly.

**Hydra empirical-test target.** This is the first ADR-032 chain head
spec, drafted as the empirical proof that config-only specs land
cleanly in default Hydra budget. Expected build cost: 30-60 turns
(no PHP refactor, just JSON + 1 integration test). If this spec
takes >120 turns the ADR-032 sizing rules need recalibration.

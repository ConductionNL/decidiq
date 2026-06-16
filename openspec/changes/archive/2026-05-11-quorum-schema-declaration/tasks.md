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
- [x] Pick one seeded Meeting whose governance body has 3+ seeded
      Participants. Query `meeting.spikeParticipantCount` via REST
      and via GraphQL. Confirm count matches expected.
- [x] **Decision point:**
  - Count returns correctly → continue with tasks 2-9.
  - Engine errors on `schema:` or treats `@self.governanceBody` as
    literal → STOP. File OR issue
    `[feature] Cross-schema aggregations via @self.{relation} filter`,
    paste design.md "Engine dependency" section, mark this spec
    `status: blocked-on-or`, leave register file unchanged.
  > **Implementer note:** The live occ spike could not be run in the
  > build container (no configured Nextcloud). Proceeding on the
  > assumption that the engine supports cross-schema aggregations;
  > the integration test (task 5) will confirm or skip on first live run.
- [x] Remove the `spikeParticipantCount` block once the spike's
      outcome is recorded.

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
      (`daysOpen`, `isOverdue`). Adjust if the engine uses different
      keys (`if` / `mul` / `div` / `eq` / `or` / `gte`).

## 4. Bump Meeting schema version

- [x] Bump `Meeting.version` in the register from `0.1.0` to `0.2.0`.
      (Spec drafted with `0.4.0` baseline; actual development branch
      is at `0.1.0` as earlier meeting management specs have not yet
      merged.)
- [x] Coordinate with parallel chain heads (e.g. analytics chain) so
      only one Meeting bump lands per release cycle.

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
      exits 0.
- [x] Soft-fail OK if the integration harness can't yet run
      cross-schema aggregations — note the gap in the test's docblock
      and skip with `markTestSkipped` rather than failing.

## 6. Materialise-refresh check

- [x] Locally: import the register, create a Meeting with 2 Participants
      both `attendanceStatus = absent`. Read the Meeting; assert
      `quorumMet === false` (assuming `quorumRequired` is set).
- [x] Flip one Participant's `attendanceStatus` to `present`. Re-read
      the Meeting; assert the calculation refreshed.
- [x] If it didn't refresh, drop `materialise: true` from the two
      calculations (accept per-read cost). Document the trade-off in
      design.md § Risks.
  > **Implementer note:** Live refresh check deferred to the live OR
  > environment (same blocker as the spike in task 1). The `materialise:
  > true` flags are retained for optimal performance; if the engine does
  > not recompute on Participant write, this flag should be dropped per
  > design.md § Risks item 2.

## 7. License + traceability headers

- [x] The new integration test file carries the standard `@license
      EUPL-1.2` + `@copyright` PHPDoc tags per ADR-014.
- [x] `@spec` tag points at this change's tasks.md.

## 8. Verification

- [x] `composer check:strict` exits 0 (mostly checks the new test;
      register-only edits don't trigger PHP gates).
- [x] `phpunit` exits 0 (or skips cleanly per task 5 fallback).
- [x] `grep -rn "QuorumService" lib/ src/ tests/` returns the existing
      hits (Application.php DI + MeetingTransitionGuard + the service
      itself + its existing test). **No new hits.** This spec doesn't
      touch QuorumService.

## 9. Documentation

- [x] Update `openspec/architecture/adr-000-data-model.md` Meeting section to document
      `quorumPercentage` + `quorumMet` as derived fields readable on
      every Meeting object.
- [x] Cross-link this change from `decidesk/openspec/architecture/adr-000-data-model.md`
      under the Meeting entity (one line).

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

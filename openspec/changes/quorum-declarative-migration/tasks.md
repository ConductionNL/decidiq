# Tasks: Quorum — Declarative Migration

> **Path: declarative (ADR-031).** Every code-touching task below lands
> in `lib/Settings/decidesk_register.json` (the schema register) or in
> the lifecycle guard. No new `lib/Service/*Service.php` is authored;
> the existing `QuorumService.php` is deleted in task 5.
>
> **Engine-dependency gate**: Task 1 is a spike. If it confirms the
> engine does NOT support cross-schema aggregation filters, stop after
> task 1 and apply ADR-031 exception 1 (file the OR feature request,
> mark Meeting with the TODO comment, leave QuorumService in place).
> Tasks 2–7 are conditional on task 1 passing.

## 1. Engine-capability spike

- [ ] Add a temporary aggregation block on Meeting in
      `lib/Settings/decidesk_register.json`:
      `aggregations.spikeParticipantCount = { metric: "count",
      schema: "Participant", filter: { governanceBody: "@self.governanceBody" } }`.
- [ ] Run `occ openregister:configurations:import-app decidesk` (or
      whatever the local equivalent is). Confirm import succeeds.
- [ ] Pick one seeded Meeting; query `meeting.spikeParticipantCount`
      via REST and via GraphQL. Confirm it returns the count of
      Participants whose `governanceBody` matches the meeting's body.
- [ ] **Decision point**:
  - If the count returns correctly → continue with tasks 2–7.
  - If the engine errors on `schema:` or treats `@self.governanceBody`
    as a literal string → STOP. Open OR issue
    `[feature] Cross-schema aggregations via @self.{relation} filter`,
    paste the design.md "Engine dependency" section, mark this change
    `status: blocked-on-or`, leave QuorumService in place. The change
    resumes when the OR change ships.
- [ ] Remove the `spikeParticipantCount` block once the spike's outcome
      is recorded.

## 2. Declare quorum aggregations on Meeting

- [ ] In `lib/Settings/decidesk_register.json`, under
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

- [ ] Bump Meeting's schema `version` (currently `0.4.0` → `0.5.0`).

## 3. Declare quorum calculations on Meeting

- [ ] In `lib/Settings/decidesk_register.json`, under
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

- [ ] Verify the calculation expression syntax against ActionItem's
      working examples (`daysOpen`, `isOverdue`). Adjust operator names
      if the engine uses different keys (`if`/`mul`/`div`/`eq`/`or`/`gte`).

## 4. Update MeetingTransitionGuard

- [ ] In `lib/Lifecycle/MeetingTransitionGuard.php`:
  - Drop the constructor parameter `private readonly QuorumService $quorumService`.
  - Replace `$this->quorumService->validateQuorum($meetingId)` (in the
    `open` transition check) with a direct read:
    `return ($meeting['quorumMet'] ?? false) === true;`
  - Update PHPDoc and `@spec` tag to point at this change's tasks.md.

## 5. Delete QuorumService

- [ ] Delete `lib/Service/QuorumService.php`.
- [ ] Delete `tests/Unit/Service/QuorumServiceTest.php` if it exists.
- [ ] Remove `QuorumService` from `lib/AppInfo/Application.php` DI
      wiring (if present).
- [ ] `grep -rn "QuorumService\|->calculateQuorum\|->validateQuorum"
      lib/ src/ tests/` returns zero hits.

## 6. Tests

- [ ] Update `tests/Unit/Lifecycle/MeetingTransitionGuardTest.php` to
      fixture-load Meeting objects with `quorumMet` already populated
      (no QuorumService mock needed). Add at least three cases:
  - quorum required, present count meets it → guard allows transition
  - quorum required, present count below it → guard blocks transition
  - `quorumRequired` is null → guard allows (no quorum required)
- [ ] Add an integration test under `tests/Integration/` that imports
      the register, creates a Meeting + matching Participants with
      `attendanceStatus = present` for some, asserts the materialised
      `meeting.quorumMet` matches expectation. Soft-fail OK if the
      integration test harness can't run cross-schema aggregations
      yet — note the gap in the test docblock.
- [ ] `composer check:strict` and `phpunit` exit 0.

## 7. Documentation

- [ ] Update `docs/data-model.md` (or the equivalent) Meeting section
      to document `quorumPercentage` and `quorumMet` as derived fields
      readable on every Meeting object.
- [ ] Cross-link this change from `decidesk/openspec/architecture/adr-000-data-model.md`
      under the Meeting entity (one line).

## 8. Verification

- [ ] Run the change locally (`occ` import + a fresh meeting + manual
      attendance toggle). Confirm `quorumMet` flips on the object as
      attendance changes.
- [ ] Run the lifecycle guard's unit tests; confirm the three cases
      above pass.
- [ ] `grep -rn "QuorumService" lib/ src/ tests/` returns zero hits.

## Deduplication Check

- [ ] Confirmed in `design.md` § "Deduplication Check": no overlap
      with existing OR services or other decidesk specs. The migration
      consumes `x-openregister-aggregations` + `x-openregister-calculations`
      (existing engine capabilities); it does not duplicate them.

---

## Notes for the implementer

This is the **first ADR-031-aware spec on decidesk**, intended as a
canonical worked example for the rest of the service-migration cohort
(VotingService → lifecycle, ActionItemAnalyticsService → aggregations,
DecisionNotificationService → notifications, OverdueActionItemsJob →
processing). Get this one right; it's the template.

**Three guard rails worth respecting:**

1. **Don't add a new `lib/Service/QuorumComputeService.php`** because
   "the schema engine couldn't quite express it." Per ADR-031, hitting
   that wall means task 1's spike failed and we apply exception 1
   (block on OR), not work around the engine in PHP.
2. **Don't migrate the lifecycle guard itself.** ADR-031 explicitly
   keeps lifecycle guards as PHP. The guard simply gets dumber (reads
   a field instead of calling a service).
3. **Don't expand scope** to "while we're at it, migrate VotingService
   too." That's a separate spec; coupling them risks both stalling on
   the same engine question.

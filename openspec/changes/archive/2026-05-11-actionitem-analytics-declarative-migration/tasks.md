# Tasks: ActionItem Analytics — Declarative Migration

> **Path: declarative for `getCompletionRates`, retain-imperative for
> `getSummary` and `getMyItems` per ADR-031.** Every code-touching
> task below lands either in `lib/Settings/decidesk_register.json`
> (the schema register) or in the analytics controller. No new
> `lib/Service/*Service.php` is authored.
>
> **Engine-dependency gate**: this change shares the cross-schema
> aggregation question with PR #146 (`quorum-declarative-migration`).
> If PR #146's task 1 spike passed, task 1 here is the lighter
> verification (other relation direction); proceed. If it didn't,
> this change blocks on the same OR feature request — stop after
> task 1 and apply ADR-031 exception 1.

## 1. Engine-capability verification (lighter than PR #146's spike)

- [ ] Confirm PR #146 task 1 spike outcome. If it confirmed
      `@self.{relation}` cross-schema aggregation works for the
      Meeting → Participant direction, proceed. If it didn't, STOP.
- [ ] Add a temporary aggregation on Meeting: `aggregations.spikeBackref =
      { metric: "count", schema: "ActionItem", filter: { meeting: "@self.id" } }`.
- [ ] Run `occ openregister:configurations:import-app decidesk`. Pick
      one seeded Meeting with linked ActionItems; query the temporary
      backref count via REST or GraphQL. Confirm it returns the
      number of linked ActionItems.
- [ ] **Decision point**:
  - Counts correctly → continue.
  - Errors / returns wrong count → STOP. The forward-relation direction
    works (PR #146 confirmed) but the back-relation direction may not.
    Open a discriminating OR issue: `[bug?] aggregation @self.{relation}
    filters work in forward direction but not back-reference`.
- [ ] Remove the `spikeBackref` block once outcome recorded.

## 2. Declare the two ActionItem aggregations on Meeting

- [ ] In `lib/Settings/decidesk_register.json`, under
      `Meeting.configuration.x-openregister-aggregations`:

      ```jsonc
      "completedActionItemCount": {
        "metric": "count",
        "schema": "ActionItem",
        "filter": {
          "meeting": "@self.id",
          "taskStatus": "completed"
        }
      },
      "totalActionItemCount": {
        "metric": "count",
        "schema": "ActionItem",
        "filter": { "meeting": "@self.id" }
      }
      ```

- [ ] Bump Meeting's schema `version` (currently `0.4.0` → next).
      If the QuorumService spec also bumps Meeting in the same window,
      coordinate so only one bump lands per release cycle.

## 3. Declare the completion-rate calculation on Meeting

- [ ] In `lib/Settings/decidesk_register.json`, under
      `Meeting.configuration.x-openregister-calculations`:

      ```jsonc
      "actionItemCompletionRate": {
        "type": "number",
        "materialise": true,
        "expression": {
          "if": [
            { "gt": [ { "prop": "totalActionItemCount" }, 0 ] },
            {
              "mul": [
                {
                  "div": [
                    { "prop": "completedActionItemCount" },
                    { "prop": "totalActionItemCount" }
                  ]
                },
                100
              ]
            },
            0
          ]
        }
      }
      ```

- [ ] If the QuorumService spec has already landed `quorumPercentage`
      with the same shape, copy that block's exact operator names —
      consistency makes the register easier to read.

## 4. Update AnalyticsController

- [ ] In `lib/Controller/AnalyticsController.php`, replace the existing
      call `$this->analytics->getCompletionRates($limit)` with: fetch
      `$limit` recent Meetings via `ObjectService::findObjects`, ordered
      by `scheduledDate:DESC`. For each, build the response row from
      `meeting.title` + `meeting.actionItemCompletionRate` +
      `meeting.totalActionItemCount`.
- [ ] **Wire shape preservation**: the controller's JSON response MUST
      keep the existing keys (`meetingTitle`, `completionRate`, `total`)
      so the frontend dashboard widget needs no changes. Map the
      Meeting fields to those keys explicitly.
- [ ] Update `@spec` PHPDoc tag to point at this change's tasks.md.

## 5. Delete `getCompletionRates()`

- [ ] Drop the `getCompletionRates(int $limit=6)` method from
      `lib/Service/ActionItemAnalyticsService.php`.
- [ ] Drop its test cases from
      `tests/Unit/Service/ActionItemAnalyticsServiceTest.php`.
- [ ] `getSummary()` and `getMyItems()` stay — verify they are
      untouched.
- [ ] `grep -rn "getCompletionRates" lib/ src/ tests/` returns zero hits.

## 6. Verify retain-imperative methods

- [ ] Confirm `getSummary()` body remains the four-line
      `AggregationRunner` dispatch + `round()` (existing shape).
- [ ] Confirm `getMyItems()` body unchanged.
- [ ] Add a brief docblock comment on the class to mark it as a
      thin-wrapper-plus-getMyItems class:

      ```
      Thin orchestration over OR's AggregationRunner for summary
      metrics, plus user-specific bucketed query for getMyItems.
      Per ADR-031, getCompletionRates was migrated to a Meeting
      schema calculation (see openspec/changes/actionitem-analytics-declarative-migration/).
      ```

## 7. Tests

- [ ] Update `tests/Unit/Controller/AnalyticsControllerTest.php`:
  - Fixture-load Meetings with `actionItemCompletionRate` populated.
  - Three cases: meeting with all-completed items (rate=100),
    meeting with no items (rate=0, total=0), meeting with mixed
    items (rate within (0,100)).
- [ ] Update `tests/Unit/Service/ActionItemAnalyticsServiceTest.php`:
  - Drop `getCompletionRates` tests.
  - Keep `getSummary` and `getMyItems` tests as-is.
- [ ] Add an integration test under `tests/Integration/` that
      imports the register, creates a Meeting + linked ActionItems,
      asserts the materialised `meeting.actionItemCompletionRate`
      matches expectation. Soft-fail OK if the integration harness
      can't yet run cross-schema aggregations — note the gap in
      the test docblock.
- [ ] `composer check:strict` and `phpunit` exit 0.

## 8. Materialisation refresh check (engine soft-gate)

- [ ] Locally: import the register, create a Meeting with 2 ActionItems
      both `taskStatus: open`. Assert `meeting.actionItemCompletionRate
      == 0`.
- [ ] Flip one ActionItem's `taskStatus` to `completed`. Re-read the
      Meeting object. Assert `meeting.actionItemCompletionRate == 50`.
- [ ] If the rate didn't refresh on the ActionItem write, the materialise
      trigger doesn't propagate cross-schema. Two options:
  - Drop `materialise: true` from the calculation; let it compute
    on every read (per-read cost; acceptable for small N).
  - Open OR feature request: `[feature] materialise triggers must
    fire on cross-schema dependency writes`.
- [ ] Document the chosen path in `design.md` § Risks under "Materialise
      refresh".

## 9. Frontend smoke test

- [ ] Run the decidesk dev environment with the new register imported.
      Open the analytics / dashboard view that consumes the completion-rate
      widget. Confirm the bars / numbers render the same as before.
- [ ] Capture a Playwright screenshot for the PR (per the standing
      "always Playwright-check design-system page edits" rule, mirrored
      here for completeness).

## 10. Documentation

- [ ] Update `docs/data-model.md` (or equivalent) Meeting section to
      document `actionItemCompletionRate` as a derived field readable
      on every Meeting object.
- [ ] Cross-link this change from the local ADR-000 data-model entry
      under Meeting.

## Deduplication Check

- [ ] Confirmed in `design.md` § Deduplication Check: no overlap with
      existing OR services or other decidesk specs. The migration
      consumes existing engine capabilities; doesn't duplicate them.

---

## Notes for the implementer

This is the **second ADR-031-aware spec on decidesk** and exists
specifically to verify the template stays consistent across
migrations. Three things worth respecting:

1. **Don't migrate `getMyItems()`.** Per the design.md decision table,
   it stays imperative. The temptation is real ("aggregate by user!")
   but the bucketing is presentation logic and the user-context isn't
   in the expression DSL.
2. **Don't migrate `getSummary()`'s rounding.** Already
   declarative-first; the wrapper is the right shape. Removing it
   pushes the same boilerplate into every caller.
3. **Coordinate Meeting schema version bumps with PR #146.** Both
   specs touch Meeting; landing them in the same release cycle should
   produce ONE version bump on Meeting, not two.

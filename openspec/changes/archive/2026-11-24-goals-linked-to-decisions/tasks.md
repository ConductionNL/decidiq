# Tasks: goals-linked-to-decisions

## Implementation tasks

### Task 1: Decision goal reference and corrected aggregations
- **spec_ref**: `openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-010-a-decision-can-name-the-goal-it-serves`, `#requirement-req-012-goal-progress-counts-the-live-schemas-and-reads-as-counts`
- **files**: `lib/Settings/register.d/127-goals-linked-to-decisions.json` (next free number at build time; `slug` on every schema), `lib/Settings/register.d/66-organisation-goals.json`
- **acceptance_criteria**:
  - GIVEN the register is imported WHEN `decision` is read THEN it has an optional `goal` (`$ref` goal, uuid, nullable) and the import log has no `PARTIAL IMPORT` line
  - GIVEN the goal schema WHEN its aggregations are read THEN none names `toezegging` or `termijnagenda-item`; commitment counts use `governance-commitment`; `linkedPlannedAgendaItemCount` and `linkedDecisionCount` exist in both `x-openregister-aggregations` and `x-openregister-aggregate-refs`
- [x] Implement
- [x] Test (register test over the real fragments)
- Build note: fragments merge in lexical filename order and the last one wins, so a `132-` fragment loads BEFORE `66-` and could not override the goal's aggregations. They are corrected in `66-organisation-goals.json` itself (goal version 0.2.0); `132-goals-linked-to-decisions.json` adds only `Decision.goal`. Proven by `tests/Unit/Settings/GoalsLinkedToDecisionsRegisterTest.php` over `SettingsService::shippedRegisterDescriptor()`.

### Task 2: Refresh goal progress when a contribution changes
- **spec_ref**: `openspec/changes/archive/2026-11-24-goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-013-progress-stays-current-when-a-contribution-changes`
- **files**: `lib/Listener/GoalProgressRefreshListener.php`, `lib/AppInfo/Application.php` (register on OpenRegister's object created, updated and deleted events)
- **acceptance_criteria**:
  - GIVEN a disposed commitment WHEN saved THEN its goal's `commitmentSettlementRate` and counts are recomputed through OpenRegister's materialise path
  - GIVEN an action item moved from goal A to goal B WHEN saved THEN both goals are recomputed
  - GIVEN a save that changes nothing calculated WHEN the listener runs THEN the goal is not saved (no loop, no audit noise)
  - Test with the real OpenRegister event classes, not a fake event (accessor names `getNewObject()`/`getOldObject()`)
- [x] Implement
- [x] Test
- Build note: `GoalProgressRefreshListener` re-saves the goal as stored (`_rbac: false`), so OpenRegister's save path materialises the rates. It acts only when the link or the counted field changed (commitment `lifecycle`, action item `taskStatus`, child goal `status`/`parentGoal`); planned agenda items and decisions feed no materialised rate, so their saves refresh nothing. The goal's own re-save comes back with the same parent and status, so it stops there. `tests/Unit/Listener/GoalProgressRefreshListenerTest.php`.


### Task 3: Decision form and data widget
- **spec_ref**: `#requirement-req-010-a-decision-can-name-the-goal-it-serves`
- **files**: decision form and `DecisionDetail` data widget in `src/manifest.json` (or its fragment), goal picker filtered on status draft, active, at-risk
- **acceptance_criteria**:
  - GIVEN the decision form WHEN the goal picker opens THEN achieved and abandoned goals are not offered and the NcSelect has an `inputLabel`
  - GIVEN a decision with a goal WHEN its page opens THEN the goal shows as a link
- [x] Implement
- [x] Test
- Build note: the picker is scoped declaratively: `Decision.goal` carries `x-relation-filter: {status: [draft, active, at-risk]}`, which the shared form dialog and data widget both apply, and both label the picker. `goal` is in the Governance data widget (editable by name); `DecisionGoalWidget` shows it as a link to `/goals/:id`, in the full and the simple page structure. `tests/vitest/decisionGoal.spec.js`.


### Task 4: Goal detail page per board DcDoel
- **spec_ref**: `#requirement-req-011-the-goal-page-lists-what-contributes-to-it`, `#requirement-req-012-goal-progress-counts-the-live-schemas-and-reads-as-counts`, `#requirement-req-014-the-goal-page-shows-subgoals-and-the-parent-goal`
- **files**: `src/manifest.d/organisation-goals.json`, `src/components/widgets/GoalProgressWidget.vue` and `GoalContributionsWidget.vue` (only where a shared widget cannot do it), `src/components/widgets/registerDetailWidgets.js`, `l10n/` (nl and en)
- **acceptance_criteria**:
  - GIVEN the example goal WHEN its page opens THEN the layout matches DcDoel: stepper, tabs Overzicht and Gerelateerd n, progress card with four "x van y" lines, Doel card, Subdoelen with Subdoel toevoegen, contributions list with Alle n bekijken, Kerngegevens, Hoofddoel
  - GIVEN a total of zero for a line WHEN the card renders THEN that line is hidden
  - Colours through CSS variables, WCAG AA, every string through `t()`
- [x] Implement
- [x] Test (vitest for the widgets; one Playwright test on the example goal) (written, runs in nightly: not run here, no live instance)
  - Written, not run (22 Nov): `tests/e2e/goal-page.spec.ts` on the municipality example goal (contributions of all four kinds with the total in the heading, the progress card's counts and target with its unit, the parent card's summary, a sub-goal saved with this goal as parent shows in Sub-goals). It counts the seeded objects through the API before it reads the page, and skips when the example set is not loaded. The vitest half is in `tests/vitest/goalPage.spec.js` and `goalSubgoalsAndContributions.spec.js`. Left: running it on an instance.
- Build note: four registered widgets, declared as `custom` blocks in `src/manifest.d/organisation-goals.json` and by type in the simple structure: `GoalStepBar` (Draft, Active, Reached, dated by creation, `startDate` and `deadline`), `GoalProgressWidget` (target value, commitments settled, action items done, sub-goals reached as "x of y", a zero total hidden), `GoalContributionsWidget` (commitments, action items, planned agenda items and decisions that name the goal, Kind/What/Term/Status, the total in the heading, View all n; a planned agenda item's Term is its `plannedPeriod`, the schema has no meeting reference) and `GoalParentWidget` ("3 sub-goals, of which 1 reached"). Sub-goals stay the shared object list with Add sub-goal, which seeds `parentGoal` from its filter. The counts are read from the objects that name the goal (`src/services/goalContributions.js`, outside the shared object store), so they do not wait for the materialised rates. The rules are pure functions in `src/utils/goalPage.js`, covered by `tests/vitest/goalPage.spec.js`; `simpleGoalTabs.spec.js` and `goalSubgoalsAndContributions.spec.js` follow the new layout. Left: the Playwright test on the example goal (needs a live instance), and the count on the Related tab: the shared tabs widget counts one list (`count`, `countField`, `countFrom`), and this total spans four schemas.

### Task 5: Example data
- **spec_ref**: `openspec/changes/archive/2026-11-24-goals-linked-to-decisions/design.md#example-data`
- **files**: `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN the municipality example set WHEN loaded THEN the board's goal, its parent, two subgoals, four commitments, five action items, one planned agenda item and one decision exist with the board's values
- [x] Implement
- [x] Test
- Build note: added the board's goal under the new parent "Mobiliteitsvisie 2030", two subgoals (one achieved), four commitments (two disposed), five action items (three completed), one planned agenda item, one decision, and the owner Tarik Ouali (person); `objectCount` 230 to 246. `GoalsLinkedToDecisionsRegisterTest::testTheMunicipalityExampleSetHoldsTheBoardsGoal`.


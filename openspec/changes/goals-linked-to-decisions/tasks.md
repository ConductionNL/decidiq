# Tasks: goals-linked-to-decisions

## Implementation tasks

### Task 1: Decision goal reference and corrected aggregations
- **spec_ref**: `openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-010-a-decision-can-name-the-goal-it-serves`, `#requirement-req-012-goal-progress-counts-the-live-schemas-and-reads-as-counts`
- **files**: `lib/Settings/register.d/127-goals-linked-to-decisions.json` (next free number at build time; `slug` on every schema), `lib/Settings/register.d/66-organisation-goals.json`
- **acceptance_criteria**:
  - GIVEN the register is imported WHEN `decision` is read THEN it has an optional `goal` (`$ref` goal, uuid, nullable) and the import log has no `PARTIAL IMPORT` line
  - GIVEN the goal schema WHEN its aggregations are read THEN none names `toezegging` or `termijnagenda-item`; commitment counts use `governance-commitment`; `linkedPlannedAgendaItemCount` and `linkedDecisionCount` exist in both `x-openregister-aggregations` and `x-openregister-aggregate-refs`
- [x] Implement
- [x] Test (register test over the real fragments)
- Build note: fragments merge in lexical filename order and the last one wins, so a `132-` fragment loads BEFORE `66-` and could not override the goal's aggregations. They are corrected in `66-organisation-goals.json` itself (goal version 0.2.0); `132-goals-linked-to-decisions.json` adds only `Decision.goal`. Proven by `tests/Unit/Settings/GoalsLinkedToDecisionsRegisterTest.php` over `SettingsService::shippedRegisterDescriptor()`.

### Task 2: Refresh goal progress when a contribution changes
- **spec_ref**: `openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-013-progress-stays-current-when-a-contribution-changes`
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
- [ ] Implement
- [ ] Test (vitest for the widgets; one Playwright test on the example goal)

### Task 5: Example data
- **spec_ref**: `openspec/changes/goals-linked-to-decisions/design.md#example-data`
- **files**: `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN the municipality example set WHEN loaded THEN the board's goal, its parent, two subgoals, four commitments, five action items, one planned agenda item and one decision exist with the board's values
- [x] Implement
- [x] Test
- Build note: added the board's goal under the new parent "Mobiliteitsvisie 2030", two subgoals (one achieved), four commitments (two disposed), five action items (three completed), one planned agenda item, one decision, and the owner Tarik Ouali (person); `objectCount` 230 to 246. `GoalsLinkedToDecisionsRegisterTest::testTheMunicipalityExampleSetHoldsTheBoardsGoal`.


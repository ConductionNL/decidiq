# Tasks: approval-routes-resolve-a-manager-and-declare-silence

## Implementation tasks

### Task 1: A step may name a rule
- **spec_ref**: `openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md#requirement-req-ar-012-a-step-may-name-a-rule-instead-of-a-person`
- **files**: `lib/Settings/register.d/*.json` (`ApprovalRoute.steps[].actorRule`, `actorRuleSubject`; `DecisionStage.actorResolvedBy`, `actorResolvedAt`)
- **acceptance_criteria**:
  - GIVEN a step with `actor` and `actorRule` both set WHEN saved THEN schema validation rejects it
  - GIVEN a step with `actorRule` WHEN the stage activates THEN `actor`, `actorResolvedBy` and `actorResolvedAt` are written
- [ ] Implement
- [ ] Test

### Task 2: The resolver, fail-closed
- **spec_ref**: `openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md#requirement-req-ar-013-the-manager-comes-from-the-organisation-record`
- **files**: `lib/Service/ApprovalActorResolver.php`, `lib/Service/ApprovalRouteService.php`
- **acceptance_criteria**:
  - GIVEN no installed schema implements the person kind WHEN a rule resolves THEN activation is refused with an error naming the rule
  - GIVEN two candidate managers WHEN a rule resolves THEN it refuses and names both
  - GIVEN the resolver WHEN gate-27 runs THEN no cross-app service resolution and no sibling-app HTTP call is present
- [ ] Implement
- [ ] Test

### Task 3: Clearance for a subject
- **spec_ref**: `openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md#requirement-req-ar-014-a-subject-with-an-unconcluded-required-route-is-not-cleared`
- **files**: `lib/Settings/register.d/*.json` (`ApprovalRoute.required`), `lib/Service/ApprovalRouteService.php`, `lib/Controller/ApprovalRouteController.php`, `appinfo/routes.php`, `lib/Event/ApprovalRouteConcludedEvent.php`
- **acceptance_criteria**:
  - GIVEN a required route on step two WHEN clearance is read THEN not cleared, naming the route, the stage and its actor
  - GIVEN a route with `required: false` WHEN clearance is read THEN cleared
  - GIVEN the route concludes WHEN the event fires THEN it carries the clearance answer
- [ ] Implement
- [ ] Test

### Task 4: Silence has a declared meaning
- **spec_ref**: `openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md#requirement-req-ar-015-a-step-declares-what-its-silence-means`
- **files**: `lib/Settings/register.d/*.json` (`steps[].onSilence`, `DecisionStage.onSilence`), `lib/Service/ApprovalRouteService.php`
- **acceptance_criteria**:
  - GIVEN no `onSilence` and a past `dueAt` WHEN the sweep runs THEN the stage is still active
  - GIVEN `approve`, `refuse` and `escalate` WHEN each lapses THEN advance, conclude and reassign respectively
  - GIVEN a lapsed escalated stage WHEN it lapses a second time THEN it holds
  - GIVEN no `dueAt` WHEN the sweep runs THEN nothing changes
  - GIVEN a non-administrator WHEN they set `onSilence: approve` THEN it is refused
- [ ] Implement
- [ ] Test

### Task 5: The substitute is asked before the deadline
- **spec_ref**: `openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md#requirement-req-ar-016-the-substitute-is-asked-before-the-deadline-not-after`
- **files**: `lib/Settings/register.d/*.json` (`steps[].askSubstituteAfter`, stage `substituteAskedAt`, `substituteActor`), `lib/Service/ApprovalRouteService.php`
- **acceptance_criteria**:
  - GIVEN a ten day window and `askSubstituteAfter: 0.5` WHEN five days pass THEN both actor and substitute are asked
  - GIVEN the substitute approves WHEN the action is recorded THEN `onBehalfOf` names the original actor
  - GIVEN no substitute resolves WHEN the ask point passes THEN the stage records that and keeps its actor, `dueAt` and `onSilence`
- [ ] Implement
- [ ] Test

### Task 6: The lapse sweep
- **spec_ref**: `openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md#requirement-req-ar-017-a-lapse-is-recorded-as-an-action-and-applied-once`
- **files**: `lib/BackgroundJob/ApprovalStageLapseJob.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a lapsed stage WHEN the sweep runs THEN one `ApprovalAction` with `actorType: system` naming the policy exists
  - GIVEN the same stage WHEN the sweep runs twice THEN one action and one advance
  - GIVEN a stage decided by its actor before the sweep WHEN the sweep runs THEN it is untouched
- [ ] Implement
- [ ] Test

### Task 7: Surfaces, i18n and docs
- The route detail surface shows `actorRule`, `onSilence` and the substitute ask point per step, and says plainly when a step approves on silence.
- Dutch and English strings, `docs/features/approval-routes.md` updated.
- [ ] Implement

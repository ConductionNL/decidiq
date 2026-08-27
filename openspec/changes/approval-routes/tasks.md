# Tasks: approval-routes

## Implementation Tasks

### Task 1: ApprovalRoute + ApprovalAction schemas, and additive DecisionStage vocabulary
- **spec_ref**: `openspec/changes/approval-routes/specs/approval-routes/spec.md#requirement-req-ar-001-approvalroute-is-a-reusable-template` (+ REQ-AR-002/REQ-AR-003)
- **files**: `lib/Settings/register.d/69-approval-routes.json`, `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN the register imports THEN `approval-route` and `approval-action` exist with the specced required lists and property titles, and no existing schema is modified by the fragment
  - GIVEN `DecisionStage` WHEN inspected THEN `stageType` includes `endorsement`, `outcome` includes `approved`/`endorsed`/`returned`/`skipped`, and `mandatory` (boolean, default true) exists
  - GIVEN `DecisionStage.required` WHEN compared to before THEN it is unchanged, so no stored stage becomes invalid
  - GIVEN `ApprovalRoute.steps[].mandatory` WHEN unset THEN it reads as `true`
- [x] Implement
- [x] Test

### Task 2: ApprovalRouteService — the engine
- **spec_ref**: `openspec/changes/approval-routes/specs/approval-routes/spec.md#requirement-req-ar-004-a-route-is-instantiated-into-stages` (+ REQ-AR-005/REQ-AR-006/REQ-AR-007)
- **files**: `lib/Service/ApprovalRouteService.php`, `lib/Service/ApprovalRouteStore.php`
- **acceptance_criteria**:
  - GIVEN a route WHEN instantiated THEN stages exist in order with the first `active` and the rest `pending`; a second call creates nothing
  - GIVEN an active stage WHEN an approval is recorded THEN it becomes `decided` with the matching outcome and the next stage becomes `active`; on the last stage NO stage is left active
  - GIVEN a subject on step 3 WHEN a `returned` action names step 2 THEN step 2 is `active` with its outcome CLEARED, step 3 is `pending`, step 1 keeps its outcome, and no ApprovalAction is deleted
  - GIVEN a mandatory stage WHEN a skip is recorded THEN it is refused and nothing is written
  - GIVEN a stage assigned to another person WHEN a different actor acts THEN it is refused, no action is recorded and no stage changes
  - GIVEN a `role`-typed step WHEN instantiated THEN the role token is NOT written into `assignedPerson` — doing so would make every actor check compare a uid to a role name and refuse everyone
- [x] Implement
- [x] Test

### Task 3: Controller + routes
- **spec_ref**: `openspec/changes/approval-routes/specs/approval-routes/spec.md#requirement-req-ar-007-the-engine-is-fail-closed-on-the-actor`
- **files**: `lib/Controller/ApprovalRouteController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authenticated caller WHEN it POSTs to instantiate/record THEN the engine runs and its refusals reach the caller as the reason, not a generic failure
  - GIVEN no signed-in user WHEN either endpoint is called THEN it answers 401
  - GIVEN a recorded action WHEN stored THEN `actor` comes from the SESSION and never from the body — reading it from the request would let any caller sign off as anyone
  - GIVEN the two routes WHEN declared THEN they carry DISTINCT names; a duplicate name collides on the route identifier and takes down every route in the app
- [x] Implement
- [x] Test

### Task 4: Seed a demonstrable route
- **spec_ref**: `openspec/changes/approval-routes/specs/approval-routes/spec.md#requirement-req-ar-001-approvalroute-is-a-reusable-template`
- **files**: `lib/Settings/register.d/69-approval-routes.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeded THEN an `approval-route` exists with four ordered steps, mixed mandatory/optional, and `isDefault: true`
- [x] Implement
- [x] Test

### Task 5: UI write affordances on DecisionRouteTab
- **spec_ref**: deferred
- **files**: `src/components/tabs/DecisionRouteTab.vue`
- **acceptance_criteria**:
  - Deferred deliberately. The tab declares itself read-only today; giving it write affordances is a separate change now that the engine it would drive exists and its shape is settled.
- [ ] Implement
- [ ] Test

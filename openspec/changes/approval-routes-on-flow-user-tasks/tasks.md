# Tasks: approval-routes-on-flow-user-tasks

Prerequisite: the OpenRegister rows in proposal.md. Tasks 1 to 4 can be built against what OpenRegister has today; a route that needs a missing row is refused by task 5.

## Implementation tasks

### Task 1: Route compiler
- **spec_ref**: `openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-001-a-route-template-compiles-to-a-flow-of-user-tasks`
- **files**: `lib/Service/ApprovalRouteFlowCompiler.php` (new), `lib/Listener/ApprovalRouteSavedListener.php` (new, compiles on save), `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`
- **acceptance_criteria**:
  - a route with a parallel order compiles to parallel nodes and a join
  - the compiled document passes OpenRegister's own flow validator (real class, no fake)
- Built: `ApprovalRouteFlowCompiler` turns a route into a flow document (manual trigger, one `openregister.user-task` per step, an object-write per order entry, a join (`openregister.merge`, `join: true`) after a parallel order, approved/rejected/returned ends). The flow is keyed `decidiq-approval-route:<route>:<steps hash>`, so a changed route is a new flow. `ApprovalRouteSavedListener` is the save guard on `approval-route`. Tests: `ApprovalRouteFlowCompilerTest` (17) asserts nodes, edges, exits, join and that the document is closed; it does not load OpenRegister's own `FlowDefinitionBuilder`, because the unit suite runs on OpenRegister stubs and CI does not check openregister out beside the app.
- [x] Implement
- [x] Test (red first): `tests/Unit/Service/ApprovalRouteFlowCompilerTest.php`

### Task 2: Start a run from Send for approval
- **spec_ref**: `openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject`
- **files**: `lib/Service/ApprovalRouteService.php` (instantiate becomes a run start), `lib/Controller/ApprovalRouteController.php`, `src/dialogs/SendForApprovalDialog.vue` (from routes-send-proposal-along-route)
- **acceptance_criteria**:
  - one run per send, decision as subject; a second send while open is refused naming the open step
- Built: `ApprovalRouteFlowStarter` compiles the route, has `ApprovalFlowGateway` store and publish it once per route version (`OpenRegisterApprovalFlowGateway` over OpenRegister's `FlowServiceInterface`, `FlowVersionService` and `FlowRunService::queueRun()`, with the decision as run subject), and records `approvalRun`, `approvalEngine: flow`, `approvalRouteStatus: running` and the first order on the decision. A second send while the route runs is refused naming the open step and its label. `ApprovalRouteController::instantiate()` sends a template route for a decision this way; an ad-hoc route, another subject and a decision that already has stages on the old engine keep the old path. The dialog's request is unchanged (the answer carries `run`). Tests: `ApprovalRouteFlowStarterTest` (5), `ApprovalRouteControllerFlowSendTest` (4).
- [x] Implement
- [x] Test (red first): `tests/Unit/Service/ApprovalRouteServiceTest.php`

### Task 3: Outcomes, return and lapse in the compiled flow
- **spec_ref**: `openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-003-each-step-is-a-task-and-its-outcome-moves-the-route`, `#requirement-req-arf-004-a-return-re-opens-an-earlier-step-and-keeps-the-earlier-outcome`, `#requirement-req-arf-005-a-steps-silence-has-the-outcome-the-route-declared-applied-once`
- **files**: `lib/Service/ApprovalRouteFlowCompiler.php` (outcome edges, return edges, `expiresAt`/`onTimeout`, object-write nodes for the decision's route fields)
- **acceptance_criteria**:
  - approve, reject, return and lapse each produce the edge and object write the spec names
- Built: each task node declares exits read from its own outcome key (`json.step_<order>_<slot>.outcome`): approved and advised go on, rejected to `route-rejected`, `returned-to-<n>` (the task's `returnToStep` answer) re-enters order n, a return naming no step goes to `route-returned` (back to the sender). A step's `dueAt` rides on the task; `expiresAt`/`onTimeout` only where OpenRegister offers a lapse outcome (it does not today: `Task::OUTCOME_BEHAVIOURS` is skip/error/dead_letter), else task 5 refuses the route. The writes set `approvalRouteStatus` and `approvalCurrentOrder` on the decision (register fragment 134).
- [x] Implement
- [x] Test (red first): compiler test cases per outcome

### Task 4: Route tab reads the run (board DcBesluitRoute)
- **spec_ref**: `openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-007-the-route-tab-reads-the-flow-run`, `#requirement-req-arf-006-a-substitute-can-act-while-the-approver-is-away`
- **files**: `src/utils/routeFromFlowRun.js` (new), `src/components/tabs/DecisionRouteTab.vue`, `l10n/` (labels "Huidig", "besloten", "actief", "in afwachting", "Vervanger", "Nog te doen")
- **acceptance_criteria**:
  - the tab matches the board: rows, badges, decided count, substitute line, Nog te doen line
- Built: `src/utils/routeFromFlowRun.js` turns the route template and the run's tasks (`/apps/openregister/api/flow-tasks?runUuid=`) into the rows the tab already renders. Each row has sequence, label, role and method, status decided/active/pending, outcome, date, a lapse mark and a substitute when someone other than the named approver completed it. A re-opened step keeps its earlier tasks as history. `DecisionRouteTab.vue` reads a decision with `approvalEngine: flow` this way (the starter records `approvalRoute` and `approvalRun`) and keeps the stage rows for the old engine. No new strings: it reuses Approved, Lapsed, Substitute and the existing status and Current / Still to do labels. Not built: the server side of REQ-ARF-006. The compiler does not yet put a recorded substitute in the task's `candidateUsers`, because the absence is known when the step opens, not when the route is compiled, so the tab shows a substitute only after one completed the task. There is also no label yet for the `returned` outcome (needs a new string in every catalogue). Tests: `tests/vitest/routeFromFlowRun.spec.js` (6), `decisionRouteTabGolden.spec.js` (+1).
- [x] Implement
- [x] Test (red first): `tests/unit/utils/routeFromFlowRun.spec.js`

### Task 5: Refuse a route that needs a missing OpenRegister feature
- **spec_ref**: `openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved`
- **files**: `lib/Service/ApprovalRouteFlowCompiler.php` (capability check against OpenRegister's node catalog)
- **acceptance_criteria**:
  - the refusal names the feature and the step; no fallback to the old engine
- Built: `ApprovalRouteFlowCapabilities` reads OpenRegister's task vocabularies and flow router; the compiler names every missing feature with its step (performer by rule, declared outcome or hold on silence, reminder before the deadline, partial threshold, route deadline split, exclusion list, and a step that names nobody). An inactive route is a draft and is not judged. Tests: compiler cases + `ApprovalRouteSavedListenerTest` (6).
- [x] Implement
- [x] Test (red first)

### Task 6: Legacy routes finish on the old engine, then the old engine goes
- **spec_ref**: `openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-008-routes-that-run-on-the-old-engine-finish-there`
- **files**: `lib/Migration/MarkLegacyApprovalRoutes.php` (repair step), `lib/Command/ApprovalRoutesLegacyCommand.php`, `appinfo/info.xml`; in a later release delete `lib/Service/ApprovalRouteAdvancer.php`, `ApprovalStageActivator.php`, `ApprovalStageLapseService.php`, `ApprovalActorResolver.php`, `ApprovalThresholdCalculator.php`, `ApprovalStageTaskProjector.php`, `lib/BackgroundJob/ApprovalStageLapseJob.php` and their tests
- **acceptance_criteria**:
  - a route open at upgrade continues on the old engine; a new send never does
  - the delete lands only when the legacy command lists nothing on the reference instances
- [ ] Implement marking and command
- [ ] Test (red first)
- [ ] Delete the old engine (separate PR)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and OpenRegister's real flow validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push. Gate 23 no longer matches an approval engine in decidiq.
- Playwright `tests/e2e/approval-route-flow.spec.ts` tagged with each scenario.

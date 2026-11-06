# Tasks: approval-routes-on-flow-user-tasks

Prerequisite: the OpenRegister rows in proposal.md. Tasks 1 to 4 can be built against what OpenRegister has today; a route that needs a missing row is refused by task 5.

## Implementation tasks

### Task 1: Route compiler
- **spec_ref**: `openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-001-a-route-template-compiles-to-a-flow-of-user-tasks`
- **files**: `lib/Service/ApprovalRouteFlowCompiler.php` (new), `lib/Listener/ApprovalRouteSavedListener.php` (new, compiles on save), `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`
- **acceptance_criteria**:
  - a route with a parallel order compiles to parallel nodes and a join
  - the compiled document passes OpenRegister's own flow validator (real class, no fake)
- [ ] Implement
- [ ] Test (red first): `tests/Unit/Service/ApprovalRouteFlowCompilerTest.php`

### Task 2: Start a run from Send for approval
- **spec_ref**: `openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-002-sending-a-proposal-starts-one-flow-run-with-the-decision-as-its-subject`
- **files**: `lib/Service/ApprovalRouteService.php` (instantiate becomes a run start), `lib/Controller/ApprovalRouteController.php`, `src/dialogs/SendForApprovalDialog.vue` (from routes-send-proposal-along-route)
- **acceptance_criteria**:
  - one run per send, decision as subject; a second send while open is refused naming the open step
- [ ] Implement
- [ ] Test (red first): `tests/Unit/Service/ApprovalRouteServiceTest.php`

### Task 3: Outcomes, return and lapse in the compiled flow
- **spec_ref**: `openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-003-each-step-is-a-task-and-its-outcome-moves-the-route`, `#requirement-req-arf-004-a-return-re-opens-an-earlier-step-and-keeps-the-earlier-outcome`, `#requirement-req-arf-005-a-steps-silence-has-the-outcome-the-route-declared-applied-once`
- **files**: `lib/Service/ApprovalRouteFlowCompiler.php` (outcome edges, return edges, `expiresAt`/`onTimeout`, object-write nodes for the decision's route fields)
- **acceptance_criteria**:
  - approve, reject, return and lapse each produce the edge and object write the spec names
- [ ] Implement
- [ ] Test (red first): compiler test cases per outcome

### Task 4: Route tab reads the run (board DcBesluitRoute)
- **spec_ref**: `openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-007-the-route-tab-reads-the-flow-run`, `#requirement-req-arf-006-a-substitute-can-act-while-the-approver-is-away`
- **files**: `src/utils/routeFromFlowRun.js` (new), `src/components/tabs/DecisionRouteTab.vue`, `l10n/` (labels "Huidig", "besloten", "actief", "in afwachting", "Vervanger", "Nog te doen")
- **acceptance_criteria**:
  - the tab matches the board: rows, badges, decided count, substitute line, Nog te doen line
- [ ] Implement
- [ ] Test (red first): `tests/unit/utils/routeFromFlowRun.spec.js`

### Task 5: Refuse a route that needs a missing OpenRegister feature
- **spec_ref**: `openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-009-a-route-that-needs-a-missing-openregister-feature-is-refused-when-saved`
- **files**: `lib/Service/ApprovalRouteFlowCompiler.php` (capability check against OpenRegister's node catalog)
- **acceptance_criteria**:
  - the refusal names the feature and the step; no fallback to the old engine
- [ ] Implement
- [ ] Test (red first)

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

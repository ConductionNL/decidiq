# Tasks: flow-request-decision-node

## Implementation tasks

### Task 1: FlowDecisionService
- **spec_ref**: `openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-003-the-step-fails-closed` (+ REQ-FRD-004)
- **files**: `lib/Service/FlowDecisionService.php`
- **acceptance_criteria**:
  - GIVEN createDecision() reports failure or no id THEN raise() throws
  - GIVEN each guard answer and envelope status THEN readState() answers the matching one of the six states
- [x] Implement
- [x] Test (`tests/Unit/Service/FlowDecisionServiceTest.php`)

### Task 2: DecidiqRequestDecisionNode
- **spec_ref**: `.../spec.md#requirement-req-frd-002-the-decision-is-about-the-object-the-flow-carries` (+ REQ-FRD-001, 003, 004, 005)
- **files**: `lib/Flow/DecidiqRequestDecisionNode.php`
- [x] Implement
- [x] Test (`tests/Unit/Flow/DecidiqRequestDecisionNodeTest.php`, `tests/Unit/Flow/RequestDecisionHeartbeatRecoveryTest.php`)

### Task 3: Wake on conclusion
- **spec_ref**: `.../spec.md#requirement-req-frd-006-a-concluded-decision-wakes-the-run-that-asked`
- **files**: `lib/Listener/FlowDecisionConcludedListener.php`
- [x] Implement
- [x] Test (`tests/Unit/Listener/FlowDecisionConcludedListenerTest.php`)

### Task 4: Guarded registration
- **spec_ref**: `.../spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node`
- **files**: `lib/Flow/DecidiqFlowNodeListener.php`, `lib/AppInfo/Registrar/FlowNodeRegistrar.php`, `lib/AppInfo/Application.php`
- [x] Implement
- [x] Test (`tests/Unit/Flow/DecidiqFlowNodeListenerTest.php`, `tests/Unit/AppInfo/FlowNodeRegistrarTest.php`)

### Task 5: Analysis and test stubs for OpenRegister's flow classes
- **files**: `tests/Stubs/Service/Flow/*`, `tests/Stubs/Db/FlowRun*.php`, `psalm.xml`, `phpstan.neon`
- [x] Implement

### Task 6: Verify
- [ ] `composer check:strict` once, `npm run lint`, `npm run format`, `npm run test:l10n`

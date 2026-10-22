# Tasks: decisions-leaf-board-card

### Task 1: The decisions leaf draws each decision with its route
- **spec_ref**: `openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route`
- **files**: `src/integrations/CnDecisionsTab.vue`, `src/integrations/decisionCard.js`
- [x] Implement: the card, `stageSteps`, `decisionMetaLine`, `initialsOf`
- [x] Test: `tests/vitest/decisionsBoardCard.spec.js`

### Task 2: The decisions leaf says whether the drafter may take the decision
- **spec_ref**: `openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-says-whether-the-drafter-may-take-the-decision`
- **files**: `lib/Service/DecisionAuthority.php`, `lib/Controller/DecisionAuthorityController.php`, `appinfo/routes.php`, `src/integrations/decisionCard.js`
- [x] Implement: `DecisionAuthority::forDecision`, `GET /api/decisions/{decisionId}/authority`, `mandateNotice`
- [x] Test: `tests/Unit/Service/DecisionAuthorityTest.php`, `tests/Unit/Controller/DecisionAuthorityControllerTest.php`

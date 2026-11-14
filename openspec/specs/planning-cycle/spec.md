# planning-cycle Specification

## Purpose
A yearly planning and control cycle made from a template gets its steps, with dates in the cycle year, and the cycle page lists them in order with an overdue marker.

## Requirements

### Requirement: REQ-PCG-001 A cycle made from a template gets its steps

Creating a planning cycle with a template SHALL create its steps in order with dates in the cycle year, once.

#### Scenario: cycle 2027 from the municipal template
- GIVEN the template holds kadernota, begroting and jaarrekening
- WHEN the controller creates cycle 2027 with that template
- THEN the cycle page lists the three steps in that order with 2027 deadlines

@e2e exclude covered by tests/Unit/Listener/PlanningCycleCreatedListenerTest.php::testCycle2027FromTheMunicipalTemplateGetsItsStepsInOrder and tests/vitest/planningCycleSteps.spec.js "lists the steps in sequence order"; a Playwright run on the live instance is owed

#### Scenario: an overdue step is marked
- GIVEN the begroting step deadline has passed and it is not done
- WHEN the controller opens the cycle page
- THEN the step shows Overdue

@e2e exclude covered by tests/vitest/planningCycleSteps.spec.js "an overdue step is marked"; a Playwright run on the live instance is owed

# first-time-setup Specification

## Purpose
The first-time setup wizard's contract between the manifest and the server. The example data step is one cards step in which every card except None loads its own set, and the setup status endpoint reports exactly the step ids the manifest declares, so no step the server forgot keeps reopening the wizard (ADR-042).

## Requirements

### Requirement: Each example data card loads itself

The `example-set` setup step MUST be a cards choice step with `loadAction: load-example-set`. The setup wizard MUST NOT carry a separate run-action step that loads the picked dataset.

#### Scenario: The operator loads a dataset from its card

- GIVEN the setup wizard shows the example data cards
- WHEN the operator presses Load on a card
- THEN the wizard posts `{ "dataset": <card value> }` to `/api/setup/action/load-example-set`
- AND the server loads that dataset
- AND the server records the dataset as the pick only after the load succeeds
- @e2e exclude the card and its spinner are CnSetupWizard UI, tested in nextcloud-vue; the posted body is covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: An unknown dataset is refused

- GIVEN a dataset id that no card offers
- WHEN it is posted to `/api/setup/action/load-example-set`
- THEN the server answers 400 with `success: false`
- AND nothing is loaded or stored
- @e2e tests/e2e/spec-coverage/example-set-setup-step.spec.ts

#### Scenario: A call without a body keeps working

- GIVEN a dataset was stored through `/api/setup/config`
- WHEN `/api/setup/action/load-example-set` is called without a body
- THEN the stored dataset is loaded
- @e2e tests/e2e/spec-coverage/example-set-setup-step.spec.ts

#### Scenario: A failed load leaves the step open

- GIVEN the load of the posted dataset fails
- WHEN the server answers
- THEN the answer carries `success: false`
- AND no pick or decision is stored
- @e2e exclude needs a load that fails on a live instance; covered by tests/Unit/Controller/SetupControllerTest.php

### Requirement: Setup status reports every manifest step

`GET /api/setup/status` MUST report a `done` state for every step id in `manifest.setup.steps`.

#### Scenario: The status ids match the manifest

- GIVEN the Decidiq manifest
- WHEN an administrator reads `/api/setup/status`
- THEN `steps` holds an entry for every manifest step id
- AND the retired load step is not reported
- @e2e tests/e2e/spec-coverage/example-set-setup-step.spec.ts

### Requirement: The setup wizard guides an administrator through a fresh installation

On a fresh installation the app SHALL open the setup wizard for an administrator, and the wizard SHALL walk through its steps in order: a welcome, the choice of example set, and a closing step, showing which step of how many is open. No step SHALL be required. Finishing the wizard SHALL record the setup version it finished in the app setting `setup_completed_version`, and the wizard SHALL NOT open again on its own until the app ships a higher setup version.

#### Scenario: An administrator sets up a new installation

- **GIVEN** decidiq was just installed and nobody finished its setup
- **WHEN** an administrator opens decidiq
- **THEN** the setup wizard opens on its welcome step and shows that it is step 1 of its steps
- **AND** the administrator can move to the example set step and on to the end without filling in anything

#### Scenario: A finished setup stays closed

- **GIVEN** an administrator finished the setup wizard
- **WHEN** they open decidiq again
- **THEN** the wizard does not open and `setup_completed_version` holds the version they finished

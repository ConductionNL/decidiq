# first-time-setup Specification (delta)

## ADDED Requirements

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

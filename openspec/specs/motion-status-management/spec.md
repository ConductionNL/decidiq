# motion-status-management Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-motion-and-voting-other-t1. Update Purpose after archive.

## Requirements

### Requirement: REQ-MSM-001 Permitted motion types are configurable per GovernanceBody
The app SHALL read permitted `motionType` values from `GovernanceBody.workflowTemplate` (JSON) when rendering the motion creation form and when validating motion submissions. If `workflowTemplate` is empty or invalid JSON, the platform default list (`motion`, `amendment`, `order`, `procedural`) is used as fallback.

#### Scenario: Clerk creates a motion for a body with restricted motion types
- **GIVEN** a `GovernanceBody` with `workflowTemplate` containing `"permittedMotionTypes": ["motion","amendment"]`
- **WHEN** a member opens the "Motie indienen" form
- **THEN** the `motionType` dropdown shows only "Motie" and "Amendement"; "Order" and "Procedureel" options are not rendered

#### Scenario: Motion submission with a disallowed type is rejected
- **GIVEN** a `GovernanceBody` with `workflowTemplate` containing `"permittedMotionTypes": ["motion"]`
- **WHEN** a `POST /api/motions` request is made with `motionType: "order"`
- **THEN** the backend returns `400 Bad Request` with message "This motion type is not permitted for this governance body"

---

### Requirement: REQ-MSM-002 Lifecycle transition rules are configurable per GovernanceBody
`MotionService::transitionLifecycle()` SHALL validate the requested transition against the `transitions` map in `GovernanceBody.workflowTemplate`. If the transition is not listed, the service returns a `400 Bad Request`. If `workflowTemplate` is empty, the platform defaults apply.

#### Scenario: Chair attempts a transition allowed by configuration
- **GIVEN** a `GovernanceBody` with `workflowTemplate.transitions.submitted = ["debating","withdrawn"]` and a Motion with `lifecycle: "submitted"`
- **WHEN** the chair clicks "Debat openen"
- **THEN** `MotionService::transitionLifecycle()` succeeds and the Motion is updated to `lifecycle: "debating"`

#### Scenario: Chair attempts a transition not in configuration
- **GIVEN** a `GovernanceBody` whose `workflowTemplate.transitions` does not list `"voting"` as reachable from `"submitted"`
- **WHEN** the chair calls `POST /api/motions/{id}/transition` with `newState: "voting"`
- **THEN** the backend returns `400 Bad Request` with message "Transition not permitted by governance body configuration"

---

### Requirement: REQ-MSM-003 Majority rule is configurable per GovernanceBody
The majority rule used by `VotingService::tallyResults()` SHALL be read from `GovernanceBody.workflowTemplate.majorityRule`. Supported values: `simple` (more For than Against), `absolute` (For > half of eligible voters), `qualified-two-thirds` (For ≥ ⌈2/3 × eligible⌉). Default: `simple`.

#### Scenario: Simple majority — motion adopted
- **GIVEN** `majorityRule: "simple"`, 25 For, 20 Against, 5 Abstain
- **WHEN** `VotingService::tallyResults()` is called
- **THEN** `VotingRound.result` is set to `"adopted"`

#### Scenario: Absolute majority — motion rejected because For < half of eligible
- **GIVEN** `majorityRule: "absolute"`, 40 eligible voters, 18 For, 15 Against, 7 Abstain
- **WHEN** `VotingService::tallyResults()` is called
- **THEN** `VotingRound.result` is set to `"rejected"` because 18 < 20 (half of 40)

#### Scenario: Two-thirds qualified majority — motion adopted
- **GIVEN** `majorityRule: "qualified-two-thirds"`, 36 eligible voters, 24 For, 12 Against, 0 Abstain
- **WHEN** `VotingService::tallyResults()` is called
- **THEN** `VotingRound.result` is set to `"adopted"` because 24 ≥ ⌈24⌉ = 24 (= 2/3 × 36)

---

### Requirement: REQ-MSM-004 Admin settings page provides a visual workflow configuration editor
The admin settings surface SHALL include a `WorkflowConfigSection.vue` component under the GovernanceBody settings. It displays the current `workflowTemplate` as an editable form (motion type checklist, allowed transition pairs, majority rule selector) and saves it as JSON to `GovernanceBody.workflowTemplate` via `ObjectService.saveObject()`.

#### Scenario: Admin configures motion types for a governance body
- **GIVEN** the admin opens the settings page for a GovernanceBody
- **WHEN** the admin unchecks "Order" and "Procedureel" in the motion type list and clicks Save
- **THEN** `GovernanceBody.workflowTemplate` is updated with `"permittedMotionTypes": ["motion","amendment"]` and subsequent motion creation forms reflect the new restriction

#### Scenario: Admin resets to platform defaults
- **GIVEN** the admin opens the workflow configuration editor
- **WHEN** the admin clicks "Reset to defaults"
- **THEN** `GovernanceBody.workflowTemplate` is cleared (set to `null`) and the motion forms revert to the platform default list

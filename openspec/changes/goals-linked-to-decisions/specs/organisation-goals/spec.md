# organisation-goals Specification (delta)

## Purpose

A decision can serve an organisation goal, and the goal page shows every object that works towards it with counts that stay current. Follows board DcDoel. Covers row fol-09 (decision 100).

## ADDED Requirements

### Requirement: REQ-010 A decision can name the goal it serves

The `decision` schema SHALL carry an optional `goal` reference to a `goal` object. The decision form SHALL offer the goals in state draft, active or at-risk, and the decision's data widget SHALL show the linked goal as a link to its detail page. A decision without a goal SHALL stay valid.

#### Scenario: A clerk links a decision to a goal
- GIVEN the active goal "Verlichte en veilige fietsroutes in 2027"
- WHEN a clerk edits the decision "Raadsvoorstel verlichting fietspaden 2027" and picks that goal
- THEN the decision stores the goal's id
- AND the decision page shows the goal as a link to `/goals/:id`

#### Scenario: A finished goal is not offered
- GIVEN a goal in state achieved
- WHEN a clerk opens the goal picker on a decision
- THEN that goal is not in the list

### Requirement: REQ-011 The goal page lists what contributes to it

The goal detail page SHALL show a list "Wat aan dit doel bijdraagt" with the columns Soort, Wat, Termijn and Status, holding every governance commitment, action item, planned agenda item and decision whose `goal` is this goal, with the total in the heading and a link Alle n bekijken to the full list. Each row SHALL link to the object's own detail page. The tab Gerelateerd SHALL carry the same total.

#### Scenario: Four kinds of contribution
- GIVEN a goal with two commitments, one action item, one planned agenda item and one decision pointing at it
- WHEN a user opens the goal page
- THEN the list heading reads 5 and shows rows of Soort Toezegging, Actiepunt, Termijnagenda and Besluit
- AND the Termijn of the planned agenda item names its meeting

#### Scenario: A goal nothing points at
- GIVEN a new goal with no linked objects
- WHEN a user opens the goal page
- THEN the list shows an empty state that says nothing is linked yet

### Requirement: REQ-012 Goal progress counts the live schemas and reads as counts

The goal's aggregations SHALL count `governance-commitment` (settled when `lifecycle` is `disposed`), `action-item` (done when `taskStatus` is `completed`) and child goals (reached when `status` is `achieved`), and SHALL count linked planned agenda items and decisions. No aggregation SHALL name a retired schema. The progress card SHALL show, as "x van y", the current against the target value with its unit, Toezeggingen afgedaan, Actiepunten klaar and Subdoelen bereikt, and SHALL hide a line whose total is zero.

#### Scenario: Commitments on the renamed schema count
- GIVEN a goal with four `governance-commitment` objects pointing at it, two of them disposed
- WHEN the goal page loads
- THEN the progress card reads "Toezeggingen afgedaan 2 van 4"

#### Scenario: The target value
- GIVEN a goal with `targetValue` 12, `currentValue` 7 and unit "fietsroutes met verlichting"
- WHEN the goal page loads
- THEN the card reads "Doelwaarde 7 van 12 fietsroutes met verlichting"

### Requirement: REQ-013 Progress stays current when a contribution changes

When a governance commitment, action item, planned agenda item, decision or child goal that points at a goal is created, changed or deleted, or its `goal` (or `parentGoal`) moves to another goal, the system SHALL recompute the calculated progress of every goal it pointed at before and after the change. The recompute SHALL write only calculated fields and SHALL not save the goal when nothing changed.

#### Scenario: Settling a commitment moves the count
- GIVEN a goal whose card reads "Toezeggingen afgedaan 2 van 4"
- WHEN a clerk disposes a third linked commitment
- THEN the goal page reads "3 van 4" without anyone editing the goal

#### Scenario: Moving an action item to another goal
- GIVEN an action item linked to goal A
- WHEN its goal is changed to goal B
- THEN goal A's action item total drops by one and goal B's rises by one

### Requirement: REQ-014 The goal page shows subgoals and the parent goal

The goal detail page SHALL show a Subdoelen table (Subdoel, Eigenaar, Deadline, Status) of the goals whose `parentGoal` is this goal, with an action Subdoel toevoegen that opens the goal form with `parentGoal` filled in, and SHALL show a Hoofddoel card with the parent's title and how many of the parent's subgoals are reached. The stepper SHALL show Concept, Actief and Bereikt with their dates.

#### Scenario: Adding a subgoal
- GIVEN the goal "Verlichte en veilige fietsroutes in 2027"
- WHEN a user clicks Subdoel toevoegen and saves "Schoolroutes veilig ingericht"
- THEN the new goal has that goal as `parentGoal` and appears in the Subdoelen table

#### Scenario: Parent card
- GIVEN the parent "Mobiliteitsvisie 2030" has three subgoals, one achieved
- WHEN a user opens one of its subgoals
- THEN the Hoofddoel card reads "3 subdoelen, waarvan 1 bereikt"

## MODIFIED Requirements

### Requirement: REQ-004 Goal progress rolls up from linked commitments and tasks
The system MUST declare a progress aggregation on `Goal` via `x-openregister-aggregations` and `x-openregister-calculations` (ADR-031) that counts linked `governance-commitment` and `action-item` objects (total and settled/completed) referencing the goal, without a new PHP aggregation service. The retired `toezegging` schema MUST NOT be counted.

#### Scenario: Progress reflects linked commitments
- GIVEN a Goal with two linked `governance-commitment` objects (`goal` = the Goal's id), one `lifecycle: "disposed"` and one `lifecycle: "open"`
- WHEN the Goal's aggregated `linkedCommitmentCount` and `settledCommitmentCount` fields are read
- THEN `linkedCommitmentCount` is 2 and `settledCommitmentCount` is 1

#### Scenario: Progress reflects linked action items
- GIVEN a Goal with three linked `ActionItem` objects (`goal` = the Goal's id), two `taskStatus: "completed"` and one `taskStatus: "open"`
- WHEN the Goal's aggregated `linkedActionItemCount` and `completedActionItemCount` fields are read
- THEN `linkedActionItemCount` is 3 and `completedActionItemCount` is 2

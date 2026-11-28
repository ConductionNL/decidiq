---
status: done
---

# organisation-goals Specification

**Status**: done
**Scope**: decidiq
**OpenSpec changes**:
- organisation-goals (archived) — Organisation Goals capability (Goal schema, owner/body linkage, progress rollup from Toezegging/ActionItem, ISO 9001 §6.2 alignment)

## Purpose
Organisation Goals give a governance body (council, board, department, committee) a place to record what it is trying to achieve — shaped on ISO 9001 §6.2 quality objectives: measurable, owned, deadlined, and monitored at the relevant function and level. Goals are the target end of the accountability chain that Toezegging (commitment) and ActionItem (task) already track day-to-day; this capability adds the target and rolls up progress from what already links to it, without duplicating either. See ADR-000 (data model — Goal is a documented extension, not Popolo), ADR-001 (Popolo — no Popolo equivalent for a target/objective, so Goal is custom), ADR-002 (CalDAV — ActionItem stays a read-only VTODO projection; a `goal` reference on it must round-trip through the existing generic non-core field blob), ADR-031 (declarative lifecycle/aggregation over new services).

## Requirements

### Requirement: REQ-001 Goal schema
The system MUST provide a `Goal` schema (slug `goal`) with required properties `title`, `description`, `horizon`, `body`, `deadline`, and `status`, and optional properties `owner`, `startDate`, `targetValue`, `currentValue`, `unit`, and `parentGoal`.

#### Scenario: Creating a minimal goal
- GIVEN a griffie/secretariat user with write access to the decidesk register
- WHEN they create a `Goal` object with `title`, `description`, `horizon: "annual"`, `body: <GovernanceBody uuid>`, and `deadline: "2027-01-01"`
- THEN the object is created with `status` defaulted to its initial lifecycle state
- AND omitting `title`, `body`, or `deadline` is rejected by OpenRegister schema validation

#### Scenario: Goal horizon spans every planning cadence
- GIVEN the `horizon` property's enum `["multi-year", "annual", "quarterly"]`
- WHEN a Goal is created for a multi-year strategic objective, an annual departmental target, or a quarterly council target
- THEN all three are representable without a schema change

### Requirement: REQ-002 Goal owner and body reach every organisational level
The system MUST let a Goal reference its responsible `owner` (→ Person) and the `body` (→ GovernanceBody) it belongs to, so that a goal can be set at council, board, department, or commission level using the existing `GovernanceBody.bodyType` enum.

#### Scenario: Department-level goal
- GIVEN a GovernanceBody with `bodyType: "operational"` representing a department
- WHEN a Goal references that GovernanceBody as `body`
- THEN the goal is scoped to that department exactly as a goal referencing a `bodyType: "legislative"` council would be scoped to the council

#### Scenario: Goal without a named owner is still valid
- GIVEN `owner` is optional (a goal may be collectively owned by a body before an individual is assigned)
- WHEN a Goal is created with `body` set and `owner` omitted
- THEN the object is created successfully

### Requirement: REQ-003 Goal lifecycle is declarative
The system MUST declare the Goal `status` lifecycle via `x-openregister-lifecycle` (ADR-031) with states `draft`, `active`, `at-risk`, `achieved`, and `abandoned`; `achieved` and `abandoned` MUST be terminal; no application-level state-machine service MUST be introduced for this transition logic.

#### Scenario: Guarded transition
- GIVEN a Goal with `status: "active"`
- WHEN a user attempts to set `status` directly to `"draft"`
- THEN OpenRegister's lifecycle guard rejects the transition because it is not declared in the `transitions` array
- AND a valid transition (e.g. `active` → `achieved`) is accepted

#### Scenario: Terminal state has no outgoing transition
- GIVEN a Goal with `status: "achieved"` or `status: "abandoned"`
- WHEN any further status transition is attempted
- THEN it is rejected — no transition is declared from a terminal state

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

### Requirement: REQ-005 Goal supports single-level parent/child cascade
The system MUST let a Goal reference a `parentGoal` (→ Goal, self-referential, nullable), and MUST declare a direct-child rollup aggregation (count of Goals whose `parentGoal` equals the current Goal's id) so an organisation-wide goal can see how many department/quarterly goals cascade from it.

#### Scenario: Department goal cascades from an org-wide goal
- GIVEN an org-wide Goal G1 (`body.bodyType: "legislative"`, `horizon: "multi-year"`)
- AND a department Goal G2 (`body.bodyType: "operational"`, `horizon: "annual"`, `parentGoal: G1.id`)
- WHEN G1's aggregated `childGoalCount` field is read
- THEN it is at least 1

#### Scenario: Multi-level cascade is out of scope
- GIVEN a quarterly Goal G3 with `parentGoal: G2.id` (G2 itself has `parentGoal: G1.id`)
- WHEN G1's aggregated `childGoalCount` is read
- THEN G3 is NOT counted (only direct children of G1 are) — a deeper cascade is explicitly deferred (see proposal.md Out of Scope)

### Requirement: REQ-006 Toezegging references its goal
The system MUST add an optional, nullable `goal` property (→ Goal) to the `Toezegging` schema (register.d/45) so a commitment can declare which goal it serves.

#### Scenario: Commitment linked to a goal
- GIVEN a Goal G and a Toezegging created with `goal: G.id`
- WHEN the Toezegging is read back
- THEN its `goal` reference resolves to G
- AND an existing Toezegging created before this change (no `goal` set) remains valid — the field is optional

### Requirement: REQ-007 ActionItem references its goal through the existing CalDAV projection
The system MUST add an optional `goal` property (→ Goal) to the `ActionItem` schema, and the reference MUST round-trip through the existing generic non-core `fields` blob that `ActionItemWriter` already uses for `decision` and `meeting` — no new write path or PHP change.

#### Scenario: Action item linked to a goal round-trips
- GIVEN a Goal G
- WHEN an ActionItem VTODO is created via `ActionItemWriter::create()` with `goal: G.id` in its payload
- THEN `goal` is not a core VTODO field (not in `toTaskData()`'s `coreKeys`), so it rides into the `fields` blob exactly like `decision`/`meeting` do today
- AND reading the action item back through the read-only OpenRegister projection returns `goal: G.id`

### Requirement: REQ-008 TermijnagendaItem references its goal
The system MUST add an optional, nullable `goal` property (→ Goal) to the `TermijnagendaItem` schema (register.d/50) so the forward-planning view can show which planned topic serves which goal.

#### Scenario: Planned topic linked to a goal
- GIVEN a Goal G and a TermijnagendaItem created with `goal: G.id`
- WHEN the item is viewed on the TermijnagendaDetail page
- THEN the `goal` reference is available to render as a navigable related-object link (existing `related` widget), without a Vue code change

### Requirement: REQ-009 Goals index and detail pages are declared, not custom-built
The system MUST declare a Goals index page and a Goals detail page via a `manifest.d/organisation-goals.json` fragment, using the generic `data`/`related` widgets (the same declarative shape as the existing `termijnagenda.json` fragment), plus exactly one menu entry.

#### Scenario: Goals pages render without new Vue components
- GIVEN the `organisation-goals.json` manifest fragment is merged by `main.js`'s existing `require.context('./manifest.d/', ...)` glob
- WHEN the app loads
- THEN a Goals index page and Goals detail page are available at their declared routes, rendered entirely by existing generic manifest-driven components

#### Scenario: Menu placement is a documented handoff, not a file edit
- GIVEN the concurrent `ia-six-clusters` change owns the "Tasks & Commitments" cluster nav layout
- WHEN this change's manifest fragment declares its menu entry
- THEN the entry carries a placement note ("belongs under Tasks & Commitments") for `ia-six-clusters` to consume
- AND this change does not modify `openspec/changes/ia-six-clusters/`

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

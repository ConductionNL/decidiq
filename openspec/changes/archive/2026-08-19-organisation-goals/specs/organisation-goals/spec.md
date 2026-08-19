# organisation-goals Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- organisation-goals

## Purpose

Organisation Goals give a governance body (council, board, department, committee) a place to record what it is trying to achieve — shaped on ISO 9001 §6.2 quality objectives: measurable, owned, deadlined, and monitored at the relevant function and level. Goals are the target end of the accountability chain that Toezegging (commitment) and ActionItem (task) already track day-to-day; this capability adds the target and rolls up progress from what already links to it, without duplicating either. See ADR-000 (data model — Goal is a documented extension, not Popolo), ADR-001 (Popolo — no Popolo equivalent for a target/objective, so Goal is custom), ADR-002 (CalDAV — ActionItem stays a read-only VTODO projection; a `goal` reference on it must round-trip through the existing generic non-core field blob), ADR-031 (declarative lifecycle/aggregation over new services).

## ADDED Requirements

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
The system MUST declare a progress aggregation on `Goal` via `x-openregister-aggregations` and `x-openregister-calculations` (ADR-031) that counts linked `Toezegging` and `ActionItem` objects (total and settled/completed) referencing the goal, without a new PHP aggregation service.

#### Scenario: Progress reflects linked commitments
- GIVEN a Goal with two linked `Toezegging` objects (`goal` = the Goal's id), one `lifecycle: "disposed"` and one `lifecycle: "open"`
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

## Non-Functional Requirements

- **Performance:** Goal aggregation fields (REQ-004, REQ-005) are computed by OpenRegister's declarative aggregation engine at read time, consistent with the existing Meeting quorum aggregation pattern already in production — no additional performance budget beyond what that pattern already carries.
- **Accessibility:** Goals index/detail pages use the same generic manifest-driven components (index list, data widget, related widget) already WCAG 2.2 AA audited elsewhere in Decidesk — no new component, no new accessibility surface.
- **Internationalization:** Dutch and English MUST be supported (ADR-005/ADR-007) for the `horizon` and `status` enum labels and the Goals page/menu titles.

## Acceptance Criteria

- [ ] `Goal` schema exists in `lib/Settings/register.d/66-organisation-goals.json` with the properties in REQ-001/REQ-002 and passes `openspec validate`
- [ ] `x-openregister-lifecycle` on `Goal` matches REQ-003 and rejects an undeclared transition when exercised live
- [ ] `x-openregister-aggregations`/`x-openregister-calculations` on `Goal` produce the counts in REQ-004 and REQ-005 when exercised against seed data
- [ ] `Toezegging`, `ActionItem`, and `TermijnagendaItem` each expose an optional `goal` reference (REQ-006/007/008) that resolves against a real Goal object
- [ ] `src/manifest.d/organisation-goals.json` declares the Goals index/detail pages and menu entry (REQ-009) and is picked up without a `main.js` edit

## Notes

- The `linkedCommitmentCount`/`linkedActionItemCount`/`childGoalCount` aggregation names are provisional field names chosen for this spec; design.md's Seed Data section shows the exact declared field names as they will appear in the register.d fragment.
- Open question carried from proposal.md: whether the declarative aggregation engine accepts a `Goal`-schema filter target from within the `Goal` schema itself (self-referential aggregation for REQ-005). If it does not, `childGoalCount` degrades to absent rather than a build failure — see DEFERRED_QUESTIONS in the change's generation record.
- No Popolo equivalent exists for "objective/target" (ADR-001) — `Goal` is a documented custom extension, consistent with how `Decision` (schema.org `custom:Decision`) already extends beyond Popolo where the standard has no match.

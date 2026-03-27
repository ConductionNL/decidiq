---
status: idea
---

# Process Configuration Specification

## Purpose

Process configuration enables administrators to define and customize decision-making workflows for different governance contexts. A process template defines the state machine, voting rules, quorum requirements, and procedural rules for a specific type of decision or meeting. The system uses YAML-based Symfony Workflow definitions for state machines and DMN-inspired decision tables for voting rules. This allows Decidesk to serve municipal councils, corporate boards, associations, and operational teams with their own procedural rules.

**Standards**: Symfony Workflow Component (YAML config), DMN (Decision Model and Notation) for voting rules, XState (frontend visualization), Schema.org (`HowTo`, `HowToStep`)
**Feature tier**: V1

## Evidence Base

### Market Research

| Statistic | Source |
|-----------|--------|
| No competitor covers all 5 decision-making domains (legislative, association, corporate governance, operations, citizen participation) | Intelligence DB insight #7 |
| Diligent Boards costs $48K-$155K/year, creating massive market gap for open-source alternative | Intelligence DB insight #8 |
| Board portal market growing at 11-20% CAGR, expected $5-7B by 2030 | Intelligence DB insight #3 |
| 74% of nonprofits want digital transformation but only 12% are digitally mature | Intelligence DB insight #4 (IEEE) |
| Only 45% of boards deemed effective (Gartner benchmark) | External source #172 (Gartner Peer Insights) |

### Technology Evaluation

| Technology | Verdict | Evidence |
|------------|---------|----------|
| Symfony Workflow Component | **Selected** -- PHP-native state machine with YAML config, guards, event-driven audit trail | External source #301; Intelligence DB insight #11 |
| XState 5 | **Selected for frontend** -- serializable JSON machine definitions, visual statechart diagrams, @xstate/vue integration | External source #300, #318; Intelligence DB insight #12 |
| DMN decision tables | **Adapted** -- DMN-inspired decision tables in PHP for configurable voting rules (not full engine) | External source #283; Intelligence DB insight #13 |
| Full BPMN engines (Camunda, Flowable) | **Rejected** -- Java-based, significant infrastructure overhead, overkill for this use case | External source #282; Intelligence DB insight #14 |
| UML Harel Statecharts | **Informed design** -- hierarchical states, parallel regions, guard conditions inform our state machine design | External source #286 |

### Legal Requirements

| Requirement | Source | Impact |
|-------------|--------|--------|
| Gemeentewet Art. 20/29/30 -- quorum >50%, absolute majority, secret ballot for appointments | Intelligence DB insight #17 | Non-negotiable system requirements for municipal templates |
| BW 2:230/238 -- statutes can specify custom majority/quorum; written procedure requires unanimous consent to METHOD | Intelligence DB insight #18 | Templates must support configurable majority types |
| Awb Art. 1:3 -- decisions must be written with motivation | Intelligence DB insight #16 | Every decision record must capture motivation |
| WDAV -- digital ALV law pending (July 2026 deadline) | Intelligence DB insight #20 | Association templates must comply with WDAV |
| Dutch Corporate Governance Code 2025 -- digitalisation knowledge required | External source #197 | Corporate templates must support comply-or-explain |

### Competitor Analysis

| Competitor | Relevant Features | Gap |
|------------|-------------------|-----|
| Diligent Boards | Action item tracking, compliance automation, AI analytics | No configurable process templates, $48K-$155K/year |
| iBabs | Quorum tracking, digital voting, ISO-certified | Fixed workflow, no state machine configuration |
| Fellow.app | Action item tracking, meeting templates | No formal voting, no governance workflows |
| Loomio | Consent-based decisions, discuss-vote-record | No state machine, limited process customization |
| OpenSlides | Legislative workflow, motion management | Known security vulnerabilities (X41 advisory 2025) |
| Decisions.com | Smart timer, AI agenda, decision capture | Microsoft Teams only, no self-hosted |

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full ProcessTemplate entity definition.

## Requirements

---

### Requirement: Process Template Management

The system MUST support creating, editing, and managing process templates. Each template MUST define a state machine (states and transitions), voting rules, quorum requirements, and optional time limits. Templates MUST be stored as OpenRegister objects in the `decidesk` register using the `processTemplate` schema.

**Feature tier**: V1

#### Scenario: Create a process template for ALV decisions

- GIVEN an administrator configuring Decidesk for an association
- WHEN they create a process template with name "ALV Standard Decision"
- THEN the template MUST define states: draft, proposed, debating, voting, adopted, rejected
- AND the template MUST specify voting rule: simple majority (50%+1 of votes cast)
- AND the template MUST specify quorum: 50%+1 of total members present or represented
- AND the template MUST be assignable to the "ALV" body
- AND the template MUST comply with WDAV requirements for digital meetings (intelligence DB insight #20)

#### Scenario: Create a process template for statute amendments

- GIVEN the same administrator
- WHEN they create a process template "ALV Statute Amendment"
- THEN the template MUST specify voting rule: qualified majority (2/3 of votes cast)
- AND the template MUST specify quorum: 2/3 of total members present
- AND the template MUST include a required legal review step before voting
- AND the template MUST reference BW 2:230 requirements (intelligence DB insight #18)

#### Scenario: Duplicate and customize an existing template

- GIVEN an existing process template "Board Standard Decision"
- WHEN the administrator duplicates it as "Board Urgent Decision"
- THEN the new template MUST be a copy of the original
- AND the administrator MUST be able to modify states, transitions, and rules independently
- AND the original template MUST remain unchanged

#### Scenario: Import template from YAML definition

- GIVEN an administrator with a Symfony Workflow YAML configuration file
- WHEN they import the YAML into Decidesk
- THEN the system MUST validate the YAML syntax against Symfony Workflow Component schema
- AND the system MUST create a process template with the defined states, transitions, and guards
- AND the imported template MUST be editable through the admin UI

---

### Requirement: Symfony Workflow State Machine Configuration

The system MUST support defining state machines as YAML-based Symfony Workflow configurations. Each state MUST have a name, optional description, and optional metadata (e.g., required approvers, time limits). Transitions MUST define from-state, to-state, guard conditions, and triggered actions. The backend MUST use the Symfony Workflow Component (PHP) for state machine execution.

**Feature tier**: V1
**Evidence**: Symfony Workflow provides native state machine with parallel states, guards, event-driven audit trail, and YAML configuration. Best fit for Nextcloud because PHP-native (external source #301; insight #11).

#### Scenario: Define a custom state machine with guard conditions

- GIVEN an administrator editing a process template
- WHEN they add a transition "start_voting" from "debating" to "voting" with guard condition `quorum_met AND all_amendments_resolved`
- THEN the system MUST validate the YAML syntax
- AND the guard condition MUST be enforced at runtime via Symfony Workflow guards
- AND the transition MUST only be allowed when both conditions are true
- AND the guard evaluation MUST be logged in the audit trail (external source #311)

#### Scenario: Configure parallel states for committee and plenary tracks

- GIVEN a municipal council process template
- WHEN the administrator defines parallel states for committee review and legal review
- THEN both reviews MUST be able to proceed simultaneously
- AND the transition to plenary debate MUST require both reviews to be completed
- AND this MUST use Symfony Workflow's marking store for multiple active places (external source #301)

#### Scenario: Define timed transitions with automatic escalation

- GIVEN a process template with a "review" state
- WHEN the administrator sets a 7-day timeout on the "review" state
- THEN the system MUST automatically trigger an escalation transition after 7 days without action
- AND stakeholders MUST receive notifications at configurable intervals before the deadline (insight #31)

#### Scenario: Audit trail for every state transition

- GIVEN a decision object moving through a process template
- WHEN any state transition occurs
- THEN the system MUST record: WHO (actor), WHAT (transition name), WHEN (timestamp), WHY (guard conditions met), and HOW (manual/automatic) (external source #311)
- AND the audit trail MUST be immutable and linked to the decision object

---

### Requirement: XState Frontend Visualization

The system MUST provide a visual state machine diagram on the frontend using XState. The diagram MUST show all states and transitions from the process template and highlight the current state when viewing a specific decision.

**Feature tier**: V1
**Evidence**: XState implements Harel statecharts in JS/TS with serializable JSON machine definitions, @xstate/vue integration, and visual statechart diagrams (external source #300, #318; insight #12).

#### Scenario: Visualize the state machine in the admin UI

- GIVEN a process template with a defined state machine
- WHEN the administrator views the template
- THEN the system MUST display a visual state machine diagram using XState rendering
- AND states MUST be shown as nodes with transition arrows between them
- AND guard conditions MUST be visible on transition labels

#### Scenario: Highlight current state for a specific decision

- GIVEN a decision in the "debating" state using a process template
- WHEN a user views the decision detail
- THEN the state machine diagram MUST highlight the "debating" state
- AND available transitions MUST be visually distinguished from unavailable ones
- AND completed transitions MUST show completion timestamps

#### Scenario: Backend-frontend state synchronization

- GIVEN a Symfony Workflow YAML definition on the backend
- WHEN the process template is loaded on the frontend
- THEN the system MUST generate an XState-compatible JSON definition from the Symfony YAML
- AND the frontend state machine MUST mirror the backend state machine exactly
- AND the frontend MUST NOT be authoritative -- all transitions MUST be validated server-side

---

### Requirement: DMN-Inspired Voting Rule Configuration

The system MUST support configurable voting rules using DMN-inspired decision tables. Rules MUST specify: majority type (simple, qualified, unanimous), quorum threshold, abstention handling (counted or excluded), tie-breaking method, and secret ballot requirement. The decision table approach allows complex voting rules to be configured without code changes.

**Feature tier**: V1
**Evidence**: DMN provides standard notation for decision tables with hit policies (Unique, Any, Priority, Collect). Can model voting rules as decision tables with conditions and outcomes (external source #283; insight #13).

#### Scenario: Configure a voting rule with abstention handling

- GIVEN an administrator creating a voting rule
- WHEN they set majority type to "simple", abstentions to "excluded from count", and tie-breaking to "chair's casting vote"
- THEN the rule MUST be saved and assignable to process templates
- AND when a vote has 10 for, 10 against, 3 abstain, the calculation MUST be 10/20 = 50% (not adopted, tie)
- AND the chair MUST be prompted for a casting vote

#### Scenario: Configure weighted voting for shareholders

- GIVEN a corporate BV with shareholders holding different share percentages
- WHEN the administrator configures weighted voting based on share ownership
- THEN each member's vote weight MUST be proportional to their shares
- AND the system MUST calculate results based on weighted totals, not headcount
- AND split votes MUST be supported (e.g., 60% for, 40% against on same shareholding) per Lumi Global pattern (external source #264)

#### Scenario: Configure consent-based decision rules

- GIVEN an organization using sociocracy
- WHEN the administrator configures a consent-based voting rule
- THEN the rule MUST define: proposal round, reaction round, objection round, integration round (external source #256)
- AND the decision MUST be adopted when no objections remain (not requiring explicit approval)
- AND each round MUST support configurable time limits

#### Scenario: Configure quadratic voting for prioritization

- GIVEN an organization wanting intensity-of-preference voting
- WHEN the administrator configures quadratic voting
- THEN voters MUST be allocated a vote budget (credits)
- AND the cost of votes MUST follow quadratic scaling (1 vote = 1 credit, 2 votes = 4, 3 votes = 9)
- AND this prevents tyranny of the majority while allowing preference signaling (external source #254)

#### Scenario: Configure silence/written procedure

- GIVEN a board using written decision procedures (besluitvorming buiten vergadering)
- WHEN the administrator configures a silence procedure
- THEN a draft decision MUST be circulated to all members with a deadline (24-72 hours)
- AND the decision MUST be automatically adopted if no objections within the deadline (external source #308)
- AND BW 2:238 unanimous consent to the METHOD MUST be tracked separately from consent to the decision (insight #18)

#### Scenario: Secret ballot enforcement for appointments

- GIVEN a municipal council template for appointments (Gemeentewet Art. 31)
- WHEN a vote concerns appointment or dismissal of persons
- THEN the voting rule MUST enforce secret ballot automatically
- AND individual votes MUST NOT be recorded or attributable (external source #273; insight #17)

---

### Requirement: Built-in Process Templates

The system MUST ship with built-in process templates for common governance contexts. Built-in templates MUST be read-only but duplicable for customization. Templates MUST encode domain-specific legal requirements.

**Feature tier**: V1
**Evidence**: 5 governance domains identified in intelligence DB, each with distinct procedural requirements. Market is completely siloed -- no competitor covers all 5 (insight #7).

#### Built-in template: Association ALV (Algemene Ledenvergadering)

- States: draft, proposed, agenda_published, debating, voting, adopted, rejected, enacted
- Quorum: >50% of members present or represented (configurable per statutes)
- Voting: simple majority (50%+1 of votes cast); 2/3 for statute amendments
- Legal basis: BW Book 2, WBTR, WDAV
- Special rules: proxy voting, written procedure option (BW 2:238)

#### Built-in template: Association Board Meeting

- States: draft, proposed, discussed, decided, enacted
- Quorum: >50% of board members
- Voting: simple majority (configurable)
- Special rules: unanimous written decisions allowed, conflict of interest abstention

#### Built-in template: Corporate Board (BV/NV)

- States: draft, proposed, management_review, supervisory_approval, enacted, archived
- Quorum: configurable per statutes
- Voting: depends on statutes (simple, qualified, or unanimous)
- Legal basis: BW Book 2, Dutch Corporate Governance Code
- Special rules: comply-or-explain tracking (external source #197), weighted voting by shares

#### Built-in template: Municipal Council (Gemeenteraad)

- States: draft, commission_review, BOB_beeldvorming, BOB_oordeelsvorming, BOB_besluitvorming, voting, adopted, rejected, enacted
- Quorum: >50% of seated members (Gemeentewet Art. 20); second meeting can proceed without quorum (Art. 29)
- Voting: absolute majority of votes cast; secret ballot for appointments
- Legal basis: Gemeentewet Art. 20/29/30/31/32
- Special rules: tie in non-full meeting = postpone; tie in full meeting = rejected (external source #292)
- BOB phases: Beeldvorming, Oordeelsvorming, Besluitvorming tracked per agenda item (external source #337)

#### Built-in template: Operational Team Meeting (MT)

- States: proposed, discussing, decided, action_assigned, completed
- Quorum: none required (configurable)
- Voting: consensus or simple majority
- Special rules: DACI framework integration (Driver, Approver, Contributor, Informed) per Atlassian pattern (external source #112)

#### Built-in template: Citizen Participation (Burgerberaad)

- States: intake, moderation_review, published, discussion_open, synthesis, response_pending, responded, archived
- Quorum: none (participation-based)
- Decision method: advisory (non-binding), results synthesized by themes (user story #258)
- Special rules: anonymous contributions, representative sampling

#### Scenario: Use built-in ALV template without customization

- GIVEN a new Decidesk installation for an association
- WHEN the administrator selects the built-in "Association ALV" template
- THEN the template MUST include all legally required states and voting rules for Dutch associations (BW Book 2)
- AND the template MUST be immediately usable without further configuration
- AND the template MUST display a summary of applicable legal requirements

#### Scenario: Customize a built-in template

- GIVEN the built-in "Municipal Council" template
- WHEN the administrator duplicates it for their specific municipality
- THEN they MUST be able to add custom commission states (e.g., "finance_commission", "audit_commission")
- AND they MUST be able to customize speaking time rules per their Reglement van Orde
- AND legal requirements from Gemeentewet MUST NOT be removable (enforced as read-only guards)

---

### Requirement: Admin UI for Process Template Management

The system MUST provide an administrative interface for creating, editing, and managing process templates. The UI MUST support visual editing of state machines, voting rules, and template metadata.

**Feature tier**: V1

#### Scenario: Visual state machine editor

- GIVEN an administrator creating a new process template
- WHEN they open the state machine editor
- THEN they MUST be able to add states by clicking on a canvas
- AND they MUST be able to draw transitions between states by connecting them
- AND guard conditions MUST be configurable via a form on each transition
- AND the editor MUST validate the state machine for reachability (no orphan states)

#### Scenario: Voting rule editor with decision table

- GIVEN an administrator configuring voting rules
- WHEN they open the voting rule editor
- THEN a decision table MUST be displayed with columns for conditions and outcomes
- AND conditions MUST include: vote type, majority threshold, quorum requirement, abstention handling
- AND the administrator MUST be able to add, remove, and reorder rows
- AND the table MUST preview results with sample vote inputs

#### Scenario: Template testing mode

- GIVEN a completed process template
- WHEN the administrator clicks "Test Template"
- THEN the system MUST create a sandbox decision using the template
- AND the administrator MUST be able to walk through all states and transitions
- AND the test MUST verify that all guard conditions are evaluable
- AND the test decision MUST be automatically deleted after testing

---

### Requirement: Multi-step Authorization Workflows

The system MUST support multi-step authorization workflows where different roles are required at different stages of the decision process. This is critical for destruction authorization, supervisory approvals, and committee-to-plenary escalation.

**Feature tier**: V1
**Evidence**: User story #271 (critical) requires multi-step destruction authorization. User story #25 (must) requires digital approval workflow for supervisory board decisions.

#### Scenario: Supervisory board approval workflow

- GIVEN a management decision requiring supervisory board approval
- WHEN the decision reaches the "supervisory_approval" state
- THEN only users with the "supervisory_board" role MUST be able to approve
- AND the approval MUST require a configurable number of approvers (e.g., 2 of 5)
- AND the workflow MUST support delegation with audit trail (user story #25)

#### Scenario: Committee recommendation to plenary

- GIVEN a council proposal in the "commission_review" state
- WHEN the commission completes its review
- THEN a recommendation (positive, negative, or amended) MUST be attached to the proposal
- AND the proposal MUST automatically transition to the plenary track
- AND the commission's findings MUST be linked to the decision record (user story #73)

## User Stories

### High-Priority Stories (from Intelligence DB)

1. **Supervisory board chair managing approval workflow** (DB #25, priority: must): As a supervisory board chair, I want a digital workflow for approving major management decisions, so that approvals can be obtained efficiently even outside scheduled meetings. *AC: Circular resolution capability, voting with deadline and reminders, supporting document attachment, audit trail of all votes and comments.*

2. **Secretary verifying voting requirements** (DB #59, priority: must): As secretary, I want to verify that a statute amendment vote meets the required quorum and qualified majority so that the notary can confirm proper adoption.

3. **Council member drafting motion from template** (DB #144, priority: must): As a raadslid, I want to create a motion using a standard template, so that my motion meets all procedural requirements without manual formatting.

4. **Policy advisor creating standardized council proposal** (DB #154, priority: must): As a beleidsadviseur, I want to create a council proposal using a standardized template with all required sections, so that the griffie can process it efficiently.

5. **Board secretary taking digital minutes with template** (DB #11, priority: must): As a board secretary, I want to take structured minutes during the AGM using a digital template, so that all resolutions, votes, and key discussions are accurately captured. *AC: Pre-populated template with agenda items, resolution outcomes auto-filled from voting, action item tagging.*

6. **Archivist requiring multi-step destruction authorization** (DB #271, priority: critical): As an Archivist, I want destruction to require multi-step authorization (records manager proposes, archivist approves, system executes), so that no unauthorized destruction occurs. *AC: Multi-step propose-approve-execute, destruction certificate with audit trail.*

### Medium-Priority Stories

7. **Committee member submitting report with template** (DB #73, priority: medium): As a committee member, I want to submit our committee report with findings and recommendations using a standard template so that the board can act on our advice.

8. **MT member initiating policy with template** (DB #102, priority: medium): As an MT member, I want to initiate a policy draft using a standardized template that guides me through required sections and identifies stakeholders, so that the policy is complete and ready for review.

9. **Citizen initiative leader drafting proposal** (DB #212, priority: high): As a citizen initiative leader, I want to draft my proposal using a guided template so that it meets all formal municipal requirements.

10. **Community leader drafting Right to Challenge proposal** (DB #233, priority: high): As a community leader, I want a guided template for my Right to Challenge proposal covering task description, capacity demonstration, budget, and community support.

### Capability Stories (from external source clustering)

11. **PHP state machine capabilities** (DB #365): Platform requires PHP state machine capabilities -- addressed by Symfony Workflow Component.

12. **BPMN/DMN engine capabilities** (DB #549, #550, #553, #554): Platform requires BPMN/DMN capabilities -- addressed by DMN-inspired decision tables (not full BPMN, per insight #14).

13. **Decision workflow capabilities** (DB #388): Platform requires configurable decision workflow -- core purpose of this spec.

## Acceptance Criteria

- Process templates are stored as OpenRegister objects with YAML state machine definitions
- State machines use Symfony Workflow Component YAML format on the backend
- State machine visualization uses XState on the frontend with Vue integration
- Backend-frontend state synchronization generates XState JSON from Symfony YAML
- Voting rules support simple, qualified, unanimous, weighted, consent-based, and quadratic majority
- Abstention handling is configurable (counted or excluded)
- Tie-breaking methods are configurable per template (chair's casting vote, rejection, postponement)
- Secret ballot is automatically enforced for appointment votes
- Silence/written procedure is supported with configurable deadlines
- Built-in templates ship for ALV, board, corporate, municipal council, MT, and citizen participation contexts
- Built-in templates encode non-removable legal requirements as read-only guards
- Templates are duplicable for customization
- Admin UI provides visual state machine editor and decision table editor
- Template testing mode validates all guard conditions
- Multi-step authorization workflows are configurable per template
- Every state transition is recorded in an immutable audit trail with WHO/WHAT/WHEN/WHY/HOW
- All templates comply with applicable Dutch legal requirements (BW, Gemeentewet, WBTR, WDAV)

## External Sources

| # | Type | Title | Key Insight |
|---|------|-------|-------------|
| 301 | tool | Symfony Workflow Component | PHP-native state machine, YAML config, guards, events, marking store |
| 300 | tool | XState 5 | JS/TS statecharts, serializable JSON, @xstate/vue, visual editor |
| 318 | documentation | XState Actor Model Patterns | Delayed transitions, parallel states, Vue integration |
| 283 | standard | DMN Decision Model and Notation | Decision tables with hit policies for voting rules |
| 282 | standard | BPMN 2.0 | Process modeling standard (informed design, not implemented) |
| 286 | standard | UML State Machine Diagrams | Hierarchical states, parallel regions, guards |
| 311 | analysis | Decision Audit Trails | WHO/WHAT/WHEN/WHY/HOW metadata per transition |
| 308 | analysis | Silence/Written Procedure | Tacit approval pattern with deadline-based adoption |
| 256 | documentation | Consent Decision Making (Sociocracy) | Proposal-reaction-objection-integration rounds |
| 254 | scientific | Quadratic Voting (SSRN) | Intensity-of-preference voting, prevents tyranny of majority |
| 273 | documentation | Gemeentewet Art. 32 | Rollcall voting, oral votes, tie-breaking rules |
| 292 | legal | Gemeentewet Quorum Art. 20/29/30 | Municipal quorum and voting rules |
| 293 | legal | BW 2:230/238 | Configurable majority, written procedure rules |
| 112 | blog | DACI Framework (Atlassian) | Driver-Approver-Contributor-Informed decision model |
| 317 | analysis | EU Council Decision Procedures | QMV, simple majority, unanimity patterns |
| 284 | standard | CMMN 1.1 | Case management notation for less structured processes |

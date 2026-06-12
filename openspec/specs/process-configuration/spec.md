---
status: idea
status-note: 2026-06-12 audit — 0/4 requirements built (V1-deferred). WorkflowService backend plumbing exists; no template management, state-machine editor, voting-rule configuration, or built-in templates.
---

# Process Configuration Specification

## Purpose
@e2e exclude V1 feature — no processTemplate pages exist in the manifest and no process configuration UI is built in the SPA. The YAML/DMN state-machine editor and built-in template selection UI are planned but not yet implemented.

Process configuration enables administrators to define and customize decision-making workflows for different governance contexts. A process template defines the state machine, voting rules, quorum requirements, and procedural rules for a specific type of decision or meeting. The system uses YAML-based Symfony Workflow definitions for state machines and DMN-inspired decision tables for voting rules. This allows Decidesk to serve municipal councils, corporate boards, associations, and operational teams with their own procedural rules.

**Standards**: Symfony Workflow Component (YAML config), DMN (Decision Model and Notation) for voting rules, Schema.org (`HowTo`, `HowToStep`)
**Feature tier**: V1

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

#### Scenario: Create a process template for statute amendments

- GIVEN the same administrator
- WHEN they create a process template "ALV Statute Amendment"
- THEN the template MUST specify voting rule: qualified majority (2/3 of votes cast)
- AND the template MUST specify quorum: 2/3 of total members present
- AND the template MUST include a required legal review step before voting

#### Scenario: Duplicate and customize an existing template

- GIVEN an existing process template "Board Standard Decision"
- WHEN the administrator duplicates it as "Board Urgent Decision"
- THEN the new template MUST be a copy of the original
- AND the administrator MUST be able to modify states, transitions, and rules independently
- AND the original template MUST remain unchanged

---

### Requirement: State Machine Configuration

The system MUST support defining state machines as YAML-based Symfony Workflow configurations. Each state MUST have a name, optional description, and optional metadata (e.g., required approvers, time limits). Transitions MUST define from-state, to-state, guard conditions, and triggered actions.

**Feature tier**: V1

#### Scenario: Define a custom state machine with guard conditions

- GIVEN an administrator editing a process template
- WHEN they add a transition "start_voting" from "debating" to "voting" with guard condition "quorum_met AND all_amendments_resolved"
- THEN the system MUST validate the YAML syntax
- AND the guard condition MUST be enforced at runtime
- AND the transition MUST only be allowed when both conditions are true

#### Scenario: Visualize the state machine

- GIVEN a process template with a defined state machine
- WHEN the administrator views the template
- THEN the system MUST display a visual state machine diagram showing all states and transitions
- AND the current state MUST be highlighted when viewing a specific decision

---

### Requirement: Voting Rule Configuration

The system MUST support configurable voting rules using DMN-inspired decision tables. Rules MUST specify: majority type (simple, qualified, unanimous), quorum threshold, abstention handling (counted or excluded), tie-breaking method, and secret ballot requirement.

**Feature tier**: V1

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

---

### Requirement: Built-in Process Templates

The system MUST ship with built-in process templates for common governance contexts: association ALV, association board, corporate board (BV), municipal council, and operational team meeting. Built-in templates MUST be read-only but duplicable for customization.

**Feature tier**: V1

#### Scenario: Use built-in ALV template without customization

- GIVEN a new Decidesk installation for an association
- WHEN the administrator selects the built-in "Association ALV" template
- THEN the template MUST include all legally required states and voting rules for Dutch associations (BW Book 2)
- AND the template MUST be immediately usable without further configuration

## User Stories

1. **Legal counsel tracking governance code compliance**: As legal counsel, I want to track compliance with each provision of the Corporate Governance Code, so that I can prepare the comply-or-explain statement for the annual report. (Source: intelligence DB #39)

2. **Supervisory board chair managing approval workflow**: As a supervisory board chair, I want a digital workflow for approving major management decisions, so that approvals can be obtained efficiently even outside scheduled meetings. (Source: intelligence DB #25)

3. **Secretary verifying voting requirements**: As secretary, I want to verify that a statute amendment vote meets the required quorum and qualified majority so that the notary can confirm proper adoption. (Source: intelligence DB #59)

## Acceptance Criteria

- Process templates are stored as OpenRegister objects with YAML state machine definitions
- State machines use Symfony Workflow Component YAML format
- Voting rules support simple, qualified, unanimous, and weighted majority
- Abstention handling is configurable (counted or excluded)
- Tie-breaking methods are configurable per template
- Built-in templates ship for ALV, board, council, and operational contexts
- Templates are duplicable for customization
- State machine visualization is available in the admin UI

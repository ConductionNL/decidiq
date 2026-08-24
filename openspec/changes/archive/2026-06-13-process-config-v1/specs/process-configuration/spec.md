---
status: draft
---

# Spec Delta: Process Configuration (process-config-v1)

## Purpose

Builds the four V1-deferred requirements of the seeded process-configuration
spec. The requirement texts below replace their seeded counterparts to reflect
the implementation: process templates are OpenRegister objects carrying a
**structured JSON** state machine (1:1 convertible to Symfony Workflow YAML — a
YAML import/export remains future work) and per-template default voting rules. A
body's assigned template drives the existing `DecisionTransitionGuard` /
`WorkflowService` policy **additively**, falling back to the built-in hardcoded
default-deny policy when no template is assigned. All other requirements are
untouched.

## MODIFIED Requirements

---

### Requirement: Process Template Management

The system MUST support creating, editing, duplicating, listing and deleting
process templates. Each template MUST define a state machine (states and
transitions), default voting rule, quorum requirement and optional
decide-without-vote flag. Templates MUST be stored as OpenRegister objects in the
`decidesk` register using the `processTemplate` schema and MUST be managed through
an admin-gated surface (`#[AuthorizedAdminSetting]`). Built-in templates MUST be
read-only (edit and delete refused) but MUST be duplicable into an editable copy.

#### Scenario: Create a process template for ALV decisions

- GIVEN an administrator on the Decidesk admin process-templates section
- WHEN they create a template "ALV Standard Decision"
- THEN the template MUST persist as a `processTemplate` object with its state set
- AND the template MUST carry a default voting rule (simple-majority)
- AND the template MUST be assignable to a body via the body's `processTemplate`
  identifier

#### Scenario: Duplicate and customize an existing template

- GIVEN an existing process template (built-in or custom)
- WHEN the administrator duplicates it
- THEN the new template MUST be a copy with a fresh slug and `builtIn` cleared
- AND the administrator MUST be able to modify the copy's states, transitions and
  rules independently
- AND the original template MUST remain unchanged

#### Scenario: Built-in templates are read-only

- GIVEN a built-in template (`builtIn: true`)
- WHEN the administrator attempts to edit or delete it
- THEN the system MUST refuse the operation
- AND the administrator MUST still be able to duplicate it

---

### Requirement: State Machine Configuration

The system MUST support defining a per-template state machine as a structured
object: `states[]` (each with a name and optional metadata) and `transitions[]`
(each with `from`, `to`, optional `chairOnly` flag and optional `guards[]`). The
system MUST validate the transition graph server-side on save (fail closed): it
MUST reject a transition referencing a state absent from `states[]` (dangling), a
state with no inbound and no outbound transition that is not the declared
`initialState` (unreachable), and an unrecognized guard token. A body's assigned
template MUST drive the decision/meeting transition guards: when a body has a
template, the template's policy (chair-only transitions, quorum enforcement,
decide-without-vote) MUST be consulted; when no template is assigned, the built-in
hardcoded default-deny domain policy MUST apply unchanged. A malformed template
MUST fall back to the default-deny policy (never fail open).

#### Scenario: Reject an invalid transition graph

- GIVEN an administrator editing a template's state machine
- WHEN they add a transition whose `to` state is not declared in `states[]`
- THEN the server MUST refuse to save the template (HTTP 400)
- AND the validation error MUST identify the dangling state

#### Scenario: Assigned template drives the guard, absent template falls back

@e2e exclude Backend guard-policy resolution with no UI surface; covered by DecisionTransitionGuardTest + ProcessTemplateServiceTest (guard-consults-template-with-fallback).

- GIVEN a decision whose meeting belongs to a body with an assigned template
- WHEN the lifecycle guard evaluates an available transition
- THEN the guard MUST use the template's policy (chair-only, quorum)
- AND GIVEN a decision whose meeting belongs to a body with no template
- THEN the guard MUST use the built-in hardcoded domain policy unchanged

---

### Requirement: Voting Rule Configuration

The system MUST support a per-template default voting rule specifying
`voteThreshold` (simple-majority, qualified-majority-two-thirds,
qualified-majority-three-quarters, unanimous), `abstentionHandling` (exclude or
count) and `tieBreakRule` (rejected, chair-decides, revote) — mirroring the
VotingRound schema enums added by voting-rules-v1. When a voting round is opened
for a motion under a body that has a template, the template's rule defaults MUST
apply unless the caller explicitly overrides them; an explicit caller-supplied
value MUST always take precedence. A missing template MUST leave the built-in
method defaults in place (fail-soft).

#### Scenario: Template voting-rule defaults applied at round-open

@e2e exclude Backend round-open default resolution; covered by VotingServiceTemplateRuleTest + Newman (admin/non-admin auth). API behaviour, not a UI surface.

- GIVEN a body with a template whose default `voteThreshold` is
  `qualified-majority-two-thirds`
- WHEN a voting round is opened for a motion under that body without an explicit
  threshold
- THEN the round MUST be created with `voteThreshold = qualified-majority-two-thirds`
- AND GIVEN the caller supplies an explicit `voteThreshold`
- THEN the caller's value MUST be used instead

---

### Requirement: Built-in Process Templates

The system MUST ship with built-in process templates for common governance
contexts: association ALV, association board, corporate board (BV), municipal
council, and operational team. Built-in templates MUST be seeded via
`x-openregister-seeds` so a fresh install has usable templates immediately. Each
built-in MUST carry `builtIn: true`, a valid state machine and an appropriate
default voting rule for its context.

#### Scenario: Use built-in ALV template without customization

@e2e exclude Built-in seed presence + usability is asserted via Newman (list returns the five built-ins) and the read-only built-in row in process-configuration.spec.ts; no distinct UI surface for "use without customization".

- GIVEN a fresh Decidesk installation
- WHEN the administrator selects the built-in "Association ALV" template for a body
- THEN the template MUST be immediately usable with its seeded states and voting
  rule, without further configuration

# Design: process-config-v1

## Context

`DecisionTransitionGuard` (decision lifecycle) and `WorkflowService` (meeting
lifecycle) are pure, DI-free guards whose policy is a set of PHP `const` arrays
keyed by governance domain (`legislative|association|corporate|operations|citizen`),
each falling back to a restrictive default-deny `RESTRICTED_POLICY` for unknown
domains (#314). `GovernanceBody` already carries a `processTemplate` identifier
(#61) plus `additionalTemplates`, but no schema, service or UI manages the templates
those identifiers point at — the identifier is inert.

Goal: make a body's assigned template the source of lifecycle/voting policy, while
preserving the hardcoded constants as the fallback so nothing regresses.

## Goals / Non-Goals

**Goals**
- CRUD + duplicate for process templates as OpenRegister objects.
- Per-template state machine with server-validated transition graph.
- Per-template voting-rule defaults applied at round-open.
- Built-in seed templates for the five standard contexts.
- Additive guard wiring with hardcoded fallback.

**Non-Goals**
- Replacing the guards with a Symfony Workflow runtime dependency. The spec
  references Symfony Workflow YAML *format*; decidesk keeps its existing
  guarded-transition-map pattern and stores the equivalent data as JSON on the
  template object (the structured form the editor produces). A YAML import/export
  is out of scope for V1 (recorded as residue).
- A graphical drag-and-drop state diagram. V1 ships a structured form plus a
  read-only textual graph summary; an SVG diagram is residue.
- Weighted voting *execution*. The template can declare `weighted` as the voting
  method, but the weighted-tally engine is owned by the voting-system spec, not
  this change. The template merely carries the configuration.

## Key Decisions

### Decision 1: Template stored as JSON, not YAML
The spec mentions Symfony Workflow YAML. Decidesk's guards are a const-map pattern,
not Symfony Workflow. Storing the state machine as a structured JSON object on the
`processTemplate` schema (states[], transitions[]) lets the OpenRegister object API,
the structured Vue editor and the PHP policy resolver all consume one shape without
a YAML parser dependency. The shape is 1:1 convertible to Symfony Workflow YAML, so
a later import/export is purely additive.

### Decision 2: Additive `?array $policyOverride` on the guards
`DecisionTransitionGuard::getDomainPolicy()` and the methods that call it
(`isTransitionAllowed`, `requiresChairAuthorization`, `isQuorumRequired`,
`getAvailableActions`) gain an optional trailing `?array $policyOverride = null`.
When non-null, the override array replaces the domain-keyed lookup; when null,
behaviour is byte-identical to today. `WorkflowService` gets the same treatment.
The guards stay pure — the override is just data, computed by the caller. This is
the only way to keep the guards DI-free and exhaustively unit-testable while letting
a template drive them.

### Decision 3: `ProcessTemplatePolicyResolver` translates template → policy shape
A template's `stateMachine.transitions[]` (each `{from,to,chairOnly,guards[]}`) plus
its `quorumRequired` / `allowDecideWithoutVote` flags are mapped to the guard policy
shape `{quorumEnforced, chairOnlyTransitions:["from:to"], allowDecideWithoutVote}`.
The resolver is a pure translator (no DI) so it is unit-testable in isolation. It
returns null when the template is missing required keys, so the caller falls back to
the hardcoded domain policy — fail-safe, never fail-open: a malformed template never
*loosens* the guard, it just reverts to the default-deny domain constants.

### Decision 4: Body-template resolution lives in the lifecycle service
`DecisionLifecycleService` already resolves `domain` from decision → meeting →
'operations'. It additionally resolves the linked meeting's `governanceBody`, loads
that body, reads `processTemplate`, loads the template object, and runs it through
the resolver to produce the override (or null). The guard call sites pass the
override alongside the existing domain. The domain is still resolved and still used
as the fallback key inside the guard, so the two coexist.

### Decision 5: Voting-rule defaults at round-open
`VotingService::openVotingRound()` gains an optional `?string $governanceBodyId`.
When supplied and the caller left a rule at its method default, the body template's
`votingRule` value is substituted. Explicit caller values always win (so an
amendment round that needs qualified-majority can still force it). Resolution is
fail-soft: a missing template leaves the method defaults in place.

### Decision 6: Transition-graph validation server-side (fail closed)
`ProcessTemplateService::validateStateMachine()` rejects: empty states; a transition
referencing a state not in `states[]` (dangling); a state with no inbound and no
outbound transition that is not the declared `initialState` (unreachable); an unknown
guard token (only `quorum_met`, `chair_only`, `all_amendments_resolved`,
`legal_review_complete` are recognised in V1). Create/edit refuse to persist an
invalid graph (HTTP 400). The Vue editor runs the same checks for fast feedback but
is never authoritative.

### Decision 7: Built-in templates are read-only seeds
The five built-ins ship as `x-openregister-seeds` with stable slugs
(`association-alv`, `association-board`, `corporate-board`, `municipal-council`,
`operational-team`) and `builtIn: true`. The service refuses edit/delete on
`builtIn` templates (409) but allows duplicate, which clears `builtIn` and assigns a
new slug — matching the spec's "read-only but duplicable" requirement.

## Risks / Trade-offs

- **Concurrent meeting-efficiency PR** may also touch `decidesk_register.json`,
  `registry.js`, `run-all.sh`, l10n and `manifest.json`. Mitigated by the ADR-037
  fragment system (new schema lands in `register.d/43-process-config-v1.json`, not
  the monolith) and union-merge on rebase for the shared frontend files.
- **Resolver fail-safe direction**: a malformed template reverts to default-deny,
  never fail-open. Verified by a unit test asserting a template missing
  `stateMachine` yields a null override (→ hardcoded fallback).

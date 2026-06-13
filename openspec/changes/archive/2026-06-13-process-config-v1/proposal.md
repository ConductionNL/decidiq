## Why

The seeded `process-configuration` spec is V1-deferred with 0/4 requirements
built. The decision/meeting lifecycle policy that drives `DecisionTransitionGuard`
and `WorkflowService` is currently hardcoded as PHP constants
(`DOMAIN_POLICIES` / `DOMAIN_WORKFLOWS`). The admin-settings change (#61) added
template *assignment* (a `processTemplate` identifier on `GovernanceBody`) and an
ADR-037 register-fragment system, but template *management* — creating/editing
the templates themselves, configuring the state machine and voting rules a body
follows — does not exist. As a result the assigned template identifier is inert:
nothing consumes it.

This change builds the complement: process-template management (CRUD), per-template
state-machine configuration with server-side transition-graph validation,
per-template default voting rules, and built-in seed templates for the standard
governance contexts. Crucially, it wires the assigned template into the existing
guards **additively**: when a body has a template assigned, the template's policy
drives the guard; when absent, the built-in hardcoded default-deny policy still
applies unchanged. Nothing that works today regresses.

## What Changes

- **Process template management** — a new `processTemplate` schema (ADR-037
  fragment), a `ProcessTemplateService` with create/edit/duplicate/list/delete
  over the OpenRegister object API, and a `ProcessTemplateController` admin-gated
  surface. A new admin settings section (`ProcessTemplates.vue` inside the existing
  Decidesk admin panel) lists, creates, edits and duplicates templates.
- **State-machine configuration** — each template carries a `stateMachine`
  (states + transitions with `from`/`to`/`guards`/`chairOnly`). The service
  validates the transition graph server-side (rejects unreachable and dangling
  states, unknown guard tokens). A structured editor (`StateMachineEditor.vue`)
  edits states/transitions; the graph is validated client-side for fast feedback
  and authoritatively server-side on save.
- **Guard wiring (additive)** — `DecisionTransitionGuard` and `WorkflowService`
  gain optional `?array $policyOverride` parameters on their policy methods. A new
  `ProcessTemplatePolicyResolver` translates a body's assigned template into the
  policy shape the guards consume. `DecisionLifecycleService` resolves the body
  template and passes the override through; when no template is assigned (or it is
  unparseable), the override is null and the existing hardcoded constants apply.
- **Voting-rule configuration** — each template carries a `votingRule`
  (`voteThreshold` / `abstentionHandling` / `tieBreakRule`). When a voting round is
  opened under a body with a template, the template's defaults apply unless the
  caller explicitly overrides them. `VotingService::openVotingRound()` gains an
  optional `?string $governanceBodyId` that, when supplied, sources the rule
  defaults from the body's template (caller-supplied values still win).
- **Built-in templates** — five read-only seed templates (Association ALV,
  Association Board, Corporate Board (BV), Municipal Council, Operational Team)
  ship via `x-openregister-seeds` on the new schema, so a fresh install has usable
  templates immediately.

## Impact

- Affected specs: `process-configuration` (idea → built; 4 requirements).
- Affected code: new `processTemplate` schema fragment; new
  `ProcessTemplateService`, `ProcessTemplatePolicyResolver`,
  `ProcessTemplateController`; additive params on `DecisionTransitionGuard`,
  `WorkflowService`, `VotingService`, `DecisionLifecycleService`; new admin Vue
  sections + store module; new routes; i18n; tests (PHPUnit, vitest, Playwright,
  Newman).
- Non-breaking: every wiring point falls back to today's hardcoded behaviour when
  no template is assigned. Schema changes are additive.

# process-configuration Specification (delta)

**Status**: planned
**Scope**: decidesk
**OpenSpec changes**:
- unified-decision-templates

## Purpose

Delta for the unified-decision-templates change (schema-declaration link of an
ADR-032 chain): declares a unified `DecisionTemplate` schema that supersedes
`ProcessTemplate` (this capability) and `VveDecisionTemplate` +
`ModelreglementPreset` (`vve-alv-pack`), keyed by `decisionType` ×
`context` rather than `context` alone, and adds an ordered `checklist[]` — a
capability no prior template schema had. This delta declares the schema,
seeds it, and non-destructively marks the superseded schemas inactive; it
does **not** change how `ProcessTemplateService` / `ProcessTemplatePolicyResolver`
/ `DecisionTransitionGuard` resolve a body's template — every existing
requirement in this capability (template CRUD, state-machine validation, the
guard consulting `ProcessTemplate`, voting-rule defaults) continues to
describe the live system exactly as before until the dependent
`unified-decision-templates-consumer-rewrite` change lands.

## ADDED Requirements

### Requirement: Unified DecisionTemplate schema declaration

The system SHALL declare a `DecisionTemplate` schema in the `decidesk`
register (slug `decision-template`) via a
`lib/Settings/register.d/67-unified-decision-templates.json` fragment
(ADR-037 — additive, never editing `decidesk_register.json` or the legacy
fragments in place). The schema SHALL carry every property `ProcessTemplate`
carries today (`name`, `description`, `context`, `builtIn`, `initialState`,
`stateMachine` with `states[]`/`transitions[]`, `votingRule`
`{voteThreshold, abstentionHandling, tieBreakRule}`, `quorumRequired`,
`quorumRule`, `allowDecideWithoutVote`, and `urgencyPolicy`
`{allowedTriggerRoles, minimumNoticeFloorHours, responseDeadlineHours, ratificationRequired, ratifyingBody}`
folded in natively rather than as a bolt-on delta), plus three new
properties: `decisionType` (optional string, one of the `Decision.decisionType`
enum values — `motion`, `amendment`, `resolution`, `contract`,
`contract-renewal`, `report-adoption`, `appointment`, `management-point`,
`policy`, `meeting-outcome`; absent means the template is the generic default
for its `context`, mirroring `GovernanceBody.processTemplate`'s existing
default-template semantics, while a populated value mirrors
`GovernanceBody.additionalTemplates[]`'s existing per-decision-type
semantics), `templateCategory` (optional string — a finer classification
within `decisionType` for domains that need one, e.g. the VvE ALV categories
`discharge`, `annual-accounts`, `reserve-fund-contribution`,
`mjop-adoption`, `authorisation-above-threshold`,
`amendment-internal-regulations`, `other`), and `proposedText` +
`regulationSource` (both optional strings, ported unchanged from
`VveDecisionTemplate`). `context` SHALL use the same enum as
`ProcessTemplate.context` (`association`, `corporate`, `legislative`,
`operations`, `citizen`) and remains required. This schema declaration alone
creates no new consumer — resolution against a `GovernanceBody` or a
`Decision.decisionType` is unchanged and continues to use `ProcessTemplate`
until the consumer-rewrite change lands.

#### Scenario: Fragment adds DecisionTemplate without touching existing schemas

- **GIVEN** the register fragment `67-unified-decision-templates.json` is loaded
- **WHEN** the decidesk register imports
- **THEN** the `decision-template` schema exists with `decisionType`,
  `context`, `templateCategory`, `stateMachine`, `votingRule`,
  `quorumRequired`/`quorumRule`, `allowDecideWithoutVote`, `urgencyPolicy`,
  `proposedText`, `regulationSource`, and `checklist[]`
- **AND** the `process-template`, `vve-decision-template`, and
  `modelreglement-preset` schemas are unmodified in shape (only their
  `x-openregister.active` flag changes, per the superseded-schemas
  requirement below)
- **AND** `ProcessTemplateService`, `ProcessTemplatePolicyResolver`, and
  `DecisionTransitionGuard` are byte-for-byte unchanged

#### Scenario: A generic default template has no decisionType

- **GIVEN** a built-in `DecisionTemplate` ported from `ProcessTemplate`
  (e.g. "Municipal Council")
- **WHEN** the template is inspected
- **THEN** `decisionType` is absent, `context=legislative`, and the template
  is understood as the default for any decision under that context — the
  same role `GovernanceBody.processTemplate` plays today

#### Scenario: A specialized template narrows by decisionType and templateCategory

- **GIVEN** a built-in `DecisionTemplate` ported from `VveDecisionTemplate`
  "Decharge bestuur"
- **WHEN** the template is inspected
- **THEN** `context=association`, `decisionType=resolution`,
  `templateCategory=discharge`, `proposedText` carries the besluittekst, and
  `regulationSource` carries `"BW 2:48/2:49"`

### Requirement: Decision template checklist

Every `DecisionTemplate` SHALL support an optional ordered `checklist[]`: an
array of check items, each with `sequence` (1-based integer), `label`
(string, required), `description` (string, optional), and `required`
(boolean, default `true`). An empty or absent `checklist[]` SHALL remain
valid — templates ported from `ProcessTemplate`/`VveDecisionTemplate` carry
no checklist by default, preserving byte-for-byte behavioural parity for
every existing built-in. This requirement declares the checklist
**definition** on the template only; instantiating a per-decision
checklist-progress record (which items are ticked, by whom) is out of scope
for this capability delta — see the proposal's Out of Scope.

#### Scenario: A template declares an ordered checklist

- **GIVEN** an administrator (in a future consumer-rewrite editor) defining a
  `DecisionTemplate` for `decisionType=contract`
- **WHEN** they add checklist items "Legal review complete" (required),
  "Budget holder sign-off" (required), "Communications brief drafted"
  (optional)
- **THEN** the template persists `checklist` as three ordered items with
  `sequence` 1–3 and the stated `required` flags

#### Scenario: A ported built-in template has no checklist

- **GIVEN** the built-in `DecisionTemplate` ported from `ProcessTemplate`
  "Association ALV"
- **WHEN** the template is loaded
- **THEN** `checklist` is absent or empty, and nothing about the template's
  state machine, voting rule, or quorum policy behaviour differs from the
  source `ProcessTemplate` object

### Requirement: Legacy template schemas superseded, non-destructively

`ProcessTemplate` SHALL be marked superseded by `DecisionTemplate`:
`x-openregister.active` SHALL be set to `false` via the
`67-unified-decision-templates.json` fragment's deep-merge (ADR-037), with a
schema `description` note naming `decision-template` as the successor. The
existing `process-template` objects, the `ProcessTemplateService`,
`ProcessTemplatePolicyResolver`, `DecisionTransitionGuard`,
`ProcessTemplateController`, and the admin `ProcessTemplates.vue` surface
SHALL continue to function exactly as before — `active: false` on a schema
governs whether NEW objects may be created against it (an OpenRegister
create-time guard), not whether existing objects remain readable or whether
existing service code keeps working. No PHP or Vue file is edited by this
requirement.

#### Scenario: ProcessTemplate is marked inactive but remains fully functional

- **GIVEN** the `67-unified-decision-templates.json` fragment is loaded
- **WHEN** the `process-template` schema is inspected
- **THEN** `x-openregister.active` is `false` and the description names
  `decision-template` as the successor
- **AND** every existing `process-template` object remains readable
- **AND** `ProcessTemplateService::list()`, `::get()`, and
  `::resolvePolicyForBody()` behave identically to before this change

#### Scenario: Rollback restores ProcessTemplate to active

- **GIVEN** the `67-unified-decision-templates.json` fragment is removed
  (rollback)
- **WHEN** the register reloads
- **THEN** `process-template.x-openregister.active` reverts to `true`
  automatically (ADR-037 deep-merge — no separate un-patch step needed)

### Requirement: Live legacy template objects are repaired into DecisionTemplate objects

Because OpenRegister seed import is create-only (new seeds in
`67-unified-decision-templates.json` never touch objects an existing install
already created from `43-process-config-v1.json` / `57-vve-alv-pack.json`),
the system SHALL provide an idempotent repair migration that reads every
live `process-template` and `vve-decision-template` object and creates the
equivalent `decision-template` object, carrying forward every field plus a
provenance marker (`migratedFrom`: the source object's schema slug + UUID).
Re-running the migration on an already-migrated instance SHALL be a no-op
(matched by the provenance marker) and SHALL create no duplicate objects.
The migration SHALL never modify or delete the source `process-template` /
`vve-decision-template` objects.

#### Scenario: A live custom ProcessTemplate is repaired

- **GIVEN** a Decidesk install with an administrator-created custom
  `process-template` object "Waterschap Bestuur" (not a built-in seed)
- **WHEN** the repair migration runs
- **THEN** an equivalent `decision-template` object is created with the same
  `name`, `stateMachine`, `votingRule`, `quorumRequired`/`quorumRule`, and
  `allowDecideWithoutVote`, `builtIn=false`, and `migratedFrom` naming the
  source `process-template` UUID
- **AND** the original `process-template` object is unchanged

#### Scenario: Re-running the migration is a no-op

- **GIVEN** an install where the repair migration has already run once
- **WHEN** the migration runs again
- **THEN** no additional `decision-template` objects are created and the
  existing migrated objects are unchanged

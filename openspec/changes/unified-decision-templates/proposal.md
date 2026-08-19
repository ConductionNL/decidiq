---
kind: config
---

# Proposal: unified-decision-templates

## Summary

Decidesk carries three parallel "template" schemas that all answer the same
question — *what route, voting rule and quorum policy applies to this
decision?* — for three different audiences: `ProcessTemplate`
(`43-process-config-v1.json`, plus the `urgencyPolicy` delta in
`46-urgency-policy.json`) drives the body-level state machine and voting
defaults consumed by `DecisionTransitionGuard` / `ProcessTemplateService`;
`VveDecisionTemplate` + `ModelreglementPreset` (`57-vve-alv-pack.json`)
duplicate the same idea — decision content plus a default majority/quorum —
for VvE (association) ALV decisions, but through a second, unconsumed schema
family. This change declares one unified `DecisionTemplate` schema — keyed by
`decisionType` (the existing `Decision.decisionType` discriminator) crossed
with `context` (the existing organisatie-modus enum) — that supplies route
stages (state machine), voting rule, quorum policy, urgency policy, and a new
ordered **checklist** of required/optional check items. VvE templates become
built-in `DecisionTemplate` instances scoped to `context=association`, not a
separate schema family. This is the **schema-declaration** link of a
three-part chain (ADR-032); it declares the unified schema, seeds it from the
three legacy sources, and repairs already-created live objects. It does
**not** rewire `ProcessTemplateService`, `DecisionTransitionGuard`, or the
admin Vue surface to read the new schema — that is the dependent
`unified-decision-templates-consumer-rewrite` change — nor does it delete the
legacy PHP classes — that is `unified-decision-templates-legacy-deletion`.

## Motivation

Three independent "what governs this decision" schemas exist today with
overlapping but non-identical shapes, and only one of the three is actually
consumed:

- `ProcessTemplate` (5 built-in seeds) is the only one with a live consumer:
  `ProcessTemplateService` resolves a `GovernanceBody.processTemplate` /
  `additionalTemplates[]` reference into a `stateMachine`, `votingRule`,
  `quorumRequired`/`quorumRule`, and `allowDecideWithoutVote`, which
  `ProcessTemplatePolicyResolver` translates into the policy override
  `DecisionTransitionGuard` consults. The `urgencyPolicy` object was added to
  `ProcessTemplate` by a later delta (`46-urgency-policy.json`) as a bolt-on
  property, not a first-class part of the template concept.
- `VveDecisionTemplate` (6 built-in seeds) + `ModelreglementPreset` (3 built-in
  seeds, the 1992/2006/2017 modelreglement versions) model the *same* idea —
  decision content plus a default `voteThreshold`/quorum — for VvE ALV
  decisions, but neither schema has **any** PHP or Vue consumer today (grep
  confirms `VveDecisionTemplate`, `ModelreglementPreset`, `VveConfiguration`
  appear nowhere outside `57-vve-alv-pack.json`, `src/manifest.d/vve-alv-pack.json`
  and `src/menu-layout.json`). The VvE ALV pack's own OpenSpec change
  (`openspec/changes/vve-alv-pack/`) is still `Status: planned` and unarchived
  — the schemas shipped ahead of their spec and ahead of any resolution logic.
- Product decision (approved, this change): decisions get **design types** —
  per `decisionType` × organisatie-modus, a template should supply route
  stages, voting rule, quorum policy, urgency policy, **and a checklist** of
  ordered check items with required/optional flags. No existing schema has a
  checklist. Adding it three times (once per template family) would triple
  the divergence this proposal exists to remove.

Per ADR-006 (Mode Adaptation Over Parallel Entities), "domain differences are
expressed by mode adaptation, never by parallel entities... one schema per
concept." Three schemas for one concept is exactly the pattern ADR-006 retired
for the board-portal overlay in Cycle 1. Per ADR-031, template *declaration*
(the schema + seeds) is the declarative default; template *resolution* at
transition time remains the documented imperative exception (lifecycle guard).

## Affected Projects

- [ ] Project: `decidesk` — new `DecisionTemplate` schema
  (`lib/Settings/register.d/68-unified-decision-templates.json`) seeded from
  `ProcessTemplate` (5), `VveDecisionTemplate` × `ModelreglementPreset` (6),
  and the `urgencyPolicy` delta (2 urgency-enabled seeds); `ProcessTemplate`,
  `VveDecisionTemplate`, `ModelreglementPreset` marked superseded
  (`x-openregister.active: false`, retained read-only for the migration
  window — never hard-deleted in this change); `VveConfiguration` retargeted
  from a `ModelreglementPreset` reference to a plain `modelReglementVersion`
  enum; a repair migration creates `decision-template` objects from every
  live `process-template` / `vve-decision-template` object (OR seed import is
  create-only — new seeds never touch objects a prior install already
  created).

No other Conduction app changes. OpenRegister is consumed as-is.

## Scope

### In Scope

1. **`DecisionTemplate` schema** (`68-unified-decision-templates.json`):
   `decisionType` (optional — one of the existing `Decision.decisionType`
   enum values; absent = generic default for the `context`, mirroring
   `GovernanceBody.processTemplate`'s current default-template semantics),
   `context` (association/corporate/legislative/operations/citizen, same
   enum as today's `ProcessTemplate.context` and `GovernanceBody.domain`),
   `templateCategory` (optional free-vocabulary narrowing field for
   templates that need a finer classification than `decisionType` alone —
   e.g. the VvE ALV categories discharge/annual-accounts/reserve-fund-contribution/
   mjop-adoption/authorisation-above-threshold/amendment-internal-regulations/other),
   `builtIn`, `initialState` + `stateMachine` (states[]/transitions[],
   unchanged shape from `ProcessTemplate` — the body-level lifecycle state
   machine `DecisionTransitionGuard` will consult; **not** the same concept
   as `decision-route`'s `DecisionStage` route, see design.md), `votingRule`
   (unchanged shape), `quorumRequired`/`quorumRule`, `allowDecideWithoutVote`,
   `urgencyPolicy` (folded in natively from the `46-urgency-policy.json`
   delta — same shape, no longer a bolt-on), `proposedText` (from
   `VveDecisionTemplate`, the pre-filled besluittekst), `regulationSource`
   (from `VveDecisionTemplate`/`ModelreglementPreset`, free-text article
   reference, flagged for juridical review same as today), and the new
   **`checklist[]`** (ordered `{sequence, label, required, description}`
   check items).
2. **Seed data**: 5 built-in seeds ported 1:1 from `ProcessTemplate`
   (`decisionType` absent = generic per-context default), 2 urgency-enabled
   seeds ported 1:1 from the `46-urgency-policy.json` custom templates, 6
   built-in seeds ported from `VveDecisionTemplate` (`context=association`,
   `decisionType=resolution` — VvE ALV besluiten are formal meeting
   resolutions; flagged as an Open Question for product/juridical
   confirmation, same posture as the existing `regulationSource` review
   flag) with the 2017 modelreglement's `categoryRules` folded in as each
   template's default `votingRule`/`quorumRule` (the current-law version;
   1992/2006 remain documented in `regulationSource` history, not re-seeded
   as separate template rows — a VvE on an older modelreglement deviates via
   `VveConfiguration.majorityOverrides`, unchanged mechanism).
3. **Legacy schema retirement (non-destructive)**: `ProcessTemplate`,
   `VveDecisionTemplate`, `ModelreglementPreset` set
   `x-openregister.active: false` with a superseding-schema note; no PHP or
   Vue code path is repointed in this change (that is the consumer-rewrite
   link), so existing behaviour is byte-for-byte unchanged until that change
   lands. `VveConfiguration.modelRegulation` is retargeted from a
   `ModelreglementPreset` `$ref` to a plain `modelReglementVersion` string
   enum (`1992`/`2006`/`2017`) — informational only, since
   `ModelreglementPreset` is superseded and `VveConfiguration` has zero
   consumers today (safe, additive-shaped retarget).
4. **Repair migration**: a Nextcloud migration class
   (`lib/Migration/Version*.php`) that reads every live `process-template`
   and `vve-decision-template` object and creates the equivalent
   `decision-template` object (OR seed import is create-only — new seeds in
   `68-unified-decision-templates.json` never touch objects an existing
   install already created from `43-process-config-v1.json` /
   `57-vve-alv-pack.json`). Idempotent: re-running the migration on an
   already-migrated instance is a no-op (matched by source object UUID).

### Out of Scope

- **Rewiring consumers** — `ProcessTemplateService`, `ProcessTemplatePolicyResolver`,
  `DecisionTransitionGuard`, `ProcessTemplateController`, the admin
  `ProcessTemplates.vue` / `ProcessTemplateEditModal.vue` / Pinia store, and
  `GovernanceBody.processTemplate` / `additionalTemplates[]` resolution all
  keep reading `process-template` objects exactly as today. Repointing them
  at `decision-template` is `unified-decision-templates-consumer-rewrite`
  (`kind: code`, `depends_on: [unified-decision-templates]`).
- **Deleting the legacy PHP/schema surface** — `ProcessTemplate`,
  `VveDecisionTemplate`, `ModelreglementPreset` and their now-dead consumers
  are marked superseded here but not removed. Deletion is
  `unified-decision-templates-legacy-deletion` (`kind: code`,
  `depends_on: [unified-decision-templates-consumer-rewrite]`), scheduled
  only after the consumer-rewrite change has shipped and been live-verified.
- **Checklist completion tracking on a Decision instance** — this change
  declares the checklist *definition* on the template. Instantiating a
  per-decision checklist-progress object (which items are ticked, by whom)
  is consumer-rewrite / decision-instance work, not a schema-declaration
  concern.
- **VveConfiguration's per-akte override UI** and the kascommissie flow are
  untouched — `KascommissieVerklaring` and `VveConfiguration`'s
  `majorityOverrides[]` mechanism carry over unchanged (field rename only).
- **New nav entries** — `DecisionTemplate` management stays gear/admin
  config exactly like `ProcessTemplate` today (nav-ceiling + ADR-044 gates
  unaffected).

## Approach

Declare `DecisionTemplate` as a net-new schema in a new register fragment
(`68-unified-decision-templates.json`, next free number after
`66-organisation-goals.json`) rather than editing the three legacy fragments
in place — `43-process-config-v1.json`, `46-urgency-policy.json`, and
`57-vve-alv-pack.json` stay as historical record of what shipped, and the new
fragment both declares the unified schema/seeds and patches the three legacy
schemas' `x-openregister.active` flag + `VveConfiguration.modelRegulation`
(ADR-037's additive per-change-fragment pattern). A Nextcloud migration class
repairs already-created live objects since OR seed import is create-only.
Full design rationale — including the `templateCategory` vs `decisionType`
split, the `stateMachine` vs `decision-route`'s `DecisionStage` distinction,
and the modelreglement-preset folding decision — is in design.md.

## New Dependencies

None.

## Impact

- New: `lib/Settings/register.d/68-unified-decision-templates.json`,
  `lib/Migration/Version0XXX0YYYYYYY.php` (repair migration).
- Patched (via the new fragment's deep-merge, ADR-037 — no other file
  edited): `ProcessTemplate.x-openregister.active`,
  `VveDecisionTemplate.x-openregister.active`,
  `ModelreglementPreset.x-openregister.active`,
  `VveConfiguration.modelRegulation`.
- Untouched: every PHP class under `lib/Service/`, `lib/Lifecycle/`,
  `lib/Controller/` and every Vue file under `src/` — this is a
  schema-declaration change only.

## Cross-Project Dependencies

None. Decidesk-only.

## Risks

### Risk 1: The VvE decisionType mapping (all 6 seeds → `resolution`) may not survive juridical review

**Severity:** Medium — **Mitigation:** flagged explicitly as an Open Question
below and carried into design.md; the mapping is a template-seed data choice,
not a schema shape decision, so correcting it later (or adding a
`meeting-outcome` variant) is a data-only follow-up, not a schema migration.

### Risk 2: Folding only the 2017 modelreglement's `categoryRules` into seeds discards the 1992/2006 default majorities as directly selectable templates

**Severity:** Low — **Mitigation:** `VveConfiguration.majorityOverrides[]`
(unchanged mechanism) already lets a body on an older modelreglement deviate
per category; the 1992/2006 article references remain documented in
`regulationSource` history in this proposal and design.md for the
consumer-rewrite change to re-seed as explicit template rows if a live VvE
on an older modelreglement needs it before the ALV pack goes live.

### Risk 3: The repair migration runs against production data with no prior dry-run tooling in this repo

**Severity:** Medium — **Mitigation:** migration.md specifies the migration
as idempotent (matched by source UUID) and read-only against the legacy
objects (creates new `decision-template` rows, never deletes or edits
`process-template`/`vve-decision-template`), so a failed or partial run is
safe to re-execute; validation queries are specified in migration.md.

## Rollback Strategy

Revert the register fragment (delete
`68-unified-decision-templates.json`) and the migration class, then run the
migration's down-migration (deletes only the `decision-template` objects it
created, matched by its own provenance marker — never touches
`process-template`/`vve-decision-template`, which were never modified in
place). `ProcessTemplate`/`VveDecisionTemplate`/`ModelreglementPreset` revert
to `active: true` automatically once the patching fragment is removed
(ADR-037 deep-merge). No consumer code is touched, so rollback carries zero
runtime-behaviour risk.

## Open Questions

- Should the 6 VvE built-in seeds map to `decisionType=resolution` or
  `decisionType=meeting-outcome`? This proposal defaults to `resolution`
  (formal ALV besluiten) pending product/juridical confirmation — same
  review posture the original `VveDecisionTemplate.regulationSource` fields
  already carried ("flagged for juridical review before release").
- Should the dependent `unified-decision-templates-consumer-rewrite` and
  `unified-decision-templates-legacy-deletion` changes be created now (empty
  scaffolds with `depends_on` set) or only when this change is ready to
  build? This proposal narrates the chain but does not create the sibling
  change directories.

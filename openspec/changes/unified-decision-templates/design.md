# Design: unified-decision-templates

## Architecture Overview

Today three schemas answer "what governs this decision" independently:

```
ProcessTemplate (43-process-config-v1.json)          — LIVE consumer
  states/transitions, votingRule, quorumRequired/Rule, allowDecideWithoutVote
  + urgencyPolicy (bolt-on delta, 46-urgency-policy.json)
  ↓ read by
  ProcessTemplateService → ProcessTemplatePolicyResolver → DecisionTransitionGuard
  (via GovernanceBody.processTemplate / additionalTemplates[])

VveDecisionTemplate + ModelreglementPreset (57-vve-alv-pack.json)  — NO consumer
  decisionCategory, proposedText, defaultVoteThreshold, defaultQuorumFraction
  + a separate majority/quorum-per-category lookup table (3 modelreglement
    versions), bound to a body via VveConfiguration
  ↓ read by
  nothing (grep-confirmed zero PHP/Vue references outside the register
  fragment, the manifest, and menu-layout.json)
```

This change declares one schema, `DecisionTemplate`, that is the union of
`ProcessTemplate`'s shape (route/voting/quorum/urgency — the part that is
actually consumed) and `VveDecisionTemplate`'s shape (decision content —
`proposedText`, `regulationSource`, a category narrower), plus a new
`checklist[]`. `ModelreglementPreset`'s three modelreglement versions are not
kept as a fourth, still-separate lookup schema — their 2017 (current-law)
`categoryRules` are folded directly into the corresponding
`DecisionTemplate` seeds as that seed's `votingRule`/`quorumRule` defaults,
because a "preset a template consults" and "the template's own default" are
the same fact once VveDecisionTemplate and DecisionTemplate are one object:

```
DecisionTemplate (67-unified-decision-templates.json)         — schema-declared here
  decisionType?  (Decision.decisionType enum, absent = generic default)
  context        (association|corporate|legislative|operations|citizen)
  templateCategory?  (finer narrower, e.g. VvE ALV categories)
  stateMachine, votingRule, quorumRequired/Rule, allowDecideWithoutVote
  urgencyPolicy?  (native, no longer a bolt-on delta)
  proposedText?, regulationSource?   (from VveDecisionTemplate)
  checklist[]?    (NEW — ordered required/optional check items)
  ↓ read by
  NOTHING YET — this change is schema-declaration only. The consumer chain
  above (ProcessTemplateService et al.) is untouched and keeps reading
  process-template until unified-decision-templates-consumer-rewrite lands.
```

`ProcessTemplate`, `VveDecisionTemplate`, and `ModelreglementPreset` are
marked `x-openregister.active: false` (a create-time guard only — existing
objects and existing service code are unaffected) rather than deleted, and a
repair migration back-fills `decision-template` objects for every live
`process-template` / `vve-decision-template` object so the consumer-rewrite
change has real migrated data to point its rewired consumers at, not just
fresh seeds.

## Decisions

### Decision 1: `decisionType` is optional, not required

**Choice:** `DecisionTemplate.decisionType` is optional; absent means
"generic default for this `context`."

**Why:** `GovernanceBody` already has two fields anticipating exactly this
two-tier resolution — `processTemplate` (one default identifier) and
`additionalTemplates[]` ("specialized process templates... for specific
decision types", per `42-admin-settings-v1.json`). Making `decisionType`
required would force every one of the 5 existing `ProcessTemplate` built-ins
(which are body-level defaults, not decision-type-specific) to either pick
an arbitrary `decisionType` or be duplicated once per type. Optional
preserves their existing default-template role unchanged.

**Alternative considered:** Require `decisionType` and add a sentinel value
`"any"`. Rejected — an explicit sentinel adds a vocabulary term to maintain
for no behavioural gain over "absent means any", and every consumer
(existing and future) already treats "no value" as "no constraint" for
optional discriminators elsewhere in this schema set (e.g.
`templateCategory`).

### Decision 2: `templateCategory` is a new field, not an extension of `decisionType`

**Choice:** Keep `Decision.decisionType`'s enum untouched; add a separate,
narrower `templateCategory` string for classifications that exist *within* a
`decisionType` (the VvE ALV categories: discharge, annual-accounts, etc.).

**Why:** `Decision.decisionType` is a cross-cutting discriminator consumed
by progressive disclosure across the entire universal `Decision` schema
(ADR-005/ADR-006) — every VvE ALV besluit legitimately IS a `resolution`
(the formal vote-outcome shape), but "which kind of resolution" is a
template-selection concern, not a decision-form concern. Folding VvE
categories into `Decision.decisionType` would grow that enum by 7 municipal-
domain-irrelevant values and require every non-VvE `resolution` consumer
(legislative, corporate) to now reason about association-only sub-values.
`templateCategory` keeps the growth scoped to template *selection*, matching
ADR-006 mechanism 3 (progressive disclosure) layered on mechanism 2 (a type
discriminator) rather than growing mechanism 2's vocabulary indefinitely.

**Alternative considered:** A separate schema per `context` (i.e., keep
`VveDecisionTemplate` distinct). Rejected — this is the exact pattern
ADR-006 retired for the board portal; one schema per concept, mode/type
narrowed via fields, not parallel schemas.

### Decision 3: `DecisionTemplate.stateMachine` is not the same "stage" concept as `decision-route`'s `DecisionStage`

**Choice:** Keep the field named `stateMachine` (states[]/transitions[],
unchanged from `ProcessTemplate`) and do not attempt to unify it with
`decision-route`'s `DecisionStage` route objects.

**Why:** These are two genuinely different mechanisms that happen to share
the word "stage": `DecisionTemplate.stateMachine` is the lifecycle graph
`DecisionTransitionGuard` walks for a SINGLE decision's own status field
(`draft → proposed → deliberating → voting → decided → enacted → archived`,
per-decision, guard-enforced). `decision-route`'s `DecisionStage` is an
ordered set of separate OpenRegister objects representing which
*decision-makers* (bodies/people) a decision travels through (e.g.
college → raadscommissie → gemeenteraad), each with its own resolution
method (manual/vote/signature/chair-register/advice, per
`decision-methods`). A decision has exactly one `stateMachine`-driven status
and can independently have zero or more `DecisionStage` route entries. This
change does not touch `decision-route` or `decision-methods` in any way —
confirmed by review, no schema or requirement in either capability needs a
change.

**Alternative considered:** Rename `DecisionTemplate.stateMachine`'s
`states[]`/`transitions[]` to align vocabulary with `DecisionStage`.
Rejected as unnecessary churn — the two concepts are correctly distinct, and
a shared vocabulary would suggest a relationship that does not exist.

### Decision 4: Non-destructive supersession + a repair migration, not an in-place rename

**Choice:** Add a new schema and mark the three legacy schemas
`x-openregister.active: false`, rather than renaming `ProcessTemplate` to
`DecisionTemplate` in place or deleting `VveDecisionTemplate`/
`ModelreglementPreset`.

**Why:** ADR-037's additive per-change-fragment pattern exists precisely so
concurrent builds never conflict and every change is independently
revertible; an in-place rename of `ProcessTemplate` would require editing
`43-process-config-v1.json`, which every fragment convention in this repo
(see the register.d README) treats as historical record. Because OR seed
import is create-only (memory: `or-agg` / `reference_or-aggregation-dialect-silently-discarded.md`
sibling lesson, and explicitly the `annotation-only-schema-change-never-deploys`
lesson), a schema-only rename would leave every already-created
`process-template` object stranded under a schema no service reads —
exactly the failure mode this change's repair migration exists to close.
`active: false` is a create-time guard, not a read guard (verified via
`ProcessTemplateService::list()`/`get()`, which filter by `register`/`schema`
context, never by `x-openregister.active`), so marking a schema inactive
does not orphan its existing objects or break its existing reader code.

**Alternative considered:** Delete `ProcessTemplate`/`VveDecisionTemplate`/
`ModelreglementPreset` immediately. Rejected — that is exactly the scope of
the dependent `unified-decision-templates-legacy-deletion` change, which
must wait until `unified-decision-templates-consumer-rewrite` has shipped
and been live-verified (deleting a schema still read by
`ProcessTemplateService` would 500 every template operation).

### Decision 5: The chain split (ADR-032)

**Choice:** Split into `unified-decision-templates` (this change, `kind:
config`) → `unified-decision-templates-consumer-rewrite` (`kind: code`,
`depends_on: [unified-decision-templates]`) →
`unified-decision-templates-legacy-deletion` (`kind: code`, `depends_on:
[unified-decision-templates-consumer-rewrite]`). Only this change's
artifacts are generated now; the two dependent changes are narrated, not
scaffolded.

**Why:** The full scope — new schema + repair migration + rewiring
`ProcessTemplateService`/`ProcessTemplatePolicyResolver`/
`DecisionTransitionGuard`/`ProcessTemplateController` + rewriting the admin
Vue surface (`ProcessTemplates.vue`, `ProcessTemplateEditModal.vue`, the
Pinia store) + deleting three schemas and their now-dead PHP/Vue — is
squarely the `mixed`-shaped anti-pattern the opsx-ff skill's ADR-32 guidance
warns burns a builder's full turn budget without producing a PR. Splitting
lets the schema land, get seeded, and get its live-data repair verified
independently of the (much larger, judgment-heavy) consumer rewiring.

**Alternative considered (deferred to an Open Question):** Create the two
dependent change directories now as empty `depends_on`-tagged scaffolds so
`/opsx-plan-to-issues` can wire the dependency graph immediately. Not done
in this generation pass — flagged as an Open Question for the human to
decide, since creating scaffolds for work not yet designed risks the empty
shells drifting stale before the design catches up (this change's own specs
had to be written after reading the actual consumer code, which the
scaffolds would not yet reflect).

## API Design

None. This is a schema-declaration-only change — no new or modified
controller endpoint. `ProcessTemplateController`'s existing routes are
untouched.

## Database Changes

New Nextcloud migration class (see Migration Plan below) — no SQL schema
change (Decidesk owns no database tables; all persistence is via
OpenRegister's `ObjectService`). The migration's job is entirely
OpenRegister object creation (repair), not a `changeSchema()` DDL step.

## Nextcloud Integration

- **Controllers:** none new; `ProcessTemplateController` unchanged.
- **Services:** none new; `ProcessTemplateService`,
  `ProcessTemplatePolicyResolver`, `DecisionTransitionGuard` unchanged.
- **Migration:** one new `lib/Migration/MigrateLegacyTemplatesToDecisionTemplate.php`
  implementing `OCP\Migration\IRepairStep` (verified pattern — every existing
  `lib/Migration/*.php` in this repo uses `IRepairStep` with a descriptive
  class name, not a version-numbered class) that reads `process-template` /
  `vve-decision-template` objects via `ObjectService::findAll()` and creates
  `decision-template` objects via `ObjectService::saveObject()`. Registered
  in `appinfo/info.xml` under `<repair-steps><post-migration>`, **after**
  `OCA\Decidesk\Repair\InitializeSettings` (which imports the register and
  plants the new seeds) — the same ordering constraint
  `RenameDutchDecideskValues` already documents inline ("After
  InitializeSettings imports the register").
- **Register:** new fragment `lib/Settings/register.d/67-unified-decision-templates.json`,
  merged by the existing `SettingsService::deepMergeConfig()` /
  `ConfigurationService::importFromApp()` path (ADR-037) — no change to that
  merge machinery itself.

## Security Considerations

No new endpoint, no new auth surface. The repair migration runs as an
admin-triggered Nextcloud migration (occ upgrade / app enable), the same
trust boundary every existing Decidesk migration runs under — it is not
reachable from an HTTP request. The migration is read-mostly against
existing data (reads `process-template`/`vve-decision-template`, writes only
new `decision-template` objects) and never deletes or mutates a
pre-existing object, so it carries no data-loss risk distinct from any other
`ObjectService::saveObject()` call already trusted in this codebase.

## NL Design System

N/A — no Vue/frontend change in this slice.

## File Structure

```
lib/
  Settings/
    register.d/
      67-unified-decision-templates.json   (new — schema + seeds + supersession patches)
  Migration/
    MigrateLegacyTemplatesToDecisionTemplate.php   (new — IRepairStep, see
                                                     Nextcloud Integration)
appinfo/
  info.xml    (modified — one new <step> line under <repair-steps><post-migration>,
               after InitializeSettings)
```

No file under `lib/Controller/`, `lib/Service/`, `lib/Lifecycle/`, or `src/`
is created or modified.

## Seed Data

Every seed below carries forward its source object's fields unchanged except
for the new `decisionType`/`templateCategory`/`checklist` properties (all
built-ins ship `checklist: []` — no built-in acquires a checklist in this
change; checklist authoring is a consumer-rewrite / admin-UI concern).

### Schema: `decision-template` (representative sample — full seed set below)

| Field | `association-alv` | `municipal-council` | `decharge-bestuur` | `municipal-council-urgent` |
|---|---|---|---|---|
| slug | association-alv | municipal-council | decharge-bestuur | municipal-council-urgent |
| context | association | legislative | association | legislative |
| decisionType | *(absent)* | *(absent)* | resolution | *(absent)* |
| templateCategory | *(absent)* | *(absent)* | discharge | *(absent)* |
| builtIn | true | true | true | false |
| stateMachine | ported from `ProcessTemplate` (7 states) | ported (7 states) | *(none — content template)* | ported (7 states) |
| votingRule.voteThreshold | simple-majority | simple-majority | simple-majority | simple-majority |
| quorumRequired | true | true | *(none — content template)* | true |
| proposedText | — | — | "De vergadering verleent het bestuur decharge voor het gevoerde beleid en beheer over het boekjaar {boekjaar}, gehoord de verklaring van de kascommissie." | — |
| regulationSource | — | — | "BW 2:48/2:49" | — |
| urgencyPolicy | — | — | — | `{allowedTriggerRoles:["chair"], minimumNoticeFloorHours:24, responseDeadlineHours:{min:12,max:96}, ratificationRequired:true, ratifyingBody:"gemeenteraad-amsterdam"}` |
| checklist | [] | [] | [] | [] |
| migratedFrom | *(none — new seed, not migrated)* | *(none)* | *(none)* | *(none)* |

**Full built-in seed set (13 objects, all `builtIn: true`, all
`checklist: []`):**

1–5. Ported 1:1 from `ProcessTemplate` (`decisionType` absent):
`association-alv`, `association-board`, `corporate-board`,
`municipal-council`, `operational-team` — same `context`, `stateMachine`,
`votingRule`, `quorumRequired`/`quorumRule`, `allowDecideWithoutVote`.

6–7. Ported 1:1 from the `46-urgency-policy.json` custom templates
(`decisionType` absent, `builtIn: false` — these were custom, not built-in,
in the source fragment, and stay that way): `municipal-council-urgent`,
`corporate-board-urgent` — same `context`, `stateMachine`, `votingRule`,
`urgencyPolicy`.

8–13. Ported from `VveDecisionTemplate` (`context=association`,
`decisionType=resolution`, no `stateMachine` — these are content templates,
not state-machine templates, exactly as `VveDecisionTemplate` carried no
state machine before): `decharge-bestuur` (`templateCategory=discharge`),
`vaststelling-jaarrekening` (`annual-accounts`), `dotatie-reservefonds`
(`reserve-fund-contribution`), `vaststelling-mjop` (`mjop-adoption`),
`machtiging-boven-drempel` (`authorisation-above-threshold`,
`votingRule.voteThreshold=qualified-majority-two-thirds`,
`quorumRule="2/3 (MR 2017 art. 56 lid 5)"`), `wijziging-huishoudelijk-reglement`
(`amendment-internal-regulations`,
`votingRule.voteThreshold=qualified-majority-two-thirds`) — each carrying its
source `proposedText` and `regulationSource` unchanged.

**Related items per object:** none — `DecisionTemplate` objects are
configuration, not content with attachments/notes/tasks (matching
`ProcessTemplate`'s and `VveDecisionTemplate`'s existing seed posture, which
also carry no related items).

### Schema: `vve-configuration` (existing object, retargeted field)

| Field | `vve-zeewaarts-configuratie` (before) | (after this delta) |
|---|---|---|
| modelRegulation | `"modelreglement-2017"` (slug ref) | *(removed)* |
| modelReglementVersion | *(new field)* | `"2017"` |
| majorityOverrides[0].decisionCategory | `amendment-internal-regulations` | *(renamed)* |
| majorityOverrides[0].templateCategory | *(new field name)* | `amendment-internal-regulations` |

## Migration Plan

**Current State:** `process-template` objects exist (5 built-in seeds plus
any administrator-created customs); `vve-decision-template` objects exist (6
built-in seeds); no `decision-template` objects exist.

**Target State:** every live `process-template` and `vve-decision-template`
object has a corresponding `decision-template` object; the 13 new built-in
`decision-template` seeds exist; `process-template`,
`vve-decision-template`, `modelreglement-preset` remain readable but
`x-openregister.active: false`.

**Migration Class:** `lib/Migration/MigrateLegacyTemplatesToDecisionTemplate.php`
implementing `IRepairStep` (matches `MigrateActionItemsToDeckLeaf.php` /
`MigrateEmailLinksToRegistry.php` / `MigrateCommentsToTalkLeaf.php`'s
established pattern — descriptive class name, not version-numbered).
Registered in `appinfo/info.xml`'s `<repair-steps><post-migration>` list
after `InitializeSettings`, so it runs during `occ upgrade` / app
install-or-update after the register fragment (and its seeds) have been
imported.

**Migration Steps:**
1. `ObjectService::findAll(['filters' => ['register' => 'decidesk', 'schema' => 'process-template']])` — read every live `process-template` object.
2. For each, check for an existing `decision-template` object with `migratedFrom.sourceUuid` equal to that object's UUID (idempotency guard); skip if found.
3. Otherwise, build a `decision-template` payload carrying `name`, `description`, `context`, `builtIn`, `initialState`, `stateMachine`, `votingRule`, `quorumRequired`, `quorumRule`, `allowDecideWithoutVote`, `urgencyPolicy` (if present) forward unchanged, `decisionType` and `templateCategory` absent, `checklist: []`, and `migratedFrom: {sourceSchema: 'process-template', sourceUuid: <uuid>}`; `ObjectService::saveObject()`.
4. Repeat steps 1–3 for `vve-decision-template` objects, mapping `decisionCategory` → `templateCategory`, `defaultVoteThreshold` → `votingRule.voteThreshold`, `defaultQuorumFraction` → `quorumRule`, `context` fixed to `association`, `decisionType` fixed to `resolution` (per Decision 2/the vve-alv-pack delta's REQ-VVE-010), and `migratedFrom.sourceSchema = 'vve-decision-template'`.
5. Log a summary (count migrated, count skipped-as-already-migrated) at `info` level.

**Data Impact:** bounded by how many `process-template` +
`vve-decision-template` objects exist per install — typically single-digit
built-ins plus any admin customs (no fleet install is expected to have more
than a few dozen). No data loss: the migration only creates objects, never
deletes or edits. Safe to run on live data; safe to re-run.

**Rollback Procedure:** delete every `decision-template` object whose
`migratedFrom` is populated (a query, not a schema change — the migration
class's `down()`/reverse step, or a one-off admin script if the framework's
migration base class has no reverse hook); delete the register fragment
`67-unified-decision-templates.json`, which reverts `process-template` /
`vve-decision-template` / `modelreglement-preset` to `active: true`
automatically (ADR-037 deep-merge).

**Validation:** after the migration runs, `count(decision-template WHERE
migratedFrom IS NOT NULL)` MUST equal `count(process-template) +
count(vve-decision-template)` observed before the migration; a spot-check of
one migrated custom template's `stateMachine` MUST match its source
object's byte-for-byte.

## Declarative vs Imperative Decision (ADR-031)

| Behaviour | Path chosen | Rationale |
|---|---|---|
| `DecisionTemplate` schema + built-in seeds | **Declarative** (`x-openregister.seedData`, ADR-016/ADR-037) | Pure data declaration — no computed value, no trigger. |
| Legacy schema supersession (`active: false`) | **Declarative** (schema-property patch via the same fragment) | A static flag, not a computed/triggered behaviour. |
| Repair migration (create `decision-template` from live `process-template`/`vve-decision-template`) | **Imperative** (Nextcloud `IRepairStep`/migration class) — documented ADR-031 exception | This is one-time bulk data transformation over already-created objects, matching ADR-031's named exception "scheduled bulk work that genuinely needs... rather than a derived field" — there is no `x-openregister-calculations`/`aggregations` mechanism for "materialise one object per existing object of a different schema"; OpenRegister has no cross-schema object-creation trigger. |
| Template resolution at transition time (which template a body/decisionType uses) | **Not touched by this change** — remains the existing imperative `ProcessTemplateService`/`ProcessTemplatePolicyResolver`/`DecisionTransitionGuard` lifecycle-guard path, an already-documented ADR-031 exception (lifecycle guard) | Consumer-rewrite scope, not schema-declaration scope. |
| Checklist item completion tracking | **Not declared in this change** | Deferred to consumer-rewrite/decision-instance work per the proposal's Out of Scope — no declarative or imperative mechanism is specified here because no instantiation of a per-decision checklist exists yet. |

## Risks / Trade-offs

- **[Risk]** A future reader might assume `x-openregister.active: false`
  hides existing objects from the UI (a read guard), when it is actually
  only a create-time guard. → **Mitigation:** the process-configuration
  delta spec's "Legacy template schemas superseded, non-destructively"
  requirement states this explicitly with a scenario, and this design doc
  cites where it was verified (`ProcessTemplateService::list()`/`get()`
  filter by register/schema context only).
- **[Risk]** The `decisionType=resolution` mapping for all 6 VvE seeds is a
  judgment call pending juridical review (Proposal Risk 1). → **Mitigation:**
  documented as an Open Question in both the proposal and this design; the
  mapping is seed *data*, correctable without a schema migration.
- **[Trade-off]** Folding only the 2017 modelreglement's category rules into
  seeds (not all three versions × all categories) trades completeness for
  scope discipline in a config-only change. → Accepted: `VveConfiguration.majorityOverrides[]`
  already covers the deviation case, and re-seeding 1992/2006 variants later
  is additive, non-breaking data work.

## Open Questions

- Confirm `decisionType=resolution` (vs `meeting-outcome`) for the 6 VvE
  built-in seeds with product/juridical review before this change is applied
  (see proposal Open Questions).
- Should `unified-decision-templates-consumer-rewrite` and
  `unified-decision-templates-legacy-deletion` be scaffolded now (empty
  `depends_on`-tagged change directories) so `/opsx-plan-to-issues` can wire
  the dependency graph immediately, or created only when this change is
  ready to build? Deferred to the human (Decision 5).

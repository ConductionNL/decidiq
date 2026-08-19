---
kind: config
---

# Proposal: organisation-facet-composition

## Summary

Turns the existing `GovernanceBodyDetail` page into the "Organisation" hub described by ADR-004 v2's Organisation cluster ("governance bodies/members, PLUS retirement schedules, other-positions/gifts integrity data, and on/offboarding + proxy-authorization surfaces"). Eight capabilities already ship a body-scoped register (retirement schedules, term rules, nevenfuncties, geschenken, shared-body participation, zienswijzerondes) but every one of them left the reverse-facet on `GovernanceBodyDetail` explicitly out of scope, because a `manifest.d` fragment cannot patch a single widget onto an existing page — `mergePages` replaces a same-id page wholesale (design D6). This change is the one that finally does that composition work, directly in the base `src/manifest.json`, plus a small schema delta on `GovernanceBody` (a `faction` / `fractie` `bodyType` value and a new `parentBody` self-reference) so "Factions" becomes representable as ordinary `GovernanceBody` objects per ADR-006 (mode/type adaptation, never a parallel schema) rather than as the standalone `Fractie` schema proposed by the stale, pre-ADR-006 draft change `fractievoorzitter-fractie-koppeling`.

## Motivation

Six sibling capabilities (`appointments-and-terms`, `interests-and-integrity`, `shared-governance-bodies`) each shipped their own index/detail pages for a governance-body-scoped register, and each one's manifest fragment says, verbatim, that the reverse-facet on `GovernanceBodyDetail` is "out of scope for this declarative-core fragment." A griffier who opens a body today sees members, meetings, documents, the process template, efficiency, retention and self-evaluation — but has to leave the page and search four other index pages to find that body's retirement schedule, its term rule, its nevenfuncties/geschenken register, or (for a gemeenschappelijke regeling) its participating organisations and open zienswijzerondes. The data already exists and is already body-scoped by a real schema field (`body`, `governanceBody`, or `sharedBody`); it just isn't surfaced from the one page a griffier actually opens. Separately, `GovernanceBody.bodyType` has no value for "faction/fractie" today, even though `fractievoorzitter-fractie-koppeling` (drafted 2026-05-22, before ADR-006 was accepted 2026-06-14) proposed a full parallel `Fractie`/`FractieLidmaatschap`/`SchriftelijkeVraag` schema set to model exactly this concept — the kind of parallel-entity duplication ADR-006 now explicitly forbids. Several already-shipped fragments (`vragenuur-interpellatie`, `raadsinformatiebrieven`, `constituency-consultation`) carry placeholder plain-string "Fractie" references waiting on that draft. Doing nothing leaves that inconsistency to compound with every new fragment that copies the same placeholder pattern.

## Affected Projects
- [x] Project: `decidesk` — `GovernanceBodyDetail` page composition (base `src/manifest.json`), `GovernanceBody` schema delta (base `lib/Settings/decidesk_register.json`), three capability specs updated (`governance-bodies`, `governance-body-crud`)

## Scope

### In Scope
- Add a `faction` `bodyType` enum value and a new `parentBody` (self-referencing, nullable) property to the `GovernanceBody` schema in `lib/Settings/decidesk_register.json`, so a faction is an ordinary `GovernanceBody` object (`bodyType=faction`, `parentBody=<council id>`) per ADR-006 — no new schema.
- Compose eight new facet widgets onto `GovernanceBodyDetail` in `src/manifest.json`, each a declarative `object-list` widget filtered by the body's own object id against the schema's existing scoping field — no new Vue components:
  - Retirement schedule (`rooster-van-aftreden`, filter `body`) → links into the existing `RoosterDetail` page.
  - Term rules, read-only (`termijn-regeling`, filter `body`) → links into the existing `TermijnRegelingDetail` page; no inline create/edit action (editing stays on the dedicated `Termijnregelingen` admin surface, i.e. "the gear").
  - Other positions (`nevenfunctie`, filter `governanceBody`) → links into `NevenfunctieDetail`.
  - Gifts (`geschenk`, filter `governanceBody`) → links into `GeschenkDetail`.
  - Participating organisations (`body-participation`, filter `sharedBody`) — for a body that is itself a shared body (`bodyType=shared-body`).
  - Shared-body participations (`body-participation`, filter `participant`) — the shared bodies this body participates in.
  - Zienswijze rounds (`zienswijzeronde`, filter `sharedBody`) → links into `ZienswijzerondeDetail`.
  - Factions (`governance-body`, filter `parentBody` + `bodyType=faction`) → links back into `GovernanceBodyDetail` itself.
- Verify (read-only check, no code change) that the existing Members tab satisfies "Memberships of this body, schema `membership`, ref `governanceBody`, with Person resolution" — see Open Questions; this change does not alter `GovernanceBodyMembersTab.vue` or its dialogs.
- Update the `governance-bodies` and `governance-body-crud` capability specs to describe the new hub composition and the `bodyType`/`parentBody` schema delta.

### Out of Scope
- Any Vue/PHP code change. Every facet added here is an existing `object-list` widget pattern already used by `body-meetings`; no new registry component is introduced.
- Rewriting `GovernanceBodyMembersTab.vue` (and its four modal dialogs) off the deprecated `participant` schema onto `membership` + Person resolution. Investigation found the current tab queries `participant`, not `membership` — a genuine pre-existing gap against the Popolo model ADR-001/ADR-006 already mandate — but the fix spans 5 files (~1,245 lines: the tab plus `MemberAddDialog`, `MemberRoleDialog`, `MemberGroupImportDialog`, `MemberCsvImportDialog`) and is Vue code, not declarative config. Bundling it here would make this a `mixed`-shaped spec (forbidden per ADR-032 except for a ≤20 LOC/≤2 file thin-glue exception, which this is not) and risks burning the builder's turn budget without landing either half. See Open Questions — flagged as a follow-up change.
- A citizen/person-directory page. Person/Membership have no dedicated UI today; this change scopes strictly to facets reachable from `GovernanceBodyDetail`.
- Resolving the fate of the stale `fractievoorzitter-fractie-koppeling` draft change (full `Fractie`/`FractieLidmaatschap`/`SchriftelijkeVraag`/`PolitiekePartij`/`Kandidatenlijst`/`FractieOndersteuning` schema set) or of the plain-string `Fractie` placeholder fields already seeded in `vragenuur-interpellatie`, `raadsinformatiebrieven`, and `constituency-consultation`. This change only adds the minimal `bodyType=faction` + `parentBody` discriminator; see Open Questions.
- Enforcing `parentBody` as conditionally required when `bodyType=faction` — OpenRegister's schema dialect has no conditional-required expression (the same limitation documented elsewhere in this register, e.g. `constituency-consultation`'s at-least-one-of rule), and adding save-time enforcement would require an imperative service class, which is out of scope for a `kind: config` change.

## Approach

Two purely declarative edits, both to the base (non-fragment) files that already own these definitions:

1. **Schema delta** (`lib/Settings/decidesk_register.json`, `GovernanceBody`): append `"faction"` to `bodyType`'s enum, and add a `parentBody` property (`type: string`, `format: uuid`, `$ref: GovernanceBody`, `nullable: true`) describing it as the parent body a faction (or, generically, any sub-body) belongs to.
2. **Page composition** (`src/manifest.json`, `GovernanceBodyDetail`): the page's `widgets` and `layout` arrays are edited in place (base file, not a fragment — sidesteps the D6 wholesale-replace hazard other fragments hit) to append the eight new `object-list` widgets described above, each following the `body-meetings` widget's existing pattern (`filter: { "<scopeField>": "@objectId" }`, `rowRoute`, `emptyText`, `limit`). No widget introduces a new Vue component; `type: "custom"` components already on the page (`GovernanceBodyMembersTab`, template/efficiency/retention/evaluations tabs) are untouched.

design.md carries the full widget definitions, the grid layout, and the declarative-vs-imperative rationale (ADR-031) for treating all eight facets as pure filtered listings rather than aggregation/relation dialect additions.

## New Dependencies
None.

## Impact
- `lib/Settings/decidesk_register.json` — `GovernanceBody.bodyType` enum + new `GovernanceBody.parentBody` property.
- `src/manifest.json` — `GovernanceBodyDetail` page: 8 new widgets + layout entries.
- `openspec/specs/governance-bodies/spec.md`, `openspec/specs/governance-body-crud/spec.md` — updated capability descriptions.
- No route, controller, service, or store changes. No new menu entries (ADR-004 Rule 4 / six-item ceiling untouched — this composes an existing detail page, it does not add a nav item).

## Cross-Project Dependencies
None — self-contained within `decidesk`. The `meeting-facet-composition` and `decision-facet-composition` changes apply the identical pattern to `MeetingDetail` and the decision detail page in parallel; none of the three touches another's page, widgets, or capability spec files.

## Risks

### Risk 1: `bodyType=faction` collides with the unlanded `fractievoorzitter-fractie-koppeling` draft
**Severity:** Medium — **Mitigation:** That draft (created 2026-05-22) predates ADR-006 (accepted 2026-06-14) and proposes exactly the parallel-schema pattern ADR-006 forbids ("Forbidden: A new schema that duplicates an existing concept 'for a different audience'"). This change's `bodyType=faction` + `parentBody` discriminator is the ADR-006-compliant alternative. The two approaches are not composable as-is; flagged as an Open Question for a human decision (supersede/archive the draft, or fold its genuinely-distinct pieces — e.g. `SchriftelijkeVraag`, funding/`FractieOndersteuning` — into the universal model separately). Nothing in this change modifies or deletes the draft.
### Risk 2: Eight new widgets make `GovernanceBodyDetail` very large
**Severity:** Low — **Mitigation:** `MeetingDetail` already carries 9 custom/data widgets at a comparable page weight; every widget added here degrades gracefully to an empty-state list (e.g. a non-shared body's "Participating organisations" list is simply empty) rather than erroring, matching the existing `emptyText` convention used throughout the app.

## Rollback Strategy
Both edits are additive and reversible independently: revert the `GovernanceBodyDetail` widget/layout entries in `src/manifest.json` to drop the facets from the page (existing index/detail pages for each register are untouched and keep working standalone), and/or revert the `bodyType` enum value + `parentBody` property in `lib/Settings/decidesk_register.json` (no existing object can have `bodyType=faction` before this change ships, so the enum revert is non-destructive; any `parentBody` values written after rollout would need a one-time export/backfill note if the field removal is CVYd — call out in migration.md).

## Open Questions
1. **Members tab is on the wrong schema.** `GovernanceBodyMembersTab.vue` and its four dialogs query the deprecated `participant` schema, not `membership` + Person, contradicting the governance-bodies spec's own status note ("Person + Membership become the governance-body decision-maker model... replacing the deprecated flat Participant model"). Provisional decision: out of scope here (see Scope); recommend a dedicated follow-up `kind: code` change (e.g. `governance-body-membership-migration`).
2. **`parentBody` is a new field beyond the literal instruction to "add the bodyType enum value."** Provisional decision: add it, because without a relation field a "Factions" facet cannot be filtered to bodies related to the body being viewed — it would either be a global unscoped listing or not declarative. Flagged for confirmation.
3. **Disposition of `fractievoorzitter-fractie-koppeling`.** Provisional decision: leave the draft untouched; a human should decide whether to archive/supersede it now that ADR-006 forbids its core approach, or salvage its genuinely non-duplicative pieces (SchriftelijkeVraag, FractieOndersteuning funding tracking) into a future ADR-006-compliant change.

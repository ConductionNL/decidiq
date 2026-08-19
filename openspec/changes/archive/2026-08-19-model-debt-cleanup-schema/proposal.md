---
kind: config
---

# Proposal: model-debt-cleanup-schema

## Summary

Six accumulated OpenRegister schema-model defects in decidesk, all previously identified with evidence: an undeclared core join (`Decision.meeting`/`Decision.agendaItem` are written by the frontend but never declared on the schema), two `Participant`-shim references that outlived their own deprecation notice (`ConflictOfInterest.boardMember`, `ProxyAuthorization.grantor`/`holder`), a duplicate legacy proxy schema (`BoardProxy`) that should fold into `ProxyAuthorization`, a missing convenience property on `GoverningDocument` (parity with `Regeling.currentEffectiveDate`), and two camelCase schema slugs (`adviceRequest`, `proxyAuthorization`) that violate the fleet's kebab-case slug convention. This is the declarative half of a two-part cleanup — pure OpenRegister schema-register JSON edits (register.d fragments + two direct base-file edits), no PHP/Vue/TS. The imperative half (live-data repair steps, service/controller rewrites, and the `GovernanceBodyMembersTab` + 4 dialogs frontend rewrite) is the dependent `model-debt-cleanup-code` change.

## Motivation

Every item below was found with concrete evidence during a model-debt sweep of decidesk's schema register (`lib/Settings/decidesk_register.json` + `lib/Settings/register.d/*.json`), not by inspection alone:

- `src/components/tabs/MeetingDecisionsTab.vue` (lines 130, 151) and `src/components/tabs/AgendaMotionsTab.vue` (lines 192, 234) write `meeting`/`agendaItem` onto `decision` objects today, silently, because OpenRegister accepts undeclared properties. Validation, facets, and RBAC on this join do not exist because the property does not exist from the schema's point of view.
- `Participant`'s own schema description already says "DEPRECATED: superseded by Person + Membership... retained as a shim for quorum aggregation + vote-casting resolver" — but `ConflictOfInterest.boardMember` and `ProxyAuthorization.grantor`/`holder` still `$ref: Participant`, contradicting the shim's own stated retained scope.
- `BoardProxy`'s own description says "the signed-document side of a volmacht lives on ProxyAuthorization" — an explicit acknowledgement, in the shipped schema text, that this is two schemas for one concept.
- `openspec/changes/register-detail-optimisation/tasks.md` (Task 6, "NOT IMPLEMENTED") already documented that `GoverningDocument` has no current-in-force convenience property and flagged the fix as "requiring a schema change (own OpenRegister ticket)" — this change is that ticket.
- `adviceRequest` and `proxyAuthorization` are the only two non-kebab-case entries in `decidesk_register.json`'s `components.registers.decidesk.schemas` array (85 total slugs, 83 already kebab-case).

## Affected Projects

- [x] Project: decidesk — OpenRegister schema register (`lib/Settings/decidesk_register.json`, `lib/Settings/register.d/`), manifest fragments (`src/manifest.json`, `src/manifest.d/`)

## Scope

### In Scope

- Add `Decision.meeting` ($ref `Meeting`, optional) and `Decision.agendaItem` ($ref `AgendaItem`, optional), both `facetable: true`.
- Repoint `ConflictOfInterest.boardMember` from `$ref: Participant` to `$ref: Membership`.
- Repoint `ProxyAuthorization.grantor` and `ProxyAuthorization.holder` from `$ref: Participant` to `$ref: Person`.
- Add `ProxyAuthorization.proxyStatus` (enum `pending-approval`/`active`/`suspended`/`revoked`, additive) to carry `BoardProxy`'s approval-workflow concept onto the surviving schema.
- Retire `BoardProxy`: `x-openregister.active: false` + description pointing at `ProxyAuthorization` + `proxyStatus`. Schema definition and slug are kept (not deleted) — see design.md for why.
- Add `GoverningDocument.currentEffectiveDate` (nullable date, `facetable: true`), mirroring `Regeling.currentEffectiveDate`.
- Rename schema slug `adviceRequest` → `advice-request` (schema file, `decidesk_register.json` registry list, manifest references, seed-data object keys).
- Rename schema slug `proxyAuthorization` → `proxy-authorization` (same surfaces).
- Narrow `Participant`'s own deprecation description to name its two remaining consumers exactly (`Vote.participant`, `EngagementRecord.participant`) now that two of its four consumers are repointed.
- Seed-data examples for every new/retargeted property (OpenRegister seed import is create-only — see design.md).
- Supersede the stale `fractievoorzitter-fractie-koppeling` draft (pre-ADR-006 parallel Fractie schema set) with a note pointing at the `organisation-facet-composition` mechanism — done directly as part of this artifact generation, not a tasks.md line item.

### Out of Scope

- Any PHP/Vue/TS code change, including the live-data repair steps needed for the two `$ref` retargets and the `BoardProxy` → `ProxyAuthorization` row migration — these are the `model-debt-cleanup-code` change (`depends_on: [model-debt-cleanup-schema]`).
- Deleting the `Participant` schema. `Vote.participant` and `EngagementRecord.participant` still `$ref: Participant`, and `Participant`'s description already documents quorum aggregation + `VotingService::resolveParticipantUuid()` as live dependents. Scoped out; documented as follow-up in design.md.
- Folding `ConflictOfInterest` into the `Nevenfunctie`/`Geschenk`/`Integriteitsbeleid` interests-and-integrity models (register.d/62). Evaluated with evidence in design.md; kept separate per the ADR-006 escape clause — the two model genuinely different things (a per-agenda-item recusal event vs. a standing, publishable other-position registry).
- The unrelated `ConsultationRequest.type` enum value `"adviceRequest"` (works-council-consultation, register.d/47) — a coincidental string collision with the schema slug being renamed, not the same concept. Left untouched; called out explicitly in design.md and tasks.md so it isn't swept up by a careless find/replace.
- Wiring `GoverningDocument.currentEffectiveDate` into the register-detail index column — `register-detail-optimisation` already scoped that UI wiring out once ("requires... a schema change"); this change supplies the schema, the UI wiring stays a separate follow-up.
- Any change to `proxy-voting`'s `Vote.delegator`/`isProxy` mechanism (openspec/specs/proxy-voting) or `conflict-of-interest`'s note-based COI mechanism (openspec/specs/conflict-of-interest) — both are separate, already-spec'd, unrelated capabilities discovered during this sweep; documented as an observation in design.md, not touched.

## Approach

All edits are OpenRegister schema-register JSON, applied through the existing ADR-037 modular-fragment mechanism (`SettingsService::mergeRegisterFragments()` deep-merges `lib/Settings/register.d/*.json` onto `lib/Settings/decidesk_register.json` at import time) plus two direct edits where the fragment mechanism cannot express the change (see design.md — list-valued keys concatenate rather than rename under the merge). No new `lib/Migration/` or `lib/Repair/` class, no service/controller edit, no Vue edit. Full detail in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/` — one new fragment (`67-model-debt-cleanup.json`) plus in-place edits to two existing fragments the change already owns conceptually (`60-advisory-opinion-workflow.json`, `63-member-proxy-authorization.json`) and one the `governing-documents-register` change owns (`55-governing-documents-register.json`, one additive property).
- `lib/Settings/decidesk_register.json` — two surgical string edits inside `components.registers.decidesk.schemas` (slug renames only; no schema body touched directly since `Decision`, `ConflictOfInterest`, `Participant`, `BoardProxy` are all overridden via the new fragment instead).
- `src/manifest.json`, `src/manifest.d/advisory-opinion-workflow.json`, `src/manifest.d/member-proxy-authorization.json` — slug string updates.
- `tests/Unit/RegisterJsonTest.php` — verified NOT affected (reads `components.schemas` from the base file only; never touches `components.registers` or register.d fragments). No PHPUnit fallout from this change.

## Cross-Project Dependencies

None — decidesk-internal only.

## Risks

### Risk 1: A fragment-based property override silently no-ops if the key path is wrong
**Severity**: Medium
**Mitigation**: `deepMergeConfig()` unions by key recursively for objects but *concatenates* (not merges) list-valued keys. Every override in the new fragment targets object keys only (`properties.<name>`, `x-openregister.active`, `description`); the two list-valued renames (`components.registers.decidesk.schemas`) are done as direct edits precisely because a fragment overlay would append rather than rename there. tasks.md calls this out per-task.

### Risk 2: Renamed slugs break something the exhaustive grep missed
**Severity**: Medium
**Mitigation**: design.md documents the full grep result set for both `adviceRequest` and `proxyAuthorization`, including the one confirmed false-positive (`ConsultationRequest.type` enum value) that must NOT be touched. tasks.md enumerates every file to edit by name.

### Risk 3: Retargeting `$ref: Participant` → `$ref: Membership`/`Person` on live rows leaves existing objects holding a UUID of the wrong kind until the code chain's repair step runs
**Severity**: Medium
**Mitigation**: This is expected and by design — schema declaration and data migration are deliberately split across the two chained changes (`depends_on`). Existing rows are not silently broken: OpenRegister does not retroactively re-validate stored objects against a changed `$ref` target, so reads keep working until the code chain's repair step (gated to run after this schema import, per the `<repair-steps><post-migration>` ordering already established by `RenameDutchVocabularyColumns`) resolves the stale Participant UUIDs.

## Rollback Strategy

Revert the fragment file and the two direct-edit lines in `decidesk_register.json`/manifest files; `SettingsService::mergeRegisterFragments()` re-imports on next settings load (version-gated via the fragment-signature hash in `importRegisterConfig()`), so no manual OpenRegister cleanup is needed for the schema declaration itself. If the code chain has already run its repair steps against retargeted rows, rolling back the schema alone leaves those rows pointing at Membership/Person UUIDs under a `$ref` that once again says `Participant` — coordinate rollback of both chained changes together, never this one alone once the code chain has shipped.

## Open Questions

None — every decision in this proposal (Participant deletion scope, ConflictOfInterest-vs-interests-and-integrity fold, Person-vs-Membership retarget split) was made with grep/schema evidence and is recorded in design.md's Decisions section. See DEFERRED_QUESTIONS in the change-generation report for the one judgment call flagged for human confirmation.

## Capabilities

**Modified Capabilities:**
- `schemas-and-data-model` — adds the join declarations, retargets, retirement, and slug renames as new requirements (no existing requirement text changes; these are net-new schema surface).
- `participant-crud` — narrows REQ-PCR-010's description of which consumers still hold `$ref: Participant`.

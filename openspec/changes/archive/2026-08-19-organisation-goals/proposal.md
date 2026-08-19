---
kind: config
---

# Proposal: organisation-goals

## Summary

Decidesk's Termijnagenda (long-term agenda, schema `termijnagenda-item`) plans *when* a topic will arrive on the agenda, but nothing today captures *what a governance body is trying to achieve* and tracks progress toward it. This change adds a new `goal` schema — organisation goals shaped on ISO 9001 §6.2 (quality objectives at relevant functions and levels: measurable, owned, deadlined, monitored) — and wires it into the existing accountability chain: Toezegging (commitments) and ActionItem (tasks) get an optional `goal` reference so day-to-day commitments and tasks roll up into goal progress, and TermijnagendaItem gets an optional `goal` reference so the forward-planning view can show which planned topics serve which goal. Progress and lifecycle are declarative (x-openregister-lifecycle / x-openregister-aggregations / x-openregister-calculations per ADR-031) — no new PHP service. The whole change is JSON-only: a new register.d schema fragment, three one-property patches to existing register.d/base schemas, and a manifest.d nav fragment for the Goals index/detail pages.

## Motivation

Governance bodies (councils, boards, departments) are expected — by ISO 9001 §6.2 and by ordinary good governance — to set measurable objectives at each organisational level, own them, deadline them, and monitor progress. Decidesk today has no place to record "what are we trying to achieve" independent of a single meeting or a single commitment; Toezeggingen are commitments made by an individual portfolio holder in a specific meeting, and the Termijnagenda is a forward planning calendar, not a target-and-progress instrument. Without a `Goal` object, there is no way to answer "how many of our commitments/tasks this quarter serve our stated objectives" or "is this department on track against its annual target" from within Decidesk — that reporting either doesn't happen or happens in a spreadsheet outside the system of record.

## Affected Projects

- [x] Project: `decidesk` — new `goal` schema; `goal` reference added to `Toezegging`, `ActionItem`, `TermijnagendaItem`; Goals index/detail nav fragment (placement deferred to the concurrent `ia-six-clusters` change's menu layout)

## Scope

### In Scope

- New schema `Goal` (slug `goal`) in a new register.d fragment: `title`, `description`, `horizon` (enum: multi-year / annual / quarterly), `owner` (→ Person, $ref, responsible user), `body` (→ GovernanceBody, $ref — `bodyType` already spans legislative/association/corporate-board/operational/supervisory-board/executive-board/advisory-body, i.e. every organisational level a goal can be set at), `startDate`, `deadline` (required), `status` lifecycle (declarative, ADR-031), measurable-target fields (`targetValue`, `currentValue`, `unit`), `parentGoal` (→ Goal, $ref, self-referential — enables org-wide → department → quarterly cascades)
- Declarative lifecycle on `Goal` (`x-openregister-lifecycle`) mirroring the existing Toezegging/TermijnagendaItem dialect
- Declarative progress aggregation on `Goal` (`x-openregister-aggregations` + `x-openregister-calculations`): counts/sums of linked Toezegging and ActionItem objects, and of direct child Goals (single-level `parentGoal` rollup only — see design.md for the multi-level limitation)
- `goal` reference added to `Toezegging` (register.d/45), `ActionItem` (base `decidesk_register.json` — read-only CalDAV VTODO projection per ADR-002; the property rides the existing generic non-core `fields` blob that `ActionItemWriter` already round-trips, so no PHP change is needed), and `TermijnagendaItem` (register.d/50)
- A `manifest.d/organisation-goals.json` fragment declaring the Goals index + detail pages and ONE menu entry, matching the existing `termijnagenda.json` fragment's declarative shape (generic data/related widgets; no custom Vue component)
- Seed data for `Goal` plus the new `goal` reference on one seeded Toezegging, one seeded ActionItem-shaped seed (if any exists), and one seeded TermijnagendaItem, per ADR-001

### Out of Scope

- The nav placement itself: this change's manifest fragment declares the menu entry with a placement note; **the concurrent `ia-six-clusters` change owns actually grouping it under the "Tasks & Commitments" cluster** in the cluster nav layout. This change does not touch `openspec/changes/ia-six-clusters/`.
- Multi-level (grandparent+) progress cascade. The declarative aggregation dialect has no recursive/multi-hop query form; this change ships single-level `parentGoal` rollup only. A deeper cascade, if wanted, is a follow-up change (imperative or a future dialect extension).
- Any custom Vue widget for visualising goal progress (progress bars, burn-down, etc.) beyond the generic `data`/`related` detail widgets — deferred, same posture as the Termijnagenda board page (design D6 in that change).
- Retiring or renaming the Termijnagenda / `termijnagenda-item` schema. It keeps its own identity as the forward-planning calendar; it merely gains an optional `goal` reference.
- Changing `GovernanceBody.bodyType`'s enum. It already covers every organisational level a goal can be set against.

## Approach

Everything lands as declarative JSON, following the register.d/manifest.d fragment pattern already used by `termijnagenda`, `toezeggingen-ingekomen-stukken`, and every other recent Decidesk change (ADR-037 for register fragments; ADR-024/036 for manifest fragments). `SettingsService::mergeRegisterFragments` already globs `lib/Settings/register.d/*.json`, and `main.js`'s `require.context('./manifest.d/', ...)` already globs `src/manifest.d/*.json` — a new fragment file is picked up automatically, no PHP or JS code edit required. See design.md for the full schema sketch and the declarative-vs-imperative rationale.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/66-organisation-goals.json` (new) — `Goal` schema + seed data
- `lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json` — add `goal` property to `Toezegging`
- `lib/Settings/decidesk_register.json` — add `goal` property to `ActionItem` (schema-only; `ActionItemWriter`'s generic `fields` blob already round-trips any non-core key)
- `lib/Settings/register.d/50-termijnagenda.json` — add `goal` property to `TermijnagendaItem`
- `src/manifest.d/organisation-goals.json` (new) — Goals index/detail pages + menu entry

## Cross-Project Dependencies

None. The concurrent `ia-six-clusters` change (same repo) is the one dependency worth naming: it owns the "Tasks & Commitments" cluster nav layout that will place this change's menu entry. That change's `openspec/changes/` directory is explicitly out of scope for this change — coordination is by convention (a matching placement note on both sides), not a file edit here.

## Risks

### Risk 1: Declarative aggregation dialect may not support self-referential filters cleanly
**Severity:** Medium — **Mitigation:** The existing dialect (`x-openregister-aggregations` with `metric: count`, a target `schema`, and a `filter` keyed by `@self.*`) already supports a filter target equal to the current object's own schema in principle (schema is just a string); this change is the first to point that filter at the *same* schema as the one declaring it (`parentGoal: "@self.id"` on `Goal` itself). If the aggregation engine rejects same-schema self-reference, the child-goal-count aggregation degrades to absent (dashboard shows no rollup) rather than failing the whole schema — verify during apply/seed-import per design.md's open question.

### Risk 2: `ActionItem`'s read-only CalDAV projection may not round-trip a new field if the object-source provider does NOT generically pass through the `fields` blob
**Severity:** Low — **Mitigation:** `ActionItemWriter::toTaskData()` already treats any key not in its `coreKeys` list (which `goal` is not) as a pass-through field into the `X-OPENREGISTER-DATA` blob, exactly like the existing `decision`/`meeting` references — this is an established, working pattern, not new plumbing. Verify with a live create/read round-trip during apply (tasks.md includes this as an explicit verification task, not a code task).

## Rollback Strategy

Delete `lib/Settings/register.d/66-organisation-goals.json` and `src/manifest.d/organisation-goals.json`, and revert the three one-property patches to `45-toezeggingen-ingekomen-stukken.json`, `decidesk_register.json` (ActionItem), and `50-termijnagenda.json`. No data migration is needed to roll back — `goal` is an optional/nullable reference everywhere it was added, so removing it drops the reference but not the referencing objects.

## Open Questions

- Does the `x-openregister-aggregations` engine support a filter target schema equal to the declaring schema itself (Goal → Goal via `parentGoal`)? Provisional decision: declare it as documented in the existing dialect; verify at seed-import time; if unsupported, the child-goal-count field is simply absent (not a hard failure) — see DEFERRED_QUESTIONS.

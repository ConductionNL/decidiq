---
kind: config
---

# Proposal: appointment-decision-type-schema

## Summary

Completes ADR-005 by implementing `Decision.decisionType = appointment` — a value
already reserved in the discriminator enum but never given folded fields, relations,
or seed data. This change folds the standalone `voordracht` (nomination) schema
(`lib/Settings/register.d/61-appointments-and-terms.json`) into `Decision`, following
the exact pattern the archived `unify-decision-supertype` change used for
motion/amendment/resolution: one schema per concept (ADR-006), discriminated by
`decisionType`, with type-specific fields revealed via progressive disclosure
(ADR-004 Rule 2). This is the config-only head of a two-change chain; the imperative
Membership-materialization service and the Decision-form UI for the new fields ship
in the dependent change `appointment-decision-type-membership`.

## Motivation

`appointment` has sat in `Decision.decisionType`'s enum since `unify-decision-supertype`
(2026-06-14) with zero folded fields — the only seed using it
(`besluit-benoeming-penningmeester`) is a generic title/text decision indistinguishable
from `meeting-outcome`. Meanwhile the `appointments-and-terms` change shipped a
**parallel** `voordracht` register (`register.d/61`) that duplicates exactly what
`decisionType` was designed to discriminate: a nomination that gets decided upon,
producing an appointment. This is the same "vocabulary confusion" ADR-005 and
ADR-006 already named and fixed once for motion/resolution — a second parallel
schema for a decision-shaped concept re-introduces it. `voordracht` also reinvents
scheduling (`agendaItem`, `votingRound` fields) that `Decision.route` →
`DecisionStage` → `VotingRound` (via `DecisionStage.decisionStage`) already provides
generically to every decision type — so folding removes duplicated machinery, not
just duplicated vocabulary.

## Affected Projects

- [x] Project: `decidesk` — `Decision` schema gains folded appointment fields;
      `voordracht` schema and its seed data are removed from `register.d/61`;
      `src/manifest.d/appointments-and-terms.json` drops the Voordrachten/
      VoordrachtDetail menu entry and pages (Roosters/Termijnregelingen pages are
      untouched — those three schemas stay as-is, out of scope here).

## Scope

### In Scope

- Add `decisionType=appointment` folded fields to `Decision` in
  `lib/Settings/decidesk_register.json`: `targetBody`, `targetPosts`, `targetRole`,
  `candidates`, `nominatingParty`, `appointedMemberships` (the last is a
  server-set/nullable output field — populated by the dependent change's
  imperative service, declared here so the schema is complete in one place).
- Remove the `Voordracht` schema and its `seedData.objects.voordracht` block from
  `lib/Settings/register.d/61-appointments-and-terms.json`. `TermijnRegeling`,
  `RoosterVanAftreden`, and `RoosterRegel` in the same file are untouched — none of
  them reference `Voordracht` (verified: `rooster-regel` and `rooster-van-aftreden`
  reference `Membership`/`GovernanceBody` directly, never `voordracht`).
- Re-author the 3 existing `voordracht` demo seeds as `Decision` seeds with
  `decisionType=appointment` (re-seed migration, per ADR-005's established
  precedent — see Migration).
- Remove the `Voordrachten` (index) and `VoordrachtDetail` pages and the
  `Voordrachten` menu entry from `src/manifest.d/appointments-and-terms.json`.
  `Roosters`, `RoosterDetail`, `Roosterregels`, `RoosterregelDetail`,
  `Termijnregelingen`, `TermijnRegelingDetail` stay unchanged.
- Update the `decision-management` spec capability with the new requirement.

### Out of Scope

- The imperative Membership-materialization service (person + post + governance
  body → Membership on adoption) and the Decision-form progressive-disclosure UI
  for the new appointment fields — ships in the dependent change
  `appointment-decision-type-membership` (`depends_on` this change).
- `TermijnRegeling`, `RoosterVanAftreden`, `RoosterRegel` schemas — explicitly kept
  as-is per the product decision; they reference `Membership`, not `Voordracht`,
  and are unaffected by this fold.
- Adding a "Nominations" nav entry that filters the Decisions register to
  `decisionType=appointment` — nav placement across the 6-item ceiling (ADR-004)
  is owned by the parallel change `ia-six-clusters`. This change deliberately does
  not touch that change's directory or `menu-layout.json`; `ia-six-clusters` is
  expected to add the filtered nav entry once this change's `decisionType=appointment`
  exists to filter on.
- Cross-decision relations, decision route/stage UI, decision methods — pre-existing
  capabilities (`decision-route`, `decision-methods`), unaffected.

## Approach

Mirror the archived `unify-decision-supertype` design exactly: fold the retiring
schema's fields onto `Decision`, reveal them via `decisionType=appointment`
progressive disclosure, reuse `Decision`'s existing 7-state lifecycle instead of
`Voordracht`'s bespoke 5-state one (`submitted→handled→appointed|not-appointed→
withdrawn` maps onto `draft→proposed→deliberating→voting→decided→enacted→
archived|withdrawn`), and re-seed the 3 demo `voordracht` objects as typed
decisions. Full field mapping and the fold-vs-keep-separate evaluation are in
design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/decidesk_register.json` — `Decision` schema (`0.7.0` → `0.8.0`):
  new properties, new seed objects.
- `lib/Settings/register.d/61-appointments-and-terms.json` — `Voordracht` schema
  and its seed block removed.
- `src/manifest.d/appointments-and-terms.json` — 2 of 6 pages + 1 of 3 menu
  entries removed.
- No PHP controller/service code, no Vue components — config only (schema
  register + manifest JSON).

## Cross-Project Dependencies

None — decidesk-internal only. Sibling apps do not consume the `voordracht`
schema (grepped `lib/` and `src/` repo-wide: only `register.d/61` and
`manifest.d/appointments-and-terms.json` reference it).

## Risks

### Risk 1: A consumer reads the `voordracht` schema/register directly
**Severity:** Low — **Mitigation:** Repo-wide grep confirms no PHP or Vue code
references `voordracht`/`Voordracht` outside the two files this change edits; the
manifest-driven list/detail pages are generic (`CnListPage`/`CnDetailPage` reading
`register`+`schema` config), so no custom Vue component targets the retired schema.

### Risk 2: Demo re-seed loses fidelity vs. the original 3 voordracht seeds
**Severity:** Low — **Mitigation:** Field-by-field mapping table in design.md;
the shipped data is seed/demo data (ADR-005 precedent), not production records —
re-seed is the sanctioned migration path.

## Rollback Strategy

Revert the branch (schema-register + manifest JSON only, no imperative migration
of live objects). Re-import the register from the reverted files. No irreversible
data transformation — the previous `voordracht` objects are demo seeds, not
production records requiring preservation.

## Open Questions

None — the fold-vs-keep-separate decision and field mapping are resolved in
design.md.

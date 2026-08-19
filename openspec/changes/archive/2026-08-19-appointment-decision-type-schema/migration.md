# Migration: appointment-decision-type-schema

> Decidesk owns no database tables — it is a thin client over OpenRegister. This is
> therefore a **register-schema + seed-data** migration of `lib/Settings/decidesk_register.json`
> and `lib/Settings/register.d/61-appointments-and-terms.json`, applied via the register
> re-import, NOT a Nextcloud `lib/Migration/VersionXXXX.php` class. The shipped `voordracht`
> objects are SEEDED DEMO DATA, so the migration is re-seed-based per ADR-005 (no
> data-preserving migration of irreplaceable records) — the same approach the archived
> `unify-decision-supertype` change used for motion/amendment/resolution.

## Current State

- `Decision` (`decidesk_register.json`, v0.7.0) has `decisionType=appointment` in
  its enum but no folded fields for it and one generic, indistinguishable-from-
  `meeting-outcome` seed (`besluit-benoeming-penningmeester`).
- `Voordracht` (`register.d/61-appointments-and-terms.json`) is a standalone
  schema: `body`, `post`, `targetRole`, `kandidaten` (array), `nominatingParty`,
  `rationale`, `lifecycle` (bespoke 5-state: `submitted→handled→appointed|
  not-appointed|withdrawn`), `agendaItem`, `votingRound`, `decision`,
  `membership`. 3 seeds: `voordracht-auditcommissie-lid` (submitted),
  `voordracht-rvc-vanduin` (appointed), `voordracht-auditcommissie-vz` (withdrawn).
- `src/manifest.d/appointments-and-terms.json` has 6 pages / 3 menu entries:
  `Voordrachten`+`VoordrachtDetail`, `Roosters`+`RoosterDetail`,
  `Roosterregels`+`RoosterregelDetail`, `Termijnregelingen`+`TermijnRegelingDetail`.

## Target State

- `Decision` gains folded appointment fields (`targetBody`, `targetPosts`,
  `targetRole`, `candidates`, `nominatingParty`, `appointedMemberships`),
  version bumps `0.7.0 → 0.8.0`. 3 new seeds re-author the 3 retired
  `voordracht` seeds; the existing `besluit-benoeming-penningmeester` seed is
  untouched.
- `Voordracht` and its seed block are removed from `register.d/61-...json`.
  `TermijnRegeling`, `RoosterVanAftreden`, `RoosterRegel` and their seed blocks
  are unchanged.
- `src/manifest.d/appointments-and-terms.json` drops to 4 pages / 2 menu
  entries: `Voordrachten`/`VoordrachtDetail` and their menu entry are removed;
  the Rooster/Termijnregeling pages and menu entries are unchanged.

## Migration Class

```
No Nextcloud migration class. The change is applied by editing
lib/Settings/decidesk_register.json, lib/Settings/register.d/61-appointments-and-terms.json,
and src/manifest.d/appointments-and-terms.json, then re-importing the register
(SettingsService register setup / occ register import on app upgrade).

Key operations:
- decidesk_register.json: add Decision.targetBody/targetPosts/targetRole/
  candidates/nominatingParty/appointedMemberships (revealed when
  decisionType=appointment); bump Decision.version; add 3 re-authored seeds
- register.d/61-appointments-and-terms.json: delete components.schemas.Voordracht
  and x-openregister.seedData.objects.voordracht
- manifest.d/appointments-and-terms.json: delete the Voordrachten menu entry
  and the Voordrachten/VoordrachtDetail page entries
```

## Migration Steps

1. **(config)** Add `targetBody`, `targetPosts`, `targetRole`, `candidates`,
   `nominatingParty`, `appointedMemberships` to `Decision.properties` in
   `decidesk_register.json`; bump `Decision.version` to `0.8.0`.
2. **(data)** Rewrite the 3 `Voordracht` seeds as `Decision` seeds with
   `decisionType=appointment` using the D2/D3 field and lifecycle mappings in
   design.md (new slugs: `benoeming-lid-auditcommissie`,
   `benoeming-lid-rvc-acme-van-duin`, `benoeming-voorzitter-auditcommissie-
   ingetrokken` — new slugs rather than reused ones, since these are now
   `Decision` objects, not `Voordracht` objects, and the slug namespace is
   per-schema in OpenRegister).
3. **(config)** Delete `components.schemas.Voordracht` and
   `x-openregister.seedData.objects.voordracht` from
   `register.d/61-appointments-and-terms.json`. Leave `TermijnRegeling`,
   `RoosterVanAftreden`, `RoosterRegel` and their seed blocks untouched.
4. **(config)** Delete the `Voordrachten` menu entry and the `Voordrachten`/
   `VoordrachtDetail` page entries from `manifest.d/appointments-and-terms.json`.
   Leave the Rooster/Termijnregeling menu entries and pages untouched.
5. **(deploy)** Re-import the register so OpenRegister applies the schema
   changes and seeds; re-merge manifest fragments (`main.js::mergeManifestFragments`).

## Data Impact

- **Demo/seed data only.** 3 `voordracht` seed objects are transformed into
  typed `Decision` objects; no irreplaceable records are touched. The pre-
  existing `besluit-benoeming-penningmeester` decision seed is untouched.
- **No relation re-pointing needed.** Verified repo-wide: no other schema in
  `decidesk_register.json` or `register.d/61-...json` references `Voordracht`
  by `$ref` (`TermijnRegeling`/`RoosterVanAftreden`/`RoosterRegel` reference
  `Membership`/`GovernanceBody` directly, never `Voordracht`), so retiring the
  schema orphans nothing.
- **Live data:** On a production instance with real `voordracht` objects (not
  the demo register), operators MUST export those objects, re-import them as
  `decision` objects with `decisionType=appointment` using the D2/D3 mapping
  in design.md, before removing the `Voordracht` schema. This change ships the
  re-seed for the demo register; a live-data export/re-import runbook is the
  operator's responsibility, identical in shape to the runbook the archived
  `unify-decision-supertype` change already documents for motion/amendment/
  resolution.

## Rollback Procedure

Revert the branch (or restore the previous versions of the 3 edited files) and
re-import the register / re-merge the manifest. This restores the `Voordracht`
schema, its seeds, and the `Voordrachten` nav pages. No irreversible data
migration runs, so rollback on the demo register is a clean re-import. On a
live instance, restore the pre-migration register export.

## Validation

- `Decision` (`decidesk_register.json`) lists `targetBody`, `targetPosts`,
  `targetRole`, `candidates`, `nominatingParty`, `appointedMemberships` in
  `properties`; `Voordracht` is absent from `register.d/61-...json`
  `components.schemas`.
- A register listing returns exactly 3 new `decision` objects with
  `decisionType=appointment` carrying non-empty `candidates`, plus the
  pre-existing `besluit-benoeming-penningmeester` (4 appointment decisions
  total, up from 1).
- `TermijnRegeling`/`RoosterVanAftreden`/`RoosterRegel` seed counts and schema
  definitions are byte-identical before/after (2/2/5 objects respectively).
- `src/manifest.d/appointments-and-terms.json` `menu` has 2 entries (`Roosters`,
  `Termijnregelingen`) and `pages` has 4 entries, down from 3/6.

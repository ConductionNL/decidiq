# Migration: organisation-facet-composition

> Decidesk owns no database tables (thin client over OpenRegister). "Migration"
> here means an OpenRegister schema-register change, applied by the existing
> `InitializeSettings` `IRepairStep` → `SettingsService::importConfiguration()` →
> `ConfigurationService::importFromApp()` path — not a `lib/Migration/VersionXXXX.php`
> DB-schema class. No new PHP migration class is introduced by this change.

## Current State

`lib/Settings/decidesk_register.json`, schema `GovernanceBody` (version `0.2.0`):
- `bodyType` enum has 10 values (`legislative`, `association`, `corporate-board`,
  `operational`, `citizen-panel`, `supervisory-board`, `executive-board`,
  `advisory-body`, `works-council`, `shared-body`) — no `faction` value.
- No `parentBody` property exists.

Register `info.version` (top-level, gates re-import): `0.8.0`.

## Target State

`GovernanceBody` schema (bumped to version `0.3.0`):
- `bodyType` enum gains an 11th value: `faction`.
- New property `parentBody`: `{ "title": "Parent body", "type": "string", "format": "uuid", "$ref": "GovernanceBody", "nullable": true, "description": "Reference to the parent GovernanceBody this body belongs to — used for factions (bodyType=faction) referencing their council, and generically available for other sub-body hierarchies. Optional." }`.

Register `info.version` bumped to `0.9.0` — **required**, not cosmetic: the
importer is version-gated (`SettingsService::importRegisterConfig()` passes
`configData['info']['version']` to `ConfigurationService::importFromApp()`); an
unchanged `info.version` makes the next `occ upgrade` (or repair-step run) a
no-op and the schema delta silently never lands (observed previously in this
workspace — see the "or-gotchas" project memory: "version unchanged ⇒
`occ upgrade` is a no-op").

Two new seed `governance-body` objects (`groenlinks-fractie-amsterdam`,
`d66-fractie-amsterdam`) and one new seed `membership` object
(`m-marie-groenlinks-fractie`) — see design.md Seed Data.

`src/manifest.json`, page `GovernanceBodyDetail`: 8 new entries in `widgets[]`
and matching entries in `layout[]` (see design.md Decisions 1–4 for the exact
widget definitions); no existing widget, layout entry, or slot is removed or
renamed.

## Migration Class

Not applicable — no `lib/Migration/VersionXXXXXXXXXX.php` is added. The existing
`lib/Repair/InitializeSettings.php` repair step already re-imports the whole
register on every app upgrade where `info.version` changed; that mechanism picks
up this change's schema delta and seed additions automatically once
`info.version` is bumped as described above. No new repair step is needed.

## Migration Steps

1. Edit `lib/Settings/decidesk_register.json`: add `faction` to `GovernanceBody.bodyType` enum, add the `parentBody` property, bump `GovernanceBody`'s own `version` to `0.3.0`.
2. Append the two faction `governance-body` seed objects and the one `membership` seed object (design.md Seed Data) to their respective seed arrays in the same file.
3. Bump the register's top-level `info.version` from `0.8.0` to `0.9.0`.
4. Edit `src/manifest.json`: add the 8 new widgets + layout entries to `GovernanceBodyDetail` (see design.md).
5. On the shared dev instance, run `occ upgrade` (or trigger the repair step directly) and confirm the repair step's log line reports the new version and re-import success — do not merely confirm the step ran; confirm the reported version changed (a same-version "success" is the no-op failure mode this step exists to catch).

## Data Impact

- **Records affected:** 0 existing records modified or invalidated. The enum addition and new nullable property are purely additive — no existing `GovernanceBody` object fails validation under the new schema.
- **New records:** 3 seed objects (2 `governance-body`, 1 `membership`), all net-new, none overwriting existing seed data.
- **Live-data safe:** yes — additive schema changes are safe to import against a running instance with existing `GovernanceBody`/`Membership` data; OpenRegister validates on write, not retroactively on existing rows.

## Rollback Procedure

- Revert `lib/Settings/decidesk_register.json` to drop the `faction` enum value, the `parentBody` property, and the 3 seed objects; revert `GovernanceBody.version` and `info.version` to their prior values.
- Revert `src/manifest.json`'s `GovernanceBodyDetail` widget/layout additions.
- Re-run the repair step (`occ upgrade`) so the reverted register re-imports.
- **Caveat:** if any real (non-seed) `GovernanceBody` object was given `bodyType=faction` and/or a `parentBody` value in production before rollback, those field values are orphaned by the enum/property removal (OpenRegister does not delete data on a schema field removal, but new writes against the reverted schema would reject `bodyType=faction`). Export/backfill those objects' `bodyType` to a valid pre-change value before rolling back if this is a concern in a live tenant — not needed in dev/seed-only environments.

## Validation

- `GET` the `GovernanceBody` schema via OpenRegister's schema API (or `occ` schema inspection) and confirm `bodyType` enum includes `faction` and `parentBody` is present with `$ref: GovernanceBody`.
- Confirm the two new `governance-body` seed objects and the one new `membership` seed object exist by slug.
- Load `/governance-bodies/{gemeenteraad-amsterdam-id}` in the browser and confirm the new "Factions" widget lists `groenlinks-fractie-amsterdam` and `d66-fractie-amsterdam`, and that each links through to its own `GovernanceBodyDetail`.
- Confirm the other 7 new widgets render (with real data for bodies that have matching `rooster-van-aftreden`/`termijn-regeling`/`nevenfunctie`/`geschenk`/`body-participation`/`zienswijzeronde` objects, and with their empty state for bodies that do not) — see test-plan.md for the full per-widget checklist.

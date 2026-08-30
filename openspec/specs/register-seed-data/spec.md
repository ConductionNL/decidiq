# register-seed-data Specification

## Purpose
TBD - created by archiving change fix-inert-seeds. Update Purpose after archive.
## Requirements
### Requirement: REQ-SEED-001 Seed data is declared where OpenRegister reads it
Decidiq's register configuration SHALL declare seed objects under the top-level
`x-openregister.seedData.objects` key, mapping each **schema slug** to an array of seed objects.
Decidiq SHALL NOT declare seeds under any `x-openregister-seed`/`x-openregister-seeds` schema-level
annotation: neither spelling exists in OpenRegister's `Schema::ANNOTATION_VOCABULARY`, so
`setConfiguration()` drops them silently and no seed can plant.

#### Scenario: Seed objects plant on import
- GIVEN the `process-template` schema exists in the decidesk register and holds no objects
- WHEN the decidesk register configuration is imported
- THEN the 5 declared process templates are planted as `process-template` objects
- AND the import log records `Importing seed data objects` and no `Seed-data import failed`.

#### Scenario: Seed keys are schema slugs
- GIVEN a seed block declared under `x-openregister.seedData.objects`
- WHEN the importer resolves the target schema
- THEN the key is the schema's slug (e.g. `process-template`, `governance-body`)
- AND it is NOT the PascalCase key used under `components.schemas`, which would resolve to no schema
  and skip the seed.

#### Scenario: An out-of-vocabulary seed key is not reintroduced
- GIVEN the register configuration files
- WHEN they are inspected for `x-openregister-seeds` or `x-openregister-seed`
- THEN neither key is present.

### Requirement: REQ-SEED-002 Seeds declared in a register fragment reach the importer
Seed data declared in a `lib/Settings/register.d/*.json` fragment SHALL survive the ADR-037 fragment
merge and reach OpenRegister's importer, so a fragment is a first-class place to declare seeds.

#### Scenario: Fragment seed data is merged, not dropped
- GIVEN `register.d/43-process-config-v1.json` declares `x-openregister.seedData.objects`
- WHEN `SettingsService::loadConfiguration()` merges fragments onto the base config
- THEN the merged config passed to `importFromApp()` still contains the fragment's seed objects
- AND those objects plant.

### Requirement: REQ-SEED-003 A corrected configuration is not skipped by the import version gate
Decidiq SHALL bump `info.version` in `decidesk_register.json` whenever it changes its register
configuration in a way that must reach existing installs. OpenRegister's
`ImportHandler::importFromJson()` early-returns when the computed version is `<=` the stored version
and the content hash is unchanged, and that return happens before seed import runs — so a corrected
config with an unbumped version is itself inert.

#### Scenario: Version bump lets the corrected config through
- GIVEN an install whose stored `imported_config_decidesk_version` predates this change
- WHEN decidiq is upgraded and `info.version` is newer
- THEN the import proceeds past the version gate and seed data is imported.

#### Scenario: The fragment signature alone is not relied on
- GIVEN the effective version is `info.version` plus a `+frag.<md5>` signature
- WHEN a fragment's content changes but `info.version` does not
- THEN the change SHALL NOT rely on `version_compare()` ordering two opaque md5 segments to defeat
  the gate, because that ordering is incidental rather than guaranteed.


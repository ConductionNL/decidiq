## ADDED Requirements

### Requirement: REQ-LPCG-001 The parity checker MUST run in CI
`tests/l10n/check-l10n-parity.js` MUST be invoked by both `.forgejo/workflows/tests.yml` and
`.forgejo/workflows/app-tests.yml` as a hard gate, in addition to the existing
`tests/l10n/check-l10n.js` drift check. A locale missing a key or shipping an empty translation
for a required locale MUST fail the pipeline.

#### Scenario: A required locale is missing a translation key
- **GIVEN** `l10n/nl.json` is missing a key present in `l10n/en.json`
- **WHEN** the CI pipeline runs
- **THEN** the `test:l10n:parity` step fails and blocks the pipeline

#### Scenario: All required locales are at full parity
- **GIVEN** every required locale has every English source key with a non-empty translation
- **WHEN** the CI pipeline runs
- **THEN** the `test:l10n:parity` step passes

### Requirement: REQ-LPCG-002 The initial required set is scoped to `nl`
The initial rollout of this gate MUST scope `L10N_REQUIRED_LOCALES` to `nl` (the flagship /
NL Design System locale for this app) rather than the script's full ISO 639-1 default set, since
backfilling every non-English locale in one change is disproportionate to the finding. Widening
the required set is explicit future work, not silently deferred.

#### Scenario: CI runs the parity check scoped to Dutch only
- **GIVEN** the CI workflow step for `test:l10n:parity`
- **WHEN** it executes
- **THEN** it sets `L10N_REQUIRED_LOCALES=nl`, not the script's full default locale set

### Requirement: REQ-LPCG-003 `nl.json` MUST be at full parity with `en.json`
`l10n/nl.json` MUST contain a non-empty, genuinely-Dutch (not English-copy) translation for every
key present in `l10n/en.json`.

#### Scenario: Every English key has a Dutch translation
- **GIVEN** `l10n/en.json` and `l10n/nl.json`
- **WHEN** `check-l10n-parity.js` runs with `L10N_REQUIRED_LOCALES=nl`
- **THEN** it reports 0 missing keys and 0 empty values for `nl`

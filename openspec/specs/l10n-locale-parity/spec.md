---
status: done
---

# l10n-locale-parity Specification

## Purpose
Every English source string of decidiq has a Dutch translation, and CI fails when a new string ships without one. Built by l10n-parity-ci-gate (archived 2026-10-10).

## Requirements

### Requirement: REQ-LPCG-001 The parity checker MUST run in CI
`tests/l10n/check-l10n-parity.js` MUST run in the GitHub `code-quality.yml` workflow as a hard gate: the `test:l10n:parity:nl` npm script is a `frontend-checks` leg, next to the existing `test:l10n` drift check. A required locale missing a key or shipping an empty translation MUST fail the pipeline.

#### Scenario: A required locale is missing a translation key
- **GIVEN** `l10n/nl.json` is missing a key present in `l10n/en.json`
- **WHEN** the CI pipeline runs
- **THEN** the `test:l10n:parity:nl` leg fails and blocks the pipeline

@e2e exclude a CI leg, not a browser flow; covered by tests/vitest/l10nParityGate.spec.js (the leg is listed) and the checker's own exit code

#### Scenario: All required locales are at full parity
- **GIVEN** every required locale has every English source key with a non-empty translation
- **WHEN** the CI pipeline runs
- **THEN** the `test:l10n:parity:nl` leg passes

@e2e exclude a CI leg, not a browser flow; covered by tests/vitest/l10nParityGate.spec.js

### Requirement: REQ-LPCG-002 The initial required set is scoped to `nl`
The gate MUST scope `L10N_REQUIRED_LOCALES` to `nl` (the flagship NL Design System locale for this app) rather than the script's full ISO 639-1 default set, since backfilling every non-English locale in one change is disproportionate. Widening the required set is explicit future work, not silently deferred.

#### Scenario: CI runs the parity check scoped to Dutch only
- **GIVEN** the CI leg `test:l10n:parity:nl`
- **WHEN** it executes
- **THEN** it sets `L10N_REQUIRED_LOCALES=nl`, not the script's full default locale set

@e2e exclude a CI leg, not a browser flow; covered by tests/vitest/l10nParityGate.spec.js (the script text)

### Requirement: REQ-LPCG-003 `nl.json` MUST be at full parity with `en.json`
`l10n/nl.json` MUST contain a non-empty, genuinely Dutch (not English-copy) translation for every key present in `l10n/en.json`.

#### Scenario: Every English key has a Dutch translation
- **GIVEN** `l10n/en.json` and `l10n/nl.json`
- **WHEN** `check-l10n-parity.js` runs with `L10N_REQUIRED_LOCALES=nl`
- **THEN** it reports 0 missing keys and 0 empty values for `nl`

@e2e exclude a catalogue property, not a browser flow; covered by tests/vitest/l10nParityGate.spec.js

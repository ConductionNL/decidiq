# Tasks: l10n-parity-ci-gate

## Implementation Tasks

### Task 1: Wire the parity script into npm scripts
- **spec_ref**: `openspec/changes/l10n-parity-ci-gate/specs/l10n-locale-parity/spec.md#requirement-req-lpcg-001-the-parity-checker-must-run-in-ci`
- **files**: `package.json`
- **acceptance_criteria**:
  - GIVEN `npm run test:l10n:parity` THEN it runs `node tests/l10n/check-l10n-parity.js` and exits
    non-zero when any required locale is missing a key or has an empty translation
- [ ] Add `"test:l10n:parity": "node tests/l10n/check-l10n-parity.js"` to `package.json` scripts,
      alongside the existing `test:l10n`.

### Task 2: Scope the required-locale set for this change
- **spec_ref**: `openspec/changes/l10n-parity-ci-gate/specs/l10n-locale-parity/spec.md#requirement-req-lpcg-002-the-initial-required-set-is-scoped-to-nl`
- **files**: `.forgejo/workflows/tests.yml`, `.forgejo/workflows/app-tests.yml`
- **acceptance_criteria**:
  - GIVEN the CI step for `test:l10n:parity` THEN it sets `L10N_REQUIRED_LOCALES=nl` (the script's
    documented override) rather than the script's full ISO 639-1 default set
- [ ] Add a `run: L10N_REQUIRED_LOCALES=nl node tests/l10n/check-l10n-parity.js` step to
      `.forgejo/workflows/tests.yml`, next to the existing `test:l10n` step.
- [ ] Add the same step to `.forgejo/workflows/app-tests.yml`.
- [ ] Update each workflow's header comment (`tests.yml:13`, `app-tests.yml:18`) to document the
      new gate, following the existing `l10n-check — HARD GATE. ...` comment style.

### Task 3: Backfill the 29 missing `nl.json` keys
- **spec_ref**: `openspec/changes/l10n-parity-ci-gate/specs/l10n-locale-parity/spec.md#requirement-req-lpcg-003-nljson-must-be-at-full-parity-with-enjson`
- **files**: `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN `node tests/l10n/check-l10n-parity.js` with `L10N_REQUIRED_LOCALES=nl` THEN it exits 0
- [ ] Add real Dutch translations (not English placeholders) for the 29 keys currently missing
      from `l10n/nl.json`, including: `"Could not load participation rounds"`,
      `"Withdraw"`, `"{count} decision"` / `"{count} decisions"` (plural forms),
      `"Advice"`, `"Could not create proposal."`, `"Could not load decision-making."`,
      `"Could not update status."`, `"Deck board error"`, `"In Deck"`, `"None"`,
      `"Nothing to project."`, `"Open in decidiq"`, and the remainder reported by running the
      script locally.
- [ ] Re-run `npm run test:l10n:parity` locally to confirm 0 missing / 0 empty for `nl`.

## Cross-cutting follow-up (not in this change's scope)
- Widening `L10N_REQUIRED_LOCALES` beyond `nl` (the script's default is the full ISO 639-1
  European set) requires backfilling 275+ keys per locale across ~35 locales — tracked separately,
  not part of this change.

# Tasks: signing-external-service-with-order

## Implementation tasks

### Task 1: Fix the integriq lookup
- **spec_ref**: `openspec/changes/signing-external-service-with-order/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy`
- **files**: `lib/Service/EIDASSignatureService.php`, `tests/Unit/Service/EIDASSignatureServiceTest.php`
- **acceptance_criteria**:
  - GIVEN integriq installed WHEN a signing round starts THEN the call goes through integriq without a missing-class error
- [x] Implement
- [x] Test (red first)

### Task 2: Signing order and send from three pages
- **spec_ref**: `openspec/changes/signing-external-service-with-order/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy`
- **files**: `src/components/tabs/MinutesSignersTab.vue`, `lib/Controller/`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a motion with signers Anna then Pieter WHEN the griffier presses Send for signature THEN the request lists Anna first
  - GIVEN the service reports signed THEN the signed PDF is stored and linked on the motion
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
